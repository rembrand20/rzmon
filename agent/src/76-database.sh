# ---------------------------------------------------------------- Databases (agent 1.81.0)
# Databasechecks uit RzSense: "id <tab> db <tab> mysql|pgsql <tab> local|container <tab> container (of -) <tab> gebruiker (of -)
# <tab> wachtwoord (base64, of -) <tab> database (of -) <tab> sleutel <tab> interval".
# Per sleutel (één verbinding) één meting, hoe veel checks er ook op die verbinding staan. Alleen lezen: status, verbindingen,
# lopende query's, replicatie en grootte; nooit data. Zonder gebruiker lokaal zonder wachtwoord: MariaDB/MySQL als root via
# de socket, PostgreSQL als gebruiker postgres (peer). In een container: docker/podman exec met de client in die container
# (zonder gebruiker: root met het wachtwoord uit de omgeving van de container, of postgres). Een wachtwoord stuurt RzSense
# alleen over een versleutelde verbinding; het gaat via de omgeving naar de client, nooit op de opdrachtregel.
db_json=""
if [ "$TEST" != 1 ] && [ -r "${t_cache-}" ] && grep -q $'^[0-9]*\tdb\t' "$t_cache"; then
    lap db
    db_dir="$t_dir/db"
    mkdir -p "$db_dir" 2>/dev/null && chmod 700 "$db_dir" 2>/dev/null
    db_my_sql="SHOW GLOBAL STATUS;
SELECT 'v:max_connections', @@max_connections;
SELECT 'v:version', VERSION();
SELECT 'v:read_only', @@read_only;
SELECT 'longq', CONCAT(TIME, '|', IFNULL(USER, ''), '|', IFNULL(DB, ''), '|', REPLACE(TO_BASE64(LEFT(INFO, 300)), CHAR(10), '')) FROM information_schema.PROCESSLIST WHERE COMMAND NOT IN ('Sleep', 'Daemon', 'Binlog Dump', 'Binlog Dump GTID') AND INFO IS NOT NULL AND ID <> CONNECTION_ID() AND TIME >= 5 ORDER BY TIME DESC LIMIT 5;
SHOW SLAVE STATUS\\G
SHOW REPLICA STATUS\\G"
    db_my_size="SELECT CONCAT('size:', table_schema), COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema NOT IN ('information_schema', 'performance_schema', 'sys', 'mysql') GROUP BY table_schema;"
    db_pg_sql="SELECT 'version', current_setting('server_version');
SELECT 'uptime', floor(extract(epoch from now() - pg_postmaster_start_time()))::bigint;
SELECT 'max_conn', current_setting('max_connections');
SELECT 'conn', count(*) FROM pg_stat_activity WHERE backend_type = 'client backend';
SELECT 'active', count(*) FROM pg_stat_activity WHERE backend_type = 'client backend' AND state = 'active' AND pid <> pg_backend_pid();
SELECT 'idle_tx', count(*) FROM pg_stat_activity WHERE state LIKE 'idle in transaction%' AND now() - state_change > interval '5 minutes';
SELECT 'xact', sum(xact_commit + xact_rollback) FROM pg_stat_database;
SELECT 'blks_hit', sum(blks_hit) FROM pg_stat_database;
SELECT 'blks_read', sum(blks_read) FROM pg_stat_database;
SELECT 'deadlocks', sum(deadlocks) FROM pg_stat_database;
SELECT 'temp_files', sum(temp_files) FROM pg_stat_database;
SELECT 'wrap_age', max(age(datfrozenxid)) FROM pg_database;
SELECT 'lock_wait', count(*) FROM pg_locks WHERE NOT granted;
SELECT 'recovery', pg_is_in_recovery()::int;
SELECT 'repl_lag', COALESCE(floor(extract(epoch from now() - pg_last_xact_replay_timestamp()))::bigint, -1) WHERE pg_is_in_recovery();
SELECT 'replicas', count(*) FROM pg_stat_replication;
SELECT 'arch_failed', failed_count FROM pg_stat_archiver;
SELECT 'size:' || datname, pg_database_size(datname) FROM pg_database WHERE datallowconn AND NOT datistemplate;
SELECT 'longq', floor(extract(epoch from now() - query_start))::bigint || '|' || coalesce(usename, '') || '|' || coalesce(datname, '') || '|' || translate(encode(convert_to(left(query, 300), 'UTF8'), 'base64'), E'\\n', '') FROM pg_stat_activity WHERE backend_type = 'client backend' AND state = 'active' AND pid <> pg_backend_pid() AND now() - query_start > interval '5 seconds' ORDER BY query_start LIMIT 5;"
    # Uitvoer (sleutel <tab> waarde, en "  Veld: waarde" van SHOW SLAVE STATUS) als JSON; alleen bekende sleutels
    db_to_json() {  # db_to_json <engine> <ms> <exit> <uitvoerbestand>
        LC_ALL=C awk -v eng="$1" -v ms="$2" -v rc="$3" -v now="$(date +%s)" '
            function esc(s) { gsub(/\\/, "\\\\", s); gsub(/"/, "\\\"", s); gsub(/[\001-\037]/, " ", s); return substr(s, 1, 400) }
            BEGIN { my = "^(Uptime|Threads_connected|Threads_running|Questions|Slow_queries|Aborted_connects|Innodb_buffer_pool_reads|Innodb_buffer_pool_read_requests|Innodb_deadlocks|Innodb_row_lock_current_waits|Created_tmp_tables|Created_tmp_disk_tables|Max_used_connections|wsrep_cluster_status|wsrep_cluster_size|wsrep_local_state_comment|wsrep_ready|v:max_connections|v:version|v:read_only)$"
                    pg = "^(version|uptime|max_conn|conn|active|idle_tx|xact|blks_hit|blks_read|deadlocks|temp_files|wrap_age|lock_wait|recovery|repl_lag|replicas|arch_failed)$"
                    rp = "^(Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master|Replica_IO_Running|Replica_SQL_Running|Seconds_Behind_Source|Last_IO_Error|Last_SQL_Error|Master_Host|Source_Host)$" }
            /\t/ { k = $0; sub(/\t.*/, "", k); v = $0; sub(/^[^\t]*\t/, "", v)
                   if (k == "longq") { if (++nl <= 5) kv = kv sprintf("\"longq:%d\":\"%s\",", nl, esc(v)); next }
                   if (k ~ /^size:/) { if (++ns <= 60) kv = kv sprintf("\"%s\":\"%s\",", esc(k), esc(v)); next }
                   if (k ~ (eng == "pgsql" ? pg : my)) kv = kv sprintf("\"%s\":\"%s\",", k, esc(v)); next }
            /^ *[A-Za-z_]+: / && eng == "mysql" { k = $0; sub(/^ */, "", k); sub(/:.*/, "", k); v = $0; sub(/^ *[A-Za-z_]+: /, "", v)
                   if (k ~ rp) kv = kv sprintf("\"repl:%s\":\"%s\",", k, esc(v)); next }
            /[A-Za-z]/ && err == "" && !/^\*+/ { err = $0 }
            END { sub(/,$/, "", kv)
                  if (kv == "") printf "{\"ok\":0,\"ms\":%d,\"err\":\"%s\",\"at\":%d}", ms, esc(err == "" ? "geen antwoord (exit " rc ")" : err), now
                  else printf "{\"ok\":1,\"ms\":%d,\"kv\":{%s},\"at\":%d}", ms, kv, now }' "$4"
    }
    # db_run <engine> <waar> <container> <gebruiker> <wachtwoord> <database> <sql>: de query als alleen-lezen client, uitvoer naar stdout
    db_run() {
        local eng=$1 where=$2 ct=$3 usr=$4 pw=$5 dbn=$6 sql=$7 ctx
        if [ "$where" = container ]; then
            if [[ "$ct" == */* ]]; then ctx=${ct%%/*}; ct=${ct#*/}
            elif command -v docker >/dev/null 2>&1 && timeout 5 docker inspect "$ct" >/dev/null 2>&1; then ctx=docker
            else ctx=root; fi
            if [ "$eng" = mysql ]; then
                # shellcheck disable=SC2016  # $… wordt in de container uitgewerkt
                printf '%s\n' "$sql" | MYSQL_PWD="$pw" pm "$ctx" exec -i --env MYSQL_PWD "$ct" sh -c \
                    'c=$(command -v mariadb || command -v mysql) || { echo "geen mariadb/mysql-client in de container"; exit 127; }
                     [ -n "$MYSQL_PWD" ] || MYSQL_PWD=${MARIADB_ROOT_PASSWORD:-${MYSQL_ROOT_PASSWORD:-}}; export MYSQL_PWD
                     exec "$c" -N -B --force --connect-timeout=5 -u "${1:-root}" ${2:+"$2"}' sh "$usr" "$dbn" 2>&1
            else
                if [ -n "$usr" ]; then
                    printf '%s\n' "$sql" | PGPASSWORD="$pw" pm "$ctx" exec -i --env PGPASSWORD "$ct" psql -X -A -t -F $'\t' -q -h 127.0.0.1 -U "$usr" -d "${dbn:-postgres}" 2>&1
                else
                    printf '%s\n' "$sql" | pm "$ctx" exec -i -u postgres "$ct" psql -X -A -t -F $'\t' -q -d "${dbn:-postgres}" 2>&1
                fi
            fi
        elif [ "$eng" = mysql ]; then
            local c; c=$(command -v mariadb || command -v mysql) || { echo "mariadb/mysql-client ontbreekt op de server"; return 127; }
            printf '%s\n' "$sql" | MYSQL_PWD="$pw" timeout 20 "$c" -N -B --force --connect-timeout=5 -u "${usr:-root}" ${dbn:+"$dbn"} 2>&1
        else
            command -v psql >/dev/null 2>&1 || { echo "psql ontbreekt op de server (postgresql-client)"; return 127; }
            if [ -n "$usr" ]; then
                printf '%s\n' "$sql" | PGPASSWORD="$pw" PGCONNECT_TIMEOUT=5 timeout 20 psql -X -A -t -F $'\t' -q -h 127.0.0.1 -U "$usr" -d "${dbn:-postgres}" 2>&1
            elif [ "$(id -u)" = 0 ]; then
                (cd / && printf '%s\n' "$sql" | PGCONNECT_TIMEOUT=5 timeout 20 runuser -u postgres -- psql -X -A -t -F $'\t' -q -d "${dbn:-postgres}" 2>&1)
            else
                echo "zonder gebruiker kan alleen een agent als root PostgreSQL lezen (als gebruiker postgres)"; return 1
            fi
        fi
    }
    db_seen=" "
    while IFS=$'\t' read -r d_id d_type d_eng d_where d_ct d_user d_pass d_name d_key d_ival _; do
        [ "$d_type" = db ] && [[ "$d_id" =~ ^[0-9]+$ ]] && [[ "$d_key" =~ ^[0-9a-f]{12}$ ]] || continue
        case "$db_seen" in *" $d_key "*) continue ;; esac
        db_seen+="$d_key "
        [[ "$d_eng" =~ ^(mysql|pgsql)$ ]] && [[ "$d_where" =~ ^(local|container)$ ]] || continue
        [ "$d_ct" = - ] && d_ct=""; [ "$d_user" = - ] && d_user=""; [ "$d_name" = - ] && d_name=""
        [[ "$d_ct" =~ ^([A-Za-z0-9_.-]{1,32}/)?[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$|^$ ]] && [[ "$d_user" =~ ^[A-Za-z0-9_.@-]{0,80}$ ]] && [[ "$d_name" =~ ^[A-Za-z0-9_$-]{0,64}$ ]] || continue
        [ "$d_where" = container ] && [ -z "$d_ct" ] && continue
        d_pw=""
        [ "$d_pass" != - ] && [ -n "$d_pass" ] && d_pw=$(printf '%s' "$d_pass" | base64 -d 2>/dev/null)
        [[ "$d_ival" =~ ^[0-9]+$ ]] || d_ival=60
        d_out="$db_dir/$d_key.json"
        if ! due_cached "$d_out" "$d_ival"; then
            d_raw="$db_dir/$d_key.raw"
            d_t0=$(now_ms)
            if [ "$d_eng" = mysql ]; then d_sql=$db_my_sql; else d_sql=$db_pg_sql; fi
            db_run "$d_eng" "$d_where" "$d_ct" "$d_user" "$d_pw" "$d_name" "$d_sql" > "$d_raw"
            d_rc=$?
            d_ms=$(( $(now_ms) - d_t0 ))
            # Grootte per database (MariaDB/MySQL) hooguit eens per half uur: information_schema.tables kan traag zijn
            if [ "$d_eng" = mysql ] && grep -q $'^Uptime\t' "$d_raw"; then
                due_cached "$db_dir/$d_key.size" 1800 || db_run mysql "$d_where" "$d_ct" "$d_user" "$d_pw" "$d_name" "$db_my_size" | grep $'^size:' > "$db_dir/$d_key.size"
                cat "$db_dir/$d_key.size" >> "$d_raw" 2>/dev/null
            fi
            db_to_json "$d_eng" "$d_ms" "$d_rc" "$d_raw" > "$d_out.tmp" && mv -f "$d_out.tmp" "$d_out"
            rm -f "$d_raw"
        fi
        [ -s "$d_out" ] && db_json+="\"$d_key\":$(head -c 20000 "$d_out"),"
    done < "$t_cache"
    unset d_pw d_pass
    [ -n "$db_json" ] && db_json="{${db_json%,}}"
fi
