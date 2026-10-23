#!/usr/bin/env node
"use strict";

// Runs inside the converter sandbox. It receives only server-authored absolute
// paths, never credentials or user-derived command fragments.
const crypto = require("crypto");
const fs = require("fs");
const fsp = fs.promises;
const path = require("path");
const { spawnSync } = require("child_process");

const MAX_PAGES = 200;
const OFFICE_MIMES = new Set([
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "application/vnd.openxmlformats-officedocument.presentationml.presentation",
  "application/vnd.openxmlformats-officedocument.presentationml.slideshow",
  "application/vnd.openxmlformats-officedocument.presentationml.template",
  "application/vnd.ms-powerpoint",
  // Legacy Word and Excel. Absent from here a mime the server has accepted falls
  // through to the PDF branch, pdfinfo fails, and the user is told their file is
  // invalid — the silent gate.
  "application/msword",
  "application/vnd.ms-excel",
]);
const IMAGE_MIMES = new Set([
  "image/jpeg", "image/png", "image/gif", "image/webp",
  "image/bmp", "image/tiff", "image/heic", "image/heif", "image/avif",
]);
const MAX_OUTPUT_BYTES = 250 * 1024 * 1024;
const COMMANDS = Object.freeze({
  convert: "/usr/bin/convert",
  identify: "/usr/bin/identify",
  libreoffice: "/usr/bin/libreoffice",
  pdfinfo: "/usr/bin/pdfinfo",
  pdftoppm: "/usr/bin/pdftoppm",
});

function run(command, args) {
  // The worker is single-job and already has an outer 125-second cgroup
  // deadline. Keep stdin as the default pipe: stdio "ignore" opens /dev/null,
  // which is intentionally unavailable inside this bubblewrap namespace.
  const result = spawnSync(command, args, {
    encoding: "utf8",
    maxBuffer: 1024 * 1024,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(command + " failed: " + String(result.stderr || "").slice(0, 2000));
  }
  return String(result.stdout || "");
}

async function hash(file) {
  const digest = crypto.createHash("sha256");
  await new Promise(function (resolve, reject) {
    const stream = fs.createReadStream(file);
    stream.on("data", function (chunk) { digest.update(chunk); });
    stream.on("error", reject);
    stream.on("end", resolve);
  });
  return digest.digest("hex");
}

async function main() {
  const input = path.resolve(process.argv[2] || "");
  const output = path.resolve(process.argv[3] || "");
  const mime = String(process.argv[4] || "");
  if (!path.isAbsolute(input) || !path.isAbsolute(output)) throw new Error("absolute paths are required");
  await fsp.mkdir(output, { recursive: true, mode: 0o750 });
  const temp = path.join(output, "work");
  await fsp.mkdir(temp, { recursive: true, mode: 0o750 });
  let pdf = input;
  // Anything LibreOffice must render to PDF first. This list has to stay in step
  // with the control plane's CONVERTIBLE: a mime the server accepts but the
  // worker does not recognise falls through to the PDF branch, where pdfinfo
  // fails and the whole conversion is reported as a bad file.
  if (OFFICE_MIMES.has(mime)) {
    await run(COMMANDS.libreoffice, ["--headless", "--nologo", "--nodefault", "--nolockcheck", "--norestore",
      "--convert-to", "pdf", "--outdir", temp, input]);
    const candidates = (await fsp.readdir(temp)).filter(function (name) { return name.toLowerCase().endsWith(".pdf"); });
    if (candidates.length !== 1) throw new Error("office conversion did not produce one PDF");
    pdf = path.join(temp, candidates[0]);
  }
  if (IMAGE_MIMES.has(mime)) {
    // "[0]" selects the first frame, and it is load-bearing: an animated GIF or
    // a multi-page TIFF otherwise makes ImageMagick write page-1-0.webp,
    // page-1-1.webp, ... and no page-1.webp at all, so the manifest the control
    // plane requires cannot be built.
    await run(COMMANDS.convert, [input + "[0]", "-auto-orient", "-resize", "1920x1080>", "-define", "webp:lossless=true", path.join(output, "page-1.webp")]);
  } else {
    const info = await run(COMMANDS.pdfinfo, [pdf]);
    const pagesMatch = String(info).match(/^Pages:\s+(\d+)\s*$/m);
    const pageCount = pagesMatch ? Number(pagesMatch[1]) : 0;
    if (!Number.isInteger(pageCount) || pageCount < 1 || pageCount > MAX_PAGES) {
      throw new Error("document page limit exceeded");
    }
    await run(COMMANDS.pdftoppm, ["-png", "-r", "144", "-f", "1", "-l", String(pageCount), pdf, path.join(temp, "page")]);
    const pngs = (await fsp.readdir(temp)).filter(function (name) { return /^page-\d+\.png$/.test(name); }).sort(function (a, b) {
      return Number(a.match(/\d+/)[0]) - Number(b.match(/\d+/)[0]);
    });
    if (!pngs.length || pngs.length > MAX_PAGES) throw new Error("document page limit exceeded");
    let generatedBytes = 0;
    for (let i = 0; i < pngs.length; i++) {
      const rendered = path.join(output, "page-" + (i + 1) + ".webp");
      await run(COMMANDS.convert, [path.join(temp, pngs[i]), "-resize", "1920x1080>", "-define", "webp:lossless=true", rendered]);
      generatedBytes += (await fsp.stat(rendered)).size;
      if (generatedBytes > MAX_OUTPUT_BYTES) throw new Error("document output limit exceeded");
    }
  }
  const files = (await fsp.readdir(output)).filter(function (name) { return /^page-\d+\.webp$/.test(name); }).sort(function (a, b) {
    return Number(a.match(/\d+/)[0]) - Number(b.match(/\d+/)[0]);
  });
  if (!files.length || files.length > MAX_PAGES) throw new Error("document page limit exceeded");
  let total = 0;
  const pages = [];
  for (let i = 0; i < files.length; i++) {
    const file = path.join(output, files[i]);
    const stat = await fsp.stat(file);
    total += stat.size;
    if (total > MAX_OUTPUT_BYTES) throw new Error("document output limit exceeded");
    // "[0]" here is defence in depth for the same reason it is on the convert
    // above: identify repeats %w %h once PER FRAME with no separator, so a
    // multi-frame file yields "200 150200 150" and the split reads a height of
    // 150200. Measured, not hypothetical.
    const dimensions = String(await run(COMMANDS.identify, ["-format", "%w %h", file + "[0]"])).trim().split(/\s+/).map(Number);
    pages.push({
      pageNumber: i + 1,
      storagePath: file,
      mime: "image/webp",
      bytes: stat.size,
      sha256: await hash(file),
      width: dimensions[0],
      height: dimensions[1],
    });
  }
  await fsp.rm(temp, { recursive: true, force: true });
  process.stdout.write(JSON.stringify({ pages: pages }));
}

// Guarded so the mime sets can be asserted directly by a test rather than
// grepped for. The worker is always spawned as a script, never required, so
// this changes nothing about how it runs — and asserting the real Set is what
// catches the silent gate where the server accepts a mime the worker will not
// route to LibreOffice.
if (require.main === module) {
  main().catch(function (error) {
    process.stderr.write(String(error && error.message || error) + "\n");
    process.exitCode = 1;
  });
}

module.exports = { OFFICE_MIMES: OFFICE_MIMES, IMAGE_MIMES: IMAGE_MIMES, MAX_PAGES: MAX_PAGES };
