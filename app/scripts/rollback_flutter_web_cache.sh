#!/bin/bash
set -euo pipefail

SITE_CONF="${1:-/etc/nginx/sites-available/ringq}"
RELOAD_NGINX="${RELOAD_NGINX:-1}"

if [ ! -f "$SITE_CONF" ]; then
    echo "Missing nginx site config: $SITE_CONF" >&2
    exit 1
fi

if ! grep -Eq 'location[[:space:]]*=[[:space:]]*/cloudapp/(main\.dart\.js|flutter_service_worker\.js|flutter_bootstrap\.js)' "$SITE_CONF"; then
    echo "$SITE_CONF: Flutter cache optimization not present; nothing to remove"
    exit 0
fi

BACKUP="${SITE_CONF}.bak-remove-flutter-cache-$(date +%Y%m%d%H%M%S)-$$"
cp "$SITE_CONF" "$BACKUP"

python3 - "$SITE_CONF" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

no_cache_block = """        location ~* \\.(?:css|js)$ {
            root /var/www/webapp;
            gzip_vary on;
            expires -1;
            add_header Cache-Control "no-cache, public, must-revalidate";
            access_log off;
            log_not_found off;
        }
"""


def should_remove_location(header, body):
    if re.search(r'location\s*=\s*/cloudapp/(?:main\.dart\.js|flutter_service_worker\.js|flutter_bootstrap\.js)\s*\{', header):
        return True

    if 'css|js' not in header:
        return False

    return (
        re.search(r'no-cache,\s*public,\s*must-revalidate', body) is not None
        or re.search(r'add_header\s+Cache-Control\s+"public"\s+always;', body) is not None
    )


def remove_managed_locations(source):
    lines = source.splitlines(keepends=True)
    output = []
    index = 0

    while index < len(lines):
        line = lines[index]
        if re.match(r'^[ \t]*location\b', line) and '{' in line:
            block = [line]
            brace_depth = line.count('{') - line.count('}')
            index += 1

            while index < len(lines) and brace_depth > 0:
                block.append(lines[index])
                brace_depth += lines[index].count('{') - lines[index].count('}')
                index += 1

            body = ''.join(block)
            if should_remove_location(block[0], body):
                while index < len(lines) and lines[index].strip() == '':
                    index += 1
                continue

            output.extend(block)
            continue

        output.append(line)
        index += 1

    return ''.join(output)


text = remove_managed_locations(text)

matches = re.findall(r'^[ \t]*client_body_buffer_size\s+128k;\s*$', text, flags=re.M)
if not matches:
    raise SystemExit(f'{path}: client_body_buffer_size 128k not found; no rollback block inserted')

text = re.sub(
    r'(^[ \t]*client_body_buffer_size\s+128k;\s*$)',
    r'\1\n' + no_cache_block.rstrip(),
    text,
    flags=re.M,
)

path.write_text(text)
print(f'{path}: removed Flutter cache optimization from {len(matches)} server block(s)')
PY

nginx -t

if [ "$RELOAD_NGINX" = "1" ]; then
    systemctl reload nginx
fi

echo "Backup: $BACKUP"
