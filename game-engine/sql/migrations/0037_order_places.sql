-- Each order carries its drone's place (#462, part of #428). A local order
-- book (queries 600, 601, 610, 611, 612) read the open orders for the
-- resource, then looked up every seller's place in agents. With three game
-- engines on a 3,000-ship world, MySQL used three of four cores and these
-- books were a third of its work (~3 ms a call, ~330 calls a second).
--
-- latest_orders.place is the drone's agents.object_id: set when an order is
-- recorded, and kept by a trigger when the drone's place changes (a move
-- settling). A drone in transit keeps the place it left until it arrives,
-- as agents does; the books still leave out drones with a move to come.
-- An index (resource, event_type, is_open, place, price) lets a local book
-- read only the open orders here, in price order.

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'latest_orders' AND column_name = 'place');
SET @sql := IF(@have = 0,
    'ALTER TABLE latest_orders
        ADD COLUMN place INT NULL,
        ADD INDEX latest_orders_here (resource, event_type, is_open, place, price)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS keep_order;
DROP PROCEDURE IF EXISTS refresh_order;
DROP TRIGGER IF EXISTS agents_order_places;

DELIMITER //

-- As 0034, with the place
CREATE PROCEDURE keep_order(e INT UNSIGNED, d INT UNSIGNED, r INT UNSIGNED, t INT UNSIGNED, p INT, q INT)
BEGIN
    INSERT INTO latest_orders (drone, resource, event_type, event_id, price, quantity, units, place)
        VALUES (d, r, t, e, p, q, holding_units(d, r), (SELECT object_id FROM agents WHERE agent_id = d))
    ON DUPLICATE KEY UPDATE
        price = IF(VALUES(event_id) >= event_id, VALUES(price), price),
        quantity = IF(VALUES(event_id) >= event_id, VALUES(quantity), quantity),
        units = VALUES(units),
        place = VALUES(place),
        event_id = GREATEST(event_id, VALUES(event_id));
END //

-- As 0034, with the place
CREATE PROCEDURE refresh_order(d INT UNSIGNED, r INT UNSIGNED, t INT UNSIGNED)
BEGIN
    DECLARE latest INT UNSIGNED;
    SET latest = IFNULL((SELECT MAX(id) FROM events WHERE event_type = t AND outcome = 1 AND resource = r
                         AND drone = d), 0);
    IF latest = 0 THEN
        DELETE FROM latest_orders WHERE drone = d AND resource = r AND event_type = t;
    ELSE
        INSERT INTO latest_orders (drone, resource, event_type, event_id, price, quantity, units, place)
            SELECT d, r, t, id, new_credits, new_quantity, holding_units(d, r),
                   (SELECT object_id FROM agents WHERE agent_id = d) FROM events WHERE id = latest
        ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), price = VALUES(price), quantity = VALUES(quantity),
            units = VALUES(units), place = VALUES(place);
    END IF;
END //

-- A drone's orders go where it goes
CREATE TRIGGER agents_order_places AFTER UPDATE ON agents FOR EACH ROW
BEGIN
    IF NOT (OLD.object_id <=> NEW.object_id) THEN
        UPDATE latest_orders SET place = NEW.object_id WHERE drone = NEW.agent_id;
    END IF;
END //

DELIMITER ;

-- The places so far
UPDATE latest_orders o JOIN agents a ON a.agent_id = o.drone SET o.place = a.object_id;
