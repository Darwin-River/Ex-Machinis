-- Taxes on assessed wealth (#367). A company's tax is a share of what it is
-- worth: its credits plus the goods aboard its working drones at the House
-- price, as the leaderboard values them (App\Services\Leaderboard). These
-- views give the engines and the website the same numbers.

-- The House price of a good: the least a House station pays for it, or if
-- none buys it, the least one sells it for
CREATE OR REPLACE VIEW house_goods_prices AS
SELECT resource,
       COALESCE(MIN(CASE WHEN side = 1 THEN price END), MIN(CASE WHEN side = 2 THEN price END)) AS price
FROM station_goods GROUP BY resource;

-- ...and a packed Starter Kit I (5901) is worth its contents (what unpacking
-- it, protocol 25901, adds)
CREATE OR REPLACE VIEW house_prices AS
SELECT resource, price FROM house_goods_prices
UNION ALL
SELECT 5901, IFNULL(SUM(p.price * GREATEST(1, e.quantity)), 0)
FROM resource_effects e JOIN house_goods_prices p ON p.resource = e.resource
WHERE e.protocol = 25901 AND e.event_type = 1;

-- A company's assessed wealth: credits + goods aboard its working drones
CREATE OR REPLACE VIEW company_assessed AS
SELECT c.company_id,
       c.credits + IFNULL((SELECT SUM(i.units * p.price) FROM sqInventory i
                           JOIN agents a ON a.agent_id = i.drone
                           JOIN house_prices p ON p.resource = i.resource
                           WHERE a.company_id = c.company_id AND a.wrecked_at IS NULL AND i.units > 0), 0) AS assessed
FROM companies c;
