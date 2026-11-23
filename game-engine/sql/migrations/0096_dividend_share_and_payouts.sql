-- Paying out, written into the code (#930). A company's code sets the share
-- of each game day's profit it pays its shareholders (set_dividend_share(x):
-- companies.dividend_permille; NULL until set, then forge.dividend_permille
-- applies), and can pay out spare credits at any time (pay_out(n)): the
-- game engine records the request in company_payouts, and the website's
-- tick pays it to the shareholders by their shares, as it pays dividends
-- (PlayerShares::settlePayouts). Both are recorded in player_dividends;
-- payout = 1 marks a payout.
--
-- Only adds: a column, a table and a column.

SET @has := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'dividend_permille');
SET @sql := IF(@has = 0,
    'ALTER TABLE companies ADD COLUMN dividend_permille SMALLINT NULL COMMENT ''The share of its profit it pays as dividends, in thousandths, set by its code (#930); NULL: forge.dividend_permille''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS company_payouts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    drone INT UNSIGNED NOT NULL COMMENT 'The drone whose code asked',
    amount BIGINT NOT NULL COMMENT 'Credits asked to pay out',
    requested_t BIGINT NOT NULL COMMENT 'Game time',
    paid_t BIGINT NULL COMMENT 'Game time it was paid; NULL: not yet',
    paid BIGINT NULL COMMENT 'Credits paid to the holders (no more than it held)',
    KEY company_payouts_due (paid_t, id),
    KEY company_payouts_company (company_id, requested_t)
) ENGINE = InnoDB COMMENT 'Spare credits a company''s code paid out to its shareholders (pay_out, #930)';

SET @has := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'player_dividends' AND column_name = 'payout');
SET @sql := IF(@has = 0,
    'ALTER TABLE player_dividends ADD COLUMN payout TINYINT NOT NULL DEFAULT 0 COMMENT ''1: a payout its code made (#930), not a day''''s dividend''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
