/** Strict persistent capture, transcript, summary, review and recording storage. */

const crypto = require("crypto");
const fs = require("fs");
const fsp = fs.promises;
const path = require("path");

const LINK_TTL_SECONDS = 15 * 60;
const REVIEW_WINDOW_SECONDS = 24 * 60 * 60;

class CaptureError extends Error {
  constructor(message, code, statusCode, details) {
    super(message);
    this.name = "CaptureError";
    this.code = code || "CAPTURE_ERROR";
    this.statusCode = statusCode || 400;
    this.details = details || null;
  }
}

function uuid(value) {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(String(value || ""));
}

function hash(value) {
  return crypto.createHash("sha256").update(JSON.stringify(value)).digest("hex");
}

/**
 * Normalise one PostgreSQL `bigint` for JSON.
 *
 * node-postgres hands `bigint` (OID 20) back as a **string**, because an int8
 * does not always survive an IEEE-754 double. Every column below is a byte
 * count, a millisecond offset or a monotonic sequence — all far inside the safe
 * range — so a number is both correct and what the API contract declares.
 *
 * Leaving them as strings is not a cosmetic wart: a client that reads the field
 * as a number sees a type it cannot use. `meet_recordings.bytes` reached the
 * Flutter client as `"1524420"` and its `as num?` cast threw, so a recording
 * that had been captured, verified and stored rendered as a failed section with
 * a Retry button. `collaboration.js` has always normalised its own bigints with
 * the same helper; these two readers were the gap.
 */
function asInt(value, fallback) {
  if (value === null || value === undefined) return fallback;
  const number = Number(value);
  return Number.isSafeInteger(number) ? number : fallback;
}

/**
 * Languages a transcription may be pinned to, plus `auto` for detection.
 *
 * The client offers exactly this set, so an unknown code is a client bug and is
 * refused rather than silently downgraded to `auto` — a host who picked Tagalog
 * and got English-detected audio back would have no way to tell.
 *
 * Codes are the ones the Whisper worker accepts verbatim; keep this list and
 * `kMeetTranscriptionLanguages` in the Flutter package in step.
 */
const TRANSCRIPTION_LANGUAGES = [
  "auto", "en", "tl", "es", "pt", "fr", "de", "it", "nl", "ru", "tr",
  "ar", "hi", "id", "ms", "vi", "th", "ja", "ko", "zh",
];

function normalizeLanguage(value) {
  if (value === null || value === undefined || value === "") return "auto";
  const code = String(value).trim().toLowerCase();
  if (TRANSCRIPTION_LANGUAGES.indexOf(code) === -1) {
    throw new CaptureError("Unsupported transcription language", "VALIDATION_ERROR", 400);
  }
  return code;
}

function stateJson(row) {
  if (!row) return {
    version: 0,
    recordingId: null,
    recordingStatus: "idle",
    transcriptionStatus: "idle",
    transcriptionLanguage: "auto",
    updatedAt: null,
  };
  return {
    version: Number(row.version || 0),
    recordingId: row.recording_id || null,
    recordingStatus: row.recording_status || "idle",
    transcriptionStatus: row.transcription_status || "idle",
    transcriptionLanguage: row.transcription_language || "auto",
    updatedAt: row.updated_at ? new Date(row.updated_at).toISOString() : null,
  };
}

class CaptureService {
  constructor(opts) {
    const o = opts || {};
    if (!o.pool) throw new TypeError("CaptureService requires a PostgreSQL pool");
    if (!o.gateway) throw new TypeError("CaptureService requires a gateway");
    this.pool = o.pool;
    this.gateway = o.gateway;
    this.root = path.resolve(o.root || "/var/lib/ringq/meet-recordings");
    this.signingSecret = String(o.signingSecret || "");
    this.reviewSecret = String(o.reviewSecret || "");
    this.publicBase = String(o.publicBase || "/meet/api/v1");
    this.transcriberUrl = String(o.transcriberUrl || "").replace(/\/+$/, "");
    this.transcriberWsUrl = String(o.transcriberWsUrl || "ws://127.0.0.1:7880");
    this.transcriberSecret = String(o.transcriberSecret || "");
    this.transcriptCallbackUrl = String(o.transcriptCallbackUrl || "");
    this.segmentMaxMs = Math.max(60000, Number(o.segmentMaxMs || 4 * 60 * 60 * 1000));
    this.minimumFreeBytes = Math.max(256 * 1024 * 1024, Number(o.minimumFreeBytes || 2 * 1024 * 1024 * 1024));
    this.segmentTimers = new Map();
    this.log = o.logger || { info: function () {}, warn: function () {}, error: function () {} };
  }

  async health() {
    try {
      if (this.signingSecret.length < 32 || this.reviewSecret.length < 32) return false;
      await fsp.mkdir(this.root, { recursive: true, mode: 0o750 });
      await fsp.access(this.root, fs.constants.R_OK | fs.constants.W_OK | fs.constants.X_OK);
      const result = await this.pool.query(
        `SELECT to_regclass('public.meet_capture_states') IS NOT NULL AS state_ok,
                to_regclass('public.meet_capture_actions') IS NOT NULL AS action_ok`
      );
      return result.rows[0] && result.rows[0].state_ok === true && result.rows[0].action_ok === true;
    } catch (err) {
      this.log.warn("meet: capture health failed: " + err.message);
      return false;
    }
  }

  async getMeeting(tenant, meetingId) {
    const result = await this.pool.query(
      `SELECT id, owner_key, status, started_at, ended_at, end_reason, ended_by_ref, review_nonce
         FROM public.meet_meetings WHERE id=$1 AND domain_uuid=$2`,
      [meetingId, tenant.domainUuid]
    );
    return result.rows[0] || null;
  }

  async getState(tenant, meetingId) {
    const result = await this.pool.query(
      `SELECT * FROM public.meet_capture_states WHERE meeting_id=$1 AND domain_uuid=$2`,
      [meetingId, tenant.domainUuid]
    );
    return stateJson(result.rows[0]);
  }

  async _transaction(fn) {
    const client = await this.pool.connect();
    try {
      await client.query("BEGIN");
      const value = await fn(client);
      await client.query("COMMIT");
      return value;
    } catch (err) {
      try { await client.query("ROLLBACK"); } catch (_) {}
      throw err;
    } finally {
      client.release();
    }
  }

  async _lockedState(client, tenant, meetingId) {
    await client.query(
      `INSERT INTO public.meet_capture_states (meeting_id, domain_uuid)
       VALUES ($1,$2) ON CONFLICT (meeting_id) DO NOTHING`,
      [meetingId, tenant.domainUuid]
    );
    const result = await client.query(
      `SELECT * FROM public.meet_capture_states
        WHERE meeting_id=$1 AND domain_uuid=$2 FOR UPDATE`,
      [meetingId, tenant.domainUuid]
    );
    if (!result.rows[0]) throw new CaptureError("Meeting not found", "NOT_FOUND", 404);
    return result.rows[0];
  }

  async _duplicate(client, tenant, meetingId, actorRef, clientActionId, requestHash) {
    const result = await client.query(
      `SELECT request_hash, result FROM public.meet_capture_actions
        WHERE domain_uuid=$1 AND meeting_id=$2 AND actor_ref=$3 AND client_action_id=$4`,
      [tenant.domainUuid, meetingId, actorRef, clientActionId]
    );
    if (!result.rows[0]) return null;
    if (result.rows[0].request_hash !== requestHash) {
      throw new CaptureError("clientActionId was reused with different content", "IDEMPOTENCY_CONFLICT", 409);
    }
    return Object.assign({ duplicate: true }, result.rows[0].result || {});
  }

  async _action(client, tenant, meetingId, actorRef, clientActionId, kind, requestHash, version, result) {
    await client.query(
      `INSERT INTO public.meet_capture_actions
         (meeting_id,domain_uuid,actor_ref,client_action_id,kind,request_hash,resulting_version,result)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8::jsonb)`,
      [meetingId, tenant.domainUuid, actorRef, clientActionId, kind, requestHash, version, JSON.stringify(result)]
    );
  }

  _checkAction(input) {
    if (!uuid(input.clientActionId)) throw new CaptureError("clientActionId must be a UUID", "VALIDATION_ERROR", 400);
    if (!Number.isSafeInteger(input.expectedVersion) || input.expectedVersion < 0) {
      throw new CaptureError("expectedVersion must be a non-negative integer", "VALIDATION_ERROR", 400);
    }
  }

  async beginRecording(tenant, input) {
    this._checkAction(input);
    try {
      await fsp.mkdir(this.root, { recursive: true, mode: 0o750 });
      const disk = await fsp.statfs(this.root);
      const available = Number(disk.bavail) * Number(disk.bsize);
      if (!Number.isFinite(available) || available < this.minimumFreeBytes) {
        throw new CaptureError("Recording storage has insufficient free space", "RECORDING_LOW_DISK", 503);
      }
    } catch (error) {
      if (error instanceof CaptureError) throw error;
      throw new CaptureError("Recording storage is unavailable", "RECORDING_STORAGE_UNAVAILABLE", 503);
    }
    const requestHash = hash({ kind: "recording_start", expectedVersion: input.expectedVersion });
    return this._transaction(async (client) => {
      await client.query("SELECT pg_advisory_xact_lock(hashtext('ringq-meet-capture-slot'))");
      const duplicate = await this._duplicate(client, tenant, input.meetingId, input.actorRef, input.clientActionId, requestHash);
      if (duplicate) return duplicate;
      const state = await this._lockedState(client, tenant, input.meetingId);
      if (Number(state.version) !== input.expectedVersion) {
        throw new CaptureError("Capture state changed", "VERSION_CONFLICT", 409, { current: stateJson(state) });
      }
      if (["starting", "active", "stopping"].indexOf(state.recording_status) !== -1) {
        throw new CaptureError("A recording is already active", "RECORDING_ACTIVE", 409);
      }
      if (await this._slotHeldElsewhere(client, input.meetingId)) {
        throw new CaptureError("Capture capacity is in use", "CAPTURE_CAPACITY", 503);
      }
      const recordingId = crypto.randomUUID();
      const storagePath = path.join(this.root, tenant.tenantKey, input.meetingId, recordingId + ".mp4");
      await client.query(
        `INSERT INTO public.meet_recordings
          (id,meeting_id,domain_uuid,client_action_id,request_hash,kind,status,storage_path,original_name,mime,created_at)
         VALUES ($1,$2,$3,$4,$5,'video','starting',$6,$7,'video/mp4',now())`,
        [recordingId, input.meetingId, tenant.domainUuid, input.clientActionId, requestHash, storagePath,
          "ringq-meeting-" + input.meetingId + ".mp4"]
      );
      // Recording touches `recording_status` and nothing else. It used to move
      // transcription to 'starting' in this same statement whenever the tenant
      // had the transcription feature on, so one press of Record silently
      // started a second capture the host had not asked for — and stopping the
      // recording then had to ask whether to keep it. The two captures are
      // independent: each is started and stopped by its own control.
      const updated = await client.query(
        `UPDATE public.meet_capture_states SET version=version+1,recording_id=$3,
             recording_status='starting',updated_at=now()
           WHERE meeting_id=$1 AND domain_uuid=$2 RETURNING *`,
        [input.meetingId, tenant.domainUuid, recordingId]
      );
      const result = { state: stateJson(updated.rows[0]), recordingId: recordingId, duplicate: false };
      await this._action(client, tenant, input.meetingId, input.actorRef, input.clientActionId,
        "recording_start", requestHash, result.state.version, result);
      return result;
    });
  }

  async activateRecording(tenant, meetingId, recordingId) {
    const meetingRoot = path.join(this.root, tenant.tenantKey, meetingId);
    // Egress writes with the shared root group. Grant group write only to this
    // server-generated meeting directory while capture is active.
    await fsp.mkdir(meetingRoot, { recursive: true, mode: 0o770 });
    await fsp.chmod(meetingRoot, 0o770);
    let result;
    try {
      result = await this.gateway.startRecording(tenant, meetingId, recordingId);
    } catch (err) {
      await this.failRecording(tenant, meetingId, recordingId, err.code || "egress_start_failed");
      throw err;
    }
    await this.pool.query(
      `UPDATE public.meet_recordings SET egress_id=$4,status='active',started_at=COALESCE(started_at,now())
        WHERE id=$1 AND meeting_id=$2 AND domain_uuid=$3 AND status='starting'`,
      [recordingId, meetingId, tenant.domainUuid, result.egressId]
    );
    const state = await this.pool.query(
      `UPDATE public.meet_capture_states SET version=version+1,recording_status='active',updated_at=now()
        WHERE meeting_id=$1 AND domain_uuid=$2 AND recording_id=$3 RETURNING *`,
      [meetingId, tenant.domainUuid, recordingId]
    );
    const timer = setTimeout(async () => {
      this.segmentTimers.delete(recordingId);
      try {
        const row = await this.pool.query(
          `SELECT egress_id FROM public.meet_recordings WHERE id=$1 AND meeting_id=$2 AND domain_uuid=$3 AND status='active'`,
          [recordingId, meetingId, tenant.domainUuid]
        );
        if (row.rows[0] && row.rows[0].egress_id) {
          await this.gateway.stopRecording(tenant, meetingId, row.rows[0].egress_id);
        }
      } catch (error) {
        this.log.warn("meet: recording duration stop failed: " + error.message);
      }
    }, this.segmentMaxMs);
    timer.unref();
    this.segmentTimers.set(recordingId, timer);
    return { state: stateJson(state.rows[0]), recordingId: recordingId, egressId: result.egressId };
  }

  /**
   * Whether another meeting is holding the server-wide capture slot.
   *
   * The slot covers recording or transcription, one at a time, for the whole
   * box — so anything able to hold it indefinitely is an outage for every other
   * meeting, and that is exactly what happened: an abandoned capture refused
   * every later `recording/start` with CAPTURE_CAPACITY until the process
   * restarted. Two escapes make it self-healing:
   *
   * - A capture belonging to a meeting that has already ended is not in use.
   * - No capture may outlive one recording segment. The timer that would have
   *   stopped it lives in process memory, so a restart orphans it permanently;
   *   past `segmentMaxMs` the row is abandoned by definition.
   *
   * Deliberately not time-based alone: a legitimately long recording inside its
   * segment window must still hold the slot.
   */
  async _slotHeldElsewhere(client, meetingId) {
    const busy = await client.query(
      `SELECT s.meeting_id FROM public.meet_capture_states s
         LEFT JOIN public.meet_meetings m
           ON m.id = s.meeting_id AND m.domain_uuid = s.domain_uuid
        WHERE s.meeting_id<>$1
          AND (s.recording_status IN ('starting','active','stopping')
            OR s.transcription_status IN ('starting','active','stopping'))
          AND COALESCE(m.status,'') <> 'ended'
          AND s.updated_at > now() - ($2::bigint * interval '1 millisecond')
        LIMIT 1`,
      [meetingId, Math.round(this.segmentMaxMs)]
    );
    return !!busy.rows[0];
  }

  async failRecording(tenant, meetingId, recordingId, code) {
    await this.pool.query(
      `UPDATE public.meet_recordings SET status='failed',failure_code=$4,ended_at=now()
        WHERE id=$1 AND meeting_id=$2 AND domain_uuid=$3 AND status IN ('starting','active','stopping')`,
      [recordingId, meetingId, tenant.domainUuid, String(code || "recording_failed").slice(0, 120)]
    );
    // Only the recording fails. This used to also fail a transcription the
    // recording had chained on, because `beginRecording` moved transcription to
    // 'starting' in the same statement and a failed activation left it stuck
    // there forever — holding the server-wide capture slot. Recording no longer
    // starts a transcription at all, so a live transcription here belongs to the
    // host's own separate press and must survive a recording that could not
    // start.
    const result = await this.pool.query(
      `UPDATE public.meet_capture_states
          SET version=version+1,recording_status='failed',updated_at=now()
        WHERE meeting_id=$1 AND domain_uuid=$2 AND recording_id=$3 RETURNING *`,
      [meetingId, tenant.domainUuid, recordingId]
    );
    return stateJson(result.rows[0]);
  }

  /// Release capture state for a meeting that is over.
  ///
  /// Called when the SFU reports the room finished. Without it, a meeting that
  /// ended while recording keeps its non-terminal status forever, and because
  /// the capacity slot is server-wide that one row blocks recording for every
  /// other meeting on the box.
  async releaseForEndedMeeting(tenant, meetingId) {
    const active = await this.pool.query(
      `SELECT recording_id FROM public.meet_capture_states
        WHERE meeting_id=$1 AND domain_uuid=$2
          AND (recording_status IN ('starting','active','stopping')
            OR transcription_status IN ('starting','active','stopping'))`,
      [meetingId, tenant.domainUuid]
    );
    if (!active.rows[0]) return null;
    const recordingId = active.rows[0].recording_id;
    if (recordingId) {
      const timer = this.segmentTimers.get(recordingId);
      if (timer) {
        clearTimeout(timer);
        this.segmentTimers.delete(recordingId);
      }
      const row = await this.pool.query(
        `SELECT egress_id FROM public.meet_recordings
          WHERE id=$1 AND meeting_id=$2 AND domain_uuid=$3 AND status IN ('starting','active')`,
        [recordingId, meetingId, tenant.domainUuid]
      );
      if (row.rows[0] && row.rows[0].egress_id) {
        // Ask Egress to stop so the MP4 is finalized and the webhook completes
        // the row normally. A failure here must not stop the slot being freed.
        try {
          await this.gateway.stopRecording(tenant, meetingId, row.rows[0].egress_id);
        } catch (error) {
          this.log.warn("meet: ended-meeting recording stop failed: " + error.message);
        }
      }
    }
    await this.pool.query(
      `UPDATE public.meet_recordings SET status='failed',failure_code='meeting_ended',ended_at=COALESCE(ended_at,now())
        WHERE meeting_id=$1 AND domain_uuid=$2 AND status IN ('starting','active','stopping')`,
      [meetingId, tenant.domainUuid]
    );
    const state = await this.pool.query(
      `UPDATE public.meet_capture_states
          SET version=version+1,
              recording_status=CASE WHEN recording_status IN ('starting','active','stopping')
                THEN 'failed' ELSE recording_status END,
              transcription_status=CASE WHEN transcription_status IN ('starting','active','stopping')
                THEN 'failed' ELSE transcription_status END,
              updated_at=now()
        WHERE meeting_id=$1 AND domain_uuid=$2 RETURNING *`,
      [meetingId, tenant.domainUuid]
    );
    return state.rows[0] ? stateJson(state.rows[0]) : null;
  }

  async stopRecording(tenant, input) {
    this._checkAction(input);
    const requestHash = hash({ kind: "recording_stop", expectedVersion: input.expectedVersion });
    const reserved = await this._transaction(async (client) => {
      const duplicate = await this._duplicate(client, tenant, input.meetingId, input.actorRef, input.clientActionId, requestHash);
      if (duplicate) return duplicate;
      const state = await this._lockedState(client, tenant, input.meetingId);
      if (Number(state.version) !== input.expectedVersion) {
        throw new CaptureError("Capture state changed", "VERSION_CONFLICT", 409, { current: stateJson(state) });
      }
      if (!["starting", "active"].includes(state.recording_status) || !state.recording_id) {
        throw new CaptureError("No recording is active", "RECORDING_NOT_ACTIVE", 409);
      }
      // Stopping the recording never stops a transcription. There is no longer
      // any such thing as a transcription the recording owns, so the host is no
      // longer asked "keep live transcription running?" for a capture they
      // started themselves and can stop with its own control.
      const updated = await client.query(
        `UPDATE public.meet_capture_states SET version=version+1,recording_status='stopping',
             updated_at=now() WHERE meeting_id=$1 AND domain_uuid=$2 RETURNING *`,
        [input.meetingId, tenant.domainUuid]
      );
      await client.query(`UPDATE public.meet_recordings SET status='stopping' WHERE id=$1`, [state.recording_id]);
      const result = { state: stateJson(updated.rows[0]), recordingId: state.recording_id, duplicate: false };
      await this._action(client, tenant, input.meetingId, input.actorRef, input.clientActionId,
        "recording_stop", requestHash, result.state.version, result);
      return result;
    });
    if (reserved.duplicate) return reserved;
    const row = await this.pool.query(`SELECT egress_id FROM public.meet_recordings WHERE id=$1`, [reserved.recordingId]);
    if (row.rows[0] && row.rows[0].egress_id) {
      await this.gateway.stopRecording(tenant, input.meetingId, row.rows[0].egress_id);
    } else {
      await this.finalizeRecording(tenant, input.meetingId, reserved.recordingId, "complete");
    }
    return reserved;
  }

  async finalizeRecording(tenant, meetingId, recordingId, status, metadata) {
    const segmentTimer = this.segmentTimers.get(recordingId);
    if (segmentTimer) clearTimeout(segmentTimer);
    this.segmentTimers.delete(recordingId);
    const row = await this.pool.query(
      `SELECT storage_path FROM public.meet_recordings WHERE id=$1 AND meeting_id=$2 AND domain_uuid=$3`,
      [recordingId, meetingId, tenant.domainUuid]
    );
    if (!row.rows[0]) return null;
    let bytes = null;
    let digest = null;
    let finalStatus = status === "failed" ? "failed" : "complete";
    if (finalStatus === "complete") {
      try {
        const resolved = path.resolve(row.rows[0].storage_path);
        const expectedRoot = path.join(this.root, tenant.tenantKey, meetingId) + path.sep;
        if (!resolved.startsWith(expectedRoot)) throw new Error("recording path escaped meeting root");
        const partial = resolved.replace(/\.mp4$/i, ".partial.mp4");
        try {
          await fsp.access(partial, fs.constants.R_OK);
          await fsp.rename(partial, resolved);
        } catch (error) {
          if (error.code !== "ENOENT") throw error;
        }
        const stat = await fsp.stat(resolved);
        bytes = stat.size;
        digest = await new Promise((resolve, reject) => {
          const h = crypto.createHash("sha256");
          fs.createReadStream(resolved).on("data", (chunk) => h.update(chunk)).on("error", reject).on("end", () => resolve(h.digest("hex")));
        });
        await fsp.chmod(resolved, 0o640);
      } catch (err) {
        finalStatus = "failed";
        this.log.warn("meet: recording verification failed: " + err.message);
      }
    }
    await this.pool.query(
      `UPDATE public.meet_recordings SET status=$4,ended_at=now(),bytes=$5,sha256=$6,
         failure_code=CASE WHEN $4='failed' THEN COALESCE($7,'recording_verification_failed') ELSE NULL END
        WHERE id=$1 AND meeting_id=$2 AND domain_uuid=$3`,
      [recordingId, meetingId, tenant.domainUuid, finalStatus, bytes, digest, metadata && metadata.failureCode]
    );
    // Finalization is about the MP4. A transcription in 'stopping' is completed
    // by `stopTranscriber`, which owns that lifecycle; completing it from here
    // would report a transcription as finished because a recording happened to
    // finalize at the same moment.
    const state = await this.pool.query(
      `UPDATE public.meet_capture_states SET version=version+1,recording_status=$4,updated_at=now()
        WHERE meeting_id=$1 AND domain_uuid=$2 AND recording_id=$3 RETURNING *`,
      [meetingId, tenant.domainUuid, recordingId, finalStatus]
    );
    try {
      await fsp.chmod(path.join(this.root, tenant.tenantKey, meetingId), 0o750);
    } catch (error) {
      this.log.warn("meet: recording directory restriction failed: " + error.message);
    }
    return stateJson(state.rows[0]);
  }

  async endMeeting(tenant, meetingId) {
    const state = await this.getState(tenant, meetingId);
    if (["starting", "active", "stopping"].includes(state.recordingStatus) && state.recordingId) {
      const row = await this.pool.query(
        `SELECT egress_id FROM public.meet_recordings WHERE id=$1 AND meeting_id=$2 AND domain_uuid=$3`,
        [state.recordingId, meetingId, tenant.domainUuid]
      );
      if (row.rows[0] && row.rows[0].egress_id) {
        try { await this.gateway.stopRecording(tenant, meetingId, row.rows[0].egress_id); }
        catch (err) { this.log.warn("meet: end-of-room egress stop failed: " + err.message); }
      }
    }
    if (["starting", "active", "stopping"].includes(state.transcriptionStatus)) {
      await this.stopTranscriber(tenant, meetingId);
    }
    return this.getState(tenant, meetingId);
  }

  async setTranscription(tenant, input, enabled) {
    this._checkAction(input);
    // Only a start carries a language. Validate before the transaction so a bad
    // code costs no lock and no version bump.
    const language = enabled ? normalizeLanguage(input.language) : null;
    const kind = enabled ? "transcription_start" : "transcription_stop";
    const requestHash = hash({ kind: kind, expectedVersion: input.expectedVersion, language: language });
    return this._transaction(async (client) => {
      await client.query("SELECT pg_advisory_xact_lock(hashtext('ringq-meet-capture-slot'))");
      const duplicate = await this._duplicate(client, tenant, input.meetingId, input.actorRef, input.clientActionId, requestHash);
      if (duplicate) return duplicate;
      const state = await this._lockedState(client, tenant, input.meetingId);
      if (Number(state.version) !== input.expectedVersion) {
        throw new CaptureError("Capture state changed", "VERSION_CONFLICT", 409, { current: stateJson(state) });
      }
      if (enabled && await this._slotHeldElsewhere(client, input.meetingId)) {
        throw new CaptureError("Capture capacity is in use", "CAPTURE_CAPACITY", 503);
      }
      const next = enabled ? "starting" : "stopping";
      // A stop keeps the language it ran with, so the transcript and the state
      // the client renders still name what was transcribed.
      const updated = await client.query(
        `UPDATE public.meet_capture_states SET version=version+1,transcription_status=$3,
           transcription_language=COALESCE($4,transcription_language),updated_at=now()
         WHERE meeting_id=$1 AND domain_uuid=$2 RETURNING *`,
        [input.meetingId, tenant.domainUuid, next, language]
      );
      const result = { state: stateJson(updated.rows[0]), duplicate: false };
      await this._action(client, tenant, input.meetingId, input.actorRef, input.clientActionId,
        kind, requestHash, result.state.version, result);
      return result;
    });
  }

  async markTranscription(tenant, meetingId, status) {
    const allowed = ["active", "complete", "failed", "unavailable"];
    if (!allowed.includes(status)) throw new CaptureError("Invalid transcription status", "VALIDATION_ERROR", 400);
    const result = await this.pool.query(
      `UPDATE public.meet_capture_states SET version=version+1,transcription_status=$3,updated_at=now()
        WHERE meeting_id=$1 AND domain_uuid=$2 RETURNING *`,
      [meetingId, tenant.domainUuid, status]
    );
    return stateJson(result.rows[0]);
  }

  async startTranscriber(tenant, meetingId) {
    if (!this.transcriberUrl || !this.transcriberSecret || !this.transcriptCallbackUrl) {
      return this.markTranscription(tenant, meetingId, "unavailable");
    }
    try {
      // Read the language back from the committed state rather than threading it
      // through the caller: `setTranscription` is the only writer, and reading it
      // here keeps a reconciled or retried start on the host's original choice.
      const stored = await this.getState(tenant, meetingId);
      const language = stored.transcriptionLanguage || "auto";
      const identity = "transcriber-" + meetingId;
      const media = await this.gateway.mintToken(tenant, {
        meetingId: meetingId,
        identity: identity,
        name: "RingQ transcription",
        role: "participant",
        canPublish: false,
        canSubscribe: true,
        hidden: true,
      });
      const response = await fetch(this.transcriberUrl + "/v1/jobs", {
        method: "POST",
        headers: { "Content-Type": "application/json", Authorization: "Bearer " + this.transcriberSecret },
        body: JSON.stringify({
          tenantKey: tenant.tenantKey,
          tenantHost: tenant.subdomain,
          meetingId: meetingId,
          serverUrl: this.transcriberWsUrl,
          token: media.token,
          callbackUrl: this.transcriptCallbackUrl,
          callbackToken: this.transcriberSecret,
          language: language,
        }),
        signal: AbortSignal.timeout(8000),
      });
      if (!response.ok) throw new Error("transcriber returned " + response.status);
      return this.markTranscription(tenant, meetingId, "active");
    } catch (err) {
      this.log.warn("meet: transcriber start failed: " + err.message);
      return this.markTranscription(tenant, meetingId, "unavailable");
    }
  }

  async stopTranscriber(tenant, meetingId) {
    if (this.transcriberUrl && this.transcriberSecret) {
      try {
        await fetch(this.transcriberUrl + "/v1/jobs/" + encodeURIComponent(meetingId), {
          method: "DELETE",
          headers: { Authorization: "Bearer " + this.transcriberSecret },
          signal: AbortSignal.timeout(8000),
        });
      } catch (err) {
        this.log.warn("meet: transcriber stop failed: " + err.message);
      }
    }
    return this.markTranscription(tenant, meetingId, "complete");
  }

  async ingestTranscript(tenant, input) {
    if (!uuid(input.meetingId) || !uuid(input.segmentId)) throw new CaptureError("Invalid transcript identity", "VALIDATION_ERROR", 400);
    const text = String(input.text || "").trim();
    if (!text || Buffer.byteLength(text, "utf8") > 8192) throw new CaptureError("Transcript text is invalid", "VALIDATION_ERROR", 400);
    const participant = await this.pool.query(
      `SELECT display_name FROM public.meet_participants WHERE meeting_id=$1 AND domain_uuid=$2 AND participant_ref=$3
        ORDER BY joined_at DESC NULLS LAST LIMIT 1`,
      [input.meetingId, tenant.domainUuid, input.participantRef]
    );
    if (!participant.rows[0]) throw new CaptureError("Transcript speaker is not admitted", "FORBIDDEN", 403);
    const result = await this.pool.query(
      `INSERT INTO public.meet_transcripts
        (meeting_id,domain_uuid,segment_id,participant_ref,participant_name,participant,text,track_sid,sequence,start_ms,end_ms,language,confidence,is_final,at,created_at)
       VALUES ($1,$2,$3,$4,$5,$5,$6,$7,$8,$9,$10,$11,$12,true,now(),now())
       ON CONFLICT (domain_uuid,meeting_id,track_sid,segment_id) WHERE segment_id IS NOT NULL DO NOTHING
       RETURNING id`,
      [input.meetingId, tenant.domainUuid, input.segmentId, String(input.participantRef), participant.rows[0].display_name,
        text, String(input.trackSid || ""), Number(input.sequence || 0), Number(input.startMs || 0), Number(input.endMs || 0),
        String(input.language || "und").slice(0, 16), input.confidence == null ? null : Number(input.confidence)]
    );
    return { accepted: result.rowCount === 1, participantName: participant.rows[0].display_name };
  }

  async transcript(tenant, meetingId) {
    const result = await this.pool.query(
      `SELECT segment_id AS "segmentId",participant_ref AS "participantId",participant_name AS "participantName",
              track_sid AS "trackSid",sequence,start_ms AS "startMs",end_ms AS "endMs",language,confidence,text,at
         FROM public.meet_transcripts WHERE meeting_id=$1 AND domain_uuid=$2 AND is_final=true
        ORDER BY sequence,at,id`,
      [meetingId, tenant.domainUuid]
    );
    return (result.rows || []).map(function (row) {
      return Object.assign({}, row, {
        sequence: asInt(row.sequence, 0),
        startMs: asInt(row.startMs, 0),
        endMs: asInt(row.endMs, 0),
        confidence: row.confidence == null ? null : Number(row.confidence),
      });
    });
  }

  async summary(tenant, meetingId) {
    const result = await this.pool.query(
      `SELECT status,structured,plain_text AS "plainText",provider,model,version,failure_code AS "failureCode",updated_at AS "updatedAt"
         FROM public.meet_summaries WHERE meeting_id=$1 AND domain_uuid=$2`,
      [meetingId, tenant.domainUuid]
    );
    return result.rows[0] || { status: "unavailable", structured: {}, plainText: null, version: 0 };
  }

  async retrySummary(tenant, meetingId) {
    const result = await this.pool.query(
      `INSERT INTO public.meet_summaries (meeting_id,domain_uuid,html,status,structured,updated_at)
       VALUES ($1,$2,'','pending','{}'::jsonb,now())
       ON CONFLICT (domain_uuid,meeting_id) DO UPDATE SET status='pending',failure_code=NULL,
         version=meet_summaries.version+1,updated_at=now()
       RETURNING status,version,updated_at AS "updatedAt"`,
      [meetingId, tenant.domainUuid]
    );
    return result.rows[0];
  }

  async reviewToken(tenant, meetingId, participantId) {
    const meeting = await this.getMeeting(tenant, meetingId);
    if (!meeting || !meeting.review_nonce) return null;
    const payload = Buffer.from(JSON.stringify({ t: tenant.tenantKey, m: meetingId, p: participantId, n: meeting.review_nonce, i: Math.floor(Date.now() / 1000) })).toString("base64url");
    const mac = crypto.createHmac("sha256", this.reviewSecret).update(payload).digest("base64url");
    return payload + "." + mac;
  }

  verifyReviewToken(tenant, token) {
    const parts = String(token || "").split(".");
    if (parts.length !== 2) throw new CaptureError("Review token is invalid", "AUTH_INVALID_REVIEW", 401);
    const expected = crypto.createHmac("sha256", this.reviewSecret).update(parts[0]).digest();
    let actual;
    try { actual = Buffer.from(parts[1], "base64url"); } catch (_) { actual = Buffer.alloc(0); }
    if (actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) {
      throw new CaptureError("Review token is invalid", "AUTH_INVALID_REVIEW", 401);
    }
    let payload;
    try { payload = JSON.parse(Buffer.from(parts[0], "base64url").toString("utf8")); } catch (_) {
      throw new CaptureError("Review token is invalid", "AUTH_INVALID_REVIEW", 401);
    }
    if (payload.t !== tenant.tenantKey || !uuid(payload.m) || !payload.p || !uuid(payload.n)) {
      throw new CaptureError("Review token belongs to another tenant", "AUTH_INVALID_REVIEW", 403);
    }
    return payload;
  }

  async saveReview(tenant, payload, rating, comment) {
    const meeting = await this.getMeeting(tenant, payload.m);
    if (!meeting || meeting.review_nonce !== payload.n || meeting.end_reason !== "host_ended" || !meeting.ended_at) {
      throw new CaptureError("Reviews are available only after the host ends the meeting", "REVIEW_UNAVAILABLE", 409);
    }
    if (Date.now() > new Date(meeting.ended_at).getTime() + REVIEW_WINDOW_SECONDS * 1000) {
      throw new CaptureError("The review window has expired", "REVIEW_EXPIRED", 410);
    }
    const participant = await this.pool.query(
      `SELECT display_name,role FROM public.meet_participants WHERE meeting_id=$1 AND domain_uuid=$2 AND participant_ref=$3
        ORDER BY joined_at DESC NULLS LAST LIMIT 1`,
      [payload.m, tenant.domainUuid, payload.p]
    );
    if (!participant.rows[0] || participant.rows[0].role === "host") {
      throw new CaptureError("Only admitted non-host attendees may review", "FORBIDDEN", 403);
    }
    const result = await this.pool.query(
      `INSERT INTO public.meet_feedback (meeting_id,domain_uuid,participant_ref,participant_name,rating,comment,created_at,updated_at)
       VALUES ($1,$2,$3,$4,$5,$6,now(),now())
       ON CONFLICT (domain_uuid,meeting_id,participant_ref) WHERE participant_ref IS NOT NULL
       DO UPDATE SET rating=EXCLUDED.rating,comment=EXCLUDED.comment,participant_name=EXCLUDED.participant_name,updated_at=now()
       RETURNING rating,comment,created_at AS "createdAt",updated_at AS "updatedAt"`,
      [payload.m, tenant.domainUuid, payload.p, participant.rows[0].display_name, rating, comment || null]
    );
    return result.rows[0];
  }

  async ownReview(tenant, payload) {
    const meeting = await this.getMeeting(tenant, payload.m);
    if (!meeting || meeting.review_nonce !== payload.n || meeting.end_reason !== "host_ended" || !meeting.ended_at) {
      throw new CaptureError("Reviews are unavailable", "REVIEW_UNAVAILABLE", 409);
    }
    if (Date.now() > new Date(meeting.ended_at).getTime() + REVIEW_WINDOW_SECONDS * 1000) {
      throw new CaptureError("The review window has expired", "REVIEW_EXPIRED", 410);
    }
    const admitted = await this.pool.query(
      `SELECT role FROM public.meet_participants WHERE meeting_id=$1 AND domain_uuid=$2 AND participant_ref=$3
        ORDER BY joined_at DESC NULLS LAST LIMIT 1`,
      [payload.m, tenant.domainUuid, payload.p]
    );
    if (!admitted.rows[0] || admitted.rows[0].role === "host") {
      throw new CaptureError("Only admitted non-host attendees may review", "FORBIDDEN", 403);
    }
    const result = await this.pool.query(
      `SELECT rating,comment,created_at AS "createdAt",updated_at AS "updatedAt"
         FROM public.meet_feedback WHERE domain_uuid=$1 AND meeting_id=$2 AND participant_ref=$3`,
      [tenant.domainUuid, payload.m, payload.p]
    );
    return result.rows[0] || null;
  }

  async reviews(tenant, meetingId) {
    const result = await this.pool.query(
      `SELECT participant_ref AS "participantId",participant_name AS "participantName",rating,comment,
              created_at AS "createdAt",updated_at AS "updatedAt"
         FROM public.meet_feedback WHERE domain_uuid=$1 AND meeting_id=$2 ORDER BY created_at`,
      [tenant.domainUuid, meetingId]
    );
    return result.rows;
  }

  async recordings(tenant, meetingId) {
    const result = await this.pool.query(
      `SELECT id,status,original_name AS "fileName",bytes,sha256,mime,started_at AS "startedAt",ended_at AS "endedAt",failure_code AS "failureCode"
         FROM public.meet_recordings WHERE domain_uuid=$1 AND meeting_id=$2 ORDER BY created_at`,
      [tenant.domainUuid, meetingId]
    );
    return (result.rows || []).map(function (row) {
      return Object.assign({}, row, { bytes: asInt(row.bytes, null) });
    });
  }

  recordingLink(tenant, meetingId, recordingId) {
    const exp = Math.floor(Date.now() / 1000) + LINK_TTL_SECONDS;
    const payload = Buffer.from(JSON.stringify({ t: tenant.tenantKey, m: meetingId, r: recordingId, e: exp })).toString("base64url");
    const mac = crypto.createHmac("sha256", this.signingSecret).update(payload).digest("base64url");
    return this.publicBase + "/recordings/" + encodeURIComponent(recordingId) + "?token=" + payload + "." + mac;
  }

  _verifyRecordingToken(tenant, recordingId, token) {
    const parts = String(token || "").split(".");
    if (parts.length !== 2) throw new CaptureError("Recording link is invalid", "RECORDING_LINK_INVALID", 403);
    const expected = crypto.createHmac("sha256", this.signingSecret).update(parts[0]).digest();
    let actual;
    try { actual = Buffer.from(parts[1], "base64url"); } catch (_) { actual = Buffer.alloc(0); }
    if (actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) {
      throw new CaptureError("Recording link is invalid", "RECORDING_LINK_INVALID", 403);
    }
    let payload;
    try { payload = JSON.parse(Buffer.from(parts[0], "base64url").toString("utf8")); } catch (_) {
      throw new CaptureError("Recording link is invalid", "RECORDING_LINK_INVALID", 403);
    }
    if (payload.t !== tenant.tenantKey || payload.r !== recordingId || Number(payload.e) < Math.floor(Date.now() / 1000)) {
      throw new CaptureError("Recording link expired", "RECORDING_LINK_EXPIRED", 403);
    }
    return payload;
  }

  async serveRecording(req, res, tenant, recordingId, token) {
    const payload = this._verifyRecordingToken(tenant, recordingId, token);
    const result = await this.pool.query(
      `SELECT r.storage_path,r.original_name,r.mime,r.status,m.owner_key
         FROM public.meet_recordings r JOIN public.meet_meetings m ON m.id=r.meeting_id
        WHERE r.id=$1 AND r.meeting_id=$2 AND r.domain_uuid=$3`,
      [recordingId, payload.m, tenant.domainUuid]
    );
    const row = result.rows[0];
    if (!row || row.status !== "complete") throw new CaptureError("Recording is unavailable", "RECORDING_NOT_FOUND", 404);
    const resolved = path.resolve(row.storage_path);
    const expectedRoot = path.join(this.root, tenant.tenantKey, payload.m) + path.sep;
    if (!resolved.startsWith(expectedRoot)) throw new CaptureError("Recording is unavailable", "RECORDING_NOT_FOUND", 404);
    const stat = await fsp.stat(resolved);
    let start = 0;
    let end = stat.size - 1;
    const range = String(req.headers.range || "");
    if (range) {
      const match = /^bytes=(\d*)-(\d*)$/.exec(range);
      if (!match || (!match[1] && !match[2])) {
        res.statusCode = 416; res.setHeader("Content-Range", "bytes */" + stat.size); return res.end();
      }
      if (!match[1]) { start = Math.max(0, stat.size - Number(match[2])); }
      else { start = Number(match[1]); if (match[2]) end = Number(match[2]); }
      if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) || start < 0 || end < start || start >= stat.size) {
        res.statusCode = 416; res.setHeader("Content-Range", "bytes */" + stat.size); return res.end();
      }
      end = Math.min(end, stat.size - 1);
      res.statusCode = 206;
      res.setHeader("Content-Range", "bytes " + start + "-" + end + "/" + stat.size);
    } else res.statusCode = 200;
    const encoded = encodeURIComponent(row.original_name || "meeting-recording.mp4").replace(/['()]/g, escape).replace(/\*/g, "%2A");
    res.setHeader("Content-Type", "video/mp4");
    res.setHeader("Content-Disposition", "attachment; filename*=UTF-8''" + encoded);
    res.setHeader("Accept-Ranges", "bytes");
    res.setHeader("Content-Length", String(end - start + 1));
    res.setHeader("Cache-Control", "private, no-store");
    res.setHeader("X-Content-Type-Options", "nosniff");
    res.setHeader("Content-Security-Policy", "default-src 'none'; sandbox");
    fs.createReadStream(resolved, { start: start, end: end }).pipe(res);
  }
}

module.exports = { CaptureService, CaptureError, stateJson, uuid, TRANSCRIPTION_LANGUAGES, normalizeLanguage };
