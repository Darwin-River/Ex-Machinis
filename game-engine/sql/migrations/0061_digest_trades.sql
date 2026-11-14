-- The digest counts trades by id (#655). users.last_seen_trade: the newest
-- trade when the player last saw their digest. A trade's game time can be
-- earlier than when the events engine records it, so counting by game time
-- missed trades recorded just after a look. Used only while FORGE_NEW_RULES
-- is on.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_seen_trade');
SET @sql := IF(@have = 0,
    'ALTER TABLE users ADD COLUMN last_seen_trade BIGINT UNSIGNED NULL COMMENT ''The newest trade when the player last saw their digest (#655)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
