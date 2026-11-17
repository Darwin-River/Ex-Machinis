-- New shares to fund growth (#780). A matured company's owner may offer 10
-- new shares at a time, at the company's cash-out value a share; the credits
-- go to the company, so investing pays for a copy. One offering is open at a
-- time, and a company has at most 200 shares. Any player may buy, its owner
-- too. Round 72's players had no way to put money into a company: every
-- share trade moved credits between players or the bank.
--
-- companies.player_share_count: its shares in all (NULL: 100, as every
-- matured company had until now). company_days.shares: the count that day,
-- so a share's history divides by the shares it had then (NULL: 100).
-- player_share_offerings: each offering, open while units_left > 0 and
-- closed_t is NULL. player_share_trades.issued: 1 for a sale of new shares
-- by the company (seller_id NULL, like the bank's).

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'player_share_count');
SET @sql := IF(@have = 0,
    'ALTER TABLE companies ADD COLUMN player_share_count INT NULL COMMENT ''A matured company''''s shares in all (#780); NULL: 100''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'company_days' AND column_name = 'shares');
SET @sql := IF(@have = 0,
    'ALTER TABLE company_days ADD COLUMN shares INT NULL COMMENT ''Its share count that day (#780); NULL: 100''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'player_share_trades' AND column_name = 'issued');
SET @sql := IF(@have = 0,
    'ALTER TABLE player_share_trades ADD COLUMN issued TINYINT NOT NULL DEFAULT 0 COMMENT ''1: new shares sold by the company (#780)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS player_share_offerings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    units INT NOT NULL,
    units_left INT NOT NULL,
    raised BIGINT NOT NULL DEFAULT 0,
    created_t BIGINT NOT NULL,
    closed_t BIGINT NULL,
    INDEX player_share_offerings_company (company_id, closed_t)
);
