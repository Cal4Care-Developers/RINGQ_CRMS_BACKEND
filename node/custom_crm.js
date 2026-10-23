require('./servermode')('custom_crm'); // [server-mode] production/debug logging, see servermode.js
const ESL = require('modesl');
const axios = require('axios');
const fs = require('fs');
const redis = require('redis');
const { promisify } = require('util');

// =========================================
// REDIS CLIENT  (redis v2/v3 compatible)
// v3 auto-connects on createClient — no .connect() needed
// =========================================

let redisClient;
try {
    // Attempt to create client with legacyMode (needed for Redis v4 callback support)
    redisClient = redis.createClient({
        legacyMode: true,
        socket: {
            host: '127.0.0.1',
            port: 6379
        },
        // Fallback options for older versions
        host: '127.0.0.1',
        port: 6379,
        retry_strategy: (options) => {
            if (options.total_retry_time > 1000 * 60) return new Error('Redis retry timeout');
            return Math.min(options.attempt * 500, 3000);
        }
    });
} catch (e) {
    // Fallback for Redis v2/v3
    redisClient = redis.createClient({
        host: '127.0.0.1',
        port: 6379,
        retry_strategy: (options) => {
            if (options.total_retry_time > 1000 * 60) return new Error('Redis retry timeout');
            return Math.min(options.attempt * 500, 3000);
        }
    });
}

// Connect if it's Redis v4 (which requires calling .connect())
if (typeof redisClient.connect === 'function') {
    redisClient.connect().catch((err) => {
        log('SYSTEM', 'Redis connect promise error: ' + err.message);
    });
}

redisClient.on('connect', async () => {
    log('SYSTEM', 'Redis connected');
    await loadAllConfigsToCache();
    // Sync configurations from Redis in the background every 5 minutes (non-blocking)
    setInterval(loadAllConfigsToCache, 5 * 60 * 1000);
});

redisClient.on('error', (err) => {
    log('REDIS_ERROR', { message: err.message });
});

// Wrap callback-style redis methods into promises for utility use
const redisGet = promisify(redisClient.get).bind(redisClient);
const redisSetNX = promisify(redisClient.set).bind(redisClient);

// =========================================
// LOGGER
// =========================================

const calls = {};
const webhookCache = {}; // subdomain -> config

// Effective call_id (post agentCallUuid override, see CALL_ID_OVERRIDDEN_FOR_QUEUE)
// dedupe so a call.completed webhook is never sent twice for the same logical
// call even when two different channels both legitimately resolve to the same
// id (see the FINAL DUPLICATE-DISPATCH GUARD comment below). This MUST live in
// Redis, not a local in-memory Map: an in-memory guard only dedupes within one
// process, and pm2 restarts (or a stuck old process that hasn't fully exited
// yet) can leave two custom_crm.js processes connected to FreeSWITCH's ESL at
// once, each with its own empty in-memory map — each sends exactly one
// webhook internally, but the two together still produce a duplicate. `SET
// NX EX` is atomic in Redis, so only the first process (of however many) to
// claim a given call_id actually dispatches, no matter how many are running.
const DISPATCH_DEDUPE_TTL_SECONDS = 5 * 60;

async function isDuplicateHangupDispatch(callId) {
    if (!callId) return false;
    try {
        const key = `crm_dispatch_dedupe:${callId}`;
        const result = await redisSetNX(key, '1', 'NX', 'EX', DISPATCH_DEDUPE_TTL_SECONDS);
        // Redis SET ... NX returns 'OK' when the key was newly set (first claim),
        // or null when it already existed (someone already dispatched this call).
        return result !== 'OK';
    } catch (err) {
        log('DISPATCH_DEDUPE_REDIS_ERROR', { callId, message: err.message });
        // Fail open: if Redis itself is unreachable, don't block a real dispatch
        // over an infra hiccup — worst case we're back to the pre-fix behavior.
        return false;
    }
}

function log(type, data) {
    const line =
        `[${new Date().toISOString()}] [${type}] ` +
        JSON.stringify(data, null, 2);

    console.log(line);

    fs.appendFileSync('./webhook.log', line + '\n');
}

// =========================================
// CALL-LEVEL DEDUPE (root-leg based, not "first to arrive")
// A single phone call produces TWO channel legs in FreeSWITCH:
//   - A-leg: the caller's original inbound channel.
//     Its variable_call_uuid EQUALS its own Unique-ID.
//   - B-leg: the channel FreeSWITCH creates to bridge to the callee.
//     Its variable_call_uuid points back to the A-LEG's Unique-ID
//     (i.e. different from its own Unique-ID).
// Both legs fire their own CHANNEL_HANGUP_COMPLETE event. We only
// want to build/dispatch the webhook once, using the A-leg's data,
// because:
//   - the A-leg has the real caller number and real dialed
//     destination (the B-leg's "destination" is an internal
//     synthetic string, not what the customer dialed)
//   - "first hangup to arrive" is not reliably the A-leg — in
//     practice the B-leg's hangup event can arrive first, which
//     silently poisoned the payload with B-leg data
// So: a leg is the "root" leg (safe to use for the payload) only
// when uuid === rootCallUuid. Non-root legs are skipped for dispatch.
// =========================================

// =========================================
// REDIS: BACKGROUND CACHE INITIALIZATION / SYNC
// =========================================

async function loadAllConfigsToCache() {
    try {
        log('SYSTEM', 'Refreshing webhook configurations cache from Redis...');
        const keys = await new Promise((resolve, reject) => {
            redisClient.keys('custom_webhook:*', (err, res) => {
                if (err) reject(err);
                else resolve(res || []);
            });
        });

        const newCache = {};
        for (const key of keys) {
            const subdomain = key.replace('custom_webhook:', '');
            const raw = await redisGet(key);
            if (raw) {
                try {
                    newCache[subdomain] = JSON.parse(raw);
                } catch (e) {
                    log('REDIS_PARSE_ERROR', { key, error: e.message });
                }
            }
        }

        // Atomic replacement of local cache
        Object.keys(webhookCache).forEach(k => delete webhookCache[k]);
        Object.assign(webhookCache, newCache);

        log('SYSTEM_CACHE_SYNCED', { count: Object.keys(webhookCache).length, subdomains: Object.keys(webhookCache) });
    } catch (err) {
        log('SYSTEM_CACHE_SYNC_ERROR', { message: err.message, stack: err.stack });
    }
}

// =========================================
// API: FETCH TRANSCRIPT & AI DETAILS
// =========================================

// The report API transcribes the call's recording on demand (same engine and the same
// Setting -> Global -> AI Transcribe configuration as voicemail transcription). These messages mean "this
// will never work for this tenant" -- retrying only delays the webhook.
function isTranscriptionDisabledMessage(message) {
    return /enable AI transcription|AI transcribe token/i.test(String(message || ''));
}

// "Call not in the CDR yet" / "recording not written yet" are worth waiting for, so allow a few more tries.
async function fetchTranscriptDetails(uuid, subdomain, maxRetries = 8, retryDelay = 5000) {
    const host = subdomain || 'mr.ringq.ai';
    const url = `https://${host}:8443/reports/nodeaudiototext/${uuid}/${subdomain}`;

    let nginx404Count = 0;
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            log('TRANSCRIPT_API_REQUEST', {
                uuid,
                url,
                attempt
            });

            const response = await axios.get(url, {
                headers: {
                    "X-Internal-Key": "RingQ-Internal-Secret-818849038148786300654846001886"
                },
                // The request itself runs the speech-to-text on a fresh recording, which can take a while.
                timeout: 120000
            });

            log('TRANSCRIPT_API_RESPONSE', {
                uuid,
                url,
                attempt,
                status: response.status,
                data: response.data
            });

            // API says transcript not ready
            if (response.data?.result === "error") {
                log('TRANSCRIPT_NOT_READY', {
                    uuid,
                    attempt,
                    message: response.data?.message
                });

                // AI Transcribe is switched off (or has no token) for this tenant: nothing to wait for.
                if (isTranscriptionDisabledMessage(response.data?.message)) {
                    log('TRANSCRIPTION_DISABLED', {
                        uuid,
                        message: 'AI Transcribe is not enabled in Settings -> Global; sending the webhook without a transcript.'
                    });
                    break;
                }

                if (attempt < maxRetries) {
                    await new Promise(resolve => setTimeout(resolve, retryDelay));
                    continue;
                }

                break;
            }

            const content = response.data?.content || {};

            const result = {
                transcript:
                    content.transcribedText ||
                    content.transcript ||
                    content.text ||
                    '',

                aiSummary:
                    content.summary ||
                    content.aiSummary ||
                    content.ai_summary ||
                    '',

                sentiment:
                    content.sentiment ||
                    content.call_sentiment ||
                    ''
            };

            // On-demand transcription stores the placeholders "No Summary Available" / "neutral" next to the
            // text (it only transcribes, like voicemail). They are not real analysis -- do not pass them to
            // the CRM as if they were.
            if (/^no summary available$/i.test(result.aiSummary.trim())) {
                result.aiSummary = '';
                result.sentiment = '';
            }

            log('TRANSCRIPT_PARSED', {
                uuid,
                attempt,
                transcriptLength: result.transcript.length,
                aiSummary: result.aiSummary,
                sentiment: result.sentiment
            });

            // Transcript available
            if (result.transcript.trim().length > 0) {
                log('TRANSCRIPT_FOUND', {
                    uuid,
                    attempt
                });

                return result;
            }

            log('TRANSCRIPT_EMPTY', {
                uuid,
                attempt,
                message: `Transcript is empty. Retrying in ${retryDelay}ms...`
            });

        } catch (err) {
            const body = err.response?.data;
            // An HTML 404 is nginx's own page, not the app's JSON: either the /reports/nodeaudiototext route
            // is not deployed on this server, or the app answered "File not found" because the recording
            // file is not on disk and nginx replaced it. Log it in words, not just as a stack of errors.
            const nginx404 = err.response?.status === 404 && typeof body === 'string' && /<html/i.test(body);
            log('TRANSCRIPT_API_ERROR', {
                uuid,
                attempt,
                message: err.message,
                hint: nginx404
                    ? 'nginx 404: the transcript route is not deployed on this server, or the call recording file is missing on disk'
                    : undefined,
                response: nginx404 ? '(nginx 404 page)' : body
            });
            if (nginx404 && ++nginx404Count >= 3) {
                log('TRANSCRIPT_ENDPOINT_UNAVAILABLE', {
                    uuid,
                    message: 'Three nginx 404 pages in a row; giving up. Check the /reports/nodeaudiototext route and the call recording file.'
                });
                break;
            }
        }

        // Wait before next retry
        if (attempt < maxRetries) {
            await new Promise(resolve => setTimeout(resolve, retryDelay));
        }
    }

    log('TRANSCRIPT_TIMEOUT', {
        uuid,
        message: `Transcript not available after ${maxRetries} attempts`
    });

    return {
        transcript: '',
        aiSummary: '',
        sentiment: ''
    };
}


// =========================================
// API: FETCH CONTACT & LAST WRAP-UP NOTE
// Matches actual response shape: content = single contact object (or null),
// wrapupnote = single note object (or null).
// =========================================
async function fetchContactDetails(uuid, subdomain, phoneNumber, domainUuid, maxRetries = 5, retryDelay = 5000) {
    const url = `https://${subdomain}:8443/teams/contactsByNumber`;

    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            log('CONTACT_API_REQUEST', { uuid, url, attempt, phoneNumber, domainUuid });

            const response = await axios.post(
                url,
                { number: phoneNumber, domain_uuid: domainUuid },
                {
                    headers: {
                        "X-Internal-Key": "RingQ-Internal-Secret-818849038148786300654846001886",
                        "Content-Type": "application/json"
                    }
                }
            );

            log('CONTACT_API_RESPONSE', {
                uuid,
                url,
                attempt,
                status: response.status,
                data: response.data
            });

            if (response.data?.result === "error") {
                log('CONTACT_NOT_FOUND', {
                    uuid,
                    attempt,
                    message: response.data?.message
                });

                if (attempt < maxRetries) {
                    await new Promise(resolve => setTimeout(resolve, retryDelay));
                    continue;
                }
                break;
            }

            const contact = response.data?.content || null;
            const wrapupNote = response.data?.wrapupnote || null;

            const result = { contact, wrapupNote };

            log('CONTACT_PARSED', {
                uuid,
                attempt,
                hasContact: !!contact,
                hasWrapupNote: !!wrapupNote
            });

            if (contact || wrapupNote) {
                log('CONTACT_FOUND', { uuid, attempt });
                return result;
            }

            // The lookup itself worked and this number is simply not a contact. A wrap-up note is only sent
            // alongside a contact, so there is nothing worth waiting for.
            log('CONTACT_NOT_A_CONTACT', { uuid, attempt, message: 'No contact for this number; not retrying.' });
            return result;

            log('CONTACT_EMPTY', {
                uuid,
                attempt,
                message: `No contact or wrap-up notes yet. Retrying in ${retryDelay}ms...`
            });

        } catch (err) {
            log('CONTACT_API_ERROR', {
                uuid,
                attempt,
                message: err.message,
                response: err.response?.data
            });
        }

        if (attempt < maxRetries) {
            await new Promise(resolve => setTimeout(resolve, retryDelay));
        }
    }

    log('CONTACT_TIMEOUT', {
        uuid,
        message: `Contact information not available after ${maxRetries} attempts`
    });

    return {
        contact: null,
        wrapupNote: null
    };
}


// =========================================
// REDIS: FETCH WEBHOOK CONFIG FROM LOCAL CACHE
// Reads instantly from memory cache to optimize call flow performance
// =========================================

async function getWebhookConfig(subdomain) {
    if (!subdomain) {
        log('WEBHOOK_CONFIG_MISSING_SUBDOMAIN');
        return null;
    }
    try {
        const key = `custom_webhook:${subdomain}`;
        const raw = await redisGet(key);
        if (raw) {
            try {
                const config = JSON.parse(raw);
                log('REDIS_CONFIG_HIT', { subdomain, url: config.url, active: config.active });
                // Keep local cache in sync
                webhookCache[subdomain] = config;
                return config;
            } catch (e) {
                log('REDIS_CONFIG_PARSE_ERROR', { subdomain, key, error: e.message });
            }
        } else {
            log('REDIS_CONFIG_MISSING', { subdomain, key });
        }
    } catch (err) {
        log('REDIS_GET_ERROR', { subdomain, error: err.message });
    }

    // Fallback to local memory cache if Redis retrieval failed or was empty
    const config = webhookCache[subdomain];
    if (config) {
        log('CACHE_FALLBACK', { subdomain, url: config.url, active: config.active });
        return config;
    }
    log('WEBHOOK_CONFIG_MISSING_IN_CACHE', { subdomain });
    return null;
}

// =========================================
// PAYLOAD BUILDER
// Only includes fields listed in config.payload_fields
// =========================================

function buildPayload(config, callData, eventType) {

    const allFields = {
        // Core identifiers
        call_id:            callData.uuid || '',
        CallFrom:           callData.caller || '',
        CallTo:             callData.destination || '',
        // Reflects what actually happened on the call rather than always
        // claiming "completed": 'completed' (answered), 'abandoned' (caller
        // hung up while queued/ringing), or 'no-answer' (direct/IVR call
        // nobody picked up). See the ANSWERED / MISSED / ABANDONED STATE
        // block in the hangup handler for how this is derived.
        Status:             callData.callState || 'completed',
        DialCallStatus:     callData.callState || 'completed',
        DialCallDuration:   callData.duration || '0',
        event_type:         eventType,

        // IVR / DTMF
        intent:             callData.digits || '',          // keys the caller pressed, e.g. "1" or "12"
        ivr_path:           callData.ivrPath || '',         // same keys with timestamps (JSON array)
        ivr_number:         callData.ivrNumber || '',       // the IVR menu's own number (extension), e.g. "5001"
        ivr_name:           callData.ivrName || '',

        // AI / Transcript fields (populated if available)
        transcript:         callData.transcript || '',
        ai_summary:         callData.aiSummary || '',
        sentiment:          callData.sentiment || '',

        // contact / wrap-up note fields (populated if available). A wrap-up
        // note is only meaningful alongside a matched contact, so if there's
        // no contact, force wrap-up notes empty too rather than sending a
        // note detached from any contact.
        contacts_details:      callData.contact || '',
        wrapup_notes:          callData.contact ? (callData.wrapupNote || '') : '',

        // Recording — there's nothing to play back if the call was never
        // answered, so only include it once the call actually connected.
        recordingurl:       callData.answered ? (callData.recordingUrl || '') : '',

        // Call flow / queue / agent
        call_flow:          callData.callFlow || '',
        queue_details:      callData.queueDetails || '',
        agent_details:      callData.agentDetails || '',
        transfer_history:   callData.transferHistory || '',

        // Timestamps
        start_time:         callData.startTime
            ? new Date(callData.startTime).toISOString()
            : '',
        end_time:           (callData.endTime ? new Date(callData.endTime) : new Date()).toISOString()
    };

    // Filter to only the fields the user selected in the webhook config
    const requestedFields = config.payload_fields || [];

    const filtered = {};

    // Always include these core fields
    const coreFields = ['call_id', 'CallFrom', 'CallTo', 'Status', 'DialCallStatus', 'DialCallDuration', 'event_type', 'start_time', 'end_time'];
    coreFields.forEach(f => {
        filtered[f] = allFields[f];
    });

    // Add user-selected optional fields. The settings page saves some of them under a shorter key than the
    // payload uses ("contact", "wrapup_note"), so map those first -- otherwise they were silently never sent.
    const FIELD_ALIASES = { contact: 'contacts_details', wrapup_note: 'wrapup_notes' };
    // "IVR intent" in the settings page = everything about the IVR: pressed keys, IVR number and name.
    const FIELD_COMPANIONS = { ivr_path: ['intent', 'ivr_number', 'ivr_name'] };
    requestedFields.forEach(requested => {
        const field = FIELD_ALIASES[requested] || requested;
        [field, ...(FIELD_COMPANIONS[field] || [])].forEach(f => {
            if (f in allFields && !(f in filtered)) {
                filtered[f] = allFields[f];
            }
        });
    });

    // A call that went through an IVR always carries the keys pressed and the IVR's number and name, on every
    // event (call.completed, ivr.completed, ...) -- even if "IVR intent" is not ticked among the optional
    // payload fields. For a call with no IVR these fields are simply left out.
    if (eventType === 'ivr.completed' || callData.isIVR) {
        ['ivr_path', 'intent', 'ivr_number', 'ivr_name'].forEach(f => {
            if (!(f in filtered)) filtered[f] = allFields[f];
        });
    }

    return filtered;
}

// =========================================
// TRIGGER MATCHER
// The webhook fires ONLY for the triggers ticked in the settings page, matched from what actually
// happened to the call:
//
//   agent.connected   an agent / extension picked up and was bridged to the caller. Sent at the moment of
//                     the bridge (CHANNEL_BRIDGE), not when the call ends.
//   call.completed    the call was answered (see callData.answered) and has ended.
//   call.missed       an inbound, non-queue call nobody answered.
//   queue.abandoned   the caller left a queue before any agent answered.
//   ivr.completed     the call went through an IVR menu; sent when the call ends, with the IVR's number,
//                     name and the keys the caller pressed.
//
// One call can match several ticked triggers (e.g. ivr.completed + call.completed). Each is sent as its
// own webhook with its own event_type.
// =========================================

const LEGACY_TRIGGER_LABELS = {
    'call completed': 'call.completed',
    'call missed': 'call.missed',
    'ivr completed': 'ivr.completed',
    'queue abandoned': 'queue.abandoned',
    'agent connected': 'agent.connected'
};

function normalizeTrigger(t) {
    const s = String(t || '').trim();
    return LEGACY_TRIGGER_LABELS[s.toLowerCase()] || s;
}

// `when` is 'connect' (an agent was just bridged to the caller) or 'hangup' (the call just ended).
function getMatchingTriggers(config, when, callData) {
    const active = new Set((config.triggers || []).map(normalizeTrigger));
    const matched = [];

    if (when === 'connect') {
        if (active.has('agent.connected')) matched.push('agent.connected');
        return matched;
    }

    // call.completed: the call actually connected to someone. Requiring callData.answered (rather than
    // matching on any hangup) keeps it from also firing for a missed/abandoned call.
    if (active.has('call.completed') && callData.answered) matched.push('call.completed');
    // call.missed: an inbound call that nobody answered. Not for a queue call (that is queue.abandoned)
    // and not for an agent's own outbound call that was not picked up.
    if (active.has('call.missed') && !callData.answered && !callData.isQueue && callData.direction !== 'outbound') {
        matched.push('call.missed');
    }
    if (active.has('queue.abandoned') && callData.isQueue && !callData.answered) matched.push('queue.abandoned');
    if (active.has('ivr.completed') && callData.isIVR) matched.push('ivr.completed');

    return matched;
}

// =========================================
// IVR MENUS  (the IVR's own number)
// IVR menus live in the PBX database with an extension -- the "IVR number". A call's own variables only
// carry that number indirectly (as the destination it was transferred to), so the tenant's menu list is
// fetched once (cached 5 minutes, endpoint reports/nodeivrmenus) and matched against every destination
// the call went through.
// =========================================

const ivrMenuCache = {}; // subdomain -> { at, menus: [{ uuid, name, extension }] }

async function getIvrMenus(subdomain) {
    const cached = ivrMenuCache[subdomain];
    if (cached && Date.now() - cached.at < 5 * 60 * 1000) return cached.menus;
    try {
        const res = await axios.get(
            `https://${subdomain}:8443/reports/nodeivrmenus/${encodeURIComponent(subdomain)}`,
            { headers: { "X-Internal-Key": "RingQ-Internal-Secret-818849038148786300654846001886" }, timeout: 10000 }
        );
        const menus = (res.data?.result === 'success' && Array.isArray(res.data.menus)) ? res.data.menus : [];
        ivrMenuCache[subdomain] = { at: Date.now(), menus };
        return menus;
    } catch (err) {
        log('IVR_MENUS_ERROR', { subdomain, message: err.message, response: err.response?.data });
        return cached ? cached.menus : [];
    }
}

// Sets call.isIVR / ivrNumber / ivrName from the hangup headers.
async function detectIvr(call, h) {
    const menus = await getIvrMenus(call.subdomain);

    // Every place the IVR's number can show up: the destination at hangup (after a transfer into the
    // menu), the originally dialed one, and the transfer history ("epoch:uuid:type:src/dest/context").
    const seen = new Set();
    [
        h['Caller-Destination-Number'], h['variable_destination_number'], h['variable_dialed_extension'],
        h['variable_sip_to_user'], h['variable_sip_req_user'], call.destination
    ].forEach(v => { if (v) seen.add(String(v)); });
    const history = h['variable_transfer_history'];
    (Array.isArray(history) ? history.join(';') : String(history || ''))
        .split(/[^A-Za-z0-9*#]+/).forEach(v => { if (v) seen.add(v); });

    const menuUuid = h['variable_ivr_menu_uuid'] || '';
    const menu = (menuUuid && menus.find(m => m.uuid === menuUuid))
        || menus.find(m => m.extension && seen.has(String(m.extension)));

    if (menu) {
        call.isIVR = true;
        call.ivrNumber = String(menu.extension || '');
        call.ivrName = menu.name || '';
    } else if (menuUuid) {
        call.isIVR = true;
    }

    // If keys were pressed or an IVR was found, say what was looked at so a miss can be diagnosed.
    if (call.isIVR || call.digits) {
        log('IVR_DETECTION', {
            uuid: call.uuid, isIVR: !!call.isIVR, ivrNumber: call.ivrNumber || '', ivrName: call.ivrName || '',
            digits: call.digits, menuUuidVar: menuUuid, menusKnown: menus.length, destinationsSeen: [...seen]
        });
    }
}

// =========================================
// WEBHOOK DISPATCHER
// Sends payload to the configured CRM URL
// =========================================

// Request headers from the saved auth settings (None / Basic / Bearer / Custom header). Used by the first
// attempt AND by every retry -- retries used to send Bearer only and silently dropped Basic / custom headers.
function buildHeaders(config) {
    const headers = {
        'Content-Type': 'application/json',
        'User-Agent': 'RingQ-Webhook/1.0'
    };

    if (config.auth_type === 'Basic' && config.username && config.password) {
        const encoded = Buffer.from(`${config.username}:${config.password}`).toString('base64');
        headers['Authorization'] = `Basic ${encoded}`;
    } else if (config.auth_type === 'Bearer' && config.token) {
        headers['Authorization'] = `Bearer ${config.token}`;
    } else if (config.auth_type === 'Header' && config.header_key && config.header_value) {
        headers[config.header_key] = config.header_value;
    }
    return headers;
}

// `trigger` is one already-matched trigger name ('call.completed', ...); it becomes the payload's event_type
// and is handed to retryWebhook() unchanged.
async function dispatchWebhook(config, callData, trigger) {
    try {
        if (!config.active) {
            log('WEBHOOK_SKIPPED', { reason: 'Webhook is inactive', url: config.url });
            return;
        }

        const payload = buildPayload(config, callData, trigger);
        const headers = buildHeaders(config);

        // Add secret if configured
        if (config.secret) {
            payload.secret = config.secret;
        }

        log('WEBHOOK_DISPATCH_ATTEMPT', {
            url: config.url,
            method: 'POST',
            headers,
            payload,
            timeout: (config.timeout || 30) * 1000,
            maxRedirects: 5
        });

        const response = await axios.post(config.url, payload, {
            headers,
            timeout: (config.timeout || 30) * 1000,
            maxRedirects: 5
        });

        log('WEBHOOK_SUCCESS', {
            url: config.url,
            status: response.status,
            statusText: response.statusText,
            headers: response.headers,
            data: response.data
        });

        // Update last_status in Redis
        await updateWebhookStatus(callData.subdomain, 'success', response.status, JSON.stringify(response.data));

    } catch (err) {
        const statusCode = err.response?.status || 0;
        const responseHeaders = err.response?.headers || null;
        const responseBody = err.response?.data
            ? JSON.stringify(err.response.data)
            : err.message;

        log('WEBHOOK_ERROR', {
            url: config.url,
            message: err.message,
            statusCode,
            responseHeaders,
            responseBody,
            stack: err.stack
        });

        await updateWebhookStatus(callData.subdomain, 'failed', statusCode, responseBody);

        // Retry logic based on config.retry_count
        if (config.retry_count > 0) {
            await retryWebhook(callData.subdomain, callData, trigger, config.retry_count);
        }
    }
}

// =========================================
// RETRY LOGIC
// =========================================

async function retryWebhook(subdomain, callData, eventType, retriesLeft, attempt = 1) {
    if (retriesLeft <= 0) return;

    const delay = attempt * 3000; // 3s, 6s, 9s backoff
    log('WEBHOOK_RETRY_SCHEDULED', { attempt, retriesLeft, delayMs: delay, subdomain });

    await new Promise(resolve => setTimeout(resolve, delay));

    // Re-fetch the webhook config fresh on every retry attempt instead of
    // reusing the snapshot captured when the call first hung up. Retries can
    // trail the original attempt by many seconds (3s/6s/9s backoff, possibly
    // repeated); if the user changes the webhook URL in the admin panel
    // during that window, a stale captured config would keep resending to
    // the URL that no longer applies.
    const config = await getWebhookConfig(subdomain);
    if (!config || !config.active) {
        log('WEBHOOK_RETRY_ABORTED', {
            reason: !config ? 'Config no longer exists' : 'Webhook disabled',
            subdomain
        });
        return;
    }

    try {
        const payload = buildPayload(config, callData, eventType);
        if (config.secret) payload.secret = config.secret;

        const headers = buildHeaders(config);

        log('WEBHOOK_RETRY_ATTEMPT', {
            url: config.url,
            method: 'POST',
            headers,
            payload,
            attempt,
            retriesLeft
        });

        const response = await axios.post(config.url, payload, {
            headers,
            timeout: (config.timeout || 30) * 1000
        });

        log('WEBHOOK_RETRY_SUCCESS', {
            attempt,
            url: config.url,
            status: response.status,
            statusText: response.statusText,
            headers: response.headers,
            data: response.data
        });

        await updateWebhookStatus(callData.subdomain, 'success', response.status, JSON.stringify(response.data));

    } catch (err) {
        const statusCode = err.response?.status || 0;
        const responseHeaders = err.response?.headers || null;
        const responseBody = err.response?.data
            ? JSON.stringify(err.response.data)
            : err.message;

        log('WEBHOOK_RETRY_FAILED', {
            attempt,
            message: err.message,
            statusCode,
            responseHeaders,
            responseBody,
            stack: err.stack
        });
        await retryWebhook(subdomain, callData, eventType, retriesLeft - 1, attempt + 1);
    }
}

// =========================================
// UPDATE WEBHOOK STATUS IN REDIS
// Mirrors what PHP saveCustomWebhookDetailsAction saves
// =========================================

async function updateWebhookStatus(subdomain, status, statusCode, responseBody) {
    if (!subdomain) {
        log('WEBHOOK_STATUS_UPDATE_SKIPPED', { reason: 'No subdomain provided' });
        return;
    }
    try {
        const key = `custom_webhook:${subdomain}`;

        // Always re-read the latest saved config from Redis (live), not the
        // in-memory webhookCache snapshot, before merging in the status
        // fields. This whole object gets written straight back to Redis
        // below, so merging onto a stale snapshot would silently revert any
        // config change (e.g. a webhook URL edited from the admin panel)
        // that happened after that snapshot was captured. Only fall back to
        // the memory cache if Redis itself is unreachable/empty.
        let config;
        const raw = await redisGet(key);
        if (raw) {
            try {
                config = JSON.parse(raw);
            } catch (e) {
                log('REDIS_CONFIG_PARSE_ERROR_BEFORE_UPDATE', { subdomain, key, error: e.message });
            }
        }

        if (!config) {
            log('REDIS_GET_BEFORE_UPDATE_MISSING_IN_DB', { key });
            config = webhookCache[subdomain];
            if (!config) {
                log('REDIS_GET_BEFORE_UPDATE_MISSING_IN_CACHE', { key });
                return;
            }
        }

        config.last_status = status;
        config.last_status_code = statusCode;
        config.last_response_body = responseBody;
        config.last_attempt_time = new Date().toISOString();

        // Update memory cache instantly
        webhookCache[subdomain] = config;

        const serializedConfig = JSON.stringify(config);
        log('REDIS_SET_REQUEST_ASYNC', { key });

        // Write to Redis in background (non-blocking)
        redisClient.set(key, serializedConfig, (err) => {
            if (err) {
                log('REDIS_SET_RESPONSE_ERROR', { key, error: err.message });
            } else {
                log('REDIS_SET_RESPONSE_SUCCESS', { key, status, statusCode });
            }
        });

        log('WEBHOOK_STATUS_UPDATED_IN_CACHE', { subdomain, status, statusCode });
    } catch (err) {
        log('WEBHOOK_STATUS_UPDATE_ERROR', { message: err.message, stack: err.stack });
    }
}

// =========================================
// EXTRACT SUBDOMAIN FROM CHANNEL HEADERS
// Reads variable_domain_name → returns full subdomain/domain
// e.g. "mr.ringq.ai" → "mr.ringq.ai"
// =========================================

function extractSubdomain(headers) {
    return headers['variable_domain_name'] || '';
}

// =========================================
// EXTRACT CALLER NUMBER FROM CHANNEL HEADERS
// FreeSWITCH's "Caller-Caller-ID-Number" is unreliable across legs —
// for some clients (e.g. WebRTC/mobile apps that register with a
// hashed SIP username) it holds the raw device username instead of
// the extension number on the A-leg, even though the B-leg (the
// FreeSWITCH-originated leg) has it correct.
//
// "variable_effective_caller_id_number" is consistent across both
// legs of a bridged call and reflects the actual extension/number,
// so prefer it. Fall back to the raw header, then to name, only if
// the effective field isn't present.
// =========================================

function extractCallerNumber(headers) {
    return (
        headers['variable_effective_caller_id_number'] ||
        headers['Caller-Caller-ID-Number'] ||
        headers['Caller-Orig-Caller-ID-Number'] ||
        ''
    );
}

// =========================================
// EXTRACT ROOT CALL UUID (shared across both legs of a bridged call)
// =========================================

function extractRootCallUuid(headers, fallbackUuid) {
    return headers['variable_call_uuid'] || fallbackUuid;
}

// =========================================
// FILTER QUEUE-ROUTING NOISE OUT OF TRANSFER HISTORY
// See the call site (agentDetails/transferHistory enrichment in the hangup
// handler) for why this exists: this system's queue flow always records a
// bl_xfer-into-the-queue-extension entry and a uuid_br-to-the-agent entry in
// variable_transfer_history, even for a call nobody actually transferred.
// FreeSWITCH's JSON event format can deliver this variable as either an
// array or a single ';'-joined string, so this accepts (and returns) either.
// =========================================

function filterQueueRoutingNoise(transferHistory, queueDetails, finalCallId) {
    const isArrayForm = Array.isArray(transferHistory);
    const entries = isArrayForm
        ? transferHistory
        : (typeof transferHistory === 'string' && transferHistory ? transferHistory.split(';') : []);

    if (entries.length === 0) return transferHistory;

    const [queueExt, queueDomain] = (queueDetails || '').split('@');

    const isQueueHandoffEntry = (entry) => {
        const parts = String(entry).split(':');
        const action = parts[2] || '';
        const details = parts.slice(3).join(':') || '';
        if (action === 'bl_xfer' && queueExt && details.startsWith(`${queueExt}/${queueDomain}`)) {
            return true;
        }
        if (action === 'uuid_br' && finalCallId && details === finalCallId) {
            return true;
        }
        return false;
    };

    // The dialplan's own housekeeping also lands in this variable. Seen on a plain 1000 -> 1001 call:
    //   <epoch>:<uuid>:bl_xfer:false/mr.ringq.ai/XML
    // i.e. a `transfer` step whose destination variable evaluated to the literal "false" (an unset forward /
    // voicemail destination), run after the two parties had finished talking. Nobody was transferred. A real
    // transfer always has a real destination, so entries whose destination is empty, a boolean/none word or an
    // unexpanded ${variable} are dropped.
    const isDialplanNoise = (entry) => {
        const details = String(entry).split(':').slice(3).join(':');
        const destination = details.split('/')[0].trim();
        return /^(false|true|none|null|undefined|nil)?$/i.test(destination) || /^\$\{/.test(destination);
    };

    const filtered = entries.filter(entry => !isQueueHandoffEntry(entry) && !isDialplanNoise(entry));

    if (isArrayForm) return filtered;
    return filtered.join(';');
}

// =========================================
// ESL CONNECTION
// =========================================

const conn = new ESL.Connection(
    '127.0.0.1',
    8021,
    'ClueCon',
    () => {

        log('SYSTEM', 'FreeSWITCH ESL Connected');

        conn.events(
            'json',
            'CHANNEL_CREATE DTMF CHANNEL_HANGUP_COMPLETE CHANNEL_BRIDGE'
        );

        // =========================================
        // CHANNEL CREATE
        // =========================================

        conn.on('esl::event::CHANNEL_CREATE::*', (event) => {
            const h = {};
            event.headers.forEach(x => { h[x.name] = x.value; });

            const uuid = h['Unique-ID'];

            if (!uuid) return;

            const isIVR = h['variable_ivr_menu_uuid'];
            const subdomain = extractSubdomain(h);
            const domain = h['variable_domain_name'] || call.subdomain || 'localhost';

            calls[uuid] = {
                uuid,
                rootCallUuid:   extractRootCallUuid(h, uuid),
                subdomain,
                caller:         extractCallerNumber(h),
                destination:    h['Caller-Destination-Number'] || '',
                digits:         '',
                ivrPath:        [],
                isIVR:          !!isIVR,
                isQueue:        false,
                transcript:     '',
                aiSummary:      '',
                sentiment:      '',
                contact:          '',
                wrapupNote:       '',
                recordingUrl:   `https://${domain}:8443/register/zohoplayrecoding/${uuid}`,
                callFlow:       '',
                queueDetails:   '',
                agentDetails:   '',
                transferHistory: '',
                startTime:      Date.now()
            };

            log('CALL_CREATED', calls[uuid]);
        });

        // =========================================
        // DTMF
        // =========================================

        conn.on('esl::event::DTMF::*', (event) => {
            const h = {};
            event.headers.forEach(x => { h[x.name] = x.value; });

            const uuid = h['Unique-ID'];
            if (!uuid) { log('DTMF_ERROR', 'UUID missing'); return; }

            if (!calls[uuid]) {
                calls[uuid] = {
                    uuid,
                    rootCallUuid: extractRootCallUuid(h, uuid),
                    subdomain:  extractSubdomain(h),
                    caller:     extractCallerNumber(h),
                    digits:     '',
                    ivrPath:    [],
                    isIVR:      false
                };
            }

            const digit = h['DTMF-Digit'] || '';
            calls[uuid].digits += digit;

            // Track IVR path as array of digits with timestamps
            calls[uuid].ivrPath.push({ digit, time: new Date().toISOString() });

            log('DTMF_CAPTURED', {
                uuid,
                digit,
                allDigits: calls[uuid].digits
            });
        });

        // =========================================
        // CHANNEL BRIDGE (agent.connected trigger)
        // Fires when the caller is actually bridged to an agent / extension -- for a queue call that is the
        // member being connected to the agent, for a direct call the callee picking up. (CHANNEL_ANSWER,
        // used before, also fires for the caller's OWN leg being answered by the dialplan or an IVR, so it
        // sent "agent connected" before any agent was involved.)
        // =========================================

        conn.on('esl::event::CHANNEL_BRIDGE::*', async (event) => {
            try {
                const h = {};
                event.headers.forEach(x => { h[x.name] = x.value; });

                // mod_callcenter reports the bridge from the agent's leg as well; the call is always the
                // caller's ("member") leg, which is what calls[] is keyed by.
                const isAgentLeg = h['variable_cc_side'] === 'agent';
                const callUuid = isAgentLeg
                    ? (h['Other-Leg-Unique-ID'] || h['Bridge-A-Unique-ID'])
                    : h['Unique-ID'];
                log('ESL_EVENT_CHANNEL_BRIDGE', { uuid: h['Unique-ID'], callUuid, otherLeg: h['Other-Leg-Unique-ID'], isAgentLeg });

                const call = callUuid && calls[callUuid];
                if (!call) return;
                if (call.rootCallUuid && call.rootCallUuid !== callUuid) return;   // not the caller's leg
                if (call.agentConnected) return;                                    // once per call
                if ((h['variable_call_direction'] || '') === 'outbound') return;   // an agent dialling out

                call.agentConnected = true;
                call.answered = true;
                call.callState = 'in-progress';
                call.agentDetails = isAgentLeg
                    ? (h['variable_cc_agent'] || h['variable_effective_caller_id_number'] || h['Caller-Caller-ID-Number'] || '')
                    : (h['Other-Leg-Caller-ID-Number'] || h['Other-Leg-Destination-Number'] || h['variable_sip_to_user'] || '');
                call.subdomain = call.subdomain || extractSubdomain(h);
                if (!call.subdomain) return;

                const config = await getWebhookConfig(call.subdomain);
                if (!config || !config.active) return;

                const triggers = getMatchingTriggers(config, 'connect', call);
                if (triggers.length === 0) return;

                // The call is still going: no duration, recording or transcript yet.
                const snapshot = { ...call, ivrPath: JSON.stringify(call.ivrPath || []) };
                for (const trigger of triggers) {
                    await dispatchWebhook(config, snapshot, trigger);
                }
            } catch (err) {
                log('BRIDGE_HANDLER_ERROR', { message: err.message, stack: err.stack });
            }
        });

        // =========================================
        // HANGUP
        // =========================================

        conn.on('esl::event::CHANNEL_HANGUP_COMPLETE::*', async (event) => {
            try {
                const h = {};
                event.headers.forEach(x => { h[x.name] = x.value; });

                const uuid = h['Unique-ID'];
                if (!uuid) { log('HANGUP_ERROR', 'UUID missing'); return; }

                // A bridged phone call produces TWO CHANNEL_HANGUP_COMPLETE
                // events (one per leg: A-leg = original caller channel,
                // B-leg = the channel FreeSWITCH created to bridge out).
                // We only want to dispatch a webhook once, using the A-leg's
                // data (it has the real caller/destination; the B-leg's
                // destination is a synthetic internal string).
                const call = calls[uuid] || {};

                // IMPORTANT: rootCallUuid must come from the value captured
                // at CHANNEL_CREATE time (call.rootCallUuid), NOT re-read from
                // this hangup event's headers. FreeSWITCH rewrites
                // variable_call_uuid post-bridge so that BOTH legs report it
                // as equal to their own Unique-ID by the time they hang up —
                // re-reading it here would make every leg look like "the
                // root leg" and cause duplicate webhook dispatches (one
                // per leg, each with different/wrong caller-destination
                // data). Only fall back to the live header if we have no
                // in-memory record for this call at all (e.g. after a
                // process restart mid-call).
                const rootCallUuid = call.rootCallUuid || extractRootCallUuid(h, uuid);
                const isRootLeg = (uuid === rootCallUuid);

                if (!isRootLeg) {
                    // This is the B-leg (the outbound/bridge channel). Its
                    // caller/destination fields are not trustworthy for the
                    // payload (synthetic internal values), so we don't
                    // dispatch from here. The A-leg's own hangup event will
                    // fire separately and carries the real caller/destination.
                    log('HANGUP_NON_ROOT_LEG_SKIPPED', { uuid, rootCallUuid });
                    delete calls[uuid];
                    return;
                }

                // Filter out unsolicited SIP probes/scanners that hit the
                // public dialplan context and never matched a real tenant.
                // These are NOT legs of any real customer call — they are
                // random internet traffic (bots scanning for open SIP
                // servers) that FreeSWITCH rejects with "404 not found"
                // before any routing/subdomain lookup happens. They should
                // never reach the CRM webhook.
                const callContext = h['Caller-Context'] || h['variable_user_context'] || '';
                if (callContext === 'public' || !extractSubdomain(h)) {
                    log('HANGUP_UNROUTED_PROBE_SKIPPED', {
                        uuid,
                        context: callContext,
                        destination: h['Caller-Destination-Number'] || '',
                        networkAddr: h['Caller-Network-Addr'] || ''
                    });
                    delete calls[uuid];
                    return;
                }

                // Filter out call-center/queue AGENT legs (mod_callcenter).
                // Unlike a normal two-party bridge, mod_callcenter originates
                // the agent's leg independently: its own variable_call_uuid
                // equals itself, not the member/customer call's UUID, so it
                // would otherwise pass the isRootLeg check above and dispatch
                // a bogus second webhook — with CallTo set to the agent's
                // synthetic WebRTC session id (e.g. "fknaoj57") instead of a
                // real number. The real customer call is the "member" leg
                // (variable_cc_side === 'member', or absent), which fires
                // its own hangup separately and carries the true data. The
                // agent identity itself (variable_cc_agent) is read directly
                // off the member leg's own hangup headers — see the
                // agentDetails computation below.
                if (h['variable_cc_side'] === 'agent') {
                    log('HANGUP_CALLCENTER_AGENT_LEG_SKIPPED', {
                        uuid,
                        memberSessionUuid: h['variable_cc_member_session_uuid'] || '',
                        queue: h['variable_cc_queue'] || ''
                    });
                    delete calls[uuid];
                    return;
                }

                // Filter out LOSING queue-agent ring attempts. Ring-all (and
                // similar) queue strategies dial several agents at once; every
                // agent except the one who answers gets killed the instant
                // another agent picks up, and FreeSWITCH tags that kill with
                // Hangup-Cause "LOSE_RACE". Each losing attempt is its own
                // channel whose own variable_call_uuid equals its own
                // Unique-ID, so it passes the isRootLeg check above — and, in
                // practice, mod_callcenter does not always have variable_cc_side
                // set to "agent" yet on a leg that gets cancelled this early, so
                // the check above alone does not catch every losing leg. Left
                // unfiltered, this is exactly what produces one bogus webhook
                // per losing agent (10 agents ringing -> up to 10 dispatches),
                // each carrying that ring attempt's synthetic internal
                // caller/destination instead of the real customer call data.
                // LOSE_RACE is already the established signal this codebase
                // uses for the same scenario elsewhere (see server.js). Only
                // the member/customer leg (this call's true root leg) should
                // ever reach dispatch below; it hangs up separately once the
                // whole call — not just one ring attempt — actually ends, and
                // carries the real caller number plus the one agent who
                // actually answered.
                if (h['Hangup-Cause'] === 'LOSE_RACE') {
                    log('HANGUP_QUEUE_LOSE_RACE_SKIPPED', {
                        uuid,
                        queue: h['variable_cc_queue'] || '',
                        cancelReason: h['variable_cc_cancel_reason'] || ''
                    });
                    delete calls[uuid];
                    return;
                }

                const isIVR = h['variable_ivr_menu_uuid'];

                // Merge any in-memory call state (DTMF, IVR path, etc.) with fresh hangup headers
                const domain = h['variable_domain_name'] || call.subdomain || 'localhost';

                const m_domain_uuid = h['variable_domain_uuid'] || '';

                // ── Enrich with all available FreeSWITCH hangup variables ──
                call.uuid             = uuid;
                call.rootCallUuid     = rootCallUuid;
                call.subdomain        = call.subdomain || extractSubdomain(h);
                // IMPORTANT: prefer the value captured at CHANNEL_CREATE time
                // (call.caller, set before the call was bridged). FreeSWITCH
                // rewrites variable_effective_caller_id_number on the A-leg
                // AFTER bridging to reflect the callee's identity, so re-reading
                // it from the hangup headers at this point would give you the
                // destination, not the caller. Only fall back to the header
                // value if we somehow have no in-memory record for this call.
                call.caller           = call.caller || extractCallerNumber(h) || '';
                call.destination      = call.destination || h['Caller-Destination-Number'] || '';
                call.duration         = h['variable_billsec']       || '0';
                call.hangupCause      = h['Hangup-Cause']            || '';
                call.isIVR            = !!isIVR;
                call.isQueue           = !!(h['variable_queue_name'] || h['variable_cc_queue']);
                call.digits           = call.digits || '';

                // ── ANSWERED / MISSED / ABANDONED STATE ──
                // variable_answer_epoch (falling back to variable_bridge_epoch)
                // is set by FreeSWITCH the moment a channel actually transitions
                // to ANSWERED, regardless of how it got there (direct dial, IVR,
                // mod_callcenter ring-all, or this system's custom transfer +
                // uuid_bridge queue flow) — so it's a reliable, flow-agnostic
                // signal for "did anyone actually pick up," unlike billsec
                // (which can be misleadingly '0' for a very short answered call)
                // or digits (silent on whether the call was ever answered at all).
                const answerEpoch = h['variable_answer_epoch'] || h['variable_bridge_epoch'] || '';
                call.answered = !!(answerEpoch && answerEpoch !== '0');

                // Single semantic label for the call's outcome, used for the
                // payload's Status/DialCallStatus so the CRM sees the real
                // state instead of always "completed":
                //   - answered            -> completed (talked to someone)
                //   - unanswered + queue  -> abandoned (caller hung up while queued/ringing agents)
                //   - unanswered, no queue -> no-answer (direct/IVR call nobody picked up)
                call.callState = call.answered
                    ? 'completed'
                    : (call.isQueue ? 'abandoned' : 'no-answer');
                call.direction = h['variable_call_direction'] || '';
                call.endTime = Date.now();

                // ── QUEUE/CALL-CENTER TRANSCRIPT LOOKUP FIX ──
                // For queue calls, the audio-to-text/report service indexes
                // the transcript under the AGENT leg's UUID
                // (variable_cc_agent_uuid), NOT the member/root leg's UUID.
                // Looking it up under the member UUID (as we always used to)
                // reliably 404s with "Call ID is missing or already deleted"
                // (AI1004) after burning all 5 retries (~25s wasted per call).
                // Non-queue calls are unaffected and keep using their own uuid.
                call.agentCallUuid = h['variable_cc_agent_uuid'] || '';
                const transcriptLookupUuid = (call.isQueue && call.agentCallUuid)
                    ? call.agentCallUuid
                    : uuid;

                log('TRANSCRIPT_LOOKUP_UUID_SELECTED', {
                    uuid,
                    isQueue:            call.isQueue,
                    agentCallUuid:      call.agentCallUuid,
                    transcriptLookupUuid
                });

                // For queue/call-center calls, the CRM-facing call_id
                // (buildPayload's `call_id` field, sourced from call.uuid)
                // must also be the agent leg's UUID, not the member/root
                // leg's UUID — this is the id the report/transcript API
                // actually keys the call under, so it's what the CRM needs
                // to cross-reference. Non-queue calls keep their own uuid.
                if (call.isQueue && call.agentCallUuid) {
                    call.uuid = call.agentCallUuid;
                    log('CALL_ID_OVERRIDDEN_FOR_QUEUE', {
                        memberUuid: uuid,
                        payloadCallId: call.uuid
                    });
                }

                // ── FINAL DUPLICATE-DISPATCH GUARD ──
                // A call can be represented by more than one channel that each
                // independently pass every filter above and legitimately look
                // like "the" root/member leg — e.g. a blind transfer (bl_xfer)
                // into a queue followed by a manual bridge (uuid_br) to the
                // agent can leave two distinct channels (the pre-transfer leg
                // and the post-transfer queue-member leg) that both inherit the
                // same variable_cc_agent_uuid and so both resolve to the same
                // overridden call.uuid above. Rather than trying to enumerate
                // every FreeSWITCH transfer/queue topology that can produce
                // this, dedupe directly on the id that actually goes out in the
                // CRM payload: if a call.completed webhook was already sent for
                // this effective call id very recently, skip sending it again.
                if (await isDuplicateHangupDispatch(call.uuid)) {
                    log('HANGUP_DUPLICATE_CALL_ID_SKIPPED', {
                        uuid,
                        effectiveCallId: call.uuid,
                        rootCallUuid
                    });
                    delete calls[uuid];
                    return;
                }

                // Recording URL built from domain + uuid.
                // For queue calls, use the same UUID as call_id (the agent
                // UUID) for consistency between the two fields in the CRM
                // payload, per explicit request. NOTE: the actual .wav file
                // on disk is named after the MEMBER uuid (see
                // variable_cc_record_filename in the hangup headers), so
                // this URL may not resolve to a playable recording for
                // queue calls — only change this back if playback breaks.
                const recordingUuid = (call.isQueue && call.agentCallUuid)
                    ? call.agentCallUuid
                    : uuid;
                call.recordingUrl     = `https://${domain}:8443/register/zohoplayrecoding/${recordingUuid}`;

                // IVR path: already built as array during DTMF events — stringify for payload
                call.ivrPath          = Array.isArray(call.ivrPath)
                    ? JSON.stringify(call.ivrPath)
                    : (call.ivrPath || '');

                // Queue / agent details from FS variables.
                call.queueDetails     = h['variable_queue_name'] || h['variable_cc_queue'] || '';
                // variable_cc_agent is this org's own internal agent UUID, not
                // a phone extension — confirmed by dumping every header on the
                // agent's own leg (CHANNEL_CREATE and CHANNEL_ANSWER): this
                // platform's agents are configured as mod_callcenter
                // "callback"-type (variable_cc_agent_type=callback), which
                // dial out to whatever contact/session is registered for that
                // agent (a WebRTC session id here) rather than a normal SIP
                // extension — so there is no extension number anywhere in the
                // FreeSWITCH event stream for this agent type. variable_cc_agent
                // is however STABLE per agent (same UUID recurs across every
                // call the same agent handles), so it's used here as the
                // agent identifier by deliberate choice, not as a fallback.
                call.agentDetails     = h['variable_cc_agent'] ||
                                         h['variable_effective_caller_id_number'] ||
                                         h['variable_sip_to_user'] ||
                                         h['variable_dialed_extension'] || '';
                // Strip the queue's own internal routing bookkeeping out of
                // transfer_history before reporting it. Every call handled by
                // this system's queue flow shows exactly two mechanical
                // variable_transfer_history entries regardless of whether a
                // "real" transfer ever happened: a bl_xfer that hands the
                // call into the queue's own extension context, and a
                // uuid_br that bridges it to whichever agent answers. Neither
                // is a transfer from the caller's point of view — it's just
                // how every queue call gets wired up — so reporting it as
                // transfer_history is misleading for a call nobody actually
                // transferred. Any entry beyond those two (e.g. an agent
                // doing a real transfer afterwards) is preserved.
                call.transferHistory  = filterQueueRoutingNoise(
                    h['variable_transfer_history'] || '',
                    call.queueDetails,
                    call.agentCallUuid || call.uuid
                );

                // ── Is the webhook even wanted for this call? Decide BEFORE any slow lookup. ──
                // Previously the transcript (up to 5 x 5 s) and the contact (up to 5 x 5 s) were fetched first
                // and the config only checked afterwards, so an unwanted call still cost ~50 s.
                // Key: custom_webhook:<subdomain>  (written by PHP saveCustomWebhookDetailsAction)
                const config = await getWebhookConfig(call.subdomain);

                if (!config) {
                    log('WEBHOOK_SKIPPED', {
                        reason:    'No webhook config in Redis for this subdomain',
                        subdomain: call.subdomain,
                        redisKey:  `custom_webhook:${call.subdomain}`
                    });
                    delete calls[uuid];
                    return;
                }
                if (!config.active) {
                    log('WEBHOOK_SKIPPED', {
                        reason:    'Webhook is disabled (active=0)',
                        subdomain: call.subdomain,
                        url:       config.url
                    });
                    delete calls[uuid];
                    return;
                }

                const requested = new Set(config.payload_fields || []);
                const wants = (...keys) => keys.some(k => requested.has(k));
                const activeTriggers = (config.triggers || []).map(normalizeTrigger);

                // IVR: which menu (its number / name) the call went through, plus the keys pressed.
                if (wants('ivr_path') || activeTriggers.includes('ivr.completed')) {
                    await detectIvr(call, h);
                }

                const triggers = getMatchingTriggers(config, 'hangup', call);
                log('TRIGGERS_EVALUATED', {
                    uuid,
                    configuredTriggers: activeTriggers,
                    matched:            triggers,
                    answered:           call.answered,
                    isQueue:            call.isQueue,
                    isIVR:              call.isIVR,
                    direction:          call.direction,
                    hangupCause:        call.hangupCause
                });
                if (triggers.length === 0) {
                    log('WEBHOOK_SKIPPED', {
                        reason: 'This call matches none of the selected triggers',
                        configuredTriggers: activeTriggers
                    });
                    delete calls[uuid];
                    return;
                }

                // ── Transcript ──
                // Only when the payload asks for it AND there is a recording to transcribe (an unanswered
                // call has none). The report API transcribes the recording on demand when the tenant has
                // Settings -> Global -> AI Transcribe enabled, exactly like voicemail transcription;
                // fetchTranscriptDetails() waits for the recording to be ready and gives up at once if AI
                // Transcribe is off.
                if (wants('transcript', 'ai_summary', 'sentiment')) {
                    const hasRecording = call.answered ||
                        !!(h['variable_record_path'] || h['variable_cc_record_filename'] || h['variable_record_name']);
                    if (hasRecording) {
                        const aiData = await fetchTranscriptDetails(transcriptLookupUuid, call.subdomain);
                        call.transcript       = h['variable_call_transcript'] || aiData.transcript;
                        call.aiSummary        = h['variable_ai_summary']      || aiData.aiSummary;
                        call.sentiment        = h['variable_call_sentiment']  || aiData.sentiment;
                    } else {
                        log('TRANSCRIPT_SKIPPED', { uuid, reason: 'Call has no recording (it was never answered)' });
                    }
                }

                // ── Contact / wrap-up note, for the OTHER party on the call ──
                // Looked up by the CALLER's number (call.contact itself is always '' at this point -- it is
                // only populated by this very result). For an agent's outbound call the other party is the
                // number that was dialled.
                if (wants('contact', 'contacts_details', 'wrapup_note', 'wrapup_notes')) {
                    const otherParty = call.direction === 'outbound' ? call.destination : call.caller;
                    const contactData = await fetchContactDetails(uuid, call.subdomain, otherParty, m_domain_uuid);
                    call.contact     = contactData.contact;
                    call.wrapupNote  = contactData.wrapupNote;
                }

                // Call flow label from FS dialplan context
                call.callFlow         = h['variable_current_application'] || h['Other-Leg-Direction'] || '';

                log('HANGUP_ENRICHED', {
                    uuid,
                    rootCallUuid,
                    subdomain:    call.subdomain,
                    caller:       call.caller,
                    destination:  call.destination,
                    duration:     call.duration,
                    hangupCause:  call.hangupCause,
                    digits:       call.digits,
                    isIVR:        call.isIVR,
                    ivrNumber:    call.ivrNumber || '',
                    isQueue:      call.isQueue,
                    answered:     call.answered,
                    recordingUrl: call.recordingUrl
                });

                // One webhook per matched trigger (event_type says which). dispatchWebhook() applies the auth
                // headers, posts to config.url with config.secret, retries on failure and writes the last
                // status back to Redis.
                for (const trigger of triggers) {
                    await dispatchWebhook(config, call, trigger);
                }

                // Clean up in-memory state for this call
                delete calls[uuid];

            } catch (err) {
                log('HANGUP_HANDLER_ERROR', {
                    message:  err.message,
                    stack:    err.stack,
                    response: err.response?.data
                });
            }
        });
    }
);