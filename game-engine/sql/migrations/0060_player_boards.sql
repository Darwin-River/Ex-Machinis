-- Player boards and the digest (#640, step 5 of the new rules in #635).
-- player_wealth_months: each account's wealth when it was first seen in a
-- real month (YYYY-MM), for the monthly season board. users.last_seen_t:
-- the game time a player last saw their "while you were away" digest.
-- Used only while FORGE_NEW_RULES is on.
CREATE TABLE IF NOT EXISTS player_wealth_months (
    user_id INT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    start_wealth BIGINT NOT NULL,
    PRIMARY KEY (month, user_id)
);

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_seen_t');
SET @sql := IF(@have = 0,
    'ALTER TABLE users ADD COLUMN last_seen_t BIGINT NULL COMMENT ''Game time the player last saw their digest (#640)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
