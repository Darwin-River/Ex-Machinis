-- A rebate to the resting side of a share trade between players (#782).
-- When a standing order fills against another player's order, its owner gets
-- forge.rebate_permille (5: 0.5%) of the trade's credits from the bank, if
-- the order rested at least forge.rebate_rest_game_seconds (3,600: a game
-- hour), so players keep orders on the book. Round 72's books were mostly
-- empty: a standing order earned nothing over taking one.
--
-- player_share_orders.game_t: the game time it was placed (a pegged order
-- moved by PlayerShares::repeg keeps its own). NULL for orders placed before
-- this migration, which count as rested.
-- player_share_trades.rebate and rebate_user_id: what the resting side was
-- paid, and who (0 and NULL when nothing was).

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'player_share_orders' AND column_name = 'game_t');
SET @sql := IF(@have = 0,
    'ALTER TABLE player_share_orders ADD COLUMN game_t BIGINT NULL COMMENT ''Game time it was placed (#782); NULL before then''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'player_share_trades' AND column_name = 'rebate');
SET @sql := IF(@have = 0,
    'ALTER TABLE player_share_trades ADD COLUMN rebate INT NOT NULL DEFAULT 0 COMMENT ''Credits the bank paid the resting order (#782)'', ADD COLUMN rebate_user_id INT UNSIGNED NULL COMMENT ''Who it paid''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
