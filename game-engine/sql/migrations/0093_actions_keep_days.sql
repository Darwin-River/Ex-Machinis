-- Finished actions go after 30 game days (#944). Nothing deleted from
-- `actions`: on the live world it had grown to 44.6 million rows, 3.07 GB,
-- the database's largest table. The scheduler's keeper (App\Services\
-- ActionsPrune) now deletes an action once nothing uses it and its last event
-- is this many game days old; 0 keeps every action. No index is added: the
-- keeper walks the table by its primary key and checks each action with the
-- indexes events, events_archive and tool_locks already have.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'actions_keep_days');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN actions_keep_days INT NOT NULL DEFAULT 30 COMMENT ''Game days a finished action is kept after its last event, then deleted (0: never) (#944)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
