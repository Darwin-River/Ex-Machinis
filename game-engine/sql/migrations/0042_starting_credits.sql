-- Credits instead of a kit (#506): a released company starts with 3,000
-- credits (house_rules.start_credits, set by the game data, and
-- forge.starting_credits) and no kit, and buys its own equipment. Its
-- starter package for the tax on gains (0019) is now its credits and fuel:
-- the packed Starter Kit I's price is no longer subtracted. For a company
-- released before, 1,000 credits and a 2,000 kit come to the same 3,000.
CREATE OR REPLACE VIEW company_taxable AS
SELECT w.company_id,
       GREATEST(0, w.assessed - h.start_credits
                   - h.start_fuel * IFNULL((SELECT p.price FROM house_prices p WHERE p.resource = 2061), 0)) AS taxable
FROM company_assessed w JOIN house_rules h ON h.id = 1;
