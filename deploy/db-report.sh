#!/bin/bash
# db-report.sh: what the live database spends its time on (#694), printed by
# every deploy before it restarts anything, so the deploy's log (and the
# Deploy workflow's, in GitHub Actions) shows it without a login to the
# server. The ten statements that took the most time since the database
# last started (performance_schema's digests: no values, only the shapes),
# and the biggest tables. It never fails a deploy.
#
#   deploy/db-report.sh            (from the checkout, as the deploy user)
cd "$(dirname "$0")" || exit 0

docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --table -e "
SELECT COUNT_STAR AS calls,
       ROUND(SUM_TIMER_WAIT / 1e12) AS total_s,
       ROUND(AVG_TIMER_WAIT / 1e9, 1) AS avg_ms,
       ROUND(SUM_ROWS_EXAMINED / GREATEST(COUNT_STAR, 1)) AS rows_each,
       LEFT(REPLACE(DIGEST_TEXT, \"\n\", \" \"), 220) AS statement
FROM performance_schema.events_statements_summary_by_digest
WHERE SCHEMA_NAME = \"exmachinis\"
ORDER BY SUM_TIMER_WAIT DESC LIMIT 10;
SELECT TABLE_NAME AS big_table, TABLE_ROWS AS rows_about, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1e9, 1) AS gb
FROM information_schema.TABLES WHERE TABLE_SCHEMA = \"exmachinis\"
ORDER BY DATA_LENGTH + INDEX_LENGTH DESC LIMIT 6;
SELECT ROUND(VARIABLE_VALUE / 3600, 1) AS db_up_hours FROM performance_schema.global_status WHERE VARIABLE_NAME = \"Uptime\";
"' 2>/dev/null || echo "db-report: the database didn't answer"
exit 0
