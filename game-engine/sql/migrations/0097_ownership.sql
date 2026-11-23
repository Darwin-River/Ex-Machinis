-- Ownership (#923, #924, #925, #931), part 3 of the revised system (#921).
--
-- * A company is owned outright when it is a startup, or one player holds
--   all its shares. Its owner changes its code for free; at most
--   forge.startup_max (5) owned outright a player, startups included.
-- * companies.code_public_t: when its shares were first offered (a sell
--   order or a sale to the bank). From then its code is public, and the
--   first share sold makes it public for good (it is no longer owned
--   outright). A push by an owner outright makes the new code private again.
-- * share_sale_bans: a player can't buy a company's shares for 7 real days
--   after selling any (so the lock can't be undone by buying straight back).
-- * company_closures: close_company() from a company's code (the kill
--   switch); the website's tick ends the company.
-- * A startup's credit cap and daily buying cap are retired: the cap set to
--   none, and the buying share to all the House has on sale.
-- * Owner-led offerings of new shares (#780) retire: open ones close.

SET @has := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'code_public_t');
SET @sql := IF(@has = 0,
    'ALTER TABLE companies ADD COLUMN code_public_t BIGINT NULL COMMENT ''Game time its shares were first offered: its code is public from then (#931); NULL: private while owned outright''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS share_sale_bans (
    user_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    until_at DATETIME NOT NULL COMMENT 'Real time: it can buy this company''s shares again from then',
    PRIMARY KEY (user_id, company_id)
) ENGINE = InnoDB COMMENT 'No buying a company''s shares for 7 real days after selling any (#923)';

CREATE TABLE IF NOT EXISTS company_closures (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    drone INT UNSIGNED NOT NULL COMMENT 'The drone whose code asked',
    requested_t BIGINT NOT NULL COMMENT 'Game time',
    done_t BIGINT NULL COMMENT 'Game time it ended; NULL: not yet',
    outcome VARCHAR(32) NULL COMMENT 'closed, or why it did not (ended already)',
    KEY company_closures_due (done_t, id),
    KEY company_closures_company (company_id)
) ENGINE = InnoDB COMMENT 'close_company() from a company''s code: the kill switch (#925)';

UPDATE house_rules SET startup_credit_cap_permille = 0, startup_buy_permille = 1000 WHERE id = 1;

SET @has := (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'player_share_offerings');
SET @sql := IF(@has = 1, 'UPDATE player_share_offerings SET closed_t = created_t WHERE closed_t IS NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
