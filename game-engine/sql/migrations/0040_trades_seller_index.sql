-- A company's recent sales, quickly (#473, step 4 of the stock market #466):
-- shares_for_sale (query 696) and the House's share prices sum each
-- company's sales over the last game day from its drones' trades. trades
-- had no index by seller, so each sum read every trade.
SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'trades' AND index_name = 'trades_seller_time');
SET @sql := IF(@have = 0, 'CREATE INDEX trades_seller_time ON trades (seller, game_t)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
