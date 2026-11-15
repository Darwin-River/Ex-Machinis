-- A seedling's credits are capped at twice its debt (#672): round 68's
-- seedlings sat for 190 game days piling up 12,000 to 48,000 credits, and
-- maturing turned all of it into the founder's shares at once. When a sale
-- would take a seedling over its cap, the excess goes to the bank as a fee
-- (seedling_skims), so there is no point delaying maturing.
-- house_rules.seedling_credit_cap_permille: the cap in thousandths of the
-- debt (2000: twice it; 0: no cap).
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'seedling_credit_cap_permille');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN seedling_credit_cap_permille INT NOT NULL DEFAULT 2000 COMMENT ''Most credits a seedling may hold, in thousandths of its debt; above it they go to the bank (#672)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS seedling_skims (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    game_t BIGINT NOT NULL,
    amount BIGINT NOT NULL,
    KEY seedling_skims_company (company_id, game_t)
);

-- Seedlings already over their cap pay the excess now (the migration runs
-- before the engines start); later sales are capped by the events engine
INSERT INTO seedling_skims (company_id, game_t, amount)
SELECT c.company_id, (SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1),
       c.credits - c.debt * 2
FROM companies c
WHERE c.seedling = 1 AND c.eliminated_at IS NULL AND c.debt > 0 AND c.credits > c.debt * 2;
UPDATE companies SET credits = debt * 2
WHERE seedling = 1 AND eliminated_at IS NULL AND debt > 0 AND credits > debt * 2;
