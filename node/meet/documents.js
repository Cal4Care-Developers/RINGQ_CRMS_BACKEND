"use strict";

const crypto = require("crypto");
const fs = require("fs");
const fsp = fs.promises;
const path = require("path");
const { spawn } = require("child_process");
const { AttachmentError } = require("./attachments.js");

// Every raster image the converter can actually read, measured on the host
// rather than assumed. SVG is deliberately absent: it is active content (script
// in XML), and ImageMagick here has no SVG delegate at all.
const IMAGE_MIMES = Object.freeze([
  "image/jpeg", "image/png", "image/gif", "image/webp",
  "image/bmp", "image/tiff", "image/heic", "image/heif", "image/avif",
]);
// The whole non-macro PowerPoint family. LibreOffice renders all of them through
// the same sandbox branch; a slideshow and a template are just presentations
// with a different extension. Macro-enabled variants are refused at upload.
const PRESENTATION_MIMES = Object.freeze([
  "application/vnd.ms-powerpoint",
  "application/vnd.openxmlformats-officedocument.presentationml.presentation",
  "application/vnd.openxmlformats-officedocument.presentationml.slideshow",
  "application/vnd.openxmlformats-officedocument.presentationml.template",
]);
// Legacy Word and Excel go through the same LibreOffice branch as their OOXML
// counterparts; the binary formats are readable by the same converter.
const LEGACY_OFFICE_MIMES = Object.freeze([
  "application/msword",
  "application/vnd.ms-excel",
]);
const CONVERTIBLE = new Set([
  "application/pdf",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
].concat(PRESENTATION_MIMES, LEGACY_OFFICE_MIMES, IMAGE_MIMES));
const DIRECT = new Set([
  "image/jpeg", "image/png", "image/gif", "image/webp", "video/mp4", "video/webm", "text/plain", "text/csv",
]);
// Anything here must also be in CONVERTIBLE: presenting always goes through the
// page rasterizer, never the original bytes. XLSX reaches it the same way PPTX
// does — LibreOffice renders it to PDF inside the sandbox first — so a
// spreadsheet presents as its print layout, one page per sheet page.
//
// Word is presentable in BOTH of its formats or in neither: a build where a 1997
// .doc could be presented and a .docx could not reads as a bug, not as a policy.
const PRESENTABLE = new Set([
  "application/pdf",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
].concat(PRESENTATION_MIMES, LEGACY_OFFICE_MIMES, IMAGE_MIMES));

// Runs as root inside the sandbox, before setpriv. See the sandboxArgs comment.
const TMPFS_WRITABLE_SHIM = 'chmod 1777 /tmp && exec "$@"';

function run(command, args, timeoutMs, options) {
  return new Promise(function (resolve, reject) {
    const child = spawn(command, args, Object.assign(
      { stdio: ["ignore", "pipe", "pipe"] }, options || {},
    ));
    let stdout = "";
    let stderr = "";
    const timer = setTimeout(function () { child.kill("SIGKILL"); }, timeoutMs || 5000);
    child.stdout.on("data", function (chunk) { if (stdout.length < 2 * 1024 * 1024) stdout += chunk; });
    child.stderr.on("data", function (chunk) { if (stderr.length < 256 * 1024) stderr += chunk; });
    child.on("error", function (error) { clearTimeout(timer); reject(error); });
    child.on("close", function (code) {
      clearTimeout(timer);
      resolve({ code: code, stdout: stdout, stderr: stderr });
    });
  });
}

class DocumentService {
  constructor(opts) {
    const o = opts || {};
    this.repository = o.repository;
    this.attachments = o.attachments;
    this.root = path.resolve(o.root || "/var/lib/ringq/meet-collaboration");
    this.signingSecret = String(o.signingSecret || "");
    this.publicBase = String(o.publicBase || "/meet/api/v1");
    this.worker = path.resolve(o.worker || path.join(__dirname, "document-worker.js"));
    this.bwrap = o.bwrap || "bwrap";
    this.prlimit = o.prlimit || "prlimit";
    this.systemdRun = o.systemdRun || "systemd-run";
    this.workerUser = o.workerUser || "ringq-meet-converter";
    this.log = o.logger || { warn: function () {}, error: function () {} };
    this.jobs = new Map();
    this._health = null;
    this._healthAt = 0;
  }

  async health(force) {
    if (!force && this._health !== null && Date.now() - this._healthAt < 30000) return this._health;
    const commands = [[this.bwrap, ["--version"]], [this.prlimit, ["--version"]], [this.systemdRun, ["--version"]],
      ["id", ["-u", this.workerUser]], ["id", ["-g", this.workerUser]], ["libreoffice", ["--version"]],
      ["pdfinfo", ["-v"]], ["pdftoppm", ["-v"]], ["convert", ["-version"]], ["identify", ["-version"]]];
    let healthy = this.signingSecret.length >= 32;
    for (const command of commands) {
      if (!healthy) break;
      try { healthy = (await run(command[0], command[1], 5000)).code === 0; } catch (_) { healthy = false; }
    }
    this._health = healthy;
    this._healthAt = Date.now();
    return healthy;
  }

  async prepare(tenant, meetingId, attachmentId, purpose) {
    const attachment = await this.repository.getAttachment(tenant, meetingId, attachmentId);
    if (!attachment || ["ready", "attached"].indexOf(attachment.status) === -1 || attachment.deleted_at) {
      throw new AttachmentError("Attachment not found", "ATTACHMENT_NOT_FOUND", 404);
    }
    const mime = attachment.detected_mime || attachment.mime;
    if (!DIRECT.has(mime) && !CONVERTIBLE.has(mime)) {
      throw new AttachmentError("This file type cannot be previewed", "ATTACHMENT_PREVIEW_BLOCKED", 400);
    }
    if (purpose === "presentation" && !PRESENTABLE.has(mime)) {
      throw new AttachmentError("This file type cannot be presented", "PRESENTATION_TYPE_BLOCKED", 400);
    }
    const asset = await this.repository.ensureFileAsset(tenant, meetingId, attachmentId, purpose);
    const current = await this.repository.getFileAsset(tenant, meetingId, asset.id);
    if (current && current.status === "processing" &&
        Date.now() - Date.parse(current.updatedAt || 0) < 130000) return current;
    if (current && current.status === "ready" &&
        (purpose !== "presentation" || current.pageCount > 0)) return current;
    if (DIRECT.has(mime) && purpose !== "presentation") {
      await this.repository.replaceFilePages(tenant, meetingId, asset.id, []);
      return this.repository.getFileAsset(tenant, meetingId, asset.id);
    }
    if (!(await this.health())) {
      throw new AttachmentError("Document conversion is unavailable", "DOCUMENT_CONVERTER_UNAVAILABLE", 503);
    }
    if (!this.jobs.has(asset.id)) {
      const job = this._convert(tenant, meetingId, asset.id).finally(() => this.jobs.delete(asset.id));
      this.jobs.set(asset.id, job);
    }
    return this.repository.getFileAsset(tenant, meetingId, asset.id);
  }

  async _convert(tenant, meetingId, assetId) {
    await this.repository.setFileAssetStatus(tenant, meetingId, assetId, "processing");
    const asset = await this.repository.getFileAsset(tenant, meetingId, assetId);
    const output = path.join(this.root, tenant.tenantKey, meetingId, "previews", assetId);
    try {
      await fsp.rm(output, { recursive: true, force: true });
      await fsp.mkdir(output, { recursive: true, mode: 0o750 });
      const uidResult = await run("id", ["-u", this.workerUser], 5000);
      const gidResult = await run("id", ["-g", this.workerUser], 5000);
      const uid = Number(String(uidResult.stdout).trim());
      const gid = Number(String(gidResult.stdout).trim());
      if (uidResult.code !== 0 || gidResult.code !== 0 || !Number.isInteger(uid) || !Number.isInteger(gid)) {
        throw new Error("converter user is unavailable");
      }
      const sandboxInput = path.join(output, "input.bin");
      await fsp.copyFile(asset.storagePath, sandboxInput, fs.constants.COPYFILE_EXCL);
      await fsp.chmod(sandboxInput, 0o640);
      await fsp.chown(output, uid, gid);
      await fsp.chown(sandboxInput, uid, gid);
      // The environment is pinned with `env -i` rather than bwrap's own
      // --clearenv/--setenv, because --clearenv only reached bwrap in 0.5.0 and
      // Debian 11 ships 0.4.1 with no newer candidate. Passing it there fails
      // the sandbox outright with "bwrap: Unknown option --clearenv", so every
      // conversion failed on those hosts while health() - which only runs
      // `bwrap --version` - still advertised filePresentations as available.
      // `env -i` is coreutils, is identical in effect (the worker sees exactly
      // these three variables and nothing else), and works on every version.
      const sandboxArgs = ["--cpu=120", "--nproc=64", "--", this.bwrap,
        "--unshare-net", "--die-with-parent", "--new-session", "--ro-bind", "/", "/",
        // A fresh minimal /dev, not the host's. Under `--ro-bind / /` alone the
        // whole tree is read-only, so /dev/null is unwritable - LibreOffice's
        // helper shells die on "cannot create /dev/null" and its PDF export then
        // fails to store the file at all, reporting
        //   Error Area:Io Class:Write Code:16
        // while still EXITING 0, so the worker only discovered it one step later
        // as "office conversion did not produce one PDF". This also narrows the
        // sandbox: it replaces every host device node with null/zero/full/
        // random/urandom/tty. Must sit after `--ro-bind / /`, which would
        // otherwise cover it.
        "--dev", "/dev",
        "--tmpfs", "/tmp", "--dir", "/tmp/work", "--bind", output, "/tmp/work",
        // bwrap mounts a tmpfs 0755 root-owned on EVERY version measured (0.4.1
        // and 0.8.0 alike), so after setpriv the converter user cannot write to
        // /tmp. LibreOffice needs it: it creates its OSL_PIPE_<uid> socket there
        // and its profile under $HOME/.config, and when it cannot it dies with
        //   ERROR: no valid pipe path found.
        // Redirecting HOME, TMPDIR or -env:UserInstallation does NOT help - the
        // pipe is placed in /tmp regardless - so /tmp itself has to be writable.
        // bwrap's own `--perms`/`--chmod` reached 0.5.0, exactly like --clearenv,
        // so using them would re-break every Debian 11 host; this shim runs as
        // root inside the sandbox before setpriv drops privileges and works on
        // every version. 1777 matches real /tmp semantics and is not a widening:
        // the tmpfs lives in a private mount namespace holding one process tree
        // under one uid. The argv is passed through "$@" and never interpolated.
        "/bin/sh", "-c", TMPFS_WRITABLE_SHIM, "sh",
        "/usr/bin/env", "-i", "PATH=/usr/bin:/bin", "HOME=/tmp", "LANG=C.UTF-8",
        "/usr/bin/setpriv",
        "--reuid=" + this.workerUser, "--regid=" + this.workerUser, "--clear-groups",
        process.execPath, this.worker, "/tmp/work/input.bin", "/tmp/work", asset.mime];
      const args = ["--wait", "--collect", "--pipe", "--quiet",
        "--unit=ringq-meet-convert-" + assetId,
        "--property=MemoryMax=512M", "--property=MemorySwapMax=0",
        "--property=TasksMax=64", "--property=RuntimeMaxSec=125",
        "--property=UMask=0027",
        this.prlimit].concat(sandboxArgs);
      const result = await run(this.systemdRun, args, 125000, {
        env: { PATH: "/usr/bin:/bin", HOME: "/tmp", LANG: "C.UTF-8", TMPDIR: "/tmp" },
      });
      if (result.code !== 0) throw new Error(result.stderr || "converter failed");
      const manifest = JSON.parse(result.stdout);
      if (!Array.isArray(manifest.pages) || !manifest.pages.length || manifest.pages.length > 200) {
        throw new Error("converter returned an invalid manifest");
      }
      manifest.pages.forEach(function (page, index) {
        const expected = "page-" + (index + 1) + ".webp";
        if (page.pageNumber !== index + 1 || path.basename(String(page.storagePath || "")) !== expected) {
          throw new Error("converter returned an unsafe path");
        }
        page.storagePath = path.join(output, expected);
      });
      await fsp.rm(sandboxInput, { force: true });
      for (const page of manifest.pages) await fsp.chmod(page.storagePath, 0o640);
      await this.repository.replaceFilePages(tenant, meetingId, assetId, manifest.pages);
    } catch (error) {
      this.log.error("meet: document conversion failed: " + error.message);
      await this.repository.setFileAssetStatus(tenant, meetingId, assetId, "failed", "conversion_failed");
      try { await fsp.rm(output, { recursive: true, force: true }); } catch (_) {}
    }
  }

  // Page links are signed with their expiry inside the payload, so an expiry
  // taken from the clock makes the URL different on every call — and the same
  // page under a new URL is a total cache miss in the browser and in the
  // client's own image cache. That is what made a presented deck flicker on
  // every page turn: each turn re-read the manifest and re-signed all of it.
  //
  // Bucketing the expiry makes the link deterministic for PAGE_LINK_BUCKET
  // seconds, so repeated reads return byte-identical URLs. Two buckets ahead,
  // not one, so a link issued at the end of a bucket still has a full bucket
  // of life rather than expiring seconds after it is handed out.
  static get PAGE_LINK_BUCKET() { return 300; }

  pageLink(tenant, meetingId, attachmentId, assetId, pageNumber) {
    const bucket = DocumentService.PAGE_LINK_BUCKET;
    const expiry = (Math.floor(Date.now() / 1000 / bucket) + 2) * bucket;
    const payload = Buffer.from(JSON.stringify({
      t: tenant.tenantKey, m: meetingId, a: attachmentId, f: assetId, p: pageNumber, e: expiry,
    })).toString("base64url");
    const signature = crypto.createHmac("sha256", this.signingSecret).update(payload).digest("base64url");
    return this.publicBase + "/files/" + encodeURIComponent(attachmentId) + "/preview/" + pageNumber +
      "?token=" + payload + "." + signature;
  }

  verifyPageToken(token, tenant, attachmentId, pageNumber) {
    const parts = String(token || "").split(".");
    if (parts.length !== 2) throw new AttachmentError("Preview link is invalid", "ATTACHMENT_LINK_INVALID", 403);
    const expected = crypto.createHmac("sha256", this.signingSecret).update(parts[0]).digest();
    let received;
    try { received = Buffer.from(parts[1], "base64url"); } catch (_) { received = Buffer.alloc(0); }
    if (received.length !== expected.length || !crypto.timingSafeEqual(received, expected)) {
      throw new AttachmentError("Preview link is invalid", "ATTACHMENT_LINK_INVALID", 403);
    }
    let payload;
    try { payload = JSON.parse(Buffer.from(parts[0], "base64url").toString("utf8")); } catch (_) {
      throw new AttachmentError("Preview link is invalid", "ATTACHMENT_LINK_INVALID", 403);
    }
    if (payload.t !== tenant.tenantKey || payload.a !== attachmentId || Number(payload.p) !== Number(pageNumber) ||
        Number(payload.e) < Math.floor(Date.now() / 1000)) {
      throw new AttachmentError("Preview link expired or is invalid", "ATTACHMENT_LINK_EXPIRED", 403);
    }
    return payload;
  }

  async servePage(req, res, tenant, attachmentId, pageNumber, token) {
    const payload = this.verifyPageToken(token, tenant, attachmentId, pageNumber);
    const asset = await this.repository.getFileAsset(tenant, payload.m, payload.f);
    if (!asset || asset.attachmentId !== attachmentId || asset.status !== "ready") {
      throw new AttachmentError("Preview is no longer available", "ATTACHMENT_NOT_FOUND", 404);
    }
    const page = asset.pages.find(function (candidate) { return candidate.pageNumber === Number(pageNumber); });
    if (!page) throw new AttachmentError("Preview page not found", "ATTACHMENT_NOT_FOUND", 404);
    const stat = await fsp.stat(page.storagePath);
    res.statusCode = 200;
    res.setHeader("Content-Type", "image/webp");
    res.setHeader("Content-Length", String(stat.size));
    res.setHeader("Content-Disposition", "inline");
    res.setHeader("X-Content-Type-Options", "nosniff");
    res.setHeader("Content-Security-Policy", "default-src 'none'; sandbox");
    // Cacheable, deliberately. This serves converted deck pages and nothing
    // else; a page is written once at conversion and never changes, and the
    // access control is the signature on the URL, not the absence of a cache.
    // `no-store` here meant every viewer re-downloaded the slide on every page
    // turn — with the link now stable, this is what actually stops that.
    // Still `private`, so no shared cache ever holds a tenant's document.
    res.setHeader("Cache-Control", "private, max-age=600, immutable");
    fs.createReadStream(page.storagePath).pipe(res);
  }

  manifest(tenant, meetingId, asset) {
    return Object.assign({}, asset, {
      storagePath: undefined,
      pages: asset.pages.map((page) => ({
        pageNumber: page.pageNumber, mime: page.mime, bytes: page.bytes,
        width: page.width, height: page.height,
        url: this.pageLink(tenant, meetingId, asset.attachmentId, asset.id, page.pageNumber),
      })),
      originalUrl: DIRECT.has(asset.mime)
        ? this.attachments.link(tenant, meetingId, asset.attachmentId, "inline")
        : null,
    });
  }
}

module.exports = { DocumentService: DocumentService, CONVERTIBLE: CONVERTIBLE, DIRECT: DIRECT,
  PRESENTABLE: PRESENTABLE, IMAGE_MIMES: IMAGE_MIMES, PRESENTATION_MIMES: PRESENTATION_MIMES,
  LEGACY_OFFICE_MIMES: LEGACY_OFFICE_MIMES };
