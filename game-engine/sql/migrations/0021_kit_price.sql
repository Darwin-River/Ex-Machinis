-- A packed Starter Kit I is worth its contents (#367), and since #380 the
-- House also sells it. Without this, house_prices would list it twice (its
-- sale price and its contents) and assessments would count kits twice.
CREATE OR REPLACE VIEW house_prices AS
SELECT resource, price FROM house_goods_prices WHERE resource <> 5901
UNION ALL
SELECT 5901, IFNULL(SUM(p.price * GREATEST(1, e.quantity)), 0)
FROM resource_effects e JOIN house_goods_prices p ON p.resource = e.resource
WHERE e.protocol = 25901 AND e.event_type = 1;
