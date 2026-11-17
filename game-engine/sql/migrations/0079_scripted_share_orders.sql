-- Scripted share orders tagged (#779): an order a player's trading script
-- placed (TradeScripts, #768) is marked, and the book shows it as
-- "(script)", so players can tell a script's standing orders from a
-- person's. A pegged order moved by PlayerShares::repeg keeps its mark.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'player_share_orders' AND column_name = 'by_script');
SET @sql := IF(@have = 0,
    'ALTER TABLE player_share_orders ADD COLUMN by_script TINYINT NOT NULL DEFAULT 0 COMMENT ''1: placed by the account''''s trading script (#779)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
