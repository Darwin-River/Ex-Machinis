-- Taxes on what a company made (#367). The tax was a share of a company's
-- whole assessed wealth, which counts the starter kit's equipment (about
-- 3,000 credits at House prices): a new company paid about 20 credits a game
-- day, more than most earn. Now it is a share of its net wealth, as the
-- leaderboard reckons it: assessed wealth less the starter package (starting
-- credits, starting fuel at the House price and a packed Starter Kit I),
-- never below 0. The package numbers are game data (house_rules) and must
-- match config/forge.php.
ALTER TABLE house_rules
    ADD COLUMN start_credits INT NOT NULL DEFAULT 1000 COMMENT 'A released company''s starting credits (forge.starting_credits)',
    ADD COLUMN start_fuel INT NOT NULL DEFAULT 100 COMMENT 'Fuel in a released drone''s hold (forge.starting_fuel)';

-- What a company is taxed on: its assessed wealth less its starter package
CREATE OR REPLACE VIEW company_taxable AS
SELECT w.company_id,
       GREATEST(0, w.assessed - h.start_credits
                   - h.start_fuel * IFNULL((SELECT p.price FROM house_prices p WHERE p.resource = 2061), 0)
                   - IFNULL((SELECT p.price FROM house_prices p WHERE p.resource = 5901), 0)) AS taxable
FROM company_assessed w JOIN house_rules h ON h.id = 1;
