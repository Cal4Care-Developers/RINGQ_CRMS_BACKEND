"use strict";

const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { CaptureService } = require("../capture.js");

const tenant = {
  tenantKey: "LKRQaaaaaaaaaaaa",
  domainUuid: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
  subdomain: "tenant.example.test",
};
const meetingId = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb";
const participantId = "participant-1";
const reviewNonce = "cccccccc-cccc-4ccc-8ccc-cccccccccccc";

function service() {
  const pool = {
    query: async function (sql) {
      if (sql.indexOf("FROM public.meet_meetings") !== -1) {
        return {
          rows: [{
            id: meetingId,
            owner_key: "owner-1",
            status: "ended",
            end_reason: "host_ended",
            ended_at: new Date(),
            review_nonce: reviewNonce,
          }],
        };
      }
      return { rows: [], rowCount: 0 };
    },
  };
  return new CaptureService({
    pool: pool,
    gateway: {},
    signingSecret: "recording-signing-secret-that-is-long-enough",
    reviewSecret: "review-signing-secret-that-is-long-enough",
  });
}

(async function () {
  let passed = 0;
  const tests = [];
  const test = function (name, fn) { tests.push({ name: name, fn: fn }); };

  test("review capabilities bind tenant, meeting, participant, and durable nonce", async function () {
    const capture = service();
    const token = await capture.reviewToken(tenant, meetingId, participantId);
    const payload = capture.verifyReviewToken(tenant, token);
    assert.strictEqual(payload.t, tenant.tenantKey);
    assert.strictEqual(payload.m, meetingId);
    assert.strictEqual(payload.p, participantId);
    assert.strictEqual(payload.n, reviewNonce);
  });

  test("review capability tampering is rejected in constant-time signature validation", async function () {
    const capture = service();
    const token = await capture.reviewToken(tenant, meetingId, participantId);
    // Tamper with the FIRST character of the signature, not the last. A 32-byte
    // HMAC is 43 base64url characters carrying 258 bits, so the last character
    // has two bits that decode to nothing: 'a' and 'b' there are the same
    // signature, and the token still verified. That made this test fail roughly
    // one run in fifty, whenever the expiry second produced such a signature.
    const cut = token.indexOf(".") + 1;
    const tampered = token.slice(0, cut)
      + (token[cut] === "A" ? "B" : "A")
      + token.slice(cut + 1);
    assert.throws(
      function () { capture.verifyReviewToken(tenant, tampered); },
      function (error) { return error && error.code === "AUTH_INVALID_REVIEW"; },
    );
  });

  test("recording links bind tenant, meeting, recording, and expiry", async function () {
    const capture = service();
    const recordingId = "dddddddd-dddd-4ddd-8ddd-dddddddddddd";
    const url = new URL("https://tenant.example.test" + capture.recordingLink(tenant, meetingId, recordingId));
    const payload = capture._verifyRecordingToken(tenant, recordingId, url.searchParams.get("token"));
    assert.strictEqual(payload.t, tenant.tenantKey);
    assert.strictEqual(payload.m, meetingId);
    assert.strictEqual(payload.r, recordingId);
    assert.ok(payload.e > Math.floor(Date.now() / 1000));
    assert.throws(
      function () { capture._verifyRecordingToken(tenant, "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee", url.searchParams.get("token")); },
      function (error) { return error && error.code === "RECORDING_LINK_EXPIRED"; },
    );
  });

  test("active recording directories are writable by the Egress shared group", async function () {
    const root = await fs.promises.mkdtemp(path.join(os.tmpdir(), "ringq-meet-capture-"));
    const recordingId = "dddddddd-dddd-4ddd-8ddd-dddddddddddd";
    const capture = new CaptureService({
      pool: {
        query: async function (sql) {
          if (sql.indexOf("UPDATE public.meet_capture_states") !== -1) {
            return {
              rows: [{
                version: 2,
                recording_id: recordingId,
                recording_status: "active",
              }],
            };
          }
          return { rows: [], rowCount: 1 };
        },
      },
      gateway: {
        startRecording: async function () { return { egressId: "egress-test" }; },
      },
      root: root,
      signingSecret: "recording-signing-secret-that-is-long-enough",
      reviewSecret: "review-signing-secret-that-is-long-enough",
      segmentMaxMs: 60000,
    });
    try {
      await capture.activateRecording(tenant, meetingId, recordingId);
      const stat = await fs.promises.stat(path.join(root, tenant.tenantKey, meetingId));
      assert.strictEqual(stat.mode & 0o777, 0o770);
    } finally {
      for (const timer of capture.segmentTimers.values()) clearTimeout(timer);
      await fs.promises.rm(root, { recursive: true, force: true });
    }
  });

  // The capture slot is server-wide, so a row that can hold it forever is an
  // outage for every meeting on the box. These pin the two escapes.
  function slotService(rows) {
    const seen = [];
    const pool = {
      query: async function (sql, params) {
        seen.push({ sql: sql, params: params });
        if (sql.indexOf("meet_capture_states s") !== -1) {
          // Mimic the SQL predicates the query relies on.
          const excluded = params[0];
          const maxAgeMs = Number(params[1]);
          const held = rows.filter(function (row) {
            const busy = ["starting", "active", "stopping"].indexOf(row.recording_status) !== -1
              || ["starting", "active", "stopping"].indexOf(row.transcription_status) !== -1;
            const fresh = Date.now() - row.updatedAtMs < maxAgeMs;
            return row.meeting_id !== excluded && busy && row.meetingStatus !== "ended" && fresh;
          });
          return { rows: held.slice(0, 1) };
        }
        return { rows: [], rowCount: 0 };
      },
    };
    const capture = new CaptureService({
      pool: pool,
      gateway: {},
      signingSecret: "recording-signing-secret-that-is-long-enough",
      reviewSecret: "review-signing-secret-that-is-long-enough",
      segmentMaxMs: 60000,
    });
    return { capture: capture, seen: seen };
  }

  test("a live capture on another meeting still holds the slot", async function () {
    const { capture } = slotService([{
      meeting_id: "other", recording_status: "active", transcription_status: "idle",
      meetingStatus: "live", updatedAtMs: Date.now(),
    }]);
    assert.strictEqual(await capture._slotHeldElsewhere(capture.pool, meetingId), true);
  });

  test("a capture on an ended meeting does not hold the slot", async function () {
    const { capture } = slotService([{
      meeting_id: "other", recording_status: "active", transcription_status: "active",
      meetingStatus: "ended", updatedAtMs: Date.now(),
    }]);
    assert.strictEqual(await capture._slotHeldElsewhere(capture.pool, meetingId), false);
  });

  test("a capture older than one segment is abandoned, not blocking", async function () {
    // The timer that would have stopped it lives in process memory, so a restart
    // orphans it permanently. Without this the box refuses recordings forever.
    const { capture } = slotService([{
      meeting_id: "other", recording_status: "active", transcription_status: "idle",
      meetingStatus: "live", updatedAtMs: Date.now() - 120000,
    }]);
    assert.strictEqual(await capture._slotHeldElsewhere(capture.pool, meetingId), false);
  });

  test("the slot query bounds staleness by the segment cap", async function () {
    const { capture, seen } = slotService([]);
    await capture._slotHeldElsewhere(capture.pool, meetingId);
    const query = seen[seen.length - 1];
    assert.ok(query.sql.indexOf("<> 'ended'") !== -1, "must exclude ended meetings");
    assert.strictEqual(query.params[1], 60000);
  });

  // Recording and transcription are independent captures with independent
  // controls. Recording used to start a transcription in the same statement
  // whenever the tenant had the feature enabled, so one press of Record silently
  // ran two captures, and stopping the recording then had to ask the host
  // whether to keep the one they never chose. These pin the separation at the
  // level it was broken: the SQL.
  function sqlRecorder(rows) {
    const statements = [];
    return {
      statements: statements,
      pool: {
        query: async function (sql, params) {
          statements.push(sql);
          return { rows: rows, rowCount: rows.length, params: params };
        },
        connect: async function () {
          return {
            query: async function (sql, params) {
              statements.push(sql);
              // No prior action with this id, no other meeting holding the
              // server-wide slot, and a fresh idle state to act on.
              if (sql.indexOf("FROM public.meet_capture_actions") !== -1
                || sql.indexOf("meet_capture_states s") !== -1) {
                return { rows: [], rowCount: 0 };
              }
              if (sql.indexOf("SELECT * FROM public.meet_capture_states") !== -1) {
                return { rows: [{ version: 0, recording_status: "idle", transcription_status: "idle" }] };
              }
              return { rows: rows, rowCount: rows.length, params: params };
            },
            release: function () {},
          };
        },
      },
    };
  }

  test("starting a recording never touches transcription state", async function () {
    const recorder = sqlRecorder([{ version: 1, recording_status: "starting", transcription_status: "idle" }]);
    const root = await fs.promises.mkdtemp(path.join(os.tmpdir(), "ringq-meet-begin-"));
    const capture = new CaptureService({
      pool: recorder.pool,
      gateway: {},
      root: root,
      signingSecret: "recording-signing-secret-that-is-long-enough",
      reviewSecret: "review-signing-secret-that-is-long-enough",
      minimumFreeBytes: 1,
    });
    try {
      await capture.beginRecording(tenant, {
        meetingId: meetingId,
        actorRef: "owner",
        clientActionId: "0f1f4a20-6d0f-4e2f-9d63-0f66f0ac1b62",
        expectedVersion: 0,
      });
    } finally {
      await fs.promises.rm(root, { recursive: true, force: true });
    }
    const stateUpdate = recorder.statements.filter(function (sql) {
      return sql.indexOf("UPDATE public.meet_capture_states") !== -1;
    }).join("\n");
    assert.ok(stateUpdate.indexOf("recording_status='starting'") !== -1, "must start the recording");
    assert.ok(
      stateUpdate.indexOf("transcription_status") === -1,
      "beginRecording must not write transcription_status"
    );
    assert.ok(stateUpdate.indexOf("transcription_started_by_recording") === -1);
  });

  test("stopping a recording never stops a transcription", async function () {
    const recorder = sqlRecorder([{ version: 2, recording_status: "stopping", transcription_status: "active", recording_id: null }]);
    const capture = new CaptureService({
      pool: recorder.pool,
      gateway: {},
      signingSecret: "recording-signing-secret-that-is-long-enough",
      reviewSecret: "review-signing-secret-that-is-long-enough",
    });
    // No recording is active in this stubbed state, so the guard refuses before
    // any write. That is exactly what is being inspected: the statement text.
    await assert.rejects(capture.stopRecording(tenant, {
      meetingId: meetingId,
      actorRef: "owner",
      clientActionId: "5f2f4a20-6d0f-4e2f-9d63-0f66f0ac1b63",
      expectedVersion: 0,
    }));
    const source = require("fs").readFileSync(path.join(__dirname, "..", "capture.js"), "utf8");
    const body = source.slice(source.indexOf("async stopRecording"), source.indexOf("async finalizeRecording"));
    assert.ok(body.indexOf("recording_status='stopping'") !== -1);
    assert.ok(
      body.indexOf("transcription_status") === -1,
      "stopRecording must leave the host's own transcription running"
    );
    assert.ok(body.indexOf("keep_transcription") === -1, "the keep-transcription question is retired");
  });

  test("failing a recording leaves a separately started transcription running", async function () {
    let captureStateSql = "";
    const capture = new CaptureService({
      pool: {
        query: async function (sql) {
          if (sql.indexOf("UPDATE public.meet_capture_states") !== -1) {
            captureStateSql = sql;
            return { rows: [{ version: 2, recording_status: "failed", transcription_status: "active" }] };
          }
          return { rows: [], rowCount: 0 };
        },
      },
      gateway: {},
      signingSecret: "recording-signing-secret-that-is-long-enough",
      reviewSecret: "review-signing-secret-that-is-long-enough",
    });
    const state = await capture.failRecording(tenant, meetingId, "rec-1", "egress_start_failed");
    assert.strictEqual(state.recordingStatus, "failed");
    assert.strictEqual(state.transcriptionStatus, "active");
    assert.ok(
      captureStateSql.indexOf("transcription_status") === -1,
      "a recording that could not start must not fail the host's transcription"
    );
  });

  test("a transcription is pinned to the language the host chose", async function () {
    const recorder = sqlRecorder([{
      version: 1, recording_status: "idle", transcription_status: "starting", transcription_language: "tl",
    }]);
    const capture = new CaptureService({
      pool: recorder.pool,
      gateway: {},
      signingSecret: "recording-signing-secret-that-is-long-enough",
      reviewSecret: "review-signing-secret-that-is-long-enough",
    });
    const result = await capture.setTranscription(tenant, {
      meetingId: meetingId,
      actorRef: "owner",
      clientActionId: "6f2f4a20-6d0f-4e2f-9d63-0f66f0ac1b64",
      expectedVersion: 0,
      language: "tl",
    }, true);
    assert.strictEqual(result.state.transcriptionLanguage, "tl");
    const stateUpdate = recorder.statements.filter(function (sql) {
      return sql.indexOf("UPDATE public.meet_capture_states") !== -1;
    }).join("\n");
    assert.ok(stateUpdate.indexOf("transcription_language=COALESCE") !== -1);

    // An unknown code is refused rather than silently downgraded to detection: a
    // host who picked a language and got detected audio back cannot tell.
    await assert.rejects(
      capture.setTranscription(tenant, {
        meetingId: meetingId,
        actorRef: "owner",
        clientActionId: "7f2f4a20-6d0f-4e2f-9d63-0f66f0ac1b65",
        expectedVersion: 0,
        language: "klingon",
      }, true),
      function (error) { return error.code === "VALIDATION_ERROR"; }
    );
  });

  // node-postgres returns every `bigint` column as a string. These two readers
  // used to hand those strings straight to the client, where a numeric cast on
  // `bytes` threw and turned a stored recording into a permanent Retry button.
  test("recordings expose bytes as a JSON number, never a bigint string", async function () {
    const rows = [{
      id: "2db54e2c-0bd8-4990-8501-6a42aeac5cc2",
      status: "complete",
      fileName: "ringq-meeting.mp4",
      bytes: "1524420",
      sha256: "a".repeat(64),
      mime: "video/mp4",
      failureCode: null,
    }];
    const svc = new CaptureService({
      pool: { query: async function () { return { rows: rows, rowCount: rows.length }; } },
      gateway: {},
      signingSecret: "recording-signing-secret-that-is-long-enough",
      reviewSecret: "review-signing-secret-that-is-long-enough",
    });
    const result = await svc.recordings(tenant, meetingId);
    assert.strictEqual(typeof result[0].bytes, "number");
    assert.strictEqual(result[0].bytes, 1524420);
    // Everything else passes through untouched.
    assert.strictEqual(result[0].status, "complete");
    assert.strictEqual(result[0].fileName, "ringq-meeting.mp4");
    // A NULL byte count stays null rather than becoming 0, which would claim an
    // empty file for a recording that never finished.
    const nulled = await (new CaptureService({
      pool: { query: async function () { return { rows: [{ id: "x", status: "failed", bytes: null }], rowCount: 1 }; } },
      gateway: {},
      signingSecret: "recording-signing-secret-that-is-long-enough",
      reviewSecret: "review-signing-secret-that-is-long-enough",
    })).recordings(tenant, meetingId);
    assert.strictEqual(nulled[0].bytes, null);
  });

  test("transcript segments expose sequence and offsets as JSON numbers", async function () {
    const rows = [{
      segmentId: "seg-1",
      participantId: "p-1",
      participantName: "Ada",
      sequence: "1",
      startMs: "0",
      endMs: "2400",
      language: "en",
      confidence: "0.91",
      text: "Good morning.",
    }];
    const svc = new CaptureService({
      pool: { query: async function () { return { rows: rows, rowCount: rows.length }; } },
      gateway: {},
      signingSecret: "recording-signing-secret-that-is-long-enough",
      reviewSecret: "review-signing-secret-that-is-long-enough",
    });
    const segments = await svc.transcript(tenant, meetingId);
    assert.strictEqual(typeof segments[0].sequence, "number");
    assert.strictEqual(typeof segments[0].startMs, "number");
    assert.strictEqual(typeof segments[0].endMs, "number");
    assert.strictEqual(segments[0].endMs, 2400);
    // startMs of 0 must survive: a falsy-guard fallback would erase it.
    assert.strictEqual(segments[0].startMs, 0);
    assert.strictEqual(segments[0].confidence, 0.91);
    assert.strictEqual(segments[0].text, "Good morning.");
  });

  test("XLSX is presentable and reaches the rasterizer", async function () {
    const documents = require("../documents.js");
    const xlsx = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    assert.ok(documents.PRESENTABLE.has(xlsx), "xlsx must be presentable");
    // Presenting always goes through page rendering, never the original bytes.
    assert.ok(documents.CONVERTIBLE.has(xlsx), "xlsx must be convertible");
    assert.ok(!documents.DIRECT.has(xlsx), "xlsx must never be served as-is");
    for (const mime of documents.PRESENTABLE) {
      assert.ok(documents.CONVERTIBLE.has(mime), mime + " must be convertible");
    }
  });

  test("the whole PDF, PowerPoint and image family is presentable", async function () {
    const documents = require("../documents.js");
    const expected = ["application/pdf"]
      .concat(documents.PRESENTATION_MIMES, documents.IMAGE_MIMES);
    for (const mime of expected) {
      assert.ok(documents.PRESENTABLE.has(mime), mime + " must be presentable");
      assert.ok(documents.CONVERTIBLE.has(mime), mime + " must be convertible");
    }
    // Every non-macro PowerPoint variant, so a slideshow or template is not
    // silently refused the way the legacy .ppt was.
    for (const mime of [
      "application/vnd.ms-powerpoint",
      "application/vnd.openxmlformats-officedocument.presentationml.presentation",
      "application/vnd.openxmlformats-officedocument.presentationml.slideshow",
      "application/vnd.openxmlformats-officedocument.presentationml.template",
    ]) assert.ok(documents.PRESENTABLE.has(mime), mime + " must be presentable");
    // SVG is active content and there is no SVG delegate on these hosts, so it
    // must never join the image set however "image-like" it looks.
    for (const mime of ["image/svg+xml", "text/html", "application/zip"]) {
      assert.ok(!documents.PRESENTABLE.has(mime), mime + " must never be presentable");
    }
    // Macro-enabled Office mimes must not be presentable either - they are
    // refused at upload, so accepting them here would only be misleading.
    for (const mime of [
      "application/vnd.ms-powerpoint.presentation.macroEnabled.12",
      "application/vnd.ms-powerpoint.slideshow.macroEnabled.12",
    ]) assert.ok(!documents.PRESENTABLE.has(mime), mime + " must never be presentable");
  });

  test("every convertible non-PDF, non-image mime is routed to LibreOffice by the worker", async function () {
    // The cross-file guard. A mime the control plane accepts but the worker does
    // not recognise as an office document falls through to the PDF branch, where
    // pdfinfo fails and the user is told their file is invalid. This is exactly
    // how a presentable-but-unrenderable format ships unnoticed.
    const fs = require("fs");
    const pathMod = require("path");
    const documents = require("../documents.js");
    const source = fs.readFileSync(pathMod.join(__dirname, "..", "document-worker.js"), "utf8");
    const block = source.match(/const OFFICE_MIMES = new Set\(\[([\s\S]*?)\]\);/);
    assert.ok(block, "the worker must declare OFFICE_MIMES as a Set literal");
    const office = new Set((block[1].match(/"([^"]+)"/g) || []).map(function (q) { return q.slice(1, -1); }));
    const images = new Set(documents.IMAGE_MIMES);
    for (const mime of documents.CONVERTIBLE) {
      if (mime === "application/pdf" || images.has(mime)) continue;
      assert.ok(office.has(mime),
        mime + " is convertible but the worker would not send it to LibreOffice");
    }
    // ...and the reverse: an office mime the worker handles but the server never
    // accepts is dead code that hides a missing entry on the other side.
    for (const mime of office) {
      assert.ok(documents.CONVERTIBLE.has(mime),
        mime + " is routed to LibreOffice but is not convertible");
    }
  });

  test("the presentation start gate uses the one presentable list", async function () {
    // collaboration.js gates presentation/start on the attachment's stored mime.
    // It used to carry its OWN inline copy of the presentable list, and that copy
    // drifted twice - it never gained XLSX when that became presentable, nor the
    // legacy/OOXML PowerPoint variants. The result is the worst shape of failure:
    // the file uploads, converts and renders to pages, and is refused only at the
    // last step by a message blaming the file type.
    const fs = require("fs");
    const pathMod = require("path");
    const documents = require("../documents.js");
    const source = fs.readFileSync(pathMod.join(__dirname, "..", "collaboration.js"), "utf8");
    assert.ok(/require\("\.\/documents\.js"\)/.test(source),
      "collaboration.js must take the presentable list from documents.js");
    assert.ok(/PRESENTABLE\.has\(asset\.detected_mime\)/.test(source),
      "the start gate must test PRESENTABLE, not an inline list");
    // The drift detector: no document mime may be spelled out here at all.
    const literals = source.match(/"application\/(pdf|vnd\.[^"]+)"/g) || [];
    assert.deepStrictEqual(literals, [],
      "collaboration.js must not hardcode document mimes: " + literals.join(", "));
    // And the list it now uses really does cover what the user can prepare.
    for (const mime of documents.PRESENTABLE) {
      assert.ok(documents.CONVERTIBLE.has(mime), mime + " must be convertible");
    }
  });

  test("the worker renders exactly one frame per image page", async function () {
    // Without the "[0]" selector an animated GIF is written as an animated WebP
    // and `identify -format "%w %h"` then repeats the pair once per frame with
    // no separator, so the split reads a height of 150200 instead of 150. Both
    // call sites are pinned; this was measured on the host, not reasoned about.
    const fs = require("fs");
    const pathMod = require("path");
    const source = fs.readFileSync(pathMod.join(__dirname, "..", "document-worker.js"), "utf8");
    assert.ok(/COMMANDS\.convert, \[input \+ "\[0\]"/.test(source),
      "the image convert must select frame 0, or a multi-frame input yields an animated page");
    assert.ok(/COMMANDS\.identify, \["-format", "%w %h", file \+ "\[0\]"\]/.test(source),
      "identify must read frame 0, or a multi-frame page reports a garbage height");
  });

  for (const item of tests) {
    try {
      await item.fn();
      passed++;
      console.log("  ok   " + item.name);
    } catch (error) {
      console.error("  FAIL " + item.name);
      console.error(error && error.stack || error);
      process.exitCode = 1;
    }
  }
  console.log("passed: " + passed + "/" + tests.length);
})();
