-- Thinking costs a company processor tax, not fuel (#544). Since PF step 8
-- (#340) a drone's thinking burned 1 fuel per 200,000 instructions, and a
-- drone whose tank ran dry was a wreck: rounds 55-57 lost drones to loops
-- that thought their tanks away. Now POWER_INSTRUCTIONS is 0 and, once a
-- game hour, the events engine bills each company for the instructions its
-- drones ran since the last bill (agents.instructions - agents.power_paid),
-- at house_rules.compute_tax_per_million credits a million. A company that
-- can't pay owes the rest (compute_debt), collected from the next bills;
-- while it owes, its drones run throttled (a tenth of a turn). Nothing is
-- wrecked by the tax. db_compute.c.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'compute_debt');
SET @sql := IF(@have = 0,
    'ALTER TABLE companies ADD COLUMN compute_debt BIGINT NOT NULL DEFAULT 0 COMMENT ''Processor tax owed (#544)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'compute_tax_per_million');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN compute_tax_per_million INT NOT NULL DEFAULT 15 COMMENT ''Processor tax: credits a million instructions (#544); 0 makes thinking free''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Each hourly bill
CREATE TABLE IF NOT EXISTS compute_bills (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    charged_t BIGINT NOT NULL COMMENT 'Game time of the bill',
    instructions BIGINT NOT NULL COMMENT 'Instructions billed (whole credits only; the rest carries over)',
    amount BIGINT NOT NULL COMMENT 'Credits this bill came to',
    paid BIGINT NOT NULL COMMENT 'Credits taken: this bill and any debt, up to what it had',
    debt_after BIGINT NOT NULL,
    KEY compute_bills_company (company_id, charged_t)
) ENGINE = InnoDB;

-- When the last bill ran (one row)
CREATE TABLE IF NOT EXISTS compute_clock (
    id TINYINT NOT NULL PRIMARY KEY,
    billed_t BIGINT NOT NULL DEFAULT 0
) ENGINE = InnoDB;
INSERT IGNORE INTO compute_clock (id, billed_t) VALUES (1, 0);
