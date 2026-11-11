-- Companies and players (#324 step 4). A player (users row: the account
-- that logs in) can own many companies; each company has its own bank
-- account (credits) and drones. Until now the users row was the company.
--
-- Existing users each become one company with the same ID, name and credits.

CREATE TABLE IF NOT EXISTS companies (
    company_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'The player (users.user_id) who launched the company',
    name VARCHAR(255) NOT NULL,
    credits INT NOT NULL DEFAULT 0 COMMENT 'The company bank account',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX companies_user_idx (user_id)
);

INSERT INTO companies (company_id, user_id, name, credits, created_at)
SELECT u.user_id, u.user_id, u.name, IFNULL(u.credits, 0), u.created_at
FROM users u
WHERE NOT EXISTS (SELECT 1 FROM companies c WHERE c.company_id = u.user_id);

-- Each drone belongs to a company (agents.user_id stays its player)
ALTER TABLE agents ADD COLUMN company_id INT UNSIGNED NULL AFTER user_id,
    ADD INDEX agents_company_idx (company_id);
UPDATE agents SET company_id = user_id WHERE company_id IS NULL;
