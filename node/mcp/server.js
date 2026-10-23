"use strict";
require('../servermode')('mcp'); // [server-mode] production/debug logging, see ../servermode.js

/**
 * Support MCP adapter.
 *
 * Speaks the Model Context Protocol over Streamable HTTP to the support
 * engineer's assistant, and forwards every request to McpController, which is
 * the only thing that decides anything. This process holds no secret, reads no
 * database and makes no access decision: it translates a protocol and nothing
 * more, so a defect here cannot widen access.
 *
 * ZERO DEPENDENCIES, deliberately. Phase 1 needs initialize, tools/list,
 * tools/call and ping in single-response mode, which node's own http module
 * covers. A package.json here would mean `npm ci` on every customer box and a
 * lockfile in the update payload for about two hundred lines of framing.
 */

const http = require("http");
const https = require("https");

const PORT = Number(process.env.RINGQ_MCP_PORT || 6095);
const HOST = process.env.RINGQ_MCP_BIND || "127.0.0.1";
const UPSTREAM_PORT = Number(process.env.RINGQ_MCP_UPSTREAM_PORT || 8443);
const MAX_BODY = 256 * 1024;
const UPSTREAM_TIMEOUT = 30000;

const SUPPORTED_PROTOCOLS = ["2025-06-18", "2025-03-26", "2024-11-05"];
const DEFAULT_PROTOCOL = "2025-06-18";

const SERVER_INFO = { name: "ringq-support", version: "1.0.0" };

const PROFILES = {
    "/mcp": {
        upstream: "/mcp/tool",
        oauth: false,
        info: SERVER_INFO,
        instructions:
            "Read-only diagnostics for one RingQ phone system. Every call is written to the " +
            "customer's audit log. Recordings, message contents and passwords are never available.",
    },
    "/mcp/account": {
        upstream: "/mcp/account",
        oauth: true,
        info: { name: "ringq", version: "1.0.0" },
        instructions:
            "Your RingQ phone system account, limited to what your RingQ role can do in the web app. " +
            "Use ringq_api_search to find an operation, ringq_api_details for its parameters, then " +
            "ringq_api_read or ringq_api_write. Run ringq_api_write with dryRun first; high-risk changes " +
            "wait for the account owner's approval. Every call is written to the account's activity log.",
    },
};

const UNAUTHORIZED = Symbol("unauthorized");

function resourceMetadataUrl(host) {
    return "https://" + host + "/.well-known/oauth-protected-resource/mcp/account";
}

function sendUnauthorized(res, host, hadToken) {
    const body = JSON.stringify(rpcError(null, -32001, "Sign in to RingQ to use this server."));
    res.writeHead(401, {
        "Content-Type": "application/json",
        "Content-Length": Buffer.byteLength(body),
        "Cache-Control": "no-store",
        "WWW-Authenticate":
            'Bearer resource_metadata="' + resourceMetadataUrl(host) + '"' +
            (hadToken ? ', error="invalid_token"' : ""),
    });
    res.end(body);
}

function rpcError(id, code, message, data) {
    const error = { code, message };
    if (data !== undefined) {
        error.data = data;
    }
    return { jsonrpc: "2.0", id: id === undefined ? null : id, error };
}

function rpcResult(id, result) {
    return { jsonrpc: "2.0", id, result };
}

function send(res, status, payload) {
    const body = payload === null ? "" : JSON.stringify(payload);
    res.writeHead(status, {
        "Content-Type": "application/json",
        "Content-Length": Buffer.byteLength(body),
        "Cache-Control": "no-store",
    });
    res.end(body);
}

/**
 * Where the call really came from.
 *
 * nginx sets X-Real-IP from $remote_addr, overwriting anything the client
 * sent, so on the proxied path it is trustworthy. A direct connection to this
 * port carries no such header and falls back to the socket, which cannot be
 * forged. Either way the audit row names a machine rather than this adapter.
 */
function clientAddress(req) {
    const real = String(req.headers["x-real-ip"] || "").trim();
    if (real !== "") {
        return real.slice(0, 45);
    }
    return String(req.socket && req.socket.remoteAddress ? req.socket.remoteAddress : "").slice(0, 45);
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        let size = 0;
        const chunks = [];
        req.on("data", (chunk) => {
            size += chunk.length;
            if (size > MAX_BODY) {
                reject(new Error("request body is too large"));
                req.destroy();
                return;
            }
            chunks.push(chunk);
        });
        req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
        req.on("error", reject);
    });
}

/**
 * Ask PHP. The tenant host travels as the Host header, because that is what
 * McpController resolves the tenant from -- never a value in the body.
 *
 * The certificate is not verified: this is a loopback call to the same box,
 * whose certificate is issued for the public name rather than 127.0.0.1. The
 * connection never leaves the host.
 */
function callUpstream(caller, payload) {
    return new Promise((resolve) => {
        const body = JSON.stringify(payload);
        const request = https.request(
            {
                host: "127.0.0.1",
                port: UPSTREAM_PORT,
                path: caller.profile.upstream,
                method: "POST",
                rejectUnauthorized: false,
                servername: caller.host,
                headers: {
                    "Host": caller.host,
                    "Content-Type": "application/json",
                    "Content-Length": Buffer.byteLength(body),
                    "Authorization": caller.authorization || "",
                    "Accept": "application/json",
                    "X-Ringq-Mcp-Client-Ip": caller.ip || "",
                    "X-Ringq-Mcp-Client-Agent": caller.agent || "",
                },
            },
            (response) => {
                let raw = "";
                response.on("data", (chunk) => {
                    raw += chunk;
                    if (raw.length > MAX_BODY * 8) {
                        response.destroy();
                    }
                });
                response.on("end", () => {
                    let parsed = null;
                    try {
                        parsed = JSON.parse(raw);
                    } catch (e) {
                        parsed = null;
                    }
                    if (parsed === null) {
                        // An unparseable upstream is a fault worth naming. It
                        // must never surface as an empty success.
                        resolve({
                            ok: false,
                            error: {
                                code: "upstream_unreadable",
                                message: "The RingQ server answered in a form this adapter could not read.",
                            },
                        });
                        return;
                    }
                    resolve(parsed);
                });
            }
        );
        request.setTimeout(UPSTREAM_TIMEOUT, () => {
            request.destroy();
        });
        request.on("error", () => {
            resolve({
                ok: false,
                error: {
                    code: "upstream_unreachable",
                    message: "The RingQ server did not answer.",
                },
            });
        });
        request.write(body);
        request.end();
    });
}

function isUnauthorized(answer) {
    return !answer || (answer.ok === false && answer.error && answer.error.code === "unauthorized");
}

function textContent(value) {
    return [{ type: "text", text: typeof value === "string" ? value : JSON.stringify(value, null, 2) }];
}

async function handleRpc(message, caller) {
    const { id, method, params } = message;

    if (method === "initialize") {
        if (caller.profile.oauth) {
            const who = await callUpstream(caller, { op: "whoami" });
            if (isUnauthorized(who)) {
                return UNAUTHORIZED;
            }
        }
        const asked = params && params.protocolVersion;
        const version = SUPPORTED_PROTOCOLS.includes(asked) ? asked : DEFAULT_PROTOCOL;
        return rpcResult(id, {
            protocolVersion: version,
            capabilities: { tools: { listChanged: false } },
            serverInfo: caller.profile.info,
            instructions: caller.profile.instructions,
        });
    }

    if (method === "ping") {
        return rpcResult(id, {});
    }

    if (method === "tools/list") {
        const answer = await callUpstream(caller, { op: "list" });
        if (caller.profile.oauth && isUnauthorized(answer)) {
            return UNAUTHORIZED;
        }
        if (!answer.ok) {
            return rpcError(id, -32001, answer.error ? answer.error.message : "Support access was refused.", answer.error);
        }
        return rpcResult(id, { tools: answer.tools || [] });
    }

    if (method === "tools/call") {
        const name = params && params.name;
        const args = (params && params.arguments) || {};
        if (typeof name !== "string" || name === "") {
            return rpcError(id, -32602, "A tool name is required.");
        }
        const answer = await callUpstream(caller, {
            op: "call",
            name,
            arguments: args,
        });
        if (caller.profile.oauth && isUnauthorized(answer)) {
            return UNAUTHORIZED;
        }
        if (!answer.ok) {
            const message = answer.error ? answer.error.message : "That request was refused.";
            // Reported as a tool result rather than a protocol error so the
            // assistant can read the reason and say it, instead of the call
            // looking like a transport failure.
            return rpcResult(id, { content: textContent(message), isError: true });
        }
        return rpcResult(id, { content: textContent(answer.data), isError: false });
    }

    return rpcError(id, -32601, "This server does not implement " + method + ".");
}

const server = http.createServer(async (req, res) => {
    if (req.method === "GET" && req.url === "/healthz") {
        send(res, 200, { ok: true, service: "ringq-support-mcp" });
        return;
    }

    const path = String(req.url || "").split("?")[0];
    const profile = PROFILES[path];
    if (!profile) {
        send(res, 404, rpcError(null, -32600, "No MCP server at this path."));
        return;
    }
    const host = String(req.headers.host || "").split(":")[0];

    if (req.method !== "POST") {
        // No SSE stream: this server never initiates messages, so there is
        // nothing for a long-lived GET to carry.
        res.setHeader("Allow", "POST");
        send(res, 405, rpcError(null, -32600, "Use POST for MCP requests."));
        return;
    }

    if (profile.oauth && !/^Bearer\s+\S+$/i.test(String(req.headers.authorization || ""))) {
        sendUnauthorized(res, host, false);
        return;
    }

    let raw;
    try {
        raw = await readBody(req);
    } catch (e) {
        send(res, 413, rpcError(null, -32600, "That request was too large."));
        return;
    }

    let message;
    try {
        message = JSON.parse(raw);
    } catch (e) {
        send(res, 400, rpcError(null, -32700, "That request was not valid JSON."));
        return;
    }

    const caller = {
        host: String(req.headers.host || "").split(":")[0],
        authorization: req.headers.authorization || "",
        ip: clientAddress(req),
        agent: String(req.headers["user-agent"] || "").slice(0, 255),
        profile,
    };

    if (Array.isArray(message)) {
        const replies = [];
        for (const item of message) {
            if (!item || item.id === undefined) {
                continue;
            }
            const reply = await handleRpc(item, caller);
            if (reply === UNAUTHORIZED) {
                sendUnauthorized(res, host, true);
                return;
            }
            replies.push(reply);
        }
        if (replies.length === 0) {
            res.writeHead(202).end();
            return;
        }
        send(res, 200, replies);
        return;
    }

    if (!message || typeof message.method !== "string") {
        send(res, 400, rpcError(message && message.id, -32600, "That request was not a JSON-RPC call."));
        return;
    }

    // A notification carries no id and expects no answer.
    if (message.id === undefined) {
        res.writeHead(202).end();
        return;
    }

    try {
        const reply = await handleRpc(message, caller);
        if (reply === UNAUTHORIZED) {
            sendUnauthorized(res, host, true);
            return;
        }
        send(res, 200, reply);
    } catch (e) {
        send(res, 200, rpcError(message.id, -32603, "This adapter could not complete that request."));
    }
});

server.listen(PORT, HOST, () => {
    console.log("ringq support mcp listening on " + HOST + ":" + PORT);
});
