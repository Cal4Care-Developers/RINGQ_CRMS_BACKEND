require('./servermode')('transcript_live'); // [server-mode] production/debug logging, see servermode.js
// bridge.js
const WebSocket = require('ws');
const { io } = require('socket.io-client');
const { decodeAudioFrame } = require('./transcript_frames'); // audio from mod_audio_stream (binary) or mod_openai_audio_stream (JSON)

// Redis Client Connection Setup - Starts
const redis = require('redis');
let redisClient;
(async () => {
    try {
        redisClient = redis.createClient({
            socket: {
                path: "/var/run/redis/redis-server.sock",
            },
        });
        redisClient.on("error", (error) => console.error(`Redis Error: ${error}`));
        await redisClient.connect();
        console.log("Connected to Redis successfully via socket");
    } catch (err) {
        console.error("Redis Initialization Error:", err);
    }
})();
// Redis Client Connection Setup - Ends

// ── Connect to signal.js Socket.io Server (Port 3000) ─────
const socketClient = io("ws://127.0.0.1:3000", {
    transports: ["websocket"],
    extraHeaders: {
        "user-agent": "node-XMLHttpRequest"
    }
});



const socketClients = new io("ws://127.0.0.1:3000", {
  autoConnect: true,
  reconnectionDelayMax: 1000,
});





socketClient.on("connect", () => {
    console.log("Connected to signal.js Socket.io server on port 3000");
});

socketClients.emit("progressRinging", {
          data: "sdsadsadsadsadsadsadsa",     });



socketClient.on("error", (err) => {
    console.error("Socket client error:", err);
});

// Map to track subdomains/channels for each active call UUID
const callChannels = new Map();
const callDetails = new Map();

function getSubdomain(from, to) {
    if (to && to.includes('@')) {
        return to.split('@')[1].split(':')[0];
    }
    if (from && from.includes('@')) {
        return from.split('@')[1].split(':')[0];
    }
    return null;
}

// ── Live Agent Assist feed ─────────────────────────────────
// The agent's browser reads its own call's transcript from a short Redis list (PHP: AgentassistController
// ::feedAction), keyed by tenant + extension. Pure addition: the socket.io broadcast below is unchanged.
const ASSIST_FEED_MAX = 200;      // events kept per extension
const ASSIST_FEED_TTL = 3600;     // seconds

// "1001", "sip:1001@dom", "1001@dom:5060" -> "1001"
function assistExt(addr) {
    if (!addr || addr === 'unknown') return null;
    const m = String(addr).replace(/^sips?:/i, '').split('@')[0].trim();
    return m && /^[A-Za-z0-9*#+._-]{1,32}$/.test(m) ? m : null;
}

function publishAssistFeed(channel, payload) {
    if (!redisClient || !channel) return;
    // Partial words are not useful to the assistant and would flood the list.
    if (payload.type === 'transcript_delta') return;
    const exts = new Set([assistExt(payload.from), assistExt(payload.to)].filter(Boolean));
    for (const ext of exts) {
        const key = `assist:feed:${channel}:${ext}`;
        (async () => {
            try {
                const seq = await redisClient.incr(`assist:seq:${channel}:${ext}`);
                await redisClient.expire(`assist:seq:${channel}:${ext}`, ASSIST_FEED_TTL);
                await redisClient.rPush(key, JSON.stringify({
                    seq,
                    type: payload.type,
                    uuid: payload.uuid,
                    from: payload.from,
                    to: payload.to,
                    speaker: payload.speaker,
                    text: payload.text,
                    time: payload.time,
                }));
                await redisClient.lTrim(key, -ASSIST_FEED_MAX, -1);
                await redisClient.expire(key, ASSIST_FEED_TTL);
            } catch (e) {
                console.error(`[ASSIST] feed publish failed for ${ext}: ${e.message}`);
            }
        })();
    }
}

function broadcastToFlutter(uuid, payload) {
    const channel = callChannels.get(uuid);
    if (!channel) {
        console.warn(`Cannot broadcast event: channel not found for call UUID ${uuid}`);
        return;
    }

    // Inject dynamic call details (from, to, and sip_call_id) into payload
    const details = callDetails.get(uuid) || {};
    payload.from = details.from || 'unknown';
    payload.to = details.to || 'unknown';
    payload.sip_call_id = uuid;

    publishAssistFeed(channel, payload);

    if (socketClient && socketClient.connected) {
        socketClient.emit("transcript_event", {
            channel: channel,
            payload: payload
        });
        console.log(`Sent transcript event for call ${uuid} to channel ${channel}`);
    } else {
        console.warn(`Cannot send transcript: socketClient not connected`);
    }
}

// Split 16-bit interleaved stereo PCM into two mono buffers (left channel and right channel)
function splitStereoPCM(stereoBuffer) {
    const sampleCount = stereoBuffer.length / 4;
    const leftBuffer = Buffer.alloc(sampleCount * 2);
    const rightBuffer = Buffer.alloc(sampleCount * 2);

    for (let i = 0; i < sampleCount; i++) {
        // Copy Left channel sample (2 bytes)
        stereoBuffer.copy(leftBuffer, i * 2, i * 4, i * 4 + 2);
        // Copy Right channel sample (2 bytes)
        stereoBuffer.copy(rightBuffer, i * 2, i * 4 + 2, i * 4 + 4);
    }

    return { leftBuffer, rightBuffer };
}

// ── WebSocket server for FreeSWITCH audio ────────────────
// NOTE: FreeSWITCH must send raw PCM16 audio at 24kHz, stereo, little-endian.
const fsWss = new WebSocket.Server({ port: 5000 });
console.log('FreeSWITCH audio bridge listening on :5000');

fsWss.on('connection', async (fsWs, req) => {
    console.log('Incoming connection headers:', req.headers);
    const params  = new URL(req.url, 'http://x').searchParams;
    const uuid    = params.get('uuid') || req.headers['x-uuid'] || req.headers['x_uuid'] || 'unknown';
    const from    = params.get('from') || req.headers['x-from'] || req.headers['x_from'] || 'unknown';
    const to      = params.get('to')   || req.headers['x-to']   || req.headers['x_to']   || 'unknown';
    const domain  = params.get('domain') || req.headers['x-domain'] || req.headers['x_domain'] || 'mr.ringq.ai';

    console.log(`Call connected: ${uuid} from ${from} → ${to}`);
    
    // Dynamic FQDN Selection - Starts
    // Derive the FQDN/domain dynamically from request headers/parameters or SIP addresses
    const channel = getSubdomain(from, to) || domain;
    callChannels.set(uuid, channel);
    callDetails.set(uuid, { from, to });
    // Dynamic FQDN Selection - Ends

    // ── Forward FreeSWITCH audio → OpenAI ────────────────
    // PCM16 at 24kHz mono = 48,000 bytes/sec.
    // OpenAI requires at least 100ms = 4,800 bytes before committing.
    const BYTES_PER_MS       = 48;
    const MIN_COMMIT_BYTES   = 100 * BYTES_PER_MS;
    const COMMIT_INTERVAL_MS = 2000;

    let bufferedBytesCaller = 0;
    let commitTimerCaller   = null;
    let bufferedBytesReceiver = 0;
    let commitTimerReceiver   = null;
    let firstChunkReceived = false;

    // Define WebSocket variables for Caller & Callee sessions
    let openaiWsCaller;
    let openaiWsReceiver;

    function scheduleCommitCaller() {
        if (commitTimerCaller) return;
        commitTimerCaller = setTimeout(() => {
            commitTimerCaller = null;
            if (openaiWsCaller && openaiWsCaller.readyState === WebSocket.OPEN && bufferedBytesCaller >= MIN_COMMIT_BYTES) {
                console.log(`[AUDIO] Committing Caller audio buffer for ${uuid} (size: ${bufferedBytesCaller} bytes)`);
                openaiWsCaller.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
                bufferedBytesCaller = 0;
            }
        }, COMMIT_INTERVAL_MS);
    }

    function scheduleCommitReceiver() {
        if (commitTimerReceiver) return;
        commitTimerReceiver = setTimeout(() => {
            commitTimerReceiver = null;
            if (openaiWsReceiver && openaiWsReceiver.readyState === WebSocket.OPEN && bufferedBytesReceiver >= MIN_COMMIT_BYTES) {
                console.log(`[AUDIO] Committing Receiver audio buffer for ${uuid} (size: ${bufferedBytesReceiver} bytes)`);
                openaiWsReceiver.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
                bufferedBytesReceiver = 0;
            }
        }, COMMIT_INTERVAL_MS);
    }

    // Register audio message listener synchronously to prevent loss of early packets from FreeSWITCH
    fsWs.on('message', (audioChunk, isBinary) => {
        // Either FreeSWITCH module may be feeding this socket (see transcript_frames.js); control messages are skipped.
        const frame = decodeAudioFrame(audioChunk, isBinary);
        if (frame.kind !== 'audio') return;
        const buf = frame.pcm;

        if (!firstChunkReceived) {
            console.log(`[AUDIO] First stereo audio chunk received for ${uuid} (${buf.length} bytes)`);
            firstChunkReceived = true;
        }

        // Split stereo interleaved PCM into independent Left (Caller) and Right (Receiver) buffers
        const { leftBuffer, rightBuffer } = splitStereoPCM(buf);

        if (openaiWsCaller && openaiWsCaller.readyState === WebSocket.OPEN) {
            bufferedBytesCaller += leftBuffer.length;
            openaiWsCaller.send(JSON.stringify({
                type:  'input_audio_buffer.append',
                audio: leftBuffer.toString('base64'),
                }));
            scheduleCommitCaller();
        }

        if (openaiWsReceiver && openaiWsReceiver.readyState === WebSocket.OPEN) {
            bufferedBytesReceiver += rightBuffer.length;
            openaiWsReceiver.send(JSON.stringify({
                type:  'input_audio_buffer.append',
                audio: rightBuffer.toString('base64'),
            }));
            scheduleCommitReceiver();
        }
    });

    // Cleanup on call end
    fsWs.on('close', () => {
        console.log(`Call ended: ${uuid}`);
        if (commitTimerCaller) { clearTimeout(commitTimerCaller); commitTimerCaller = null; }
        if (commitTimerReceiver) { clearTimeout(commitTimerReceiver); commitTimerReceiver = null; }
        if (openaiWsCaller) openaiWsCaller.close();
        if (openaiWsReceiver) openaiWsReceiver.close();
        broadcastToFlutter(uuid, {
            type: 'call_ended',
            uuid,
            time: new Date().toISOString(),
        });
        callChannels.delete(uuid);
        callDetails.delete(uuid);
    });

    fsWs.on('error', (err) => console.error(`FS WS error ${uuid}:`, err));

    broadcastToFlutter(uuid, {
        type: 'call_started',
        uuid, from, to,
        time: new Date().toISOString(),
    });

    // Dynamic OpenAI API Key Retrieval from Redis - Starts
    let apiKey;
    if (redisClient) {
        try {
            const redisKey = `ai:${channel}-transcribeToken`;
            const token = await redisClient.get(redisKey);
            if (token) {
                apiKey = token;
                console.log(`Fetched OpenAI API Key from Redis for domain ${channel}: ${redisKey}`);
            } else {
                console.error(`No transcribe token found in Redis for key ${redisKey}`);
            }
        } catch (redisErr) {
            console.error(`Error retrieving key from Redis for ${channel}:`, redisErr);
        }
    }
    // Dynamic OpenAI API Key Retrieval from Redis - Ends

    if (!apiKey) {
        console.error(`Aborting call setup: OpenAI API key is missing for ${channel}`);
        fsWs.close();
        return;
    }

    // ── Open OpenAI Realtime connections for both Caller & Callee ────────
    openaiWsCaller   = createOpenAISession(uuid, 'CALLER');
    openaiWsReceiver = createOpenAISession(uuid, 'RECEIVER');

    function createOpenAISession(uuid, label) {
        const ws = new WebSocket(
            'wss://api.openai.com/v1/realtime?intent=transcription',
            {
                headers: {
                    'Authorization': `Bearer ${apiKey}`,
                }
            }
        );

        ws.on('open', () => {
            console.log(`OpenAI Realtime connected for ${uuid} [${label}]`);

            ws.send(JSON.stringify({
                type: 'session.update',
                session: {
                    type: 'transcription',
                    audio: {
                        input: {
                            format: {
                                type: 'audio/pcm',
                                rate: 24000
                            },
                            transcription: {
                                model: 'gpt-realtime-whisper'
                            },
                            turn_detection: null
                        }
                    }
                }
            }));
        });

        ws.on('message', (raw) => {
            let event;
            try { event = JSON.parse(raw); } catch { return; }

            switch (event.type) {
                case 'conversation.item.input_audio_transcription.delta':
                    if (!event.delta) {
                        break;
                    }
                    console.log(`[TRANSCRIPT][DELTA] [${label}] ${uuid}: ${event.delta}`);
                    broadcastToFlutter(uuid, {
                        type:    'transcript_delta',
                        uuid,
                        speaker: label,
                        text:    event.delta,
                        time:    new Date().toISOString(),
                        partial: true,
                    });
                    break;

                case 'conversation.item.input_audio_transcription.completed':
                    if (!event.transcript || !event.transcript.trim()) {
                        break;
                    }
                    console.log(`[TRANSCRIPT][COMPLETED] [${label}] ${uuid}: ${event.transcript}`);
                    broadcastToFlutter(uuid, {
                        type:    'transcript',
                        uuid,
                        speaker: label,
                        text:    event.transcript,
                        time:    new Date().toISOString(),
                        partial: false,
                    });

                    // Append to per-call log file
                    const logLine = `[${new Date().toLocaleTimeString()}] [${label}] ${event.transcript}\n`;
                    const logDir = '/var/log/freeswitch/transcripts';
                    const fs = require('fs');
                    try {
                        if (!fs.existsSync(logDir)) {
                            fs.mkdirSync(logDir, { recursive: true });
                        }
                        fs.appendFileSync(`${logDir}/${uuid}.txt`, logLine);
                    } catch (err) {
                        const localLogDir = require('path').join(__dirname, 'transcripts');
                        try {
                            if (!fs.existsSync(localLogDir)) {
                                fs.mkdirSync(localLogDir, { recursive: true });
                            }
                            fs.appendFileSync(require('path').join(localLogDir, `${uuid}.txt`), logLine);
                        } catch (localErr) {
                            console.error(`Failed to write transcript to local directory: ${localErr.message}`);
                        }
                    }
                    break;

                case 'session.created':
                    console.log(`Realtime session created for ${uuid} [${label}]:`, event.session?.id);
                    break;

                case 'session.updated':
                    console.log(`Realtime session updated for ${uuid} [${label}]`);
                    break;

                case 'error':
                    console.error(`OpenAI error for ${uuid} [${label}]:`, event.error);
                    break;
            }
        });

        ws.on('error', (err) => console.error(`OpenAI WS error ${uuid} [${label}]:`, err));

        return ws;
    }
});