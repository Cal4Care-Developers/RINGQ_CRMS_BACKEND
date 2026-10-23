#!/usr/bin/env python3
"""Idempotently add the RingQ Meet v1 routes to an existing TLS vhost."""

from __future__ import annotations

import os
import shutil
import sys
import tempfile
from pathlib import Path

BEGIN = "# BEGIN RINGQ MEET V1"
END = "# END RINGQ MEET V1"
MTLS_INCLUDE = "include /etc/nginx/ringq-meet-mtls-enabled/*.conf;"
LEGACY_API_REWRITE = "if ($uri ~* ^.*/api/.*$)"
MEET_SAFE_API_REWRITE = "if ($uri ~* ^(?!/meet/api/v1/).*/api/.*$)"

CENTRAL_ROUTES = r"""
    # BEGIN RINGQ MEET V1
    # Pre-compressed Flutter assets avoid transferring the full JavaScript and
    # WebAssembly payload. The updater regenerates every .gz sibling after the
    # webapp rsync, so gzip_static can never serve a stale build.
    gzip on;
    gzip_static on;
    gzip_vary on;
    gzip_types application/javascript text/javascript application/json text/css application/wasm image/svg+xml;

    location = /meetApi/accessToken {
        proxy_pass https://127.0.0.1:8443;
        proxy_ssl_server_name on;
        proxy_ssl_name $host;
        proxy_ssl_verify off;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Authorization "";
        proxy_read_timeout 15s;
        proxy_buffering off;
        client_max_body_size 4k;
        add_header Cache-Control "no-store" always;
    }

    # Setup progress for the Meet modal. This is the one Meet route that must
    # answer while Meet itself cannot: during a co-located install the control
    # plane is not yet routed, so the client asks PHP -- which is why this is a
    # /meetApi route and not a /meet/api/v1 one.
    location = /meetApi/setupStatus {
        proxy_pass https://127.0.0.1:8443;
        proxy_ssl_server_name on;
        proxy_ssl_name $host;
        proxy_ssl_verify off;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 15s;
        proxy_buffering off;
        client_max_body_size 1k;
        add_header Cache-Control "no-store" always;
    }

    # External schedules API (HMAC, machine-to-machine).
    #
    # It lives inside the Meet marker block because that block is what the
    # updater rewrites on every run. A second marker pair would not be found on
    # an already-deployed host, so its routes would be *appended* beside the
    # ones already there and nginx would refuse to start on the duplicate.
    #
    # proxy_pass carries a trailing URI, so nginx REPLACES the matched prefix:
    # /scheduleApi/v1/schedules/abc reaches Phalcon as
    # /scheduleApi/schedules/abc -> ScheduleApiController::schedulesAction.
    # This is the deliberate opposite of `location /meet/api/v1/` below, which
    # has no trailing URI and therefore proxies its prefix intact.
    #
    # The path must never be spelled /schedule/api/v1/. The server-level
    # FusionPBX rewrite above matches any URI containing /api/ and would send
    # it to index.php; /scheduleApi/ has no slash before "api", so that rule
    # cannot see it.
    location ^~ /scheduleApi/v1/ {
        proxy_pass https://127.0.0.1:8443/scheduleApi/;
        proxy_ssl_server_name on;
        proxy_ssl_name $host;
        proxy_ssl_verify off;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 30s;
        proxy_buffering off;
        client_max_body_size 64k;
        # 8443 answers every origin with Access-Control-Allow-Origin
        # $http_origin AND Allow-Credentials: true. A signed server-to-server
        # API must not inherit that: it would let any page a tenant user has
        # open drive this API with their cookies. The CloudApp's own playground
        # is same-origin and needs no CORS headers at all.
        proxy_hide_header Access-Control-Allow-Origin;
        proxy_hide_header Access-Control-Allow-Credentials;
        proxy_hide_header Access-Control-Allow-Headers;
        proxy_hide_header Access-Control-Allow-Methods;
        proxy_hide_header Cache-Control;
        add_header Cache-Control "no-store" always;
    }

    # Collaboration uploads are streamed end to end. Node enforces the 25 MiB
    # product limit while this narrow route allows multipart framing overhead.
    location ~ ^/meet/api/v1/rooms/[^/]+/(chat/attachments|presentation/assets|lobby/banner|lobby/photo)$ {
        proxy_pass http://127.0.0.1:6000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-RingQ-mTLS-Verified "";
        proxy_request_buffering off;
        proxy_buffering off;
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
        client_max_body_size 30m;
        proxy_hide_header Cache-Control;
        add_header Cache-Control "no-store" always;
    }

    # A webinar's lobby artwork — the banner and the presenter photo — and the
    # one Meet response that is meant to be cached.
    #
    # Its own location because the block below hides the control plane's
    # Cache-Control and replaces it with `no-store` — correct for an API whose
    # every answer is about a live meeting, and wrong for a picture whose URL
    # carries the digest of its own bytes. Without this the artwork is
    # re-fetched on every lobby open, which on a page an attendee leaves open
    # before a webinar is the same image over and over.
    location ~ ^/meet/api/v1/rooms/[^/]+/lobby/(banner|photo)/[^/]+$ {
        proxy_pass http://127.0.0.1:6000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-RingQ-mTLS-Verified "";
        proxy_read_timeout 30s;
        proxy_buffering off;
        client_max_body_size 4k;
    }

    location /meet/api/v1/ {
        proxy_pass http://127.0.0.1:6000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-RingQ-mTLS-Verified "";
        proxy_read_timeout 30s;
        proxy_request_buffering on;
        proxy_buffering off;
        client_max_body_size 64k;
        proxy_hide_header Cache-Control;
        add_header Cache-Control "no-store" always;
    }

    location = /meet/api/v1/gateway/events {
        if ($ssl_client_verify != SUCCESS) { return 403; }
        proxy_pass http://127.0.0.1:6000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-RingQ-mTLS-Verified $ssl_client_verify;
        client_max_body_size 64k;
        proxy_hide_header Cache-Control;
        add_header Cache-Control "no-store" always;
    }

    location ^~ /meet/m/ {
        root /var/www/webapp;
        try_files /meet/index.html =404;
        add_header Cache-Control "no-store" always;
    }

    location = /meet {
        return 308 /meet/;
    }

    location = /meet/ {
        root /var/www/webapp;
        try_files /meet/index.html =404;
        add_header Cache-Control "no-store" always;
    }

    # The lightweight Flutter guest client replaces the legacy frontend at
    # /var/www/webapp/meet. API and share-link locations above win by their
    # longer or exact matches.
    location ^~ /meet/ {
        root /var/www/webapp;
        try_files $uri =404;
        add_header Cache-Control "no-cache, public, must-revalidate" always;
    }
    location ^~ /rtc { return 410; }
    # END RINGQ MEET V1
""".strip("\n")

LOCAL_ROUTES = CENTRAL_ROUTES.replace(
    "    location ^~ /rtc { return 410; }",
    r"""    location = /meet-gateway/webhooks/primary {
        proxy_pass https://127.0.0.1:8444/v1/livekit/webhooks/primary;
        proxy_ssl_verify off;
        proxy_ssl_protocols TLSv1.3;
        proxy_http_version 1.1;
        proxy_set_header Host 127.0.0.1;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Content-Type $content_type;
        client_max_body_size 64k;
    }

    location ^~ /rtc {
        proxy_pass http://127.0.0.1:7880;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 1d;
        proxy_send_timeout 1d;
        proxy_buffering off;
    }""",
)


def matching_brace(text: str, opening: int) -> int:
    depth = 0
    quote = None
    escaped = False
    comment = False
    for index in range(opening, len(text)):
        char = text[index]
        if comment:
            if char == "\n":
                comment = False
            continue
        if escaped:
            escaped = False
            continue
        if char == "\\":
            escaped = True
            continue
        if quote:
            if char == quote:
                quote = None
            continue
        if char in ("'", '"'):
            quote = char
        elif char == "#":
            comment = True
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return index
    raise ValueError("unbalanced nginx configuration")


def tls_server(text: str) -> tuple[int, int]:
    cursor = 0
    while True:
        index = text.find("server", cursor)
        if index < 0:
            break
        brace = text.find("{", index + len("server"))
        if brace < 0:
            break
        if text[index + len("server") : brace].strip():
            cursor = index + len("server")
            continue
        end = matching_brace(text, brace)
        block = text[brace + 1 : end]
        if "listen 443" in block or "listen [::]:443" in block:
            return brace, end
        cursor = end + 1
    raise ValueError("no TLS server block was found")


def retrofit(text: str, mode: str = "central") -> str:
    routes = LOCAL_ROUTES if mode == "local" else CENTRAL_ROUTES
    opening, end = tls_server(text)
    block = text[opening + 1 : end]
    if MTLS_INCLUDE not in block:
        block = "\n\t" + MTLS_INCLUDE + block
    # FusionPBX's legacy server-level rewrite runs before Nginx chooses a
    # location. Without this narrow exclusion it rewrites every Meet v1 API
    # request to /meet/api/index.php, bypassing the control-plane proxy.
    block = block.replace(LEGACY_API_REWRITE, MEET_SAFE_API_REWRITE)

    begin = block.find(BEGIN)
    finish = block.find(END)
    if begin >= 0 or finish >= 0:
        if begin < 0 or finish < begin:
            raise ValueError("incomplete RingQ Meet marker block")
        line_start = block.rfind("\n", 0, begin) + 1
        line_end = block.find("\n", finish)
        if line_end < 0:
            line_end = len(block)
        block = block[:line_start] + routes + block[line_end:]
        return text[: opening + 1] + block + text[end:]

    legacy_markers = (
        ("# ── RingQ Meet v1 local", "# ── end RingQ Meet v1 local"),
        ("# ── RingQ Meet (SFU stack)", "# ── end RingQ Meet"),
    )
    for legacy_begin, legacy_end in legacy_markers:
        begin = block.find(legacy_begin)
        if begin < 0:
            continue
        finish = block.find(legacy_end, begin)
        if finish < 0:
            raise ValueError("incomplete legacy RingQ Meet marker block")
        line_start = block.rfind("\n", 0, begin) + 1
        line_end = block.find("\n", finish)
        if line_end < 0:
            line_end = len(block)
        block = block[:line_start] + routes + block[line_end:]
        return text[: opening + 1] + block + text[end:]

    if "location ^~ /meet/api/v1/" in block or "location /meet/api/v1/" in block or "/meetApi/accessToken" in block:
        raise ValueError("unmarked RingQ Meet routes require manual review")

    insertion = block.rfind("\n\tlocation / {")
    if insertion < 0:
        insertion = block.rfind("\n    location / {")
    if insertion < 0:
        raise ValueError("TLS server block has no root location insertion point")
    block = block[:insertion] + "\n\n" + routes + "\n" + block[insertion:]
    return text[: opening + 1] + block + text[end:]


def main() -> int:
    arguments = sys.argv[1:]
    mode = "central"
    if len(arguments) >= 2 and arguments[0] == "--mode":
        mode = arguments[1]
        arguments = arguments[2:]
    if mode not in ("central", "local") or len(arguments) != 1:
        print(
            "usage: ringq-meet-nginx-retrofit.py [--mode central|local] /etc/nginx/sites-available/ringq",
            file=sys.stderr,
        )
        return 64
    target = Path(arguments[0])
    original = target.read_text(encoding="utf-8")
    updated = retrofit(original, mode)
    if updated == original:
        return 0
    backup = target.with_suffix(target.suffix + ".pre-ringq-meet")
    if not backup.exists():
        shutil.copy2(target, backup)
    descriptor, temporary_name = tempfile.mkstemp(prefix=target.name + ".", dir=target.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            stream.write(updated)
        os.chmod(temporary, target.stat().st_mode & 0o777)
        os.replace(temporary, target)
    except Exception:
        temporary.unlink(missing_ok=True)
        raise
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
