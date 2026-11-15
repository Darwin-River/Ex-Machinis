-- A matured company's cash-out value by game day (#699): its company page
-- charts what a share fetches from the bank over time, and
-- /api/v1/companies/{id}/history gives it per day. The value is worked out
-- (PlayerShares::cashOut: credits, goods at the House's bid, hulls, less
-- debts) when the daily row is taken (Leaderboard::dailyIfDue), for matured
-- companies of the new rules only; other rows, and days before this
-- migration, hold NULL. It is the whole company's value, not a share's.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'company_days' AND column_name = 'cash_out');
SET @sql := IF(@have = 0,
    'ALTER TABLE company_days ADD COLUMN cash_out BIGINT NULL COMMENT ''Cash-out value that game day, matured companies only (#699)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
