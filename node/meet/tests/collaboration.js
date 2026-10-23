const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const collaborationMod = require("../collaboration.js");
const attachmentMod = require("../attachments.js");
const documentMod = require("../documents.js");

let passed = 0;
let failed = 0;
const failures = [];
const tests = [];
function test(name, fn) { tests.push({ name: name, fn: fn }); }

test("idempotency hashes ignore object key order but preserve content", function () {
  assert.strictEqual(
    collaborationMod.requestHash({ body: "x", attachmentIds: ["a"] }),
    collaborationMod.requestHash({ attachmentIds: ["a"], body: "x" })
  );
  assert.notStrictEqual(
    collaborationMod.requestHash({ body: "x", attachmentIds: ["a"] }),
    collaborationMod.requestHash({ body: "y", attachmentIds: ["a"] })
  );
});

test("whiteboard reducer and inverse restore the exact prior vector state", function () {
  const id = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
  const start = { elements: {} };
  const add = [{ type: "stroke", element: { id: id, points: [[0, 0], [1, 1]], color: "#fff" } }];
  const inverse = collaborationMod.CollaborationRepository.inverseOperations(start, add);
  const drawn = collaborationMod.CollaborationRepository.applyOperations(start, add);
  assert.ok(drawn.elements[id]);
  assert.deepStrictEqual(collaborationMod.CollaborationRepository.applyOperations(drawn, inverse), start);

  const erase = [{ type: "erase", elementIds: [id] }];
  const eraseInverse = collaborationMod.CollaborationRepository.inverseOperations(drawn, erase);
  const erased = collaborationMod.CollaborationRepository.applyOperations(drawn, erase);
  assert.strictEqual(erased.elements[id], undefined);
  assert.deepStrictEqual(collaborationMod.CollaborationRepository.applyOperations(erased, eraseInverse), drawn);
});

test("attachment names cannot supply paths or control characters", function () {
  assert.strictEqual(attachmentMod.safeOriginalName("../../evil\u0000.pdf"), "evil_.pdf");
  const long = attachmentMod.safeOriginalName("a".repeat(300) + ".pdf");
  assert.ok(Buffer.byteLength(long, "utf8") <= 120);
  assert.ok(long.endsWith(".pdf"));
});

test("file signatures are detected independently of claimed MIME", function () {
  assert.strictEqual(attachmentMod.signatureMime(Buffer.from("%PDF-1.7\n")), "application/pdf");
  assert.strictEqual(
    attachmentMod.signatureMime(Buffer.from([0x89,0x50,0x4e,0x47,0x0d,0x0a,0x1a,0x0a])),
    "image/png"
  );
  assert.strictEqual(attachmentMod.signatureMime(Buffer.from("<html>bad</html>")), null);
  // Legacy Office files are OLE2/CFB compound documents. The signature can only
  // say that much - .doc, .xls, .ppt and .msi share these 8 bytes - so it must
  // resolve to the container marker and never straight to a document mime.
  assert.strictEqual(
    attachmentMod.signatureMime(Buffer.from([0xd0,0xcf,0x11,0xe0,0xa1,0xb1,0x1a,0xe1])),
    attachmentMod.OLE_COMPOUND
  );
  // ...and that marker must not be reachable as a stored mime, or a renamed
  // .msi could be persisted under it.
  for (const mimes of Object.values(attachmentMod.EXTENSIONS)) {
    assert.ok(mimes.indexOf(attachmentMod.OLE_COMPOUND) === -1,
      "no extension may map to the OLE container marker");
  }
});

test("ISO-BMFF images are told from video by their brand, not by ftyp", function () {
  // Every one of these starts with the same ftyp box as an MP4. Matching only
  // "ftyp" reported them all as video/mp4, so an iPhone photo could never be
  // uploaded: the declared image mime never matched the detected video one.
  const box = function (brand) {
    return Buffer.concat([Buffer.alloc(4), Buffer.from("ftyp" + brand, "ascii"), Buffer.alloc(4)]);
  };
  assert.strictEqual(attachmentMod.signatureMime(box("heic")), "image/heic");
  assert.strictEqual(attachmentMod.signatureMime(box("mif1")), "image/heif");
  assert.strictEqual(attachmentMod.signatureMime(box("avif")), "image/avif");
  // An unknown brand must stay a video rather than being guessed into an image.
  assert.strictEqual(attachmentMod.signatureMime(box("isom")), "video/mp4");
  assert.strictEqual(attachmentMod.signatureMime(box("qt  ")), "video/mp4");
  // ...and a .heic must accept either HEIF detection, since the brand decides.
  for (const mime of ["image/heic", "image/heif"]) {
    assert.ok(attachmentMod.EXTENSIONS[".heic"].indexOf(mime) !== -1,
      ".heic must accept " + mime);
  }
});

test("the remaining raster signatures are detected", function () {
  assert.strictEqual(attachmentMod.signatureMime(Buffer.from([0x49,0x49,0x2a,0x00])), "image/tiff");
  assert.strictEqual(attachmentMod.signatureMime(Buffer.from([0x4d,0x4d,0x00,0x2a])), "image/tiff");
  assert.strictEqual(attachmentMod.signatureMime(Buffer.from("BM\u0000\u0000")), "image/bmp");
});

test("each OOXML package is verified by a member that proves its type", function () {
  // Without this map a .ppsx is only checked for being *a* zip, so a .docx
  // renamed to .ppsx would be accepted and then fail deep inside LibreOffice.
  const required = attachmentMod.ZIP_REQUIRED_MEMBER;
  assert.strictEqual(required[".pptx"], "ppt/presentation.xml");
  assert.strictEqual(required[".ppsx"], "ppt/presentation.xml");
  assert.strictEqual(required[".potx"], "ppt/presentation.xml");
  assert.strictEqual(required[".docx"], "word/document.xml");
  assert.strictEqual(required[".xlsx"], "xl/workbook.xml");
  // Every zip-backed extension the upload gate allows must have an entry, or it
  // silently degrades to "is it a zip".
  for (const ext of [".docx", ".xlsx", ".pptx", ".ppsx", ".potx"]) {
    assert.ok(attachmentMod.EXTENSIONS[ext], ext + " must be allowed");
    assert.ok(required[ext], ext + " must be verified by a required member");
  }
});

test("every image and non-macro PowerPoint extension is uploadable", function () {
  for (const ext of [".jpg", ".jpeg", ".png", ".gif", ".webp", ".bmp",
                     ".tif", ".tiff", ".heic", ".heif", ".avif",
                     ".pdf", ".ppt", ".pps", ".pot", ".pptx", ".ppsx", ".potx"]) {
    assert.ok(attachmentMod.EXTENSIONS[ext], ext + " must be an allowed extension");
  }
  // Macro-enabled Office files are refused by extension, up front, rather than
  // reaching inspectZip and failing later as active content.
  for (const ext of [".pptm", ".ppsm", ".potm", ".docm", ".xlsm", ".svg", ".exe"]) {
    assert.ok(!attachmentMod.EXTENSIONS[ext], ext + " must never be allowed");
  }
  // The OOXML slideshow and template are ordinary presentation packages and
  // must be verified as such, or a renamed zip would pass.
  assert.strictEqual(attachmentMod.declaredMimeAllowed("deck.ppsx",
    "application/vnd.openxmlformats-officedocument.presentationml.slideshow"), true);
  assert.strictEqual(attachmentMod.declaredMimeAllowed("deck.potx",
    "application/vnd.openxmlformats-officedocument.presentationml.template"), true);
});

test("the legacy .ppt extension is allowed, with its canonical mime first", function () {
  const ppt = attachmentMod.EXTENSIONS[".ppt"];
  assert.ok(Array.isArray(ppt) && ppt.length > 0, ".ppt must be an allowed extension");
  // .pps and .pot are the same binary format under different extensions.
  assert.strictEqual(attachmentMod.EXTENSIONS[".pps"][0], ppt[0]);
  assert.strictEqual(attachmentMod.EXTENSIONS[".pot"][0], ppt[0]);
  // The first entry is stored as detectedMime once the container is resolved, so
  // a browser alias must never lead the list.
  assert.strictEqual(ppt[0], "application/vnd.ms-powerpoint");
  assert.strictEqual(attachmentMod.declaredMimeAllowed("deck.ppt", "application/vnd.ms-powerpoint"), true);
  // Older platforms report these for .ppt; each must be accepted as a declared
  // value, because the browser's File.type is what arrives.
  assert.strictEqual(attachmentMod.declaredMimeAllowed("deck.ppt", "application/mspowerpoint"), true);
  assert.strictEqual(attachmentMod.declaredMimeAllowed("deck.ppt", "text/html"), false);
  // .ppt must not become a way to smuggle the modern mime, or the extension and
  // the stored type would disagree.
  assert.strictEqual(
    attachmentMod.declaredMimeAllowed("deck.ppt",
      "application/vnd.openxmlformats-officedocument.presentationml.presentation"),
    false
  );
});

test("an OLE2 upload is verified by its directory streams, not just its magic", async function () {
  const os = require("os");
  const fsp = require("fs").promises;
  const pathMod = require("path");
  const dir = await fsp.mkdtemp(pathMod.join(os.tmpdir(), "meet-ole-"));
  const header = Buffer.from([0xd0,0xcf,0x11,0xe0,0xa1,0xb1,0x1a,0xe1]);
  // A CFB directory stores stream names as UTF-16LE, which is what inspectOle
  // searches for.
  const build = function (names, padding) {
    return Buffer.concat([header, Buffer.alloc(padding || 0)]
      .concat(names.map(function (n) { return Buffer.from(n, "utf16le"); })));
  };
  const write = async function (name, buffer) {
    const file = pathMod.join(dir, name);
    await fsp.writeFile(file, buffer);
    return file;
  };
  const rejects = async function (file, extension, code, why) {
    let thrown = null;
    try { await attachmentMod.inspectOle(file, extension); } catch (e) { thrown = e; }
    assert.ok(thrown, why + " (nothing was thrown)");
    assert.strictEqual(thrown.code, code, why);
  };

  // A real legacy deck carries the mandatory "PowerPoint Document" stream, and
  // .pps/.pot are the same format under a different extension.
  for (const ext of [".ppt", ".pps", ".pot"]) {
    await attachmentMod.inspectOle(
      await write("good" + ext, build(["PowerPoint Document", "Current User", "Pictures"])), ext);
  }

  // A .doc renamed to .ppt has the same 8 magic bytes and must still be refused,
  // which is the whole reason a container marker is not enough.
  await rejects(await write("word.ppt", build(["WordDocument", "1Table"])), ".ppt",
    "ATTACHMENT_TYPE_MISMATCH", "an OLE2 file without the PowerPoint stream must be refused");

  // A plain .ppt may legally carry VBA, unlike .pptx which would have to be
  // .pptm - so this is the active-content check's counterpart.
  await rejects(await write("macro.ppt", build(["PowerPoint Document", "_VBA_PROJECT"])), ".ppt",
    "ATTACHMENT_UNSAFE", "VBA storage must be refused even with a valid stream");
  await rejects(await write("macro2.ppt", build(["PowerPoint Document", "Macros"])), ".ppt",
    "ATTACHMENT_UNSAFE", "a Macros storage must be refused");

  // Legacy Word and Excel now have contracts of their own. .doc carries the
  // mandatory WordDocument stream...
  await attachmentMod.inspectOle(
    await write("real.doc", build(["WordDocument", "1Table", "SummaryInformation"])), ".doc");
  // ...and .xls accepts EITHER of its two, because Excel 97-2003 writes
  // "Workbook" and Excel 5.0/95 writes "Book". A single-needle check passes the
  // first and silently refuses the second, which is a valid spreadsheet.
  await attachmentMod.inspectOle(
    await write("biff8.xls", build(["Workbook", "SummaryInformation"])), ".xls");
  await attachmentMod.inspectOle(
    await write("biff5.xls", build(["Book", "SummaryInformation"])), ".xls");
  // The alternatives must not be mistaken for each other's macro streams: a
  // required-name comparison rather than two separate lists would classify the
  // second alternative as active content and refuse a clean file.
  await rejects(await write("macro.xls", build(["Workbook", "_VBA_PROJECT"])), ".xls",
    "ATTACHMENT_UNSAFE", "a macro-bearing .xls must be refused");
  await rejects(await write("macro.doc", build(["WordDocument", "Macros"])), ".doc",
    "ATTACHMENT_UNSAFE", "a macro-bearing .doc must be refused");
  // Cross-renames stay refused in both directions.
  await rejects(await write("deck.doc", build(["PowerPoint Document"])), ".doc",
    "ATTACHMENT_TYPE_MISMATCH", "a .ppt renamed to .doc must be refused");
  await rejects(await write("sheet.doc", build(["Workbook"])), ".doc",
    "ATTACHMENT_TYPE_MISMATCH", "an .xls renamed to .doc must be refused");

  // An extension with no known required stream must not fall through as valid.
  // .msi shares these same 8 bytes and is exactly what must never pass.
  await rejects(await write("installer.msi", build(["WordDocument"])), ".msi",
    "ATTACHMENT_TYPE_BLOCKED", "an extension with no OLE contract must be refused");

  // The name must still be found when it straddles a read-chunk boundary. The
  // stream reads 256 KiB at a time, so without the overlap the search silently
  // misses it and a valid deck is rejected as a type mismatch.
  const marker = Buffer.from("PowerPoint Document", "utf16le");
  const pad = 256 * 1024 - header.length - Math.floor(marker.length / 2);
  await attachmentMod.inspectOle(
    await write("split.ppt", build(["PowerPoint Document"], pad)), ".ppt");

  await fsp.rm(dir, { recursive: true, force: true });
});

test("legacy Word and Excel pass every gate, and modern Word presents too", function () {
  const documentsMod = require("../documents.js");
  const workerMod = require("../document-worker.js");
  // Gate 1: the extension is offered at all.
  assert.ok(attachmentMod.EXTENSIONS[".doc"], ".doc must be an allowed extension");
  assert.ok(attachmentMod.EXTENSIONS[".xls"], ".xls must be an allowed extension");
  // The canonical stored mime is the FIRST alias, as it is for legacy PowerPoint.
  assert.strictEqual(attachmentMod.EXTENSIONS[".doc"][0], "application/msword");
  assert.strictEqual(attachmentMod.EXTENSIONS[".xls"][0], "application/vnd.ms-excel");
  // Gate 3: every OLE2-backed extension must carry a required stream, or the
  // container check degrades to "does it have the magic bytes".
  const streams = attachmentMod.OLE_REQUIRED_STREAM;
  assert.ok(streams[".doc"], ".doc must be verified by a required stream");
  assert.ok(streams[".xls"], ".xls must be verified by a required stream");
  // Gates 4-6: convertible, presentable, and known to the worker. Missing the
  // worker entry is the SILENT one: the mime falls through to the PDF branch,
  // pdfinfo fails, and the user is told the file is invalid.
  for (const mime of ["application/msword", "application/vnd.ms-excel"]) {
    assert.ok(documentsMod.CONVERTIBLE.has(mime), mime + " must be convertible");
    assert.ok(documentsMod.PRESENTABLE.has(mime), mime + " must be presentable");
    assert.ok(workerMod.OFFICE_MIMES.has(mime), mime + " must route to LibreOffice");
  }
  // Word is presentable in both its formats or in neither.
  assert.ok(documentsMod.PRESENTABLE.has(
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document"),
    ".docx must be presentable now that .doc is");
  // Anything presentable must be convertible: presenting always goes through the
  // rasterizer, never the original bytes.
  for (const mime of documentsMod.PRESENTABLE) {
    assert.ok(documentsMod.CONVERTIBLE.has(mime), mime + " is presentable but not convertible");
  }
  // ...and every office mime the server will accept must be one the worker
  // routes to LibreOffice. This is the drift class that hid the .ppt bug.
  for (const mime of documentsMod.CONVERTIBLE) {
    if (documentsMod.IMAGE_MIMES.indexOf(mime) !== -1 || mime === "application/pdf") continue;
    assert.ok(workerMod.OFFICE_MIMES.has(mime),
      mime + " is convertible but the worker will not route it to LibreOffice");
  }
});

test("declared MIME must agree with the safe extension", function () {
  assert.strictEqual(attachmentMod.declaredMimeAllowed("report.pdf", "application/pdf"), true);
  assert.strictEqual(attachmentMod.declaredMimeAllowed("report.pdf", "text/html"), false);
  assert.strictEqual(attachmentMod.declaredMimeAllowed("payload.svg", "image/svg+xml"), false);
});

test("attachment health pings clamd and fails closed when it is unavailable", async function () {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), "ringq-meet-scanner-health-"));
  const scanner = path.join(root, "scanner.sh");
  try {
    fs.writeFileSync(scanner, "#!/bin/sh\n[ \"$1\" = \"--ping\" ] && [ \"$2\" = \"1\" ]\n", { mode: 0o700 });
    const service = new attachmentMod.AttachmentService({
      repository: {}, signingSecret: "s".repeat(64), root: path.join(root, "files"), scanner: scanner,
    });
    assert.strictEqual(await service.health(true), true);
    fs.writeFileSync(scanner, "#!/bin/sh\nexit 21\n", { mode: 0o700 });
    assert.strictEqual(await service.health(true), false);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test("signed links bind tenant, meeting, attachment, disposition and expiry", function () {
  const service = new attachmentMod.AttachmentService({
    repository: {}, signingSecret: "s".repeat(64), root: "/tmp/not-used",
  });
  const tenant = { tenantKey: "LKRQ123456789abc" };
  const attachmentId = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
  const link = service.link(tenant, "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", attachmentId, "inline");
  const token = new URL(link, "https://tenant.test").searchParams.get("token");
  const payload = service.verifyToken(token, tenant, attachmentId);
  assert.strictEqual(payload.d, "inline");
  assert.throws(function () {
    service.verifyToken(token, { tenantKey: "LKRQffffffffffff" }, attachmentId);
  }, function (err) { return err.code === "ATTACHMENT_LINK_EXPIRED"; });
  // Tamper the FIRST character, not the last. The final base64url character of
  // a token carries only the leftover bits of the last byte, and a lenient
  // decoder discards the rest — so flipping it can decode to identical bytes and
  // verify perfectly. That is what made this test fail on roughly one run in
  // ten: it embeds an expiry, so the token differs every run, and the old
  // `slice(0, -1) + "x"` was sometimes a no-op and sometimes simply invisible to
  // the decoder.
  const tampered = (token.startsWith("A") ? "B" : "A") + token.slice(1);
  assert.notStrictEqual(tampered, token);
  assert.throws(function () {
    service.verifyToken(tampered, tenant, attachmentId);
  }, function (err) { return err.code === "ATTACHMENT_LINK_INVALID"; });
});

test("preview page links bind tenant, attachment, asset and page", function () {
  const service = new documentMod.DocumentService({
    repository: {}, attachments: {}, signingSecret: "p".repeat(64), root: "/tmp/not-used",
  });
  const tenant = { tenantKey: "LKRQ123456789abc" };
  const attachmentId = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
  const assetId = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb";
  const link = service.pageLink(
    tenant, "cccccccc-cccc-4ccc-8ccc-cccccccccccc", attachmentId, assetId, 2
  );
  const token = new URL(link, "https://tenant.test").searchParams.get("token");
  const payload = service.verifyPageToken(token, tenant, attachmentId, 2);
  assert.strictEqual(payload.f, assetId);
  assert.strictEqual(payload.p, 2);
  assert.throws(function () {
    service.verifyPageToken(token, tenant, attachmentId, 1);
  }, function (err) { return err.code === "ATTACHMENT_LINK_EXPIRED"; });
  assert.throws(function () {
    service.verifyPageToken(token, tenant, "dddddddd-dddd-4ddd-8ddd-dddddddddddd", 2);
  }, function (err) { return err.code === "ATTACHMENT_LINK_EXPIRED"; });
});

test("a page link is identical for the same page inside a bucket", function () {
  // The flicker guard. A link whose expiry comes straight off the clock is a
  // different string every second, so the same slide is a cache miss on every
  // read and the deck blanks while it downloads again.
  const service = new documentMod.DocumentService({
    repository: {}, attachments: {}, signingSecret: "p".repeat(64), root: "/tmp/not-used",
  });
  const tenant = { tenantKey: "LKRQ123456789abc" };
  const attachmentId = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
  const assetId = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb";
  const meetingId = "cccccccc-cccc-4ccc-8ccc-cccccccccccc";

  const realNow = Date.now;
  try {
    // Two reads a second apart, which is what a page turn plus the periodic
    // collaboration repair actually look like.
    const base = 1_800_000_000_000;
    Date.now = function () { return base; };
    const first = service.pageLink(tenant, meetingId, attachmentId, assetId, 2);
    Date.now = function () { return base + 1000; };
    const second = service.pageLink(tenant, meetingId, attachmentId, assetId, 2);
    assert.strictEqual(second, first);

    // Different pages still differ, or every slide would share one URL.
    const other = service.pageLink(tenant, meetingId, attachmentId, assetId, 3);
    assert.notStrictEqual(other, first);
  } finally {
    Date.now = realNow;
  }
});

test("a page link issued at the end of a bucket is still valid a bucket later", function () {
  // Bucketing must not round the expiry *down* into the near past: a link that
  // dies seconds after it is handed out is worse than one that never caches.
  const service = new documentMod.DocumentService({
    repository: {}, attachments: {}, signingSecret: "p".repeat(64), root: "/tmp/not-used",
  });
  const tenant = { tenantKey: "LKRQ123456789abc" };
  const attachmentId = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
  const bucket = documentMod.DocumentService.PAGE_LINK_BUCKET;

  const realNow = Date.now;
  try {
    // One second before a bucket boundary — the worst moment to be issued.
    const boundary = Math.floor(1_800_000_000_000 / 1000 / bucket) * bucket;
    Date.now = function () { return (boundary - 1) * 1000; };
    const link = service.pageLink(
      tenant, "cccccccc-cccc-4ccc-8ccc-cccccccccccc", attachmentId,
      "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", 2
    );
    const token = new URL(link, "https://tenant.test").searchParams.get("token");

    Date.now = function () { return (boundary - 1 + bucket) * 1000; };
    const payload = service.verifyPageToken(token, tenant, attachmentId, 2);
    assert.strictEqual(payload.p, 2);

    // And it does still expire — a stable link is not a permanent one.
    Date.now = function () { return (boundary + bucket * 3) * 1000; };
    assert.throws(function () {
      service.verifyPageToken(token, tenant, attachmentId, 2);
    }, function (err) { return err.code === "ATTACHMENT_LINK_EXPIRED"; });
  } finally {
    Date.now = realNow;
  }
});

test("a served preview page may be cached privately", function () {
  // `no-store` on an image re-requested on every page turn defeats the point of
  // a stable URL. Private, because the bytes are one tenant's document.
  const source = fs.readFileSync(path.join(__dirname, "..", "documents.js"), "utf8");
  const header = source.match(/setHeader\("Cache-Control", "([^"]+)"\)/);
  assert.ok(header, "servePage must set a Cache-Control header");
  assert.ok(
    !/no-store/.test(header[1]),
    "no-store forces a re-download of every slide: " + header[1]
  );
  assert.ok(
    /private/.test(header[1]),
    "a tenant's document must never reach a shared cache: " + header[1]
  );
});

test("a direct image preview is converted when promoted to a presentation", async function () {
  const attachmentId = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
  const asset = {
    id: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", status: "ready", pageCount: 0,
  };
  let converted = false;
  const repository = {
    getAttachment: async function () {
      return { status: "attached", detected_mime: "image/png" };
    },
    ensureFileAsset: async function () { return asset; },
    getFileAsset: async function () { return asset; },
  };
  const service = new documentMod.DocumentService({
    repository: repository, attachments: {}, signingSecret: "p".repeat(64), root: "/tmp/not-used",
  });
  service.health = async function () { return true; };
  service._convert = async function () { converted = true; };
  await service.prepare(
    { tenantKey: "LKRQ123456789abc" },
    "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
    attachmentId,
    "presentation"
  );
  await new Promise(function (resolve) { setImmediate(resolve); });
  assert.strictEqual(converted, true);
});


// ── the real repository, against an in-memory store ───────────────────
//
// The api.js suite drives these routes through a fake repository that
// REIMPLEMENTS the permission logic, so it proves the wiring and nothing about
// the rules. These fake only the storage: every branch, version number and
// undo-stack selection below is the real `collaboration.js` running.

const PRESENTATION_MEETING = "cccccccc-cccc-4ccc-8ccc-cccccccccccc";
const PRESENTATION_ASSET = "dddddddd-dddd-4ddd-8ddd-dddddddddddd";
const TENANT = { domainUuid: "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee" };

/**
 * A pool that answers the handful of statements these methods issue.
 *
 * Dispatches on a distinctive fragment of each query rather than parsing SQL:
 * the point is to exercise the JavaScript around the storage, and a fragment
 * that stops matching is a loud failure rather than a silent wrong answer.
 */
function fakeStore(seed) {
  const store = {
    presentation: Object.assign({
      meeting_id: PRESENTATION_MEETING,
      domain_uuid: TENANT.domainUuid,
      version: 0,
      last_sequence: 0,
      status: "idle",
      active_asset_id: null,
      presenter_ref: null,
      presenter_name: null,
      page_number: null,
      annotation_mode: "presenter",
    }, (seed || {}).presentation || {}),
    actions: [],
    annotations: {},
    annotationBatches: [],
    asset: Object.assign({
      id: PRESENTATION_ASSET,
      page_count: 3,
      detected_mime: "application/pdf",
      original_name: "slides.pdf",
    }, (seed || {}).asset || {}),
  };
  const key = function (assetId, page) { return assetId + ":" + page; };
  store.query = async function (sql, params) {
    const text = String(sql).replace(/\s+/g, " ").trim();
    const p = params || [];
    if (/^(BEGIN|COMMIT|ROLLBACK)/.test(text)) return { rows: [] };

    // presentations
    if (text.indexOf("INSERT INTO public.meet_file_presentations") === 0) return { rows: [] };
    if (text.indexOf("SELECT * FROM public.meet_file_presentations") === 0) {
      return { rows: [Object.assign({}, store.presentation)] };
    }
    if (text.indexOf("SELECT p.*, f.page_count") === 0) {
      return { rows: [Object.assign({}, store.presentation, {
        page_count: store.presentation.active_asset_id ? store.asset.page_count : 0,
        original_name: store.asset.original_name,
        detected_mime: store.asset.detected_mime,
      })] };
    }
    if (text.indexOf("SELECT * FROM public.meet_file_presentation_actions") === 0) {
      return { rows: store.actions.filter(function (a) {
        return a.actor_ref === p[2] && a.client_action_id === p[3];
      }) };
    }
    if (text.indexOf("SELECT f.*,a.detected_mime") === 0) {
      return { rows: p[0] === store.asset.id ? [Object.assign({}, store.asset)] : [] };
    }
    if (text.indexOf("SELECT page_count FROM public.meet_file_assets") === 0) {
      return { rows: [{ page_count: store.asset.page_count }] };
    }
    if (text.indexOf("INSERT INTO public.meet_file_presentation_actions") === 0) {
      store.actions.push({
        actor_ref: p[4], client_action_id: p[5], kind: p[6],
        asset_id: p[7], page_number: p[8],
      });
      return { rows: [] };
    }
    if (text.indexOf("UPDATE public.meet_file_presentations") === 0) {
      Object.assign(store.presentation, {
        version: p[2], last_sequence: p[3], status: p[4], active_asset_id: p[5],
        presenter_ref: p[6], presenter_name: p[7], page_number: p[8], annotation_mode: p[9],
      });
      return { rows: [Object.assign({}, store.presentation)] };
    }

    // annotations
    if (text.indexOf("INSERT INTO public.meet_file_presentation_annotations") === 0) {
      const k = key(p[2], p[3]);
      if (!store.annotations[k]) {
        store.annotations[k] = {
          meeting_id: p[0], domain_uuid: p[1], asset_id: p[2], page_number: p[3],
          version: 0, last_sequence: 0, document: { elements: {} },
        };
      }
      return { rows: [] };
    }
    if (text.indexOf("SELECT * FROM public.meet_file_presentation_annotations") === 0) {
      const row = store.annotations[key(p[2], p[3])];
      return { rows: row ? [JSON.parse(JSON.stringify(row))] : [] };
    }
    if (text.indexOf("SELECT * FROM public.meet_file_presentation_annotation_batches") === 0) {
      if (text.indexOf("AND kind='operations'") !== -1) {
        const matches = store.annotationBatches.filter(function (b) {
          return b.asset_id === p[2] && b.page_number === p[3] &&
            b.actor_ref === p[4] && b.kind === "operations" && b.undone === p[5];
        }).sort(function (a, b) { return b.sequence - a.sequence; });
        return { rows: matches.slice(0, 1) };
      }
      return { rows: store.annotationBatches.filter(function (b) {
        return b.actor_ref === p[2] && b.client_batch_id === p[3];
      }) };
    }
    if (text.indexOf("INSERT INTO public.meet_file_presentation_annotation_batches") === 0) {
      store.annotationBatches.push({
        id: "batch-" + (store.annotationBatches.length + 1),
        asset_id: p[2], page_number: p[3], sequence: p[4], version: p[5],
        actor_ref: p[6], client_batch_id: p[7], kind: p[8],
        operations: JSON.parse(p[9]),
        // The undo/redo INSERT writes `'[]'::jsonb` inline for the inverse, so
        // its $11 is the target batch id and NOT a JSON document. Telling the
        // two statements apart by that literal is what keeps this fake honest.
        inverse_operations: text.indexOf("'[]'::jsonb") !== -1 ? [] : JSON.parse(p[10]),
        target_batch_id: text.indexOf("'[]'::jsonb") !== -1 ? p[10] : null,
        undone: false,
      });
      return { rows: [] };
    }
    if (text.indexOf("UPDATE public.meet_file_presentation_annotation_batches SET undone") === 0) {
      const found = store.annotationBatches.find(function (b) { return b.id === p[0]; });
      if (found) found.undone = p[1];
      return { rows: [] };
    }
    if (text.indexOf("UPDATE public.meet_file_presentation_annotations") === 0) {
      const row = store.annotations[key(p[2], p[3])];
      Object.assign(row, { version: p[4], last_sequence: p[5], document: JSON.parse(p[6]) });
      return { rows: [JSON.parse(JSON.stringify(row))] };
    }
    throw new Error("fake store has no answer for: " + text.slice(0, 90));
  };
  return store;
}

function repo(seed) {
  const store = fakeStore(seed);
  const instance = new collaborationMod.CollaborationRepository({ pool: store });
  instance.testStore = store;
  return instance;
}

/** A presentation already on the stage, presented by `presenter`. */
function presenting(mode) {
  return {
    presentation: {
      version: 4, last_sequence: 4, status: "active",
      active_asset_id: PRESENTATION_ASSET, presenter_ref: "presenter",
      presenter_name: "Presenter", page_number: 1,
      annotation_mode: mode || "presenter",
    },
  };
}

function action(overrides) {
  return Object.assign({
    meetingId: PRESENTATION_MEETING,
    clientActionId: "aaaaaaaa-0000-4000-8000-" + String(counter++).padStart(12, "0"),
    expectedVersion: 4,
    isModerator: false,
  }, overrides);
}
let counter = 1;

test("a moderator advances somebody else's deck", async function () {
  const r = repo(presenting());
  const state = await r.applyPresentationAction(TENANT, action({
    kind: "page", actorId: "cohost", isModerator: true, pageNumber: 3,
  }));
  assert.strictEqual(state.pageNumber, 3);
  assert.strictEqual(state.presenterId, "presenter", "paging must not steal the presentation");
});

test("an ordinary participant still cannot page somebody else's deck", async function () {
  const r = repo(presenting());
  await assert.rejects(
    r.applyPresentationAction(TENANT, action({
      kind: "page", actorId: "guest", isModerator: false, pageNumber: 2,
    })),
    function (err) { return err.code === "PRESENTATION_FORBIDDEN"; }
  );
});

test("a page beyond the deck is refused even for a moderator", async function () {
  const r = repo(presenting());
  await assert.rejects(
    r.applyPresentationAction(TENANT, action({
      kind: "page", actorId: "cohost", isModerator: true, pageNumber: 9,
    })),
    function (err) { return err.code === "VALIDATION_ERROR"; }
  );
});

test("the presenter opens the deck to everyone, and a moderator can close it again", async function () {
  const r = repo(presenting());
  const opened = await r.applyPresentationAction(TENANT, action({
    kind: "mode", actorId: "presenter", annotationMode: "everyone",
  }));
  assert.strictEqual(opened.annotationMode, "everyone");
  const closed = await r.applyPresentationAction(TENANT, action({
    kind: "mode", actorId: "cohost", isModerator: true,
    annotationMode: "presenter", expectedVersion: opened.version,
  }));
  assert.strictEqual(closed.annotationMode, "presenter");
});

test("a participant cannot open a deck for drawing", async function () {
  const r = repo(presenting());
  await assert.rejects(
    r.applyPresentationAction(TENANT, action({
      kind: "mode", actorId: "guest", annotationMode: "everyone",
    })),
    function (err) { return err.code === "PRESENTATION_FORBIDDEN"; }
  );
});

test("stopping a presentation puts the deck back to presenter-only", async function () {
  const r = repo(presenting("everyone"));
  const stopped = await r.applyPresentationAction(TENANT, action({
    kind: "stop", actorId: "presenter",
  }));
  assert.strictEqual(stopped.status, "idle");
  assert.strictEqual(stopped.annotationMode, "presenter");
});

test("starting a presentation closes the deck, whatever the row said before", async function () {
  // Seeded idle-but-open rather than reached by stopping, deliberately: `stop`
  // also resets the mode, so a stop-then-start test passes with `start`'s own
  // reset deleted and proves nothing about the guarantee that matters -- that a
  // deck ALWAYS begins closed, however its row came to be the way it is.
  const r = repo({
    presentation: {
      version: 4, last_sequence: 4, status: "idle", active_asset_id: null,
      presenter_ref: null, presenter_name: null, page_number: null,
      annotation_mode: "everyone",
    },
  });
  const started = await r.applyPresentationAction(TENANT, action({
    kind: "start", actorId: "someone-else", actorName: "Someone Else",
    assetId: PRESENTATION_ASSET,
  }));
  assert.strictEqual(started.status, "active");
  assert.strictEqual(started.annotationMode, "presenter",
    "a fresh deck must be closed until its own presenter opens it");
});

test("annotations are stored per page and do not leak between pages", async function () {
  const r = repo(presenting());
  const stroke = function (id) {
    return [{ type: "stroke", element: { id: id, kind: "pen", color: 1, width: 4, points: [[0, 0], [1, 1]] } }];
  };
  await r.applyPresentationAnnotationBatch(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
    actorId: "presenter", clientBatchId: "b1", operations: stroke("11111111-1111-4111-8111-111111111111"),
  });
  await r.applyPresentationAnnotationBatch(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 2,
    actorId: "presenter", clientBatchId: "b2", operations: stroke("22222222-2222-4222-8222-222222222222"),
  });
  const one = await r.presentationAnnotations(TENANT, PRESENTATION_MEETING, PRESENTATION_ASSET, 1);
  const two = await r.presentationAnnotations(TENANT, PRESENTATION_MEETING, PRESENTATION_ASSET, 2);
  assert.deepStrictEqual(Object.keys(one.document.elements), ["11111111-1111-4111-8111-111111111111"]);
  assert.deepStrictEqual(Object.keys(two.document.elements), ["22222222-2222-4222-8222-222222222222"]);
  assert.strictEqual(one.version, 1, "each page carries its own version, not a meeting-wide one");
  assert.strictEqual(two.version, 1);
});

test("undo takes back this actor's own last stroke and redo puts it back", async function () {
  const r = repo(presenting());
  const id = "33333333-3333-4333-8333-333333333333";
  await r.applyPresentationAnnotationBatch(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
    actorId: "presenter", clientBatchId: "u1",
    operations: [{ type: "stroke", element: { id: id, kind: "pen", color: 1, width: 4, points: [[0, 0]] } }],
  });
  const undone = await r.presentationAnnotationUndoRedo(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
    actorId: "presenter", kind: "undo", clientBatchId: "u2",
  });
  assert.deepStrictEqual(Object.keys(undone.document.elements), []);
  const redone = await r.presentationAnnotationUndoRedo(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
    actorId: "presenter", kind: "redo", clientBatchId: "u3",
  });
  assert.deepStrictEqual(Object.keys(redone.document.elements), [id]);
});

test("one participant's undo never reaches another participant's ink", async function () {
  const r = repo(presenting("everyone"));
  const mine = "44444444-4444-4444-8444-444444444444";
  await r.applyPresentationAnnotationBatch(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
    actorId: "guest", clientBatchId: "g1",
    operations: [{ type: "stroke", element: { id: mine, kind: "pen", color: 1, width: 4, points: [[0, 0]] } }],
  });
  await assert.rejects(
    r.presentationAnnotationUndoRedo(TENANT, {
      meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
      actorId: "presenter", kind: "undo", clientBatchId: "g2",
    }),
    function (err) { return err.code === "NOTHING_TO_UNDO"; }
  );
  const still = await r.presentationAnnotations(TENANT, PRESENTATION_MEETING, PRESENTATION_ASSET, 1);
  assert.deepStrictEqual(Object.keys(still.document.elements), [mine]);
});

test("replaying a batch id is free, and reusing it for other ink is refused", async function () {
  const r = repo(presenting());
  const ops = [{ type: "stroke", element: { id: "55555555-5555-4555-8555-555555555555", kind: "pen", color: 1, width: 4, points: [[0, 0]] } }];
  const first = await r.applyPresentationAnnotationBatch(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
    actorId: "presenter", clientBatchId: "same", operations: ops,
  });
  const replay = await r.applyPresentationAnnotationBatch(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
    actorId: "presenter", clientBatchId: "same", operations: ops,
  });
  assert.strictEqual(replay.duplicate, true);
  assert.strictEqual(replay.version, first.version, "a retry must not draw the stroke twice");
  await assert.rejects(
    r.applyPresentationAnnotationBatch(TENANT, {
      meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
      actorId: "presenter", clientBatchId: "same",
      operations: [{ type: "stroke", element: { id: "66666666-6666-4666-8666-666666666666", kind: "pen", color: 1, width: 4, points: [[0, 0]] } }],
    }),
    function (err) { return err.code === "IDEMPOTENCY_CONFLICT"; }
  );
});

test("clearing a page is destructive and refuses a stale version", async function () {
  const r = repo(presenting());
  await r.applyPresentationAnnotationBatch(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
    actorId: "presenter", clientBatchId: "c1",
    operations: [{ type: "stroke", element: { id: "77777777-7777-4777-8777-777777777777", kind: "pen", color: 1, width: 4, points: [[0, 0]] } }],
  });
  await assert.rejects(
    r.clearPresentationAnnotations(TENANT, {
      meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
      actorId: "presenter", clientBatchId: "c2", expectedVersion: 0,
    }),
    function (err) { return err.code === "VERSION_CONFLICT"; }
  );
  const cleared = await r.clearPresentationAnnotations(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
    actorId: "presenter", clientBatchId: "c3", expectedVersion: 1,
  });
  assert.deepStrictEqual(Object.keys(cleared.document.elements), []);
});

test("two people drawing at once do not conflict, because ink is additive", async function () {
  const r = repo(presenting("everyone"));
  const draw = function (actor, batch, id) {
    return r.applyPresentationAnnotationBatch(TENANT, {
      meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 1,
      actorId: actor, clientBatchId: batch,
      // Both carry the SAME stale expectedVersion. A non-destructive batch must
      // not be refused for it, or one of two people drawing at once is dropped.
      expectedVersion: 0,
      operations: [{ type: "stroke", element: { id: id, kind: "pen", color: 1, width: 4, points: [[0, 0]] } }],
    });
  };
  await draw("presenter", "p1", "88888888-8888-4888-8888-888888888888");
  await draw("guest", "g1", "99999999-9999-4999-8999-999999999999");
  const state = await r.presentationAnnotations(TENANT, PRESENTATION_MEETING, PRESENTATION_ASSET, 1);
  assert.strictEqual(Object.keys(state.document.elements).length, 2);
});

test("a malformed stored document reads as empty rather than throwing", async function () {
  const r = repo(presenting());
  r.testStore.annotations[PRESENTATION_ASSET + ":1"] = {
    asset_id: PRESENTATION_ASSET, page_number: 1, version: 3, last_sequence: 3,
    document: "not a document",
  };
  const state = await r.presentationAnnotations(TENANT, PRESENTATION_MEETING, PRESENTATION_ASSET, 1);
  assert.deepStrictEqual(state.document, { elements: {} });
});


test("every annotation statement is scoped to tenant, asset, page and actor", async function () {
  // The store fake above dispatches on SQL fragments and then filters with its
  // own JavaScript, so it would answer correctly even for a query that had lost
  // its WHERE clause. This reads the statements themselves, which is the only
  // way a scoping mistake in the SQL is visible without a real database.
  const r = repo(presenting());
  const seen = [];
  const inner = r.testStore.query;
  r.testStore.query = async function (sql, params) {
    seen.push(String(sql).replace(/\s+/g, " ").trim());
    return inner.call(this, sql, params);
  };
  const id = "abababab-abab-4bab-8bab-abababababab";
  await r.applyPresentationAnnotationBatch(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 2,
    actorId: "presenter", clientBatchId: "s1",
    operations: [{ type: "stroke", element: { id: id, kind: "pen", color: 1, width: 4, points: [[0, 0]] } }],
  });
  await r.presentationAnnotationUndoRedo(TENANT, {
    meetingId: PRESENTATION_MEETING, assetId: PRESENTATION_ASSET, pageNumber: 2,
    actorId: "presenter", kind: "undo", clientBatchId: "s2",
  });

  const touching = seen.filter(function (sql) {
    return sql.indexOf("meet_file_presentation_annotation") !== -1;
  });
  assert.ok(touching.length >= 4, "expected several annotation statements, saw " + touching.length);
  const idempotency = [];
  touching.forEach(function (sql) {
    if (sql.indexOf("INSERT") === 0) return;              // values, not a lookup
    if (sql.indexOf("SET undone") !== -1) return;         // addressed by primary key
    if (/client_batch_id\s*=\s*\$/.test(sql)) { idempotency.push(sql); return; }
    assert.ok(/domain_uuid\s*=\s*\$/.test(sql), "unscoped by tenant: " + sql);
    assert.ok(/asset_id\s*=\s*\$/.test(sql), "unscoped by asset: " + sql);
    assert.ok(/page_number\s*=\s*\$/.test(sql), "unscoped by page: " + sql);
  });

  // The replay lookup is deliberately the ONE statement that is not page
  // scoped, and it has to stay that way: it mirrors the unique index
  // (meeting_id, actor_ref, client_batch_id), and narrowing it to the page
  // would miss a retry whose page had moved underneath it and draw the same
  // stroke twice.
  assert.ok(idempotency.length >= 2, "expected a replay lookup per action");
  idempotency.forEach(function (sql) {
    assert.ok(/meeting_id\s*=\s*\$/.test(sql) && /actor_ref\s*=\s*\$/.test(sql), sql);
    assert.ok(!/page_number\s*=\s*\$/.test(sql),
      "the replay lookup must not be page scoped, or a retry across a page turn draws twice: " + sql);
  });

  // The undo stack is per actor. Without this clause one participant's Ctrl+Z
  // reaches the stroke somebody else just drew.
  const undoLookup = touching.filter(function (sql) {
    return sql.indexOf("kind='operations'") !== -1;
  });
  assert.strictEqual(undoLookup.length, 1, "expected exactly one undo-target lookup");
  assert.ok(/actor_ref\s*=\s*\$/.test(undoLookup[0]),
    "the undo target must be bound to this actor: " + undoLookup[0]);
});

test("a document that is not a document reads as empty, whatever shape it is", async function () {
  const r = repo(presenting());
  for (const broken of ["not a document", 7, [], null, { elements: [] }, { elements: "x" }]) {
    r.testStore.annotations[PRESENTATION_ASSET + ":1"] = {
      asset_id: PRESENTATION_ASSET, page_number: 1, version: 3, last_sequence: 3,
      document: broken,
    };
    const state = await r.presentationAnnotations(TENANT, PRESENTATION_MEETING, PRESENTATION_ASSET, 1);
    assert.deepStrictEqual(state.document, { elements: {} },
      "expected an empty document for " + JSON.stringify(broken));
  }
});

(async function () {
  for (const item of tests) {
    try {
      await item.fn();
      passed++;
      process.stdout.write("  ok   " + item.name + "\n");
    } catch (err) {
      failed++;
      failures.push({ name: item.name, error: err });
      process.stdout.write("  FAIL " + item.name + "\n");
    }
  }
  process.stdout.write("passed: " + passed + "/" + tests.length + "\n");
  if (failed) {
    failures.forEach(function (failure) {
      process.stdout.write("\n" + failure.name + "\n  " + failure.error.stack + "\n");
    });
    process.exit(1);
  }
})();
