/**
 * Canonical PostgreSQL repository for Meet chat and whiteboards.
 *
 * Unlike recorder.js, collaboration writes are not fire-and-forget: a caller
 * must never be told that a message or drawing committed when PostgreSQL did
 * not accept it. Failures remain scoped to collaboration and do not affect the
 * LiveKit room.
 */

const crypto = require("crypto");
const { PRESENTABLE } = require("./documents.js");

class CollaborationError extends Error {
  constructor(message, code, statusCode, details) {
    super(message);
    this.name = "CollaborationError";
    this.code = code || "COLLABORATION_ERROR";
    this.statusCode = statusCode || 503;
    this.details = details || null;
  }
}

function asInt(value, fallback) {
  const number = Number(value);
  return Number.isSafeInteger(number) ? number : fallback;
}

function stableJson(value) {
  if (Array.isArray(value)) return "[" + value.map(stableJson).join(",") + "]";
  if (value && typeof value === "object") {
    return "{" + Object.keys(value).sort().map(function (key) {
      return JSON.stringify(key) + ":" + stableJson(value[key]);
    }).join(",") + "}";
  }
  return JSON.stringify(value);
}

function requestHash(value) {
  return crypto.createHash("sha256").update(stableJson(value)).digest("hex");
}

async function transaction(pool, work) {
  const client = typeof pool.connect === "function" ? await pool.connect() : pool;
  try {
    await client.query("BEGIN");
    const result = await work(client);
    await client.query("COMMIT");
    return result;
  } catch (err) {
    try { await client.query("ROLLBACK"); } catch (_) {}
    throw err;
  } finally {
    if (client !== pool && typeof client.release === "function") client.release();
  }
}

function uuid(value) {
  return typeof value === "string" &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

function mapAttachment(row) {
  return {
    id: row.id,
    name: row.original_name,
    mime: row.detected_mime || row.mime,
    bytes: asInt(row.bytes, 0),
    preview: ["image/jpeg", "image/png", "image/gif", "image/webp", "video/mp4", "video/webm"]
      .indexOf(row.detected_mime || row.mime) !== -1,
  };
}

function mapMessage(row, attachments, reply) {
  const deleted = !!row.deleted_at;
  return {
    id: row.id,
    clientMessageId: row.client_message_id,
    participantId: row.participant_ref,
    senderName: row.sender_name,
    body: deleted ? "" : row.body,
    replyToId: row.reply_to_id,
    reply: reply || null,
    attachments: deleted ? [] : (attachments || []).map(mapAttachment),
    createdSequence: asInt(row.created_sequence, 0),
    cursor: String(asInt(row.event_sequence, 0)),
    version: asInt(row.version, 1),
    sentAt: row.sent_at instanceof Date ? row.sent_at.toISOString() : row.sent_at,
    editedAt: row.edited_at instanceof Date ? row.edited_at.toISOString() : row.edited_at,
    deletedAt: row.deleted_at instanceof Date ? row.deleted_at.toISOString() : row.deleted_at,
    deleted: deleted,
  };
}

class CollaborationRepository {
  constructor(opts) {
    const o = opts || {};
    if (!o.pool) throw new TypeError("CollaborationRepository requires a pg pool");
    this.pool = o.pool;
    this.log = o.logger || { info: function () {}, warn: function () {}, error: function () {} };
  }

  async health() {
    try {
      const result = await this.pool.query(
        `SELECT to_regclass('public.meet_chat_messages') AS chat,
                to_regclass('public.meet_whiteboard_boards') AS board`
      );
      const row = result.rows && result.rows[0];
      return { chat: !!(row && row.chat), whiteboard: !!(row && row.board) };
    } catch (err) {
      this.log.warn("meet: collaboration health failed: " + err.message);
      return { chat: false, whiteboard: false };
    }
  }

  async getMeeting(tenant, meetingId) {
    const result = await this.pool.query(
      `SELECT id, owner_key, status, title, slug, started_at, ended_at, created_at
         FROM public.meet_meetings
        WHERE id = $1 AND domain_uuid = $2`,
      [meetingId, tenant.domainUuid]
    );
    return result.rows[0] || null;
  }

  async listHistory(tenant, ownerKey, opts) {
    const o = opts || {};
    const limit = Math.max(1, Math.min(100, asInt(o.limit, 30)));
    const before = o.before || null;
    const result = await this.pool.query(
      `SELECT m.id, m.slug, m.title, m.status, m.started_at, m.ended_at, m.created_at,
              EXISTS (SELECT 1 FROM public.meet_chat_messages c WHERE c.meeting_id = m.id) AS has_chat,
              EXISTS (SELECT 1 FROM public.meet_whiteboard_boards w WHERE w.meeting_id = m.id) AS has_whiteboard,
              EXISTS (SELECT 1 FROM public.meet_recordings r WHERE r.meeting_id = m.id) AS has_recordings,
              EXISTS (SELECT 1 FROM public.meet_transcripts t WHERE t.meeting_id = m.id) AS has_transcript,
              EXISTS (SELECT 1 FROM public.meet_summaries s WHERE s.meeting_id = m.id) AS has_summary,
              EXISTS (SELECT 1 FROM public.meet_feedback f WHERE f.meeting_id = m.id) AS has_reviews
         FROM public.meet_meetings m
        WHERE m.domain_uuid = $1 AND m.owner_key = $2 AND m.status = 'ended'
          AND ($3::timestamptz IS NULL OR m.created_at < $3::timestamptz)
        ORDER BY m.created_at DESC
        LIMIT $4`,
      [tenant.domainUuid, ownerKey, before, limit + 1]
    );
    const rows = result.rows || [];
    const more = rows.length > limit;
    const page = rows.slice(0, limit).map(function (row) {
      return {
        meetingId: row.id,
        slug: row.slug,
        title: row.title,
        status: row.status,
        startedAt: row.started_at,
        endedAt: row.ended_at,
        createdAt: row.created_at,
        hasChat: !!row.has_chat,
        hasWhiteboard: !!row.has_whiteboard,
        hasRecordings: !!row.has_recordings,
        hasTranscript: !!row.has_transcript,
        hasSummary: !!row.has_summary,
        hasReviews: !!row.has_reviews,
      };
    });
    return { meetings: page, nextCursor: more && page.length ? page[page.length - 1].createdAt : null };
  }

  async _messageRows(client, tenant, meetingId, whereSql, params, orderSql, limit) {
    const result = await client.query(
      `SELECT * FROM public.meet_chat_messages
        WHERE domain_uuid = $1 AND meeting_id = $2 ${whereSql}
        ORDER BY ${orderSql} LIMIT $${params.length + 1}`,
      params.concat([limit])
    );
    return result.rows || [];
  }

  async _hydrateMessages(client, tenant, meetingId, rows) {
    if (!rows.length) return [];
    const ids = rows.map(function (row) { return row.id; });
    const replyIds = rows.map(function (row) { return row.reply_to_id; }).filter(Boolean);
    const attachmentResult = await client.query(
      `SELECT * FROM public.meet_attachments
        WHERE domain_uuid = $1 AND meeting_id = $2 AND message_id = ANY($3::uuid[])
          AND status = 'attached'
        ORDER BY created_at, id`,
      [tenant.domainUuid, meetingId, ids]
    );
    const byMessage = {};
    (attachmentResult.rows || []).forEach(function (row) {
      if (!byMessage[row.message_id]) byMessage[row.message_id] = [];
      byMessage[row.message_id].push(row);
    });
    const replies = {};
    if (replyIds.length) {
      const replyResult = await client.query(
        `SELECT id, participant_ref, sender_name, body, deleted_at FROM public.meet_chat_messages
          WHERE domain_uuid = $1 AND meeting_id = $2 AND id = ANY($3::uuid[])`,
        [tenant.domainUuid, meetingId, replyIds]
      );
      (replyResult.rows || []).forEach(function (row) {
        replies[row.id] = {
          id: row.id,
          // Carried so a name policy can be applied to the quote as well as to
          // the message: without the id there is nothing to resolve an alias by.
          participantId: row.participant_ref,
          senderName: row.sender_name,
          body: row.deleted_at ? "" : String(row.body || "").slice(0, 240),
          deleted: !!row.deleted_at,
        };
      });
    }
    return rows.map(function (row) {
      return mapMessage(row, byMessage[row.id], row.reply_to_id ? replies[row.reply_to_id] : null);
    });
  }

  async listMessages(tenant, meetingId, opts) {
    const o = opts || {};
    const limit = Math.max(1, Math.min(100, asInt(o.limit, 50)));
    let where = "";
    let params = [tenant.domainUuid, meetingId];
    let order = "created_sequence DESC";
    if (o.after !== undefined && o.after !== null) {
      where = "AND event_sequence > $3";
      params.push(asInt(o.after, 0));
      order = "event_sequence ASC";
    } else if (o.before !== undefined && o.before !== null) {
      where = "AND created_sequence < $3";
      params.push(asInt(o.before, Number.MAX_SAFE_INTEGER));
    }
    const rows = await this._messageRows(this.pool, tenant, meetingId, where, params, order, limit + 1);
    const more = rows.length > limit;
    let pageRows = rows.slice(0, limit);
    const isAfterPage = o.after !== undefined && o.after !== null;
    if (!isAfterPage) pageRows = pageRows.reverse();
    const messages = await this._hydrateMessages(this.pool, tenant, meetingId, pageRows);
    const latestCursor = messages.reduce(function (max, message) {
      return Math.max(max, asInt(message.cursor, 0));
    }, asInt(o.after, 0));
    return {
      messages: messages,
      cursor: String(latestCursor),
      hasOlder: !isAfterPage && more,
      oldestCursor: messages.length ? String(messages[0].createdSequence) : null,
    };
  }

  async getMessage(tenant, meetingId, messageId) {
    const result = await this.pool.query(
      `SELECT * FROM public.meet_chat_messages
        WHERE id = $1 AND meeting_id = $2 AND domain_uuid = $3`,
      [messageId, meetingId, tenant.domainUuid]
    );
    const hydrated = await this._hydrateMessages(this.pool, tenant, meetingId, result.rows || []);
    return hydrated[0] || null;
  }

  async createMessage(tenant, input) {
    const canonicalRequest = {
      body: input.body,
      replyToId: input.replyToId || null,
      attachmentIds: (input.attachmentIds || []).slice().sort(),
    };
    const hash = requestHash(canonicalRequest);
    const created = await transaction(this.pool, async function (client) {
      const duplicate = await client.query(
        `SELECT id, request_hash FROM public.meet_chat_messages
          WHERE meeting_id = $1 AND domain_uuid = $2 AND participant_ref = $3
            AND client_message_id = $4`,
        [input.meetingId, tenant.domainUuid, input.participantId, input.clientMessageId]
      );
      if (duplicate.rows[0]) {
        if (duplicate.rows[0].request_hash !== hash) {
          throw new CollaborationError("clientMessageId was reused with different content", "IDEMPOTENCY_CONFLICT", 409);
        }
        return { id: duplicate.rows[0].id, duplicate: true };
      }
      if (input.replyToId) {
        const reply = await client.query(
          `SELECT 1 FROM public.meet_chat_messages
            WHERE id = $1 AND meeting_id = $2 AND domain_uuid = $3`,
          [input.replyToId, input.meetingId, tenant.domainUuid]
        );
        if (!reply.rows[0]) throw new CollaborationError("Reply target was not found", "REPLY_NOT_FOUND", 404);
      }
      const attachmentIds = input.attachmentIds || [];
      if (attachmentIds.length) {
        const ready = await client.query(
          `SELECT id FROM public.meet_attachments
            WHERE id = ANY($1::uuid[]) AND meeting_id = $2 AND domain_uuid = $3
              AND uploader_ref = $4 AND message_id IS NULL AND status = 'ready'
            FOR UPDATE`,
          [attachmentIds, input.meetingId, tenant.domainUuid, input.participantId]
        );
        if ((ready.rows || []).length !== attachmentIds.length) {
          throw new CollaborationError("One or more attachments are unavailable", "ATTACHMENT_NOT_READY", 409);
        }
      }
      const result = await client.query(
        `WITH seq AS (SELECT nextval('public.meet_chat_event_seq') AS n)
         INSERT INTO public.meet_chat_messages
           (meeting_id, domain_uuid, participant_ref, sender_name, body, files,
            client_message_id, request_hash, reply_to_id, created_sequence,
            event_sequence, version, sent_at)
         SELECT $1, $2, $3, $4, $5, '[]'::jsonb, $6, $7, $8, n, n, 1, now()
           FROM seq RETURNING id`,
        [input.meetingId, tenant.domainUuid, input.participantId, input.senderName,
          input.body, input.clientMessageId, hash, input.replyToId || null]
      );
      const id = result.rows[0].id;
      if (attachmentIds.length) {
        await client.query(
          `UPDATE public.meet_attachments
              SET message_id = $1, status = 'attached', expires_at = NULL
            WHERE id = ANY($2::uuid[]) AND domain_uuid = $3 AND meeting_id = $4`,
          [id, attachmentIds, tenant.domainUuid, input.meetingId]
        );
      }
      return { id: id, duplicate: false };
    });
    return { message: await this.getMessage(tenant, input.meetingId, created.id), duplicate: created.duplicate };
  }

  async editMessage(tenant, input) {
    const result = await this.pool.query(
      `UPDATE public.meet_chat_messages
          SET body = $1, version = version + 1, edited_at = now(),
              event_sequence = nextval('public.meet_chat_event_seq')
        WHERE id = $2 AND meeting_id = $3 AND domain_uuid = $4
          AND participant_ref = $5 AND version = $6 AND deleted_at IS NULL
        RETURNING id`,
      [input.body, input.messageId, input.meetingId, tenant.domainUuid,
        input.participantId, input.expectedVersion]
    );
    if (!result.rows[0]) throw new CollaborationError("Message changed; refresh and try again", "VERSION_CONFLICT", 409);
    return this.getMessage(tenant, input.meetingId, input.messageId);
  }

  async deleteMessage(tenant, input) {
    return transaction(this.pool, async (client) => {
      const result = await client.query(
        `UPDATE public.meet_chat_messages
            SET body = '', version = version + 1, deleted_at = now(),
                deleted_by_ref = $1,
                event_sequence = nextval('public.meet_chat_event_seq')
          WHERE id = $2 AND meeting_id = $3 AND domain_uuid = $4
            AND version = $5 AND deleted_at IS NULL
            AND ($6::boolean OR participant_ref = $1)
          RETURNING id`,
        [input.actorId, input.messageId, input.meetingId, tenant.domainUuid,
          input.expectedVersion, !!input.isModerator]
      );
      if (!result.rows[0]) throw new CollaborationError("Message changed or cannot be deleted", "VERSION_CONFLICT", 409);
      await client.query(
        `UPDATE public.meet_attachments SET status = 'revoked'
          WHERE message_id = $1 AND domain_uuid = $2`,
        [input.messageId, tenant.domainUuid]
      );
      const rows = await client.query(
        `SELECT * FROM public.meet_chat_messages WHERE id = $1 AND domain_uuid = $2`,
        [input.messageId, tenant.domainUuid]
      );
      const hydrated = await this._hydrateMessages(client, tenant, input.meetingId, rows.rows || []);
      return hydrated[0];
    });
  }

  async createAttachment(tenant, input) {
    const result = await this.pool.query(
      `INSERT INTO public.meet_attachments
         (id, meeting_id, domain_uuid, stored_name, original_name, mime, bytes,
          uploader_ref, storage_path, sha256, declared_mime, detected_mime,
          status, expires_at, created_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,'ready',now() + interval '1 hour',now())
       RETURNING *`,
      [input.id, input.meetingId, tenant.domainUuid, input.storedName,
        input.originalName, input.detectedMime, input.bytes, input.uploaderRef,
        input.storagePath, input.sha256, input.declaredMime, input.detectedMime]
    );
    return mapAttachment(result.rows[0]);
  }

  async getAttachment(tenant, meetingId, attachmentId) {
    const result = await this.pool.query(
      `SELECT a.*, m.deleted_at
         FROM public.meet_attachments a
         LEFT JOIN public.meet_chat_messages m ON m.id = a.message_id
        WHERE a.id = $1 AND a.meeting_id = $2 AND a.domain_uuid = $3`,
      [attachmentId, meetingId, tenant.domainUuid]
    );
    return result.rows[0] || null;
  }

  async cancelAttachment(tenant, meetingId, attachmentId, uploaderRef) {
    const result = await this.pool.query(
      `UPDATE public.meet_attachments SET status = 'revoked'
        WHERE id = $1 AND meeting_id = $2 AND domain_uuid = $3
          AND uploader_ref = $4 AND message_id IS NULL
        RETURNING storage_path`,
      [attachmentId, meetingId, tenant.domainUuid, uploaderRef]
    );
    return result.rows[0] || null;
  }

  async cleanupExpiredAttachments(tenant) {
    const result = await this.pool.query(
      `DELETE FROM public.meet_attachments
        WHERE domain_uuid = $1 AND message_id IS NULL AND expires_at < now()
          AND NOT EXISTS (
            SELECT 1 FROM public.meet_file_assets f
             WHERE f.attachment_id = meet_attachments.id
          )
        RETURNING storage_path`,
      [tenant.domainUuid]
    );
    return (result.rows || []).map(function (row) { return row.storage_path; }).filter(Boolean);
  }

  // ── internal file previews and room-wide file presentation ───────

  async ensureFileAsset(tenant, meetingId, attachmentId, purpose) {
    return transaction(this.pool, async (client) => {
      const attachmentResult = await client.query(
        `SELECT a.*, m.deleted_at
           FROM public.meet_attachments a
           LEFT JOIN public.meet_chat_messages m ON m.id = a.message_id
          WHERE a.id=$1 AND a.meeting_id=$2 AND a.domain_uuid=$3
            AND a.status IN ('ready','attached')`,
        [attachmentId, meetingId, tenant.domainUuid]
      );
      const attachment = attachmentResult.rows[0];
      if (!attachment || attachment.deleted_at) {
        throw new CollaborationError("Attachment not found", "ATTACHMENT_NOT_FOUND", 404);
      }
      const inserted = await client.query(
        `INSERT INTO public.meet_file_assets
           (attachment_id,meeting_id,domain_uuid,purpose,conversion_status,created_at,updated_at)
         VALUES ($1,$2,$3,$4,'queued',now(),now())
         ON CONFLICT (attachment_id) DO UPDATE
           SET purpose = CASE
             WHEN public.meet_file_assets.purpose='presentation' OR EXCLUDED.purpose='presentation'
               THEN 'presentation' ELSE 'preview' END
         RETURNING *`,
        [attachmentId, meetingId, tenant.domainUuid, purpose === "presentation" ? "presentation" : "preview"]
      );
      return mapFileAsset(inserted.rows[0], attachment, []);
    });
  }

  async getFileAsset(tenant, meetingId, assetId) {
    const assetResult = await this.pool.query(
      `SELECT f.*, a.original_name, a.detected_mime, a.mime AS attachment_mime,
              a.bytes AS attachment_bytes, a.storage_path, a.status AS attachment_status,
              m.deleted_at
         FROM public.meet_file_assets f
         JOIN public.meet_attachments a ON a.id=f.attachment_id
         LEFT JOIN public.meet_chat_messages m ON m.id=a.message_id
        WHERE f.id=$1 AND f.meeting_id=$2 AND f.domain_uuid=$3`,
      [assetId, meetingId, tenant.domainUuid]
    );
    const row = assetResult.rows[0];
    if (!row || row.deleted_at || ["ready", "attached"].indexOf(row.attachment_status) === -1) return null;
    const pages = await this.pool.query(
      `SELECT * FROM public.meet_file_pages
        WHERE asset_id=$1 AND meeting_id=$2 AND domain_uuid=$3 ORDER BY page_number`,
      [assetId, meetingId, tenant.domainUuid]
    );
    return mapFileAsset(row, row, pages.rows || []);
  }

  async getFileAssetByAttachment(tenant, meetingId, attachmentId) {
    const result = await this.pool.query(
      `SELECT id FROM public.meet_file_assets
        WHERE attachment_id=$1 AND meeting_id=$2 AND domain_uuid=$3`,
      [attachmentId, meetingId, tenant.domainUuid]
    );
    return result.rows[0] ? this.getFileAsset(tenant, meetingId, result.rows[0].id) : null;
  }

  async setFileAssetStatus(tenant, meetingId, assetId, status, failureCode) {
    const result = await this.pool.query(
      `UPDATE public.meet_file_assets
          SET conversion_status=$1, failure_code=$2, updated_at=now()
        WHERE id=$3 AND meeting_id=$4 AND domain_uuid=$5 RETURNING id`,
      [status, failureCode || null, assetId, meetingId, tenant.domainUuid]
    );
    if (!result.rows[0]) throw new CollaborationError("File asset not found", "ATTACHMENT_NOT_FOUND", 404);
  }

  async replaceFilePages(tenant, meetingId, assetId, pages) {
    return transaction(this.pool, async (client) => {
      await client.query(
        `DELETE FROM public.meet_file_pages WHERE asset_id=$1 AND meeting_id=$2 AND domain_uuid=$3`,
        [assetId, meetingId, tenant.domainUuid]
      );
      for (const page of pages) {
        await client.query(
          `INSERT INTO public.meet_file_pages
             (asset_id,meeting_id,domain_uuid,page_number,storage_path,mime,bytes,sha256,width,height,created_at)
           VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,now())`,
          [assetId, meetingId, tenant.domainUuid, page.pageNumber, page.storagePath,
            page.mime, page.bytes, page.sha256, page.width, page.height]
        );
      }
      await client.query(
        `UPDATE public.meet_file_assets
            SET conversion_status='ready',page_count=$1,failure_code=NULL,updated_at=now()
          WHERE id=$2 AND meeting_id=$3 AND domain_uuid=$4`,
        [pages.length, assetId, meetingId, tenant.domainUuid]
      );
    });
  }

  async presentationState(tenant, meetingId) {
    await this.pool.query(
      `INSERT INTO public.meet_file_presentations (meeting_id,domain_uuid)
       VALUES ($1,$2) ON CONFLICT (meeting_id) DO NOTHING`,
      [meetingId, tenant.domainUuid]
    );
    const result = await this.pool.query(
      `SELECT p.*, f.page_count, a.original_name, a.detected_mime
         FROM public.meet_file_presentations p
         LEFT JOIN public.meet_file_assets f ON f.id=p.active_asset_id
         LEFT JOIN public.meet_attachments a ON a.id=f.attachment_id
        WHERE p.meeting_id=$1 AND p.domain_uuid=$2`,
      [meetingId, tenant.domainUuid]
    );
    return mapPresentation(result.rows[0]);
  }

  async applyPresentationAction(tenant, input) {
    return transaction(this.pool, async (client) => {
      await client.query(
        `INSERT INTO public.meet_file_presentations (meeting_id,domain_uuid)
         VALUES ($1,$2) ON CONFLICT (meeting_id) DO NOTHING`,
        [input.meetingId, tenant.domainUuid]
      );
      const locked = await client.query(
        `SELECT * FROM public.meet_file_presentations
          WHERE meeting_id=$1 AND domain_uuid=$2 FOR UPDATE`,
        [input.meetingId, tenant.domainUuid]
      );
      const current = locked.rows[0];
      const duplicate = await client.query(
        `SELECT * FROM public.meet_file_presentation_actions
          WHERE meeting_id=$1 AND domain_uuid=$2 AND actor_ref=$3 AND client_action_id=$4`,
        [input.meetingId, tenant.domainUuid, input.actorId, input.clientActionId]
      );
      if (duplicate.rows[0]) {
        if (duplicate.rows[0].kind !== input.kind ||
            String(duplicate.rows[0].asset_id || "") !== String(input.assetId || "") ||
            asInt(duplicate.rows[0].page_number, 0) !== asInt(input.pageNumber, 0)) {
          throw new CollaborationError("clientActionId was reused for another action", "IDEMPOTENCY_CONFLICT", 409);
        }
        const state = mapPresentation(current);
        state.duplicate = true;
        return state;
      }
      if (asInt(input.expectedVersion, -1) !== asInt(current.version, 0)) {
        throw new CollaborationError("The presentation changed", "VERSION_CONFLICT", 409, mapPresentation(current));
      }
      let assetId = current.active_asset_id;
      let pageNumber = current.page_number;
      let status = current.status;
      let presenterRef = current.presenter_ref;
      let presenterName = current.presenter_name;
      let annotationMode = current.annotation_mode === "everyone" ? "everyone" : "presenter";
      if (input.kind === "start") {
        if (current.status === "active") {
          throw new CollaborationError("Another file presentation is active", "PRESENTATION_ACTIVE", 409, mapPresentation(current));
        }
        const assetResult = await client.query(
          `SELECT f.*,a.detected_mime,a.original_name
             FROM public.meet_file_assets f JOIN public.meet_attachments a ON a.id=f.attachment_id
            WHERE f.id=$1 AND f.meeting_id=$2 AND f.domain_uuid=$3
              AND f.conversion_status='ready' AND f.page_count > 0
              AND a.status IN ('ready','attached')`,
          [input.assetId, input.meetingId, tenant.domainUuid]
        );
        const asset = assetResult.rows[0];
        if (!asset) throw new CollaborationError("Presentation file is not ready", "PRESENTATION_NOT_READY", 409);
        // The SAME set documents.js uses to decide what may be prepared. This was
        // an inline copy, and it drifted twice: it never gained XLSX when that
        // was made presentable on 2026-08-04, nor the legacy/OOXML PowerPoint
        // variants and the wider image set. A file therefore uploaded, converted
        // and rendered to pages successfully and was refused only at the last
        // step, with a message that blames the file type. One list, one place.
        if (!PRESENTABLE.has(asset.detected_mime)) {
          throw new CollaborationError("File type cannot be presented", "PRESENTATION_TYPE_BLOCKED", 400);
        }
        assetId = asset.id;
        pageNumber = 1;
        status = "active";
        presenterRef = input.actorId;
        presenterName = input.actorName;
        // A new presentation never inherits the previous presenter's decision
        // to open the deck up. Whoever presents next has to say so themselves.
        annotationMode = "presenter";
      } else if (input.kind === "page") {
        // A moderator may page somebody else's deck, exactly as the `stop`
        // branch below already lets them take it down. Deliberately NOT
        // audited: stopping is one consequential act, paging happens once per
        // slide, and a moderation row per page turn would bury the events that
        // matter under hundreds that do not.
        if (current.status !== "active" ||
            (current.presenter_ref !== input.actorId && !input.isModerator)) {
          throw new CollaborationError(
            "Only the presenter or a moderator can change pages", "PRESENTATION_FORBIDDEN", 403);
        }
        const countResult = await client.query(`SELECT page_count FROM public.meet_file_assets WHERE id=$1`, [current.active_asset_id]);
        const pageCount = asInt(countResult.rows[0] && countResult.rows[0].page_count, 0);
        if (!Number.isInteger(input.pageNumber) || input.pageNumber < 1 || input.pageNumber > pageCount) {
          throw new CollaborationError("Page number is out of range", "VALIDATION_ERROR", 400);
        }
        pageNumber = input.pageNumber;
      } else if (input.kind === "mode") {
        // Who may draw is the presenter's decision about their own deck; a
        // moderator is included for the same reason they may page and stop it.
        if (current.status !== "active" ||
            (current.presenter_ref !== input.actorId && !input.isModerator)) {
          throw new CollaborationError(
            "Only the presenter or a moderator can change who may draw",
            "PRESENTATION_FORBIDDEN", 403);
        }
        if (input.annotationMode !== "presenter" && input.annotationMode !== "everyone") {
          throw new CollaborationError("annotationMode is invalid", "VALIDATION_ERROR", 400);
        }
        annotationMode = input.annotationMode;
      } else if (input.kind === "stop") {
        if (current.status !== "active") return Object.assign(mapPresentation(current), { duplicate: true });
        if (current.presenter_ref !== input.actorId && !input.isModerator) {
          throw new CollaborationError("Only the presenter or a moderator can stop this presentation", "PRESENTATION_FORBIDDEN", 403);
        }
        status = "idle";
        assetId = null;
        pageNumber = null;
        presenterRef = null;
        presenterName = null;
        annotationMode = "presenter";
      }
      const version = asInt(current.version, 0) + 1;
      const sequence = asInt(current.last_sequence, 0) + 1;
      await client.query(
        `INSERT INTO public.meet_file_presentation_actions
           (meeting_id,domain_uuid,sequence,version,actor_ref,client_action_id,kind,asset_id,page_number,created_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,now())`,
        [input.meetingId, tenant.domainUuid, sequence, version, input.actorId,
          input.clientActionId, input.kind, input.assetId || current.active_asset_id, input.pageNumber || null]
      );
      const updated = await client.query(
        `UPDATE public.meet_file_presentations
            SET version=$3,last_sequence=$4,status=$5,active_asset_id=$6,
                presenter_ref=$7,presenter_name=$8,page_number=$9,annotation_mode=$10,
                started_at=CASE WHEN $5='active' AND status='idle' THEN now() ELSE started_at END,
                updated_at=now()
          WHERE meeting_id=$1 AND domain_uuid=$2 RETURNING *`,
        [input.meetingId, tenant.domainUuid, version, sequence, status, assetId,
          presenterRef, presenterName, pageNumber, annotationMode]
      );
      const state = mapPresentation(updated.rows[0]);
      state.duplicate = false;
      return state;
    });
  }

  // ── ink drawn on a presented page ─────────────────────────────────
  //
  // Deliberately NOT the whiteboard document. That is one blob with its own
  // lock semantics, so folding slide ink into it would mean the board's
  // "Clear board" wipes a presenter's annotations, the two undo stacks
  // interleave, and the board's edit mode fights the presenter's grant mode.
  //
  // What IS reused is the pair of pure statics `applyOperations` and
  // `inverseOperations`, which already know how to fold a batch into a
  // document and how to invert one. The rest is a thinner version of the
  // board: no checkpoints, because the document is written materialised on
  // every batch, so a read is one row rather than a fold over history.

  /** Take the page's ink row, creating it on first touch, locked for update. */
  async _annotationRow(client, tenant, meetingId, assetId, pageNumber) {
    await client.query(
      `INSERT INTO public.meet_file_presentation_annotations
         (meeting_id,domain_uuid,asset_id,page_number)
       VALUES ($1,$2,$3,$4)
       ON CONFLICT (meeting_id,asset_id,page_number) DO NOTHING`,
      [meetingId, tenant.domainUuid, assetId, pageNumber]
    );
    const locked = await client.query(
      `SELECT * FROM public.meet_file_presentation_annotations
        WHERE meeting_id=$1 AND domain_uuid=$2 AND asset_id=$3 AND page_number=$4
        FOR UPDATE`,
      [meetingId, tenant.domainUuid, assetId, pageNumber]
    );
    return locked.rows[0];
  }

  async presentationAnnotations(tenant, meetingId, assetId, pageNumber) {
    const result = await this.pool.query(
      `SELECT * FROM public.meet_file_presentation_annotations
        WHERE meeting_id=$1 AND domain_uuid=$2 AND asset_id=$3 AND page_number=$4`,
      [meetingId, tenant.domainUuid, assetId, pageNumber]
    );
    return mapAnnotations(result.rows[0], assetId, pageNumber);
  }

  async applyPresentationAnnotationBatch(tenant, input) {
    return transaction(this.pool, async (client) => {
      const row = await this._annotationRow(
        client, tenant, input.meetingId, input.assetId, input.pageNumber);
      const duplicate = await client.query(
        `SELECT * FROM public.meet_file_presentation_annotation_batches
          WHERE meeting_id=$1 AND domain_uuid=$2 AND actor_ref=$3 AND client_batch_id=$4`,
        [input.meetingId, tenant.domainUuid, input.actorId, input.clientBatchId]
      );
      if (duplicate.rows[0]) {
        const existing = duplicate.rows[0];
        if (existing.kind !== (input.kind || "operations") ||
            stableJson(existing.operations || []) !== stableJson(input.operations || [])) {
          throw new CollaborationError(
            "clientBatchId was reused with different operations", "IDEMPOTENCY_CONFLICT", 409);
        }
        const settled = mapAnnotations(row, input.assetId, input.pageNumber);
        settled.duplicate = true;
        return settled;
      }
      // Only a destructive batch has to agree about the version. Two people
      // drawing at once are adding, not replacing, so making every stroke
      // carry the room's latest version would refuse one of them for no
      // reason -- exactly the trade the whiteboard already makes.
      if (input.destructive && asInt(input.expectedVersion, -1) !== asInt(row.version, 0)) {
        throw new CollaborationError("The annotations changed", "VERSION_CONFLICT", 409,
          mapAnnotations(row, input.assetId, input.pageNumber));
      }
      const document = normaliseDocument(row.document);
      const inverse = CollaborationRepository.inverseOperations(document, input.operations);
      const next = CollaborationRepository.applyOperations(document, input.operations);
      const sequence = asInt(row.last_sequence, 0) + 1;
      const version = asInt(row.version, 0) + 1;
      await client.query(
        `INSERT INTO public.meet_file_presentation_annotation_batches
           (meeting_id,domain_uuid,asset_id,page_number,sequence,version,actor_ref,
            client_batch_id,kind,operations,inverse_operations,created_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10::jsonb,$11::jsonb,now())`,
        [input.meetingId, tenant.domainUuid, input.assetId, input.pageNumber, sequence, version,
          input.actorId, input.clientBatchId, input.kind || "operations",
          JSON.stringify(input.operations), JSON.stringify(inverse)]
      );
      const updated = await client.query(
        `UPDATE public.meet_file_presentation_annotations
            SET version=$5,last_sequence=$6,document=$7::jsonb,updated_at=now()
          WHERE meeting_id=$1 AND domain_uuid=$2 AND asset_id=$3 AND page_number=$4
          RETURNING *`,
        [input.meetingId, tenant.domainUuid, input.assetId, input.pageNumber,
          version, sequence, JSON.stringify(next)]
      );
      const state = mapAnnotations(updated.rows[0], input.assetId, input.pageNumber);
      state.duplicate = false;
      return state;
    });
  }

  async presentationAnnotationUndoRedo(tenant, input) {
    return transaction(this.pool, async (client) => {
      const row = await this._annotationRow(
        client, tenant, input.meetingId, input.assetId, input.pageNumber);
      const duplicate = await client.query(
        `SELECT * FROM public.meet_file_presentation_annotation_batches
          WHERE meeting_id=$1 AND domain_uuid=$2 AND actor_ref=$3 AND client_batch_id=$4`,
        [input.meetingId, tenant.domainUuid, input.actorId, input.clientBatchId]
      );
      if (duplicate.rows[0]) {
        if (duplicate.rows[0].kind !== input.kind) {
          throw new CollaborationError(
            "clientBatchId was reused for another action", "IDEMPOTENCY_CONFLICT", 409);
        }
        const settled = mapAnnotations(row, input.assetId, input.pageNumber);
        settled.duplicate = true;
        return settled;
      }
      const undo = input.kind === "undo";
      // Per actor, and `kind='operations'` only, so a clear cannot be undone by
      // somebody else's Ctrl+Z and a page turn is not on this stack at all.
      const target = await client.query(
        `SELECT * FROM public.meet_file_presentation_annotation_batches
          WHERE meeting_id=$1 AND domain_uuid=$2 AND asset_id=$3 AND page_number=$4
            AND actor_ref=$5 AND kind='operations' AND undone=$6
          ORDER BY sequence DESC LIMIT 1 FOR UPDATE`,
        [input.meetingId, tenant.domainUuid, input.assetId, input.pageNumber, input.actorId, !undo]
      );
      if (!target.rows[0]) {
        throw new CollaborationError(
          "Nothing to " + input.kind, "NOTHING_TO_" + input.kind.toUpperCase(), 409);
      }
      const targetRow = target.rows[0];
      const operations = undo ? targetRow.inverse_operations : targetRow.operations;
      const document = normaliseDocument(row.document);
      const next = CollaborationRepository.applyOperations(document, operations);
      const sequence = asInt(row.last_sequence, 0) + 1;
      const version = asInt(row.version, 0) + 1;
      await client.query(
        `INSERT INTO public.meet_file_presentation_annotation_batches
           (meeting_id,domain_uuid,asset_id,page_number,sequence,version,actor_ref,
            client_batch_id,kind,operations,inverse_operations,target_batch_id,created_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10::jsonb,'[]'::jsonb,$11,now())`,
        [input.meetingId, tenant.domainUuid, input.assetId, input.pageNumber, sequence, version,
          input.actorId, input.clientBatchId, input.kind, JSON.stringify(operations), targetRow.id]
      );
      await client.query(
        `UPDATE public.meet_file_presentation_annotation_batches SET undone=$2 WHERE id=$1`,
        [targetRow.id, undo]
      );
      const updated = await client.query(
        `UPDATE public.meet_file_presentation_annotations
            SET version=$5,last_sequence=$6,document=$7::jsonb,updated_at=now()
          WHERE meeting_id=$1 AND domain_uuid=$2 AND asset_id=$3 AND page_number=$4
          RETURNING *`,
        [input.meetingId, tenant.domainUuid, input.assetId, input.pageNumber,
          version, sequence, JSON.stringify(next)]
      );
      const state = mapAnnotations(updated.rows[0], input.assetId, input.pageNumber);
      state.duplicate = false;
      return state;
    });
  }

  async clearPresentationAnnotations(tenant, input) {
    return this.applyPresentationAnnotationBatch(tenant, Object.assign({}, input, {
      operations: [{ type: "clear" }],
      destructive: true,
      kind: "clear",
    }));
  }

  // ── whiteboard ────────────────────────────────────────────────────

  /**
   * The board, created on first touch.
   *
   * `initialLocked` seeds the lock from the meeting's policy and is honoured
   * ONLY by the insert — `ON CONFLICT DO NOTHING` means an existing board keeps
   * whatever its host set, which is the whole contract: the policy chooses what
   * a board STARTS as, the board owns it from that moment. Anything else would
   * have a form edited months later silently re-lock a board somebody had
   * deliberately opened.
   */
  async _boardSnapshot(client, tenant, meetingId, initialLocked) {
    await client.query(
      `INSERT INTO public.meet_whiteboard_boards (meeting_id, domain_uuid, locked)
       VALUES ($1,$2,$3) ON CONFLICT (meeting_id) DO NOTHING`,
      [meetingId, tenant.domainUuid, initialLocked === true]
    );
    const boardResult = await client.query(
      `SELECT * FROM public.meet_whiteboard_boards
        WHERE meeting_id = $1 AND domain_uuid = $2`,
      [meetingId, tenant.domainUuid]
    );
    const checkpointResult = await client.query(
      `SELECT * FROM public.meet_whiteboard_checkpoints
        WHERE meeting_id = $1 AND domain_uuid = $2
        ORDER BY through_sequence DESC LIMIT 1`,
      [meetingId, tenant.domainUuid]
    );
    const checkpoint = checkpointResult.rows[0] || null;
    const after = checkpoint ? asInt(checkpoint.through_sequence, 0) : 0;
    const batches = await client.query(
      `SELECT * FROM public.meet_whiteboard_batches
        WHERE meeting_id = $1 AND domain_uuid = $2 AND sequence > $3
        ORDER BY sequence`,
      [meetingId, tenant.domainUuid, after]
    );
    return { board: boardResult.rows[0], checkpoint: checkpoint, batches: batches.rows || [] };
  }

  static applyOperations(document, operations) {
    const doc = document && typeof document === "object" ? JSON.parse(JSON.stringify(document)) : { elements: {} };
    if (!doc.elements || typeof doc.elements !== "object") doc.elements = {};
    (operations || []).forEach(function (op) {
      if (["stroke", "highlighter", "shape", "text", "restore"].indexOf(op.type) !== -1) {
        const elements = op.type === "restore" ? (op.elements || []) : [op.element];
        elements.forEach(function (element) {
          if (element && element.id) doc.elements[element.id] = element;
        });
      } else if (op.type === "erase") {
        (op.elementIds || []).forEach(function (id) { delete doc.elements[id]; });
      } else if (op.type === "clear") {
        doc.elements = {};
      }
    });
    return doc;
  }

  static inverseOperations(document, operations) {
    const inverse = [];
    const working = JSON.parse(JSON.stringify(document || { elements: {} }));
    (operations || []).forEach(function (op) {
      if (["stroke", "highlighter", "shape", "text"].indexOf(op.type) !== -1 && op.element) {
        const previous = working.elements[op.element.id];
        inverse.unshift(previous
          ? { type: "restore", elements: [previous] }
          : { type: "erase", elementIds: [op.element.id] });
      } else if (op.type === "erase") {
        const removed = (op.elementIds || []).map(function (id) { return working.elements[id]; }).filter(Boolean);
        if (removed.length) inverse.unshift({ type: "restore", elements: removed });
      } else if (op.type === "clear") {
        inverse.unshift({ type: "restore", elements: Object.values(working.elements || {}) });
      }
      const next = CollaborationRepository.applyOperations(working, [op]);
      working.elements = next.elements;
    });
    return inverse;
  }

  async whiteboardState(tenant, meetingId, afterSequence, initialLocked) {
    const snapshot = await this._boardSnapshot(this.pool, tenant, meetingId, initialLocked);
    const checkpointDocument = snapshot.checkpoint ? snapshot.checkpoint.document : { elements: {} };
    let document = checkpointDocument;
    snapshot.batches.forEach(function (batch) {
      document = CollaborationRepository.applyOperations(document, batch.operations);
    });
    const after = asInt(afterSequence, -1);
    const compacted = after >= 0 && snapshot.checkpoint && after < asInt(snapshot.checkpoint.through_sequence, 0);
    const selected = after >= 0 && !compacted
      ? snapshot.batches.filter(function (batch) { return asInt(batch.sequence, 0) > after; })
      : snapshot.batches;
    return {
      version: asInt(snapshot.board.version, 0),
      sequence: asInt(snapshot.board.last_sequence, 0),
      locked: !!snapshot.board.locked,
      presenting: !!snapshot.board.presenting,
      presenterId: snapshot.board.presenting_by_ref || null,
      presenterName: snapshot.board.presenting_by_name || null,
      resetRequired: !!compacted || after < 0,
      checkpoint: (!!compacted || after < 0) ? {
        sequence: snapshot.checkpoint ? asInt(snapshot.checkpoint.through_sequence, 0) : 0,
        document: snapshot.checkpoint ? snapshot.checkpoint.document : { elements: {} },
      } : null,
      batches: selected.map(mapBatch),
      document: document,
    };
  }

  async applyWhiteboardBatch(tenant, input) {
    return transaction(this.pool, async (client) => {
      await client.query(
        `INSERT INTO public.meet_whiteboard_boards (meeting_id, domain_uuid, locked)
         VALUES ($1,$2,$3) ON CONFLICT (meeting_id) DO NOTHING`,
        [input.meetingId, tenant.domainUuid, input.initialLocked === true]
      );
      const boardLock = await client.query(
        `SELECT * FROM public.meet_whiteboard_boards
          WHERE meeting_id = $1 AND domain_uuid = $2 FOR UPDATE`,
        [input.meetingId, tenant.domainUuid]
      );
      const board = boardLock.rows[0];
      const snapshot = await this._boardSnapshot(client, tenant, input.meetingId, input.initialLocked);
      const duplicate = await client.query(
        `SELECT * FROM public.meet_whiteboard_batches
          WHERE meeting_id = $1 AND domain_uuid = $2 AND actor_ref = $3 AND client_batch_id = $4`,
        [input.meetingId, tenant.domainUuid, input.actorId, input.clientBatchId]
      );
      if (duplicate.rows[0]) {
        const existing = duplicate.rows[0];
        if (existing.kind !== (input.kind || "operations") ||
            stableJson(existing.operations || []) !== stableJson(input.operations || [])) {
          throw new CollaborationError("clientBatchId was reused with different operations", "IDEMPOTENCY_CONFLICT", 409);
        }
        const mapped = mapBatch(existing);
        mapped.duplicate = true;
        return mapped;
      }
      if (board.locked && !input.isModerator) {
        throw new CollaborationError("The whiteboard is locked", "WHITEBOARD_LOCKED", 403);
      }
      if (input.destructive && asInt(input.expectedVersion, -1) !== asInt(board.version, 0)) {
        throw new CollaborationError("The whiteboard changed", "VERSION_CONFLICT", 409, {
          version: asInt(board.version, 0), sequence: asInt(board.last_sequence, 0),
        });
      }
      let document = snapshot.checkpoint ? snapshot.checkpoint.document : { elements: {} };
      snapshot.batches.forEach(function (batch) {
        document = CollaborationRepository.applyOperations(document, batch.operations);
      });
      const inverse = CollaborationRepository.inverseOperations(document, input.operations);
      const sequence = asInt(board.last_sequence, 0) + 1;
      const version = asInt(board.version, 0) + 1;
      const inserted = await client.query(
        `INSERT INTO public.meet_whiteboard_batches
           (meeting_id,domain_uuid,sequence,version,actor_ref,client_batch_id,kind,
            operations,inverse_operations,created_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8::jsonb,$9::jsonb,now()) RETURNING *`,
        [input.meetingId, tenant.domainUuid, sequence, version, input.actorId,
          input.clientBatchId, input.kind || "operations", JSON.stringify(input.operations), JSON.stringify(inverse)]
      );
      await client.query(
        `UPDATE public.meet_whiteboard_boards
            SET version=$3,last_sequence=$4,updated_at=now()
          WHERE meeting_id=$1 AND domain_uuid=$2`,
        [input.meetingId, tenant.domainUuid, version, sequence]
      );
      await this._maybeCheckpoint(client, tenant, input.meetingId, board, version, sequence,
        CollaborationRepository.applyOperations(document, input.operations));
      const mapped = mapBatch(inserted.rows[0]);
      mapped.duplicate = false;
      return mapped;
    });
  }

  async whiteboardUndoRedo(tenant, input) {
    return transaction(this.pool, async (client) => {
      await this._boardSnapshot(client, tenant, input.meetingId, input.initialLocked);
      const boardResult = await client.query(
        `SELECT * FROM public.meet_whiteboard_boards
          WHERE meeting_id=$1 AND domain_uuid=$2 FOR UPDATE`,
        [input.meetingId, tenant.domainUuid]
      );
      const board = boardResult.rows[0];
      const duplicate = await client.query(
        `SELECT * FROM public.meet_whiteboard_batches
          WHERE meeting_id=$1 AND domain_uuid=$2 AND actor_ref=$3 AND client_batch_id=$4`,
        [input.meetingId, tenant.domainUuid, input.actorId, input.clientBatchId]
      );
      if (duplicate.rows[0]) {
        if (duplicate.rows[0].kind !== input.kind) {
          throw new CollaborationError("clientBatchId was reused for another action", "IDEMPOTENCY_CONFLICT", 409);
        }
        const mapped = mapBatch(duplicate.rows[0]);
        mapped.duplicate = true;
        return mapped;
      }
      if (asInt(input.expectedVersion, -1) !== asInt(board.version, 0)) {
        throw new CollaborationError("The whiteboard changed", "VERSION_CONFLICT", 409, {
          version: asInt(board.version, 0), sequence: asInt(board.last_sequence, 0),
        });
      }
      const undo = input.kind === "undo";
      const target = await client.query(
        `SELECT * FROM public.meet_whiteboard_batches
          WHERE meeting_id=$1 AND domain_uuid=$2 AND actor_ref=$3 AND kind='operations'
            AND undone=$4 ORDER BY sequence DESC LIMIT 1 FOR UPDATE`,
        [input.meetingId, tenant.domainUuid, input.actorId, !undo]
      );
      if (!target.rows[0]) throw new CollaborationError("Nothing to " + input.kind, "NOTHING_TO_" + input.kind.toUpperCase(), 409);
      const targetRow = target.rows[0];
      const operations = undo ? targetRow.inverse_operations : targetRow.operations;
      const sequence = asInt(board.last_sequence, 0) + 1;
      const version = asInt(board.version, 0) + 1;
      const inserted = await client.query(
        `INSERT INTO public.meet_whiteboard_batches
           (meeting_id,domain_uuid,sequence,version,actor_ref,client_batch_id,kind,
            operations,inverse_operations,target_batch_id,created_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8::jsonb,'[]'::jsonb,$9,now()) RETURNING *`,
        [input.meetingId, tenant.domainUuid, sequence, version, input.actorId,
          input.clientBatchId, input.kind, JSON.stringify(operations), targetRow.id]
      );
      await client.query(`UPDATE public.meet_whiteboard_batches SET undone=$2 WHERE id=$1`, [targetRow.id, undo]);
      await client.query(
        `UPDATE public.meet_whiteboard_boards SET version=$3,last_sequence=$4,updated_at=now()
          WHERE meeting_id=$1 AND domain_uuid=$2`,
        [input.meetingId, tenant.domainUuid, version, sequence]
      );
      const mapped = mapBatch(inserted.rows[0]);
      mapped.duplicate = false;
      return mapped;
    });
  }

  async clearWhiteboard(tenant, input) {
    input.operations = [{ type: "clear" }];
    input.destructive = true;
    input.kind = "clear";
    return this.applyWhiteboardBatch(tenant, input);
  }

  async setWhiteboardLock(tenant, input) {
    return transaction(this.pool, async (client) => {
      await this._boardSnapshot(client, tenant, input.meetingId);
      const boardResult = await client.query(
        `SELECT * FROM public.meet_whiteboard_boards
          WHERE meeting_id=$1 AND domain_uuid=$2 FOR UPDATE`,
        [input.meetingId, tenant.domainUuid]
      );
      const board = boardResult.rows[0];
      const duplicate = await client.query(
        `SELECT * FROM public.meet_whiteboard_batches
          WHERE meeting_id=$1 AND domain_uuid=$2 AND actor_ref=$3 AND client_batch_id=$4`,
        [input.meetingId, tenant.domainUuid, input.actorId, input.clientBatchId]
      );
      if (duplicate.rows[0]) {
        const operations = duplicate.rows[0].operations || [];
        const previousLock = operations[0] && operations[0].locked;
        if (duplicate.rows[0].kind !== "lock" || previousLock !== !!input.locked) {
          throw new CollaborationError("clientBatchId was reused for another action", "IDEMPOTENCY_CONFLICT", 409);
        }
        const mapped = mapBatch(duplicate.rows[0]);
        mapped.locked = !!input.locked;
        mapped.duplicate = true;
        return mapped;
      }
      if (asInt(input.expectedVersion, -1) !== asInt(board.version, 0)) {
        throw new CollaborationError("The whiteboard changed", "VERSION_CONFLICT", 409, {
          version: asInt(board.version, 0), sequence: asInt(board.last_sequence, 0),
        });
      }
      if (!!board.locked === !!input.locked) {
        return { id: null, kind: "lock", locked: !!board.locked, sequence: asInt(board.last_sequence, 0), version: asInt(board.version, 0), duplicate: true };
      }
      const sequence = asInt(board.last_sequence, 0) + 1;
      const version = asInt(board.version, 0) + 1;
      const inserted = await client.query(
        `INSERT INTO public.meet_whiteboard_batches
           (meeting_id,domain_uuid,sequence,version,actor_ref,client_batch_id,kind,operations,inverse_operations,created_at)
         VALUES ($1,$2,$3,$4,$5,$6,'lock',$7::jsonb,'[]'::jsonb,now()) RETURNING *`,
        [input.meetingId, tenant.domainUuid, sequence, version, input.actorId,
          input.clientBatchId, JSON.stringify([{ type: "lock", locked: !!input.locked }])]
      );
      await client.query(
        `UPDATE public.meet_whiteboard_boards
            SET version=$3,last_sequence=$4,locked=$5,locked_by_ref=$6,updated_at=now()
          WHERE meeting_id=$1 AND domain_uuid=$2`,
        [input.meetingId, tenant.domainUuid, version, sequence, !!input.locked, input.actorId]
      );
      const batch = mapBatch(inserted.rows[0]);
      batch.locked = !!input.locked;
      batch.duplicate = false;
      return batch;
    });
  }

  /// Put the whiteboard on the room's stage, or take it off again.
  //
  // Mirrors setWhiteboardLock deliberately, batch and all: that gets versioning,
  // idempotency and the broadcast for free, and undo/redo already selects
  // kind='operations' so a presentation can never be undone by Ctrl+Z — which
  // it must not be, because it is view state and not a board edit.
  async setWhiteboardPresenting(tenant, input) {
    return transaction(this.pool, async (client) => {
      await this._boardSnapshot(client, tenant, input.meetingId);
      const boardResult = await client.query(
        `SELECT * FROM public.meet_whiteboard_boards
          WHERE meeting_id=$1 AND domain_uuid=$2 FOR UPDATE`,
        [input.meetingId, tenant.domainUuid]
      );
      const board = boardResult.rows[0];
      const duplicate = await client.query(
        `SELECT * FROM public.meet_whiteboard_batches
          WHERE meeting_id=$1 AND domain_uuid=$2 AND actor_ref=$3 AND client_batch_id=$4`,
        [input.meetingId, tenant.domainUuid, input.actorId, input.clientBatchId]
      );
      if (duplicate.rows[0]) {
        const operations = duplicate.rows[0].operations || [];
        const previous = operations[0] && operations[0].presenting;
        if (duplicate.rows[0].kind !== "present" || previous !== !!input.presenting) {
          throw new CollaborationError("clientBatchId was reused for another action", "IDEMPOTENCY_CONFLICT", 409);
        }
        const mapped = mapBatch(duplicate.rows[0]);
        mapped.presenting = !!input.presenting;
        mapped.presenterId = board.presenting_by_ref || null;
        mapped.presenterName = board.presenting_by_name || null;
        mapped.duplicate = true;
        return mapped;
      }
      if (asInt(input.expectedVersion, -1) !== asInt(board.version, 0)) {
        throw new CollaborationError("The whiteboard changed", "VERSION_CONFLICT", 409, {
          version: asInt(board.version, 0), sequence: asInt(board.last_sequence, 0),
        });
      }
      // Presenting is offered to whoever may draw, so a locked board — where
      // only moderators may draw — is also one only a moderator may present.
      // Checked here rather than in the route because the lock lives on the row
      // this transaction has already taken for update.
      if (input.presenting && board.locked && !input.isModerator) {
        throw new CollaborationError("The whiteboard is locked", "WHITEBOARD_LOCKED", 403);
      }
      // Only the presenter or a moderator may take it down, matching the rule a
      // file presentation already enforces. Without this any participant could
      // end somebody else's presentation.
      if (!input.presenting && board.presenting && board.presenting_by_ref !== input.actorId && !input.isModerator) {
        throw new CollaborationError(
          "Only the presenter or a moderator can stop this presentation",
          "PRESENTATION_FORBIDDEN", 403
        );
      }
      if (!!board.presenting === !!input.presenting) {
        return {
          id: null, kind: "present", presenting: !!board.presenting,
          presenterId: board.presenting_by_ref || null,
          presenterName: board.presenting_by_name || null,
          sequence: asInt(board.last_sequence, 0), version: asInt(board.version, 0), duplicate: true,
        };
      }
      const sequence = asInt(board.last_sequence, 0) + 1;
      const version = asInt(board.version, 0) + 1;
      const inserted = await client.query(
        `INSERT INTO public.meet_whiteboard_batches
           (meeting_id,domain_uuid,sequence,version,actor_ref,client_batch_id,kind,operations,inverse_operations,created_at)
         VALUES ($1,$2,$3,$4,$5,$6,'present',$7::jsonb,'[]'::jsonb,now()) RETURNING *`,
        [input.meetingId, tenant.domainUuid, sequence, version, input.actorId,
          input.clientBatchId, JSON.stringify([{ type: "present", presenting: !!input.presenting }])]
      );
      const presenterId = input.presenting ? input.actorId : null;
      const presenterName = input.presenting ? (input.actorName || null) : null;
      await client.query(
        `UPDATE public.meet_whiteboard_boards
            SET version=$3,last_sequence=$4,presenting=$5,presenting_by_ref=$6,presenting_by_name=$7,updated_at=now()
          WHERE meeting_id=$1 AND domain_uuid=$2`,
        [input.meetingId, tenant.domainUuid, version, sequence, !!input.presenting, presenterId, presenterName]
      );
      const batch = mapBatch(inserted.rows[0]);
      batch.presenting = !!input.presenting;
      batch.presenterId = presenterId;
      batch.presenterName = presenterName;
      batch.duplicate = false;
      return batch;
    });
  }

  async _maybeCheckpoint(client, tenant, meetingId, previousBoard, version, sequence, document) {
    const checkpointSequence = asInt(previousBoard.last_checkpoint_sequence, 0);
    const checkpointAt = previousBoard.last_checkpoint_at
      ? new Date(previousBoard.last_checkpoint_at).getTime()
      : new Date(previousBoard.updated_at || Date.now()).getTime();
    if (sequence - checkpointSequence < 500 && Date.now() - checkpointAt < 5 * 60 * 1000) return;
    await client.query(
      `INSERT INTO public.meet_whiteboard_checkpoints
         (meeting_id,domain_uuid,through_sequence,version,document,created_at)
       VALUES ($1,$2,$3,$4,$5::jsonb,now()) ON CONFLICT (meeting_id,through_sequence) DO NOTHING`,
      [meetingId, tenant.domainUuid, sequence, version, JSON.stringify(document)]
    );
    await client.query(
      `UPDATE public.meet_whiteboard_boards
          SET last_checkpoint_sequence=$3,last_checkpoint_at=now()
        WHERE meeting_id=$1 AND domain_uuid=$2`,
      [meetingId, tenant.domainUuid, sequence]
    );
  }
}

function mapBatch(row) {
  return {
    id: row.id,
    kind: row.kind,
    sequence: asInt(row.sequence, 0),
    version: asInt(row.version, 0),
    actorId: row.actor_ref,
    operations: row.operations || [],
    targetBatchId: row.target_batch_id || null,
    createdAt: row.created_at instanceof Date ? row.created_at.toISOString() : row.created_at,
  };
}

function mapFileAsset(row, attachment, pages) {
  const source = attachment || row || {};
  return {
    id: row.id,
    attachmentId: row.attachment_id,
    meetingId: row.meeting_id,
    purpose: row.purpose,
    status: row.conversion_status,
    pageCount: asInt(row.page_count, 0),
    failureCode: row.failure_code || null,
    updatedAt: row.updated_at instanceof Date ? row.updated_at.toISOString() : row.updated_at,
    name: source.original_name || "File",
    mime: source.detected_mime || source.attachment_mime || source.mime || "application/octet-stream",
    bytes: asInt(source.attachment_bytes !== undefined ? source.attachment_bytes : source.bytes, 0),
    storagePath: source.storage_path || null,
    pages: (pages || []).map(function (page) {
      return {
        id: page.id,
        pageNumber: asInt(page.page_number, 0),
        mime: page.mime,
        bytes: asInt(page.bytes, 0),
        sha256: page.sha256,
        width: asInt(page.width, 0),
        height: asInt(page.height, 0),
        storagePath: page.storage_path,
      };
    }),
  };
}

/** A stored annotation document, defended against a null or malformed row. */
function normaliseDocument(value) {
  if (!value || typeof value !== "object" || Array.isArray(value)) return { elements: {} };
  const elements = value.elements;
  if (!elements || typeof elements !== "object" || Array.isArray(elements)) return { elements: {} };
  return value;
}

function mapAnnotations(row, assetId, pageNumber) {
  const value = row || {};
  return {
    assetId: value.asset_id || assetId || null,
    pageNumber: value.page_number === null || value.page_number === undefined
      ? (pageNumber === undefined ? null : pageNumber)
      : asInt(value.page_number, 1),
    version: asInt(value.version, 0),
    sequence: asInt(value.last_sequence, 0),
    document: normaliseDocument(value.document),
    updatedAt: value.updated_at instanceof Date ? value.updated_at.toISOString() : value.updated_at || null,
  };
}

function mapPresentation(row) {
  const value = row || {};
  return {
    version: asInt(value.version, 0),
    sequence: asInt(value.last_sequence, 0),
    status: value.status || "idle",
    assetId: value.active_asset_id || null,
    presenterId: value.presenter_ref || null,
    presenterName: value.presenter_name || null,
    pageNumber: value.page_number === null || value.page_number === undefined ? null : asInt(value.page_number, 1),
    pageCount: asInt(value.page_count, 0),
    name: value.original_name || null,
    mime: value.detected_mime || null,
    // Absent on a database that predates annotation, and absence must read as
    // 'presenter' -- never as 'everyone', which would open a deck the
    // presenter never opened the moment a client upgrades.
    annotationMode: value.annotation_mode === "everyone" ? "everyone" : "presenter",
    updatedAt: value.updated_at instanceof Date ? value.updated_at.toISOString() : value.updated_at,
  };
}

module.exports = {
  CollaborationRepository: CollaborationRepository,
  CollaborationError: CollaborationError,
  requestHash: requestHash,
  stableJson: stableJson,
  uuid: uuid,
};
