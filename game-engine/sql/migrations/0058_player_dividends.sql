-- Dividends to players (#638, step 3 of the new rules in #635): each game
-- day, a matured company pays forge.dividend_permille of its profit (after
-- making up losses carried, as the events engine works it out in
-- company_profits) to its shareholders: players' accounts, and the bank
-- for the shares it holds. Paid by the website's tick. Used only while
-- FORGE_NEW_RULES is on.

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'player_dividend_day');
SET @sql := IF(@have = 0,
    'ALTER TABLE companies ADD COLUMN player_dividend_day INT NULL COMMENT ''The last game day it paid players dividends for (#638)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Each dividend paid: to a player (user_id), or to the bank (user_id NULL)
CREATE TABLE IF NOT EXISTS player_dividends (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    day INT NOT NULL COMMENT 'The game day its profit was made',
    units INT NOT NULL COMMENT 'Shares held',
    amount INT NOT NULL,
    PRIMARY KEY (id),
    INDEX player_dividends_user (user_id, day),
    INDEX player_dividends_company (company_id, day)
);
