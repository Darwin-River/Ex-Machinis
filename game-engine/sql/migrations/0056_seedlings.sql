-- Seedlings (#637, step 2 of the new rules in #635): every company starts
-- as a seedling, lent its hull and starting cash by the bank, plus a fee. A
-- seedling trades only with the House, can buy at most a share of the
-- House's stock of a good at one exchange in a game day, and can be
-- reprogrammed (the fee goes on its debt) or closed (it leaves the game with
-- all it holds; its wreck can't be plundered). Once it holds enough to pay
-- its debt, its player may mature it: it repays, and the player gets its
-- 1,000 shares. Used only while FORGE_NEW_RULES is on.

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'seedling');
SET @sql := IF(@have = 0,
    'ALTER TABLE companies ADD COLUMN seedling TINYINT NOT NULL DEFAULT 0 COMMENT ''1 while a seedling (#637)'', ADD COLUMN owner_id INT UNSIGNED NULL COMMENT ''The player account that started it (users.user_id; NULL for a guest''''s until claimed, #637)'', ADD COLUMN debt INT NOT NULL DEFAULT 0 COMMENT ''What a seedling owes the bank (#637)'', ADD COLUMN guest_key CHAR(64) NULL COMMENT ''SHA-256 of a guest seedling''''s claim code (#637)'', ADD COLUMN matured_t BIGINT NULL COMMENT ''Game time it matured (#637)'', ADD INDEX companies_owner (owner_id, seedling)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Most of the House's stock of a good at one exchange a seedling may buy in a
-- game day, in thousandths
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'seedling_buy_permille');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN seedling_buy_permille INT NOT NULL DEFAULT 100 COMMENT ''Most of the House''''s stock of a good at one exchange a seedling may buy in a game day, in thousandths (#637)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- What each seedling bought from the House, by exchange, good and game day
CREATE TABLE IF NOT EXISTS seedling_buys (
    company_id INT UNSIGNED NOT NULL,
    place INT NOT NULL,
    resource INT NOT NULL,
    day INT NOT NULL COMMENT 'Game day (game time / 86400)',
    units INT NOT NULL,
    PRIMARY KEY (company_id, place, resource, day)
);

-- The shares players hold (#637: a matured seedling's 1,000 go to its
-- player; trading them comes in #638)
CREATE TABLE IF NOT EXISTS player_shares (
    user_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    units INT NOT NULL,
    PRIMARY KEY (user_id, company_id),
    INDEX player_shares_company (company_id)
);
