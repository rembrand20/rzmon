//go:build linux

package main

// De query's en de sleutels voor databasechecks: letterlijk die van de bash-agent (agent/src/76-database.sh).
// tests/agent-go-shadow.sh controleert dat ze gelijk blijven. Alleen lezen: status, verbindingen, lopende query's,
// replicatie en grootte; nooit data.

import "regexp"

const dbMySQL = `SHOW GLOBAL STATUS;
SELECT 'v:max_connections', @@max_connections;
SELECT 'v:version', VERSION();
SELECT 'v:read_only', @@read_only;
SELECT 'longq', CONCAT(TIME, '|', IFNULL(USER, ''), '|', IFNULL(DB, ''), '|', REPLACE(TO_BASE64(LEFT(INFO, 300)), CHAR(10), '')) FROM information_schema.PROCESSLIST WHERE COMMAND NOT IN ('Sleep', 'Daemon', 'Binlog Dump', 'Binlog Dump GTID') AND INFO IS NOT NULL AND ID <> CONNECTION_ID() AND TIME >= 5 ORDER BY TIME DESC LIMIT 5;
SHOW SLAVE STATUS\G
SHOW REPLICA STATUS\G`

const dbMySize = `SELECT CONCAT('size:', table_schema), COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema NOT IN ('information_schema', 'performance_schema', 'sys', 'mysql') GROUP BY table_schema;`

const dbPgSQL = `SELECT 'version', current_setting('server_version');
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
SELECT 'longq', floor(extract(epoch from now() - query_start))::bigint || '|' || coalesce(usename, '') || '|' || coalesce(datname, '') || '|' || translate(encode(convert_to(left(query, 300), 'UTF8'), 'base64'), E'\n', '') FROM pg_stat_activity WHERE backend_type = 'client backend' AND state = 'active' AND pid <> pg_backend_pid() AND now() - query_start > interval '5 seconds' ORDER BY query_start LIMIT 5;`

var (
	dbMyKeys   = regexp.MustCompile(`^(Uptime|Threads_connected|Threads_running|Questions|Slow_queries|Aborted_connects|Innodb_buffer_pool_reads|Innodb_buffer_pool_read_requests|Innodb_deadlocks|Innodb_row_lock_current_waits|Created_tmp_tables|Created_tmp_disk_tables|Max_used_connections|wsrep_cluster_status|wsrep_cluster_size|wsrep_local_state_comment|wsrep_ready|v:max_connections|v:version|v:read_only)$`)
	dbPgKeys   = regexp.MustCompile(`^(version|uptime|max_conn|conn|active|idle_tx|xact|blks_hit|blks_read|deadlocks|temp_files|wrap_age|lock_wait|recovery|repl_lag|replicas|arch_failed)$`)
	dbReplKeys = regexp.MustCompile(`^(Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master|Replica_IO_Running|Replica_SQL_Running|Seconds_Behind_Source|Last_IO_Error|Last_SQL_Error|Master_Host|Source_Host)$`)
)
