-- Open orders indexed (#452, part of #428). An order book (queries 600, 601,
-- 610, 611, 612) read every order for the resource, then each seller's
-- place and stock, and kept only the open ones: a sell order while stock is
-- above the kept minimum, a buy order while it is below the wanted maximum.
-- On a 3,000-ship world almost every drone lists a sell order and almost
-- none is open: 4,382 rows read a call to return about none, 126 s of every
-- 5 minutes, and the clock fell to 427x.
--
-- latest_orders now carries the drone's stock of the resource (units: the
-- same sum the queries read from holdings, installed and locked 0 or 1) and
-- is_open from it. The triggers' procedures keep units current whenever a
-- holding or an order changes, so an order book reads only the open orders,
-- in price order, from one index.

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'latest_orders' AND column_name = 'units');
SET @sql := IF(@have = 0,
    'ALTER TABLE latest_orders
        ADD COLUMN units INT NOT NULL DEFAULT 0,
        ADD COLUMN is_open TINYINT AS (IFNULL(IF(event_type = 4, units > quantity, units < quantity), 0)) STORED,
        ADD INDEX latest_orders_open (resource, event_type, is_open, price)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP FUNCTION IF EXISTS holding_units;
DROP PROCEDURE IF EXISTS order_units;
DROP PROCEDURE IF EXISTS keep_holding;
DROP PROCEDURE IF EXISTS keep_order;
DROP PROCEDURE IF EXISTS refresh_holding;
DROP PROCEDURE IF EXISTS refresh_order;

DELIMITER //

-- What a drone holds of a resource, as the order books count it
CREATE FUNCTION holding_units(d INT UNSIGNED, r INT UNSIGNED)
    RETURNS INT READS SQL DATA
    RETURN (SELECT IFNULL(SUM(units), 0) FROM holdings
            WHERE drone = d AND resource = r AND installed IN (0, 1) AND locked IN (0, 1)) //

-- A drone's orders for a resource take its stock now
CREATE PROCEDURE order_units(d INT UNSIGNED, r INT UNSIGNED)
BEGIN
    UPDATE latest_orders SET units = holding_units(d, r) WHERE drone = d AND resource = r;
END //

-- As 0032, then the orders' stock
CREATE PROCEDURE keep_holding(e INT UNSIGNED, d INT UNSIGNED, r INT UNSIGNED, i TINYINT, l TINYINT, q INT)
BEGIN
    INSERT INTO holdings (drone, resource, installed, locked, units, event_id)
        VALUES (d, r, IFNULL(i, -1), IFNULL(l, -1), IFNULL(q, 0), e)
    ON DUPLICATE KEY UPDATE
        units = IF(VALUES(event_id) >= event_id, VALUES(units), units),
        event_id = GREATEST(event_id, VALUES(event_id));
    CALL order_units(d, r);
END //

-- As 0032, with the stock
CREATE PROCEDURE keep_order(e INT UNSIGNED, d INT UNSIGNED, r INT UNSIGNED, t INT UNSIGNED, p INT, q INT)
BEGIN
    INSERT INTO latest_orders (drone, resource, event_type, event_id, price, quantity, units)
        VALUES (d, r, t, e, p, q, holding_units(d, r))
    ON DUPLICATE KEY UPDATE
        price = IF(VALUES(event_id) >= event_id, VALUES(price), price),
        quantity = IF(VALUES(event_id) >= event_id, VALUES(quantity), quantity),
        units = VALUES(units),
        event_id = GREATEST(event_id, VALUES(event_id));
END //

-- As 0031, then the orders' stock
CREATE PROCEDURE refresh_holding(d INT UNSIGNED, r INT UNSIGNED, i TINYINT, l TINYINT)
BEGIN
    DECLARE latest INT UNSIGNED;
    SET latest = GREATEST(
        IFNULL((SELECT MAX(id) FROM events WHERE drone = d AND resource = r AND installed <=> i AND locked <=> l
                AND outcome = 1 AND event_type = 1), 0),
        IFNULL((SELECT MAX(id) FROM events WHERE drone = d AND resource = r AND installed <=> i AND locked <=> l
                AND outcome = 1 AND event_type = 2), 0));
    IF latest = 0 THEN
        DELETE FROM holdings WHERE drone = d AND resource = r AND installed = IFNULL(i, -1) AND locked = IFNULL(l, -1);
    ELSE
        INSERT INTO holdings (drone, resource, installed, locked, units, event_id)
            SELECT d, r, IFNULL(i, -1), IFNULL(l, -1), IFNULL(new_quantity, 0), id FROM events WHERE id = latest
        ON DUPLICATE KEY UPDATE units = VALUES(units), event_id = VALUES(event_id);
    END IF;
    CALL order_units(d, r);
END //

-- As 0031, with the stock
CREATE PROCEDURE refresh_order(d INT UNSIGNED, r INT UNSIGNED, t INT UNSIGNED)
BEGIN
    DECLARE latest INT UNSIGNED;
    SET latest = IFNULL((SELECT MAX(id) FROM events WHERE event_type = t AND outcome = 1 AND resource = r
                         AND drone = d), 0);
    IF latest = 0 THEN
        DELETE FROM latest_orders WHERE drone = d AND resource = r AND event_type = t;
    ELSE
        INSERT INTO latest_orders (drone, resource, event_type, event_id, price, quantity, units)
            SELECT d, r, t, id, new_credits, new_quantity, holding_units(d, r) FROM events WHERE id = latest
        ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), price = VALUES(price), quantity = VALUES(quantity),
            units = VALUES(units);
    END IF;
END //

DELIMITER ;

-- The stock so far
UPDATE latest_orders o SET o.units = holding_units(o.drone, o.resource);
