/** Private, scanned Meet chat attachment storage. */

const crypto = require("crypto");
const fs = require("fs");
const fsp = fs.promises;
const path = require("path");
const { spawn } = require("child_process");
const Busboy = require("busboy");

const MAX_FILE_BYTES = 25 * 1024 * 1024;
const MAX_NAME_BYTES = 120;
const LINK_TTL_SECONDS = 15 * 60;

// Every legacy PowerPoint extension reports one of these, and which one depends
// on the browser and platform rather than the file. The FIRST entry is canonical.
const LEGACY_PPT_MIMES = Object.freeze([
  "application/vnd.ms-powerpoint",
  "application/mspowerpoint",
  "application/powerpoint",
  "application/x-mspowerpoint",
]);

// Legacy Word and Excel, same rule and for the same reason: which alias a
// browser declares depends on the browser and the platform, not on the file.
const LEGACY_DOC_MIMES = Object.freeze([
  "application/msword",
  "application/vnd.ms-word",
  "application/doc",
  "application/x-msword",
]);
const LEGACY_XLS_MIMES = Object.freeze([
  "application/vnd.ms-excel",
  "application/excel",
  "application/x-excel",
  "application/x-msexcel",
  "application/msexcel",
]);

// Macro-enabled Office extensions (.pptm/.ppsm/.potm/.docm/.xlsm) are absent on
// purpose: inspectZip refuses vbaProject.bin as active content, so allowing the
// extension would only produce a confusing ATTACHMENT_UNSAFE late in the upload.
// They are refused up front as ATTACHMENT_TYPE_BLOCKED instead.
const EXTENSIONS = Object.freeze({
  ".jpg": ["image/jpeg"], ".jpeg": ["image/jpeg"],
  ".png": ["image/png"], ".gif": ["image/gif"], ".webp": ["image/webp"],
  ".bmp": ["image/bmp", "image/x-ms-bmp"],
  ".tif": ["image/tiff"], ".tiff": ["image/tiff"],
  // A .heic may carry a generic HEIF brand and vice versa, so each accepts both
  // detected values; whichever is detected is what gets stored.
  ".heic": ["image/heic", "image/heif"], ".heif": ["image/heif", "image/heic"],
  ".avif": ["image/avif"],
  ".mp4": ["video/mp4"], ".webm": ["video/webm"], ".pdf": ["application/pdf"],
  ".txt": ["text/plain"], ".csv": ["text/csv", "text/plain"],
  ".docx": ["application/vnd.openxmlformats-officedocument.wordprocessingml.document"],
  ".xlsx": ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"],
  ".pptx": ["application/vnd.openxmlformats-officedocument.presentationml.presentation"],
  ".ppsx": ["application/vnd.openxmlformats-officedocument.presentationml.slideshow"],
  ".potx": ["application/vnd.openxmlformats-officedocument.presentationml.template"],
  ".ppt": LEGACY_PPT_MIMES, ".pps": LEGACY_PPT_MIMES, ".pot": LEGACY_PPT_MIMES,
  ".doc": LEGACY_DOC_MIMES, ".xls": LEGACY_XLS_MIMES,
  ".zip": ["application/zip"],
});

// Legacy Office files are OLE2/CFB compound documents, and the signature says
// only that - .doc, .xls, .ppt and .msi are byte-identical in the first 8 bytes.
// So it is resolved by extension and then verified by inspectOle, exactly as
// application/zip is resolved and then verified by inspectZip for the OOXML
// formats. It is deliberately not a value any extension maps to, so it can never
// survive as a stored mime.
const OLE_COMPOUND = "application/x-ole-storage";

// The CFB directory stores stream names as UTF-16LE, so the format's mandatory
// stream is findable without parsing the FAT.
//
// A value may be an ARRAY, and .xls is why: Excel 97-2003 (BIFF8) writes
// "Workbook" while Excel 5.0/95 (BIFF5/7) writes "Book", and the two are
// genuinely distinct needles - "Workbook" carries a lower-case b, so "Book" is
// not a substring of it in UTF-16LE. Any one of the alternatives satisfies the
// check; see inspectOle, which must keep the required names and the macro names
// in separate lists for exactly this reason.
const OLE_REQUIRED_STREAM = Object.freeze({
  ".ppt": "PowerPoint Document",
  ".pps": "PowerPoint Document",
  ".pot": "PowerPoint Document",
  ".doc": "WordDocument",
  ".xls": ["Workbook", "Book"],
});

// VBA storage names. A .pptx carrying macros must be a .pptm, but a plain .ppt
// may hold them, so this is the inspectZip active-content check's counterpart.
const OLE_MACRO_STREAMS = Object.freeze(["_VBA_PROJECT", "Macros"]);

class AttachmentError extends Error {
  constructor(message, code, statusCode) {
    super(message);
    this.name = "AttachmentError";
    this.code = code || "ATTACHMENT_ERROR";
    this.statusCode = statusCode || 400;
  }
}

function safeOriginalName(value) {
  let name = path.basename(String(value || "file")).normalize("NFKC");
  name = name.replace(/[\u0000-\u001f\u007f/\\]/g, "_").replace(/\s+/g, " ").trim();
  if (!name || name === "." || name === "..") name = "file";
  while (Buffer.byteLength(name, "utf8") > MAX_NAME_BYTES) {
    const ext = path.extname(name);
    name = name.slice(0, Math.max(1, name.length - 1 - ext.length)) + ext;
  }
  return name;
}

// ISO-BMFF brands. Anything not listed here stays a video, so a new image brand
// fails closed as an unsupported upload rather than being served as media.
const AVIF_BRANDS = Object.freeze(["avif", "avis"]);
const HEIC_BRANDS = Object.freeze(["heic", "heix", "hevc", "hevx", "heim", "heis", "hevm", "hevs"]);
const HEIF_BRANDS = Object.freeze(["mif1", "msf1"]);

function signatureMime(buffer) {
  if (buffer.length >= 3 && buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) return "image/jpeg";
  if (buffer.length >= 8 && buffer.subarray(0, 8).equals(Buffer.from([0x89,0x50,0x4e,0x47,0x0d,0x0a,0x1a,0x0a]))) return "image/png";
  if (buffer.length >= 6 && (buffer.subarray(0, 6).toString("ascii") === "GIF87a" || buffer.subarray(0, 6).toString("ascii") === "GIF89a")) return "image/gif";
  if (buffer.length >= 12 && buffer.subarray(0, 4).toString("ascii") === "RIFF" && buffer.subarray(8, 12).toString("ascii") === "WEBP") return "image/webp";
  if (buffer.length >= 5 && buffer.subarray(0, 5).toString("ascii") === "%PDF-") return "application/pdf";
  if (buffer.length >= 12 && buffer.subarray(4, 8).toString("ascii") === "ftyp") {
    // HEIC, HEIF and AVIF are ISO-BMFF containers too, so the ftyp box alone
    // cannot tell an image from a video - the BRAND can. Reading only "ftyp"
    // reported every one of them as video/mp4, which is why an iPhone photo
    // could never be uploaded as an image.
    const brand = buffer.subarray(8, 12).toString("ascii").toLowerCase();
    if (AVIF_BRANDS.indexOf(brand) !== -1) return "image/avif";
    if (HEIC_BRANDS.indexOf(brand) !== -1) return "image/heic";
    if (HEIF_BRANDS.indexOf(brand) !== -1) return "image/heif";
    return "video/mp4";
  }
  if (buffer.length >= 4 && buffer.subarray(0, 4).equals(Buffer.from([0x49,0x49,0x2a,0x00]))) return "image/tiff";
  if (buffer.length >= 4 && buffer.subarray(0, 4).equals(Buffer.from([0x4d,0x4d,0x00,0x2a]))) return "image/tiff";
  // Checked last as hygiene: "BM" is only two bytes of evidence. No other
  // signature here begins with them, so the position is not load-bearing today -
  // it is ordering discipline for whatever gets added next.
  if (buffer.length >= 2 && buffer[0] === 0x42 && buffer[1] === 0x4d) return "image/bmp";
  if (buffer.length >= 4 && buffer.subarray(0, 4).equals(Buffer.from([0x1a,0x45,0xdf,0xa3]))) return "video/webm";
  if (buffer.length >= 4 && buffer[0] === 0x50 && buffer[1] === 0x4b && [0x03,0x05,0x07].indexOf(buffer[2]) !== -1) return "application/zip";
  if (buffer.length >= 8 && buffer.subarray(0, 8).equals(Buffer.from([0xd0,0xcf,0x11,0xe0,0xa1,0xb1,0x1a,0xe1]))) return OLE_COMPOUND;
  return null;
}

function declaredMimeAllowed(fileName, declaredMime) {
  const extension = path.extname(String(fileName || "")).toLowerCase();
  return !!EXTENSIONS[extension] && EXTENSIONS[extension].indexOf(String(declaredMime || "").toLowerCase()) !== -1;
}

function run(command, args, opts) {
  const o = opts || {};
  return new Promise(function (resolve, reject) {
    const child = spawn(command, args, { stdio: ["ignore", "pipe", "pipe"] });
    let stdout = "";
    let stderr = "";
    const max = o.maxOutput || 256 * 1024;
    child.stdout.on("data", function (chunk) { if (stdout.length < max) stdout += chunk.toString("utf8"); });
    child.stderr.on("data", function (chunk) { if (stderr.length < max) stderr += chunk.toString("utf8"); });
    child.on("error", reject);
    child.on("close", function (code) { resolve({ code: code, stdout: stdout, stderr: stderr }); });
  });
}

async function validateUtf8(filePath) {
  const decoder = new TextDecoder("utf-8", { fatal: true });
  return new Promise(function (resolve, reject) {
    const stream = fs.createReadStream(filePath);
    let failed = false;
    stream.on("data", function (chunk) {
      if (chunk.indexOf(0) !== -1) {
        failed = true;
        stream.destroy(new Error("NUL byte"));
        return;
      }
      try { decoder.decode(chunk, { stream: true }); } catch (err) {
        failed = true;
        stream.destroy(err);
      }
    });
    stream.on("error", function () { resolve(false); });
    stream.on("end", function () {
      if (failed) return resolve(false);
      try { decoder.decode(); resolve(true); } catch (_) { resolve(false); }
    });
  });
}

// The member that proves an OOXML package really is what its extension claims.
// A slideshow and a template are ordinary presentation packages; all three were
// verified on the host to carry ppt/presentation.xml and no vbaProject.bin.
const ZIP_REQUIRED_MEMBER = Object.freeze({
  ".docx": "word/document.xml",
  ".xlsx": "xl/workbook.xml",
  ".pptx": "ppt/presentation.xml",
  ".ppsx": "ppt/presentation.xml",
  ".potx": "ppt/presentation.xml",
});

async function inspectZip(filePath, extension) {
  const listing = await run("unzip", ["-Z1", filePath]);
  if (listing.code !== 0) throw new AttachmentError("Archive is encrypted or invalid", "ATTACHMENT_UNSAFE", 400);
  const details = await run("unzip", ["-Z", "-v", filePath]);
  if (details.code !== 0 || /file security status:\s*encrypted/i.test(details.stdout)) {
    throw new AttachmentError("Encrypted archives are not allowed", "ATTACHMENT_UNSAFE", 400);
  }
  const entries = listing.stdout.split(/\r?\n/).filter(Boolean);
  if (entries.length > 5000) throw new AttachmentError("Archive contains too many entries", "ATTACHMENT_UNSAFE", 400);
  const lowered = entries.map(function (entry) { return entry.toLowerCase(); });
  if (lowered.some(function (entry) {
    return entry.endsWith("vbaproject.bin") || entry.endsWith(".js") || entry.endsWith(".vbs") ||
      entry.endsWith(".ps1") || entry.endsWith(".sh") || entry.endsWith(".html") || entry.endsWith(".svg");
  })) throw new AttachmentError("Archive contains active content", "ATTACHMENT_UNSAFE", 400);
  const required = ZIP_REQUIRED_MEMBER[extension];
  if (required && lowered.indexOf(required) === -1) {
    throw new AttachmentError("Document signature does not match its extension", "ATTACHMENT_TYPE_MISMATCH", 400);
  }
}

// The OLE2 counterpart of inspectZip. There is no unzip for CFB and no 7z or
// olevba on these hosts, so rather than parse the FAT this scans for the
// UTF-16LE directory names: the format's mandatory stream must be present, and
// no VBA storage may be. Streamed with an overlap, because a name can straddle
// a chunk boundary - a plain per-chunk search would miss it and silently pass.
async function inspectOle(filePath, extension) {
  const required = OLE_REQUIRED_STREAM[extension];
  if (!required) {
    throw new AttachmentError("File type is not allowed", "ATTACHMENT_TYPE_BLOCKED", 400);
  }
  // Two separate lists, never one list plus a name comparison: with an
  // alternative required stream, "everything that is not THE required name"
  // would classify the second alternative as a macro and refuse a valid file as
  // active content.
  const toNeedle = function (name) {
    return { name: name, bytes: Buffer.from(name, "utf16le"), found: false };
  };
  const requiredNeedles = (Array.isArray(required) ? required : [required]).map(toNeedle);
  const macroNeedles = OLE_MACRO_STREAMS.map(toNeedle);
  const needles = requiredNeedles.concat(macroNeedles);
  const overlap = needles.reduce(function (max, n) { return Math.max(max, n.bytes.length); }, 0) - 1;
  let tail = Buffer.alloc(0);
  await new Promise(function (resolve, reject) {
    const stream = fs.createReadStream(filePath, { highWaterMark: 256 * 1024 });
    stream.on("data", function (chunk) {
      const window = tail.length ? Buffer.concat([tail, chunk]) : chunk;
      for (const needle of needles) {
        if (!needle.found && window.indexOf(needle.bytes) !== -1) needle.found = true;
      }
      tail = overlap > 0 ? window.subarray(Math.max(0, window.length - overlap)) : Buffer.alloc(0);
    });
    stream.on("error", reject);
    stream.on("end", resolve);
  });
  const macro = macroNeedles.find(function (n) { return n.found; });
  if (macro) throw new AttachmentError("Document contains active content", "ATTACHMENT_UNSAFE", 400);
  if (!requiredNeedles.some(function (n) { return n.found; })) {
    throw new AttachmentError("Document signature does not match its extension", "ATTACHMENT_TYPE_MISMATCH", 400);
  }
}

class AttachmentService {
  constructor(opts) {
    const o = opts || {};
    if (!o.repository) throw new TypeError("AttachmentService requires a collaboration repository");
    this.repository = o.repository;
    this.root = path.resolve(o.root || "/var/lib/ringq/meet-collaboration");
    this.scanner = o.scanner || "clamdscan";
    // MEET_CLAMDSCAN_BIN=off: the server runs no antivirus daemon; attachments are accepted after
    // the built-in checks only (extension / magic-number match, active-content and archive checks).
    this.scanEnabled = !/^(off|none|false|disabled)$/i.test(String(this.scanner).trim());
    this.signingSecret = String(o.signingSecret || "");
    this.publicBase = String(o.publicBase || "/meet/api/v1");
    this.log = o.logger || { info: function () {}, warn: function () {}, error: function () {} };
    this._health = null;
    this._healthAt = 0;
  }

  async health(force) {
    if (!force && this._health !== null && Date.now() - this._healthAt < 30000) return this._health;
    let healthy = false;
    try {
      await fsp.mkdir(this.root, { recursive: true, mode: 0o750 });
      await fsp.access(this.root, fs.constants.R_OK | fs.constants.W_OK | fs.constants.X_OK);
      // Checking the binary version is insufficient: clamdscan can report its
      // version while the daemon/socket is unavailable. Ping clamd itself so
      // capabilities and uploads fail closed during a scanner outage.
      let scannerOk = true;
      if (this.scanEnabled) {
        const scan = await run(this.scanner, ["--ping", "1"], { maxOutput: 4096 });
        scannerOk = scan.code === 0;
      }
      healthy = scannerOk && this.signingSecret.length >= 32;
    } catch (err) {
      this.log.warn("meet: attachment health failed: " + err.message);
    }
    this._health = healthy;
    this._healthAt = Date.now();
    return healthy;
  }

  async receive(req, tenant, meetingId, actor) {
    if (!(await this.health())) {
      throw new AttachmentError("Attachment scanning is unavailable", "ATTACHMENT_SCANNER_UNAVAILABLE", 503);
    }
    if (typeof this.repository.cleanupExpiredAttachments === "function") {
      const expired = await this.repository.cleanupExpiredAttachments(tenant);
      await Promise.all(expired.map(async function (file) {
        try { await fsp.unlink(file); } catch (err) { if (err.code !== "ENOENT") throw err; }
      }));
    }
    const id = crypto.randomUUID();
    const meetingRoot = path.join(this.root, tenant.tenantKey, meetingId);
    const quarantine = path.join(meetingRoot, "quarantine");
    const readyRoot = path.join(meetingRoot, "attachments");
    await fsp.mkdir(quarantine, { recursive: true, mode: 0o750 });
    await fsp.mkdir(readyRoot, { recursive: true, mode: 0o750 });
    const partPath = path.join(quarantine, id + ".part");
    let finalPath = null;
    let originalName = null;
    let declaredMime = "application/octet-stream";
    let bytes = 0;
    let digest = null;
    try {
      await new Promise(function (resolve, reject) {
        let seenFile = false;
        let finished = false;
        let writeDone = false;
        const hash = crypto.createHash("sha256");
        let parser;
        try {
          parser = Busboy({ headers: req.headers, limits: { files: 1, fileSize: MAX_FILE_BYTES, fields: 0, parts: 1 } });
        } catch (err) { return reject(new AttachmentError("Invalid multipart request", "ATTACHMENT_MULTIPART_INVALID", 400)); }
        parser.on("file", function (_field, file, info) {
          if (seenFile) { file.resume(); return; }
          seenFile = true;
          originalName = safeOriginalName(info.filename);
          declaredMime = String(info.mimeType || "application/octet-stream").toLowerCase();
          const output = fs.createWriteStream(partPath, { flags: "wx", mode: 0o640 });
          file.on("data", function (chunk) { bytes += chunk.length; hash.update(chunk); });
          file.on("limit", function () {
            reject(new AttachmentError("Attachment exceeds 25 MiB", "ATTACHMENT_TOO_LARGE", 413));
          });
          file.on("error", reject);
          output.on("error", reject);
          output.on("finish", function () { digest = hash.digest("hex"); writeDone = true; if (finished) resolve(); });
          file.pipe(output);
        });
        parser.on("error", reject);
        parser.on("finish", function () {
          finished = true;
          if (!seenFile) return reject(new AttachmentError("One file is required", "ATTACHMENT_REQUIRED", 400));
          if (writeDone) resolve();
        });
        req.pipe(parser);
      });
      if (!bytes) throw new AttachmentError("Attachment is empty", "ATTACHMENT_EMPTY", 400);
      const extension = path.extname(originalName).toLowerCase();
      if (!EXTENSIONS[extension]) throw new AttachmentError("File type is not allowed", "ATTACHMENT_TYPE_BLOCKED", 400);
      if (!declaredMimeAllowed(originalName, declaredMime)) {
        throw new AttachmentError("Declared MIME does not match the file extension", "ATTACHMENT_TYPE_MISMATCH", 400);
      }
      const handle = await fsp.open(partPath, "r");
      const header = Buffer.alloc(8192);
      const read = await handle.read(header, 0, header.length, 0);
      await handle.close();
      let detectedMime = signatureMime(header.subarray(0, read.bytesRead));
      if (extension === ".txt" || extension === ".csv") {
        if (!(await validateUtf8(partPath))) throw new AttachmentError("Text file is not valid UTF-8", "ATTACHMENT_UNSAFE", 400);
        detectedMime = extension === ".csv" ? "text/csv" : "text/plain";
      }
      if (detectedMime === "application/zip") {
        await inspectZip(partPath, extension);
        detectedMime = EXTENSIONS[extension][0];
      }
      if (detectedMime === OLE_COMPOUND) {
        await inspectOle(partPath, extension);
        detectedMime = EXTENSIONS[extension][0];
      }
      if (!detectedMime || EXTENSIONS[extension].indexOf(detectedMime) === -1) {
        throw new AttachmentError("File signature does not match its extension", "ATTACHMENT_TYPE_MISMATCH", 400);
      }
      if (this.scanEnabled) {
        const scan = await run(this.scanner, ["--fdpass", "--no-summary", partPath], { maxOutput: 64 * 1024 });
        if (scan.code === 1) throw new AttachmentError("Malware was detected", "ATTACHMENT_MALWARE", 400);
        if (scan.code !== 0) {
          this._health = false;
          throw new AttachmentError("Attachment scanning is unavailable", "ATTACHMENT_SCANNER_UNAVAILABLE", 503);
        }
      }
      const storedName = id + extension;
      finalPath = path.join(readyRoot, storedName);
      await fsp.rename(partPath, finalPath);
      const attachment = await this.repository.createAttachment(tenant, {
        id: id, meetingId: meetingId, storedName: storedName, originalName: originalName,
        declaredMime: declaredMime, detectedMime: detectedMime, bytes: bytes,
        uploaderRef: actor.participantId, storagePath: finalPath, sha256: digest,
      });
      return attachment;
    } catch (err) {
      try { await fsp.unlink(partPath); } catch (_) {}
      if (finalPath) {
        try { await fsp.unlink(finalPath); } catch (_) {}
      }
      throw err;
    }
  }

  async cancel(tenant, meetingId, attachmentId, actor) {
    const row = await this.repository.cancelAttachment(tenant, meetingId, attachmentId, actor.participantId);
    if (!row) throw new AttachmentError("Attachment was not found", "ATTACHMENT_NOT_FOUND", 404);
    try { await fsp.unlink(row.storage_path); } catch (err) {
      if (err.code !== "ENOENT") this.log.warn("meet: attachment unlink failed: " + err.message);
    }
  }

  link(tenant, meetingId, attachmentId, disposition) {
    const exp = Math.floor(Date.now() / 1000) + LINK_TTL_SECONDS;
    const payload = Buffer.from(JSON.stringify({
      t: tenant.tenantKey, m: meetingId, a: attachmentId,
      d: disposition === "inline" ? "inline" : "attachment", e: exp,
    })).toString("base64url");
    const signature = crypto.createHmac("sha256", this.signingSecret).update(payload).digest("base64url");
    return this.publicBase + "/files/" + encodeURIComponent(attachmentId) + "?token=" + payload + "." + signature;
  }

  verifyToken(token, tenant, attachmentId) {
    const parts = String(token || "").split(".");
    if (parts.length !== 2) throw new AttachmentError("Download link is invalid", "ATTACHMENT_LINK_INVALID", 403);
    const expected = crypto.createHmac("sha256", this.signingSecret).update(parts[0]).digest();
    let received;
    try { received = Buffer.from(parts[1], "base64url"); } catch (_) { received = Buffer.alloc(0); }
    if (received.length !== expected.length || !crypto.timingSafeEqual(received, expected)) {
      throw new AttachmentError("Download link is invalid", "ATTACHMENT_LINK_INVALID", 403);
    }
    let payload;
    try { payload = JSON.parse(Buffer.from(parts[0], "base64url").toString("utf8")); } catch (_) {
      throw new AttachmentError("Download link is invalid", "ATTACHMENT_LINK_INVALID", 403);
    }
    if (payload.t !== tenant.tenantKey || payload.a !== attachmentId || asNumber(payload.e) < Math.floor(Date.now() / 1000)) {
      throw new AttachmentError("Download link expired or is invalid", "ATTACHMENT_LINK_EXPIRED", 403);
    }
    return payload;
  }

  async serve(req, res, tenant, attachmentId, token) {
    const payload = this.verifyToken(token, tenant, attachmentId);
    const row = await this.repository.getAttachment(tenant, payload.m, attachmentId);
    if (!row || row.status !== "attached" || row.deleted_at) {
      throw new AttachmentError("Attachment is no longer available", "ATTACHMENT_NOT_FOUND", 404);
    }
    const stat = await fsp.stat(row.storage_path);
    const inlineTypes = ["image/jpeg", "image/png", "image/gif", "image/webp", "video/mp4", "video/webm"];
    const disposition = payload.d === "inline" && inlineTypes.indexOf(row.detected_mime) !== -1 ? "inline" : "attachment";
    const encoded = encodeURIComponent(row.original_name).replace(/['()]/g, escape).replace(/\*/g, "%2A");
    res.setHeader("Content-Type", row.detected_mime || "application/octet-stream");
    res.setHeader("Content-Disposition", disposition + "; filename*=UTF-8''" + encoded);
    res.setHeader("X-Content-Type-Options", "nosniff");
    res.setHeader("Content-Security-Policy", "default-src 'none'; sandbox");
    res.setHeader("Cache-Control", "private, no-store");
    res.setHeader("Accept-Ranges", "bytes");
    let start = 0;
    let end = stat.size - 1;
    const range = String(req.headers.range || "");
    if (range) {
      const match = /^bytes=(\d*)-(\d*)$/.exec(range);
      if (!match || (!match[1] && !match[2])) {
        res.statusCode = 416; res.setHeader("Content-Range", "bytes */" + stat.size); return res.end();
      }
      if (!match[1]) {
        const suffix = Number(match[2]);
        start = Math.max(0, stat.size - suffix);
        end = stat.size - 1;
      } else {
        start = Number(match[1]);
        if (match[2]) end = Number(match[2]);
      }
      if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) || start < 0 || end < start || start >= stat.size) {
        res.statusCode = 416; res.setHeader("Content-Range", "bytes */" + stat.size); return res.end();
      }
      end = Math.min(end, stat.size - 1);
      res.statusCode = 206;
      res.setHeader("Content-Range", "bytes " + start + "-" + end + "/" + stat.size);
    } else {
      res.statusCode = 200;
    }
    res.setHeader("Content-Length", String(end - start + 1));
    fs.createReadStream(row.storage_path, { start: start, end: end }).pipe(res);
  }
}

function asNumber(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

module.exports = {
  AttachmentService: AttachmentService,
  AttachmentError: AttachmentError,
  MAX_FILE_BYTES: MAX_FILE_BYTES,
  safeOriginalName: safeOriginalName,
  signatureMime: signatureMime,
  declaredMimeAllowed: declaredMimeAllowed,
  inspectOle: inspectOle,
  EXTENSIONS: EXTENSIONS,
  OLE_COMPOUND: OLE_COMPOUND,
  ZIP_REQUIRED_MEMBER: ZIP_REQUIRED_MEMBER,
  OLE_REQUIRED_STREAM: OLE_REQUIRED_STREAM,
};
