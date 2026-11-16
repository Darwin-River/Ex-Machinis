-- Pegged share orders (#766): an order whose price follows the bank's
-- buying price (buys_at, the cash-out value) or selling price (sells_at),
-- plus or minus a percent. The website re-prices it when they move
-- (PlayerShares::repeg, on the scheduler's keeper). Round 72's standing
-- orders went stale within 10 game days, under cash-out or out of range.

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'player_share_orders' AND column_name = 'peg_ref');
SET @sql := IF(@have = 0,
    'ALTER TABLE player_share_orders ADD COLUMN peg_ref VARCHAR(8) NULL COMMENT ''buys_at or sells_at: the bank price it follows (#766); NULL for a fixed price'', ADD COLUMN peg_offset_bp INT NULL COMMENT ''Its offset from that price, in hundredths of a percent''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
