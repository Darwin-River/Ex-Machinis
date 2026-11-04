-- A drone's own status line (#380, after round 39): set_status(text) in a
-- drone's script says what it is doing or waiting for ("waiting for metals:
-- none on sale here"), shown on its page and in the API. Newcomers asked for
-- an "idle reason": a drone that sat still for days gave no sign why.
-- script_status: the text (up to 80 characters); script_status_t: the game
-- time it was set.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'agents' AND column_name = 'script_status');
SET @sql := IF(@have = 0,
    'ALTER TABLE agents ADD COLUMN script_status VARCHAR(80) NULL, ADD COLUMN script_status_t BIGINT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
