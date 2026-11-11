-- Wrecks, plunder and company end (#324 step 12).
-- A wreck (a drone that ran out of fuel, crashed too often, or whose
-- company was eliminated) can be plundered once by another player's drone
-- at the same place. Companies pay a tax every game day after a grace
-- period; one that can't pay is eliminated: its credits vanish and its
-- drones become wrecks. The numbers are game data (house_rules).
ALTER TABLE agents
    ADD COLUMN plundered_at BIGINT NULL COMMENT 'Game time the wreck was plundered (once only)',
    ADD COLUMN plundered_by INT NULL COMMENT 'The drone that plundered it';

ALTER TABLE companies
    ADD COLUMN founded_t BIGINT NULL COMMENT 'Game time the tax office first saw it (its grace period starts)',
    ADD COLUMN taxed_t BIGINT NULL COMMENT 'Game time its tax period last started',
    ADD COLUMN eliminated_at BIGINT NULL COMMENT 'Game time it was eliminated for not paying its tax';

CREATE TABLE IF NOT EXISTS house_rules (
    id INT NOT NULL PRIMARY KEY,
    tax_period INT NOT NULL COMMENT 'Game seconds between tax bills',
    tax_grace INT NOT NULL COMMENT 'Game seconds a new company pays no tax',
    tax_flat INT NOT NULL COMMENT 'Credits each company pays per bill',
    tax_permille INT NOT NULL COMMENT 'Plus this many thousandths of the player''s combined credits',
    plunder_share INT NOT NULL COMMENT 'Percent of a wreck''s goods and parts a plunderer gets'
);

CREATE TABLE IF NOT EXISTS company_taxes (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    charged_t BIGINT NOT NULL COMMENT 'Game time of the bill',
    amount BIGINT NOT NULL COMMENT 'Credits charged (all it had if it was eliminated)',
    credits_after BIGINT NOT NULL,
    eliminated TINYINT NOT NULL DEFAULT 0,
    KEY company_taxes_company (company_id, charged_t)
);
