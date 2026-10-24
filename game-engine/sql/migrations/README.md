# Database migrations

Every change to the database schema after the base schema
(`game-engine/sql/create_db.sql` and `game-engine/db-backup/exmachinis_tables.sql`)
is a numbered SQL file here: `0001_engine_schema_upgrades.sql`, `0002_...`.

`game-engine/sql/migrate.sh` applies the files the database hasn't had yet, in
number order, and records each in the `schema_version` table. It runs:

- in Docker, as the `migrate` service on every start, before the website and
  engines (which then loads `game_data.sql`);
- in CI, after the base schema and before the test fixtures;
- by hand: `DB_USER=... DB_NAME=exmachinis MYSQL_PWD=... game-engine/sql/migrate.sh`
  (`DB_HOST` and `DB_PORT` default to `127.0.0.1` and `3306`).

## Adding a migration

1. Create the next number: `NNNN_what_it_does.sql`, for example
   `0002_game_time_columns.sql`. Two files with the same number are refused.
2. Write plain SQL. The `mysql` client runs it, so `DELIMITER` works for
   procedures.
3. Never edit or renumber a migration once it is merged; add a new one.
4. MySQL can't roll back schema changes, so a migration that fails halfway
   leaves its earlier statements applied. Keep each migration small, and make
   it safe to run again where you can (`CREATE TABLE IF NOT EXISTS`, or check
   `information_schema` first, as `0001` does).
5. Migrations run as the database's root user (in Docker and CI), so they may
   create triggers and procedures.
6. Game data (resources, protocols, queries) is not a migration: change the
   spreadsheets and run `build_game_data.py`.
