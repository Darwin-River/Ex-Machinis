-- Dividends from profit (#603). Until now each sale paid the seller's outside
-- shareholders their part of it at once, so a trader buying at 20 and
-- selling at 26 paid out on the whole 26 (round 62: buyers of shares earned
-- 3 to 13 times what they paid). The events engine now settles once a game
-- day: a company's profit for the day (sales after tax, less everything it
-- paid for in trades; trades between one owner's companies at the market's
-- price) first makes up any earlier losses, and the rest is shared among
-- its holders.
--
-- companies.dividend_day: the last game day settled (NULL: never; the
-- engine then logs the last 7 days without paying, so share prices have a
-- profit history, and pays from the next day on).
-- companies.dividend_carry: losses not yet made up (0 or below).
-- company_profits: each settled day, for the company page and queries.

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'dividend_day');
SET @sql := IF(@have = 0,
    'ALTER TABLE companies ADD COLUMN dividend_day INT NULL COMMENT ''Last game day its dividends were settled (#603)'', '
    'ADD COLUMN dividend_carry BIGINT NOT NULL DEFAULT 0 COMMENT ''Losses not yet made up (#603)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS company_profits (
    company INT UNSIGNED NOT NULL,
    day INT NOT NULL COMMENT 'Game day',
    sales BIGINT NOT NULL COMMENT 'Sales after sales tax',
    purchases BIGINT NOT NULL COMMENT 'Paid for goods, fuel and equipment',
    profit BIGINT NOT NULL COMMENT 'sales - purchases',
    carry_before BIGINT NOT NULL DEFAULT 0 COMMENT 'Losses not yet made up, before this day',
    paid BIGINT NOT NULL DEFAULT 0 COMMENT 'Dividends paid to other holders',
    holders INT NOT NULL DEFAULT 0,
    logged_only TINYINT NOT NULL DEFAULT 0 COMMENT '1: logged when profit dividends began, not paid',
    PRIMARY KEY (company, day),
    CONSTRAINT company_profits_company FOREIGN KEY (company) REFERENCES companies (company_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- Purchases are summed by buyer each day: without this every company-day
-- would scan the whole trades table (the seller has trades_seller_time)
SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'trades' AND index_name = 'trades_buyer_time');
SET @sql := IF(@have = 0, 'ALTER TABLE trades ADD INDEX trades_buyer_time (buyer, game_t)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
