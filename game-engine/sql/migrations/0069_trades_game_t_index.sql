-- Trades by game time alone (#723): the dashboard sums the last 60 and 14
-- game days of trades, and /api/v1/history and the market board the last
-- few, with no resource or drone to start from, so each read the whole
-- trades table.
SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'trades' AND index_name = 'trades_game_t');
SET @sql := IF(@have = 0, 'CREATE INDEX trades_game_t ON trades (game_t)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
