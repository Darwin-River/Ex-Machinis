-- Current state beside the history (#430, part of #428): what each drone
-- holds and each drone's latest buy and sell order for each resource, kept
-- up to date by triggers on events. The engine, the House market and the
-- game-data queries asked these of the events history on every look: an
-- order book walked every seller's events, and round 41's clock fell to a
-- tenth of 1,800x by game day 65 (#422). Now each look is one row.
--
-- events stays the record of what happened. The triggers recompute an
-- affected row by the same rule the queries used (the newest successful
-- event of its kind, by id), so these tables always say what the history
-- says, whichever program wrote the event and in whatever order:
-- - holdings: per drone, resource, installed and locked, the units of its
--   newest successful increment (1) or depletion (2), as sqInventory and
--   holding() read them (an event with no installed or locked flag is kept
--   under -1: sqInventory counted it, holding() reads 0 and 1 only);
-- - latest_orders: per drone, resource and side (4 sell, 5 buy), its newest
--   successful order event: price (new_credits) and quantity (new_quantity).

CREATE TABLE IF NOT EXISTS holdings (
    drone INT UNSIGNED NOT NULL,
    resource INT UNSIGNED NOT NULL,
    installed TINYINT NOT NULL,
    locked TINYINT NOT NULL,
    units INT NOT NULL,
    event_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (drone, resource, installed, locked),
    INDEX holdings_resource (resource, drone),
    -- A deleted drone's events go by cascade, which fires no trigger
    CONSTRAINT holdings_drone FOREIGN KEY (drone) REFERENCES agents (agent_id) ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS latest_orders (
    drone INT UNSIGNED NOT NULL,
    resource INT UNSIGNED NOT NULL,
    event_type INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NOT NULL,
    price INT NULL,
    quantity INT NULL,
    PRIMARY KEY (drone, resource, event_type),
    INDEX latest_orders_book (resource, event_type, drone),
    CONSTRAINT latest_orders_drone FOREIGN KEY (drone) REFERENCES agents (agent_id) ON DELETE CASCADE
) ENGINE = InnoDB;

DROP FUNCTION IF EXISTS is_holding;
DROP FUNCTION IF EXISTS is_order;
DROP PROCEDURE IF EXISTS refresh_holding;
DROP PROCEDURE IF EXISTS refresh_order;
DROP TRIGGER IF EXISTS events_state_insert;
DROP TRIGGER IF EXISTS events_state_update;
DROP TRIGGER IF EXISTS events_state_delete;

DELIMITER //

-- Whether an event row can be a holding or an order (drone and resource set)
CREATE FUNCTION is_holding(o INT, t INT UNSIGNED, d INT UNSIGNED, r INT UNSIGNED)
    RETURNS BOOLEAN DETERMINISTIC NO SQL
    RETURN o = 1 AND t IN (1, 2) AND d IS NOT NULL AND r IS NOT NULL //

CREATE FUNCTION is_order(o INT, t INT UNSIGNED, d INT UNSIGNED, r INT UNSIGNED)
    RETURNS BOOLEAN DETERMINISTIC NO SQL
    RETURN o = 1 AND t IN (4, 5) AND d IS NOT NULL AND r IS NOT NULL //

-- A holding from its newest increment or depletion: each one step down
-- events_inventory (drone, resource, installed, locked, outcome, event_type)
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
END //

-- An order from its newest successful order event (events_orders:
-- event_type, outcome, resource, drone)
CREATE PROCEDURE refresh_order(d INT UNSIGNED, r INT UNSIGNED, t INT UNSIGNED)
BEGIN
    DECLARE latest INT UNSIGNED;
    SET latest = IFNULL((SELECT MAX(id) FROM events WHERE event_type = t AND outcome = 1 AND resource = r
                         AND drone = d), 0);
    IF latest = 0 THEN
        DELETE FROM latest_orders WHERE drone = d AND resource = r AND event_type = t;
    ELSE
        INSERT INTO latest_orders (drone, resource, event_type, event_id, price, quantity)
            SELECT d, r, t, id, new_credits, new_quantity FROM events WHERE id = latest
        ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), price = VALUES(price), quantity = VALUES(quantity);
    END IF;
END //

CREATE TRIGGER events_state_insert AFTER INSERT ON events FOR EACH ROW
BEGIN
    IF is_holding(NEW.outcome, NEW.event_type, NEW.drone, NEW.resource) THEN
        CALL refresh_holding(NEW.drone, NEW.resource, NEW.installed, NEW.locked);
    ELSEIF is_order(NEW.outcome, NEW.event_type, NEW.drone, NEW.resource) THEN
        CALL refresh_order(NEW.drone, NEW.resource, NEW.event_type);
    END IF;
END //

-- Most updates settle a pending event (outcome 0 to its result); others set
-- its place, or zero a wreck's holdings. Only those touching what these
-- tables record refresh them.
CREATE TRIGGER events_state_update AFTER UPDATE ON events FOR EACH ROW
BEGIN
    DECLARE was_h, now_h, was_o, now_o BOOLEAN;
    SET was_h = is_holding(OLD.outcome, OLD.event_type, OLD.drone, OLD.resource);
    SET now_h = is_holding(NEW.outcome, NEW.event_type, NEW.drone, NEW.resource);
    SET was_o = is_order(OLD.outcome, OLD.event_type, OLD.drone, OLD.resource);
    SET now_o = is_order(NEW.outcome, NEW.event_type, NEW.drone, NEW.resource);
    -- Nothing these tables record changed (most often: the place was set)
    IF (was_h OR now_h OR was_o OR now_o) AND NOT (OLD.outcome <=> NEW.outcome AND OLD.event_type <=> NEW.event_type
            AND OLD.drone <=> NEW.drone AND OLD.resource <=> NEW.resource AND OLD.installed <=> NEW.installed
            AND OLD.locked <=> NEW.locked AND OLD.new_quantity <=> NEW.new_quantity
            AND OLD.new_credits <=> NEW.new_credits) THEN
        IF now_h THEN
            CALL refresh_holding(NEW.drone, NEW.resource, NEW.installed, NEW.locked);
        END IF;
        IF was_h AND NOT (now_h AND OLD.drone <=> NEW.drone AND OLD.resource <=> NEW.resource
                          AND OLD.installed <=> NEW.installed AND OLD.locked <=> NEW.locked) THEN
            CALL refresh_holding(OLD.drone, OLD.resource, OLD.installed, OLD.locked);
        END IF;
        IF now_o THEN
            CALL refresh_order(NEW.drone, NEW.resource, NEW.event_type);
        END IF;
        IF was_o AND NOT (now_o AND OLD.drone <=> NEW.drone AND OLD.resource <=> NEW.resource
                          AND OLD.event_type <=> NEW.event_type) THEN
            CALL refresh_order(OLD.drone, OLD.resource, OLD.event_type);
        END IF;
    END IF;
END //

CREATE TRIGGER events_state_delete AFTER DELETE ON events FOR EACH ROW
BEGIN
    IF is_holding(OLD.outcome, OLD.event_type, OLD.drone, OLD.resource) THEN
        CALL refresh_holding(OLD.drone, OLD.resource, OLD.installed, OLD.locked);
    ELSEIF is_order(OLD.outcome, OLD.event_type, OLD.drone, OLD.resource) THEN
        CALL refresh_order(OLD.drone, OLD.resource, OLD.event_type);
    END IF;
END //

DELIMITER ;

-- The state so far, from the history
DELETE FROM holdings;
INSERT INTO holdings (drone, resource, installed, locked, units, event_id)
    SELECT e.drone, e.resource, IFNULL(e.installed, -1), IFNULL(e.locked, -1), IFNULL(e.new_quantity, 0), e.id
    FROM events e INNER JOIN (SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type IN (1, 2)
                              AND drone IS NOT NULL AND resource IS NOT NULL
                              GROUP BY drone, resource, installed, locked) latest ON latest.id = e.id;
DELETE FROM latest_orders;
INSERT INTO latest_orders (drone, resource, event_type, event_id, price, quantity)
    SELECT e.drone, e.resource, e.event_type, e.id, e.new_credits, e.new_quantity
    FROM events e INNER JOIN (SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type IN (4, 5)
                              AND drone IS NOT NULL AND resource IS NOT NULL GROUP BY drone, resource, event_type) latest ON latest.id = e.id;

-- sqInventory (the engine, the website and the leaderboard) reads it too.
-- Same columns as before; game_data.sql defines it the same way.
CREATE OR REPLACE VIEW sqInventory AS
    SELECT drone, resource, CAST(SUM(units) AS SIGNED) AS units FROM holdings GROUP BY drone, resource;
