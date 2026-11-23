-- Credits that balance (#937). Credits are held in companies.credits,
-- users.credits, users.share_credits and share_commitments.credits. Every
-- other change moves them between those holders. A few things create or
-- destroy them: a company founded with credits, a company that ends with
-- some left, sales tax, a startup's skim over its cap, processor tax, the
-- bank (maturing, selling and buying shares, rebates, the bank's dividends),
-- push fees and accounts opened or deleted.
--
-- Each of those writes a row to credit_flows, in the writer's own
-- transaction: + for credits created, - for credits destroyed. Triggers
-- write the ones the engines and the website already record somewhere
-- (company_taxes, startup_skims, compute_bills, a company's insert and its
-- end, a user's insert and delete); the website writes the rest itself
-- (App\Services\CreditFlows). Once a game day the website's tick sums what
-- every holder has and the flows since the last check, in one consistent
-- snapshot (App\Services\CreditCheck), and records the difference in
-- credit_checks: the change in what is held less the flows. Anything else
-- is a bug that created or destroyed credits.
--
-- Only adds: two tables and seven triggers.

CREATE TABLE IF NOT EXISTS credit_flows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    game_t BIGINT NULL COMMENT 'Game time of the flow, when known',
    kind VARCHAR(32) NOT NULL COMMENT 'What created or destroyed the credits: sales_tax, company_founded, bank_paid...',
    company_id INT NULL,
    user_id INT NULL,
    amount BIGINT NOT NULL COMMENT 'Credits created (+) or destroyed (-)',
    check_id INT UNSIGNED NULL COMMENT 'The credit check that counted it; NULL: not counted yet',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY credit_flows_check (check_id)
) ENGINE = InnoDB COMMENT 'Every place credits are created or destroyed (#937)';

CREATE TABLE IF NOT EXISTS credit_checks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    game_t BIGINT NOT NULL COMMENT 'Game time of the check',
    day INT NOT NULL COMMENT 'The game day it checked (since the epoch)',
    held BIGINT NOT NULL COMMENT 'Credits every holder had, summed',
    `change` BIGINT NULL COMMENT 'held less the last check''s held; NULL: the first check (a baseline)',
    flows BIGINT NOT NULL DEFAULT 0 COMMENT 'Credits created less destroyed since the last check',
    difference BIGINT NULL COMMENT 'change less flows: 0 when credits balance',
    created BIGINT NOT NULL DEFAULT 0 COMMENT 'Credits created since the last check (the + flows)',
    destroyed BIGINT NOT NULL DEFAULT 0 COMMENT 'Credits destroyed since the last check (the - flows, as a positive number)',
    kinds JSON NULL COMMENT 'The flows by kind',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY credit_checks_day (day)
) ENGINE = InnoDB COMMENT 'Once a game day: did the change in credits held match the flows? (#937)';

DROP TRIGGER IF EXISTS credit_flow_company_founded;
DROP TRIGGER IF EXISTS credit_flow_company_ended;
DROP TRIGGER IF EXISTS credit_flow_sales_tax;
DROP TRIGGER IF EXISTS credit_flow_startup_skim;
DROP TRIGGER IF EXISTS credit_flow_processor_tax;
DROP TRIGGER IF EXISTS credit_flow_account_opened;
DROP TRIGGER IF EXISTS credit_flow_account_deleted;

DELIMITER //

-- A company founded with credits: a startup's grant, a bank company, the House
CREATE TRIGGER credit_flow_company_founded AFTER INSERT ON companies FOR EACH ROW
BEGIN
    IF NEW.credits <> 0 THEN
        INSERT INTO credit_flows (game_t, kind, company_id, amount)
            VALUES ((SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1),
                    'company_founded', NEW.company_id, NEW.credits);
    END IF;
END //

-- A company that ends with credits loses them: closed, dissolved, its last
-- drone gone, wound up (after paying its holders)
CREATE TRIGGER credit_flow_company_ended AFTER UPDATE ON companies FOR EACH ROW
BEGIN
    IF OLD.eliminated_at IS NULL AND NEW.eliminated_at IS NOT NULL AND NEW.credits <> OLD.credits THEN
        INSERT INTO credit_flows (game_t, kind, company_id, amount)
            VALUES (NEW.eliminated_at, CONCAT('ended_', IFNULL(NEW.end_cause, 'other')), NEW.company_id,
                    NEW.credits - OLD.credits);
    END IF;
END //

-- Sales tax: the seller pays it, and nobody receives it
CREATE TRIGGER credit_flow_sales_tax AFTER INSERT ON company_taxes FOR EACH ROW
BEGIN
    IF NEW.amount <> 0 THEN
        INSERT INTO credit_flows (game_t, kind, company_id, amount)
            VALUES (NEW.charged_t, 'sales_tax', NEW.company_id, -NEW.amount);
    END IF;
END //

-- A startup's credits over its cap go to the bank (#672)
CREATE TRIGGER credit_flow_startup_skim AFTER INSERT ON startup_skims FOR EACH ROW
BEGIN
    IF NEW.amount <> 0 THEN
        INSERT INTO credit_flows (game_t, kind, company_id, amount)
            VALUES (NEW.game_t, 'startup_skim', NEW.company_id, -NEW.amount);
    END IF;
END //

-- Processor tax paid (#544)
CREATE TRIGGER credit_flow_processor_tax AFTER INSERT ON compute_bills FOR EACH ROW
BEGIN
    IF NEW.paid <> 0 THEN
        INSERT INTO credit_flows (game_t, kind, company_id, amount)
            VALUES (NEW.charged_t, 'processor_tax', NEW.company_id, -NEW.paid);
    END IF;
END //

-- An account opened with credits (a class group's pupils)
CREATE TRIGGER credit_flow_account_opened AFTER INSERT ON users FOR EACH ROW
BEGIN
    IF IFNULL(NEW.credits, 0) + IFNULL(NEW.share_credits, 0) <> 0 THEN
        INSERT INTO credit_flows (game_t, kind, user_id, amount)
            VALUES ((SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1),
                    'account_opened', NEW.user_id, IFNULL(NEW.credits, 0) + IFNULL(NEW.share_credits, 0));
    END IF;
END //

-- An account deleted with credits
CREATE TRIGGER credit_flow_account_deleted AFTER DELETE ON users FOR EACH ROW
BEGIN
    IF IFNULL(OLD.credits, 0) + IFNULL(OLD.share_credits, 0) <> 0 THEN
        INSERT INTO credit_flows (game_t, kind, user_id, amount)
            VALUES ((SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1),
                    'account_deleted', OLD.user_id, -(IFNULL(OLD.credits, 0) + IFNULL(OLD.share_credits, 0)));
    END IF;
END //

DELIMITER ;
