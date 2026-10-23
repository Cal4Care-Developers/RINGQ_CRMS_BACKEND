"use strict";

require("dotenv").config({
  path: process.env.MEET_ENV_FILE || "/etc/ringq/meet-control-plane.env",
  // Keep this worker on the same authoritative configuration contract as the
  // control plane even when PM2 retains stale variables across reloads.
  override: true,
  quiet: true,
});

const { Pool } = require("pg");

const POLL_MS = Math.max(1000, Number(process.env.MEET_SUMMARY_POLL_MS || 5000));
const MAX_ATTEMPTS = Math.max(1, Math.min(5, Number(process.env.MEET_SUMMARY_MAX_ATTEMPTS || 3)));
const ENDPOINT = String(process.env.MEET_SUMMARY_PROVIDER_URL || "").trim();
const MODEL = String(process.env.MEET_SUMMARY_PROVIDER_MODEL || "").trim();
const TOKEN = String(process.env.MEET_SUMMARY_PROVIDER_TOKEN || "").trim();
const PROVIDER = String(process.env.MEET_SUMMARY_PROVIDER || "ringq").trim();

const pool = new Pool({
  host: process.env.PostgreSQL_DB_HOST || "127.0.0.1",
  port: Number(process.env.PostgreSQL_DB_PORT || 5432),
  database: process.env.PostgreSQL_DB_NAME,
  user: process.env.PostgreSQL_DB_USER,
  password: process.env.PostgreSQL_DB_PASSWORD,
  max: 2,
  idleTimeoutMillis: 10000,
  connectionTimeoutMillis: 5000,
});

const summarySchema = {
  type: "object",
  additionalProperties: false,
  required: ["overview", "keyPoints", "decisions", "actionItems", "unansweredQuestions"],
  properties: {
    overview: { type: "string", maxLength: 8000 },
    keyPoints: { type: "array", maxItems: 100, items: { type: "string", maxLength: 2000 } },
    decisions: { type: "array", maxItems: 100, items: { type: "string", maxLength: 2000 } },
    actionItems: {
      type: "array",
      maxItems: 100,
      items: {
        type: "object",
        additionalProperties: false,
        required: ["item", "owner"],
        properties: {
          item: { type: "string", maxLength: 2000 },
          owner: { type: ["string", "null"], maxLength: 300 },
        },
      },
    },
    unansweredQuestions: { type: "array", maxItems: 100, items: { type: "string", maxLength: 2000 } },
  },
};

function validateSummary(value) {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error("summary_schema_invalid");
  const stringList = (name) => {
    if (!Array.isArray(value[name]) || value[name].some((item) => typeof item !== "string")) {
      throw new Error("summary_schema_invalid");
    }
  };
  if (typeof value.overview !== "string") throw new Error("summary_schema_invalid");
  stringList("keyPoints");
  stringList("decisions");
  stringList("unansweredQuestions");
  if (!Array.isArray(value.actionItems) || value.actionItems.some((item) =>
    !item || typeof item !== "object" || typeof item.item !== "string" ||
    !(item.owner === null || typeof item.owner === "string"))) {
    throw new Error("summary_schema_invalid");
  }
  return value;
}

function plainText(summary) {
  const section = (title, values) => `${title}\n${values.length ? values.map((v) => `- ${v}`).join("\n") : "- None stated"}`;
  return [
    `Overview\n${summary.overview}`,
    section("Key points", summary.keyPoints),
    section("Decisions", summary.decisions),
    section("Action items", summary.actionItems.map((item) => `${item.item}${item.owner ? ` — ${item.owner}` : ""}`)),
    section("Unanswered questions", summary.unansweredQuestions),
  ].join("\n\n");
}

function extractProviderJson(payload) {
  if (payload && payload.output && typeof payload.output === "object") return payload.output;
  const chat = payload && payload.choices && payload.choices[0] && payload.choices[0].message;
  if (chat && typeof chat.parsed === "object") return chat.parsed;
  if (chat && typeof chat.content === "string") return JSON.parse(chat.content);
  const responseText = payload && Array.isArray(payload.output)
    ? payload.output.flatMap((item) => item.content || []).find((item) => item.type === "output_text")?.text
    : null;
  if (responseText) return JSON.parse(responseText);
  throw new Error("summary_provider_response_invalid");
}

async function providerSummary(transcript) {
  if (!ENDPOINT || !MODEL || !TOKEN) throw new Error("summary_provider_unavailable");
  const response = await fetch(ENDPOINT, {
    method: "POST",
    headers: { authorization: `Bearer ${TOKEN}`, "content-type": "application/json" },
    body: JSON.stringify({
      model: MODEL,
      temperature: 0,
      messages: [
        {
          role: "system",
          content: "Summarize only what is explicitly stated in the finalized meeting transcript. Do not infer decisions, owners, or action items. Return JSON matching the supplied schema.",
        },
        { role: "user", content: transcript },
      ],
      response_format: {
        type: "json_schema",
        json_schema: { name: "meeting_summary", strict: true, schema: summarySchema },
      },
    }),
    signal: AbortSignal.timeout(120000),
  });
  if (!response.ok) throw new Error(`summary_provider_${response.status}`);
  return validateSummary(extractProviderJson(await response.json()));
}

async function claim() {
  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    const found = await client.query(
      `SELECT id, meeting_id, domain_uuid, attempts
         FROM public.meet_summaries
        WHERE status = 'pending' AND attempts < $1
        ORDER BY updated_at ASC
        FOR UPDATE SKIP LOCKED LIMIT 1`,
      [MAX_ATTEMPTS],
    );
    if (!found.rowCount) {
      await client.query("COMMIT");
      return null;
    }
    const row = found.rows[0];
    await client.query(
      `UPDATE public.meet_summaries
          SET status='processing', attempts=attempts+1, failure_code=NULL, updated_at=now()
        WHERE id=$1`,
      [row.id],
    );
    await client.query("COMMIT");
    return { ...row, attempts: Number(row.attempts) + 1 };
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
}

async function processOne(row) {
  const segments = await pool.query(
    `SELECT participant_name, start_ms, text
       FROM public.meet_transcripts
      WHERE domain_uuid=$1 AND meeting_id=$2 AND is_final=true
      ORDER BY sequence ASC, start_ms ASC`,
    [row.domain_uuid, row.meeting_id],
  );
  if (!segments.rowCount) throw new Error("transcript_unavailable");
  const transcript = segments.rows.map((segment) =>
    `[${Math.max(0, Math.floor(Number(segment.start_ms || 0) / 1000))}s] ${segment.participant_name || "Participant"}: ${segment.text}`,
  ).join("\n");
  if (Buffer.byteLength(transcript, "utf8") > 2 * 1024 * 1024) throw new Error("transcript_too_large");
  const summary = await providerSummary(transcript);
  await pool.query(
    `UPDATE public.meet_summaries
        SET status='ready', structured=$2::jsonb, plain_text=$3, provider=$4,
            model=$5, failure_code=NULL, updated_at=now()
      WHERE id=$1`,
    [row.id, JSON.stringify(summary), plainText(summary), PROVIDER, MODEL],
  );
}

async function fail(row, error) {
  const code = String(error && error.message || "summary_failed").replace(/[^a-zA-Z0-9_-]/g, "_").slice(0, 100);
  const status = code === "summary_provider_unavailable" ? "unavailable" : row.attempts >= MAX_ATTEMPTS ? "failed" : "pending";
  await pool.query(
    `UPDATE public.meet_summaries SET status=$2, failure_code=$3, updated_at=now() WHERE id=$1`,
    [row.id, status, code],
  );
}

let stopping = false;
async function tick() {
  if (stopping) return;
  let row = null;
  try {
    row = await claim();
    if (row) await processOne(row);
  } catch (error) {
    if (row) await fail(row, error).catch(() => {});
    else console.error("[meet-summary] queue failure", String(error && error.message || error));
  } finally {
    if (!stopping) setTimeout(tick, row ? 25 : POLL_MS).unref();
  }
}

for (const signal of ["SIGTERM", "SIGINT"]) {
  process.on(signal, async () => {
    stopping = true;
    await pool.end().catch(() => {});
    process.exit(0);
  });
}

tick();
