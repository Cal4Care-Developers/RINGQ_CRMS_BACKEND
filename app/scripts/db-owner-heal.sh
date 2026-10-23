#!/bin/bash
# Database objects the RingQ app role cannot use because another role (usually postgres) owns them.
# The app role is not a superuser, so a table created by a root-run migration and left owned by
# postgres refuses it ("permission denied for table ..."): queues rang nobody (my1,
# q_call_center_tier_order) and Time Machine backups failed in pg_dump (68.183.181.71).
#
#   db-owner-heal.sh status <database> <role>   one JSON line: relations in schema public not owned by <role>
#   db-owner-heal.sh fix <database> <role>      ALTER ... OWNER TO <role> for each of them, as postgres
#
# Only ownership changes, plus USAGE and CREATE on schema public when the role lacks them. Always exits 0.

LOG=${RINGQ_DB_OWNER_LOG:-/var/log/ringq-db-owner.log}
PSQL=${RINGQ_PSQL:-psql}

log() {
    echo "$(date '+%F %T') $*" >> "$LOG" 2>/dev/null || true
}

valid_name() {
    case "$1" in
        ''|[0-9]*|*[!A-Za-z0-9_]*) return 1 ;;
    esac
    [ "${#1}" -le 63 ]
}

as_postgres() {
    if [ -n "${RINGQ_PG_AS_SELF:-}" ] || [ "$(id -u 2>/dev/null)" != 0 ]; then
        "$PSQL" "$@"
    else
        runuser -u postgres -- "$PSQL" "$@"
    fi
}

LIST_SQL="SELECT coalesce(json_agg(json_build_object('name', c.relname, 'kind', c.relkind, 'owner', pg_get_userbyid(c.relowner)) ORDER BY c.relname), '[]')
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind IN ('r','p','v','m','f','S')
   AND pg_get_userbyid(c.relowner) <> current_setting('ringq.owner_role')"

FIX_SQL="DO \$\$
DECLARE
    r record;
    target text := current_setting('ringq.owner_role');
    kind text;
BEGIN
    FOR r IN SELECT c.relname, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public' AND c.relkind IN ('r','p','v','m','f')
                AND pg_get_userbyid(c.relowner) <> target ORDER BY c.relname LOOP
        kind := CASE r.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'f' THEN 'FOREIGN TABLE' ELSE 'TABLE' END;
        BEGIN
            EXECUTE format('ALTER %s public.%I OWNER TO %I', kind, r.relname, target);
            RAISE NOTICE 'owner changed: % %', lower(kind), r.relname;
        EXCEPTION WHEN others THEN
            RAISE NOTICE 'not changed: % % (%)', lower(kind), r.relname, SQLERRM;
        END;
    END LOOP;
    FOR r IN SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public' AND c.relkind = 'S'
                AND pg_get_userbyid(c.relowner) <> target ORDER BY c.relname LOOP
        BEGIN
            EXECUTE format('ALTER SEQUENCE public.%I OWNER TO %I', r.relname, target);
            RAISE NOTICE 'owner changed: sequence %', r.relname;
        EXCEPTION WHEN others THEN
            RAISE NOTICE 'not changed: sequence % (%)', r.relname, SQLERRM;
        END;
    END LOOP;
END
\$\$;"

GRANT_SQL="DO \$\$
DECLARE
    target text := current_setting('ringq.owner_role');
BEGIN
    IF NOT has_schema_privilege(target, 'public', 'USAGE') OR NOT has_schema_privilege(target, 'public', 'CREATE') THEN
        EXECUTE format('GRANT USAGE, CREATE ON SCHEMA public TO %I', target);
        RAISE NOTICE 'granted usage and create on schema public';
    END IF;
END
\$\$;"

run_sql() {
    local db="$1" role="$2" sql="$3"
    as_postgres -X -q -At -v ON_ERROR_STOP=1 -d "$db" \
        -c "SELECT set_config('ringq.owner_role', '$role', false)" -c "$sql" 2>&1 | sed '1{/^'"$role"'$/d;}'
}

status() {
    local db="$1" role="$2" out
    if ! valid_name "$db" || ! valid_name "$role"; then
        echo '{"ok":false,"error":"invalid database or role name","objects":[]}'
        return
    fi
    out=$(run_sql "$db" "$role" "$LIST_SQL" | tail -1)
    case "$out" in
        \[*) echo "{\"ok\":true,\"database\":\"$db\",\"role\":\"$role\",\"objects\":$out}" ;;
        *) echo '{"ok":false,"error":"could not read the database as postgres","objects":[]}' ;;
    esac
}

fix() {
    local db="$1" role="$2" line
    if ! valid_name "$db" || ! valid_name "$role"; then
        log "refused: invalid database or role name"
        return
    fi
    if [ "$(run_sql "$db" "$role" "SELECT 1 FROM pg_roles WHERE rolname = current_setting('ringq.owner_role')" | tail -1)" != 1 ]; then
        log "refused: role $role does not exist in $db"
        return
    fi
    run_sql "$db" "$role" "$FIX_SQL" | while IFS= read -r line; do
        line=${line#NOTICE:  }
        [ -n "$line" ] && log "$db: $line"
    done
    run_sql "$db" "$role" "$GRANT_SQL" | while IFS= read -r line; do
        line=${line#NOTICE:  }
        [ -n "$line" ] && log "$db: $line"
    done
}

case "$1" in
    status) status "$2" "$3" ;;
    fix) fix "$2" "$3" ;;
    *) echo "usage: $0 status|fix <database> <role>" >&2 ;;
esac
exit 0
