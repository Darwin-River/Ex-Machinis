-- The current-state triggers without locking reads (follows 0031, #430).
-- 0031's triggers found the newest event by reading events. Inside an
-- insert or update, MySQL takes shared next-key locks on what such a read
-- touches, held until commit, including the gap at the end of the table
-- where every new event goes. So an events-engine transaction settling a
-- batch blocked the game engine's inserts. Measured on round 41's world:
-- inserts spent 127 of their 133 s waiting for locks, and were the top
-- cost.
--
-- A new or settled event carries its own id and values, so the trigger
-- keeps whichever row has the larger id, with no read of events. That is
-- the same rule, the newest successful event by id, whatever order
-- transactions commit in. Only an event that stops counting (a success
-- turned to a failure, its key changed, or deleted) still recomputes from
-- the history: rare, and the same as 0031.

DROP TRIGGER IF EXISTS events_state_insert;
DROP TRIGGER IF EXISTS events_state_update;
DROP PROCEDURE IF EXISTS keep_holding;
DROP PROCEDURE IF EXISTS keep_order;

DELIMITER //

-- Keeps event e as the holding if it is the newest of its kind
CREATE PROCEDURE keep_holding(e INT UNSIGNED, d INT UNSIGNED, r INT UNSIGNED, i TINYINT, l TINYINT, q INT)
BEGIN
    INSERT INTO holdings (drone, resource, installed, locked, units, event_id)
        VALUES (d, r, IFNULL(i, -1), IFNULL(l, -1), IFNULL(q, 0), e)
    ON DUPLICATE KEY UPDATE
        -- units first: it compares with the event_id this row had
        units = IF(VALUES(event_id) >= event_id, VALUES(units), units),
        event_id = GREATEST(event_id, VALUES(event_id));
END //

-- Keeps event e as the order if it is the newest of its kind
CREATE PROCEDURE keep_order(e INT UNSIGNED, d INT UNSIGNED, r INT UNSIGNED, t INT UNSIGNED, p INT, q INT)
BEGIN
    INSERT INTO latest_orders (drone, resource, event_type, event_id, price, quantity)
        VALUES (d, r, t, e, p, q)
    ON DUPLICATE KEY UPDATE
        price = IF(VALUES(event_id) >= event_id, VALUES(price), price),
        quantity = IF(VALUES(event_id) >= event_id, VALUES(quantity), quantity),
        event_id = GREATEST(event_id, VALUES(event_id));
END //

CREATE TRIGGER events_state_insert AFTER INSERT ON events FOR EACH ROW
BEGIN
    IF is_holding(NEW.outcome, NEW.event_type, NEW.drone, NEW.resource) THEN
        CALL keep_holding(NEW.id, NEW.drone, NEW.resource, NEW.installed, NEW.locked, NEW.new_quantity);
    ELSEIF is_order(NEW.outcome, NEW.event_type, NEW.drone, NEW.resource) THEN
        CALL keep_order(NEW.id, NEW.drone, NEW.resource, NEW.event_type, NEW.new_credits, NEW.new_quantity);
    END IF;
END //

-- Most updates settle a pending event (outcome 0 to its result); others set
-- its place, or zero a wreck's holdings. Only those touching what these
-- tables record change them.
CREATE TRIGGER events_state_update AFTER UPDATE ON events FOR EACH ROW
BEGIN
    DECLARE was_h, now_h, was_o, now_o, same_h, same_o BOOLEAN;
    SET was_h = is_holding(OLD.outcome, OLD.event_type, OLD.drone, OLD.resource);
    SET now_h = is_holding(NEW.outcome, NEW.event_type, NEW.drone, NEW.resource);
    SET was_o = is_order(OLD.outcome, OLD.event_type, OLD.drone, OLD.resource);
    SET now_o = is_order(NEW.outcome, NEW.event_type, NEW.drone, NEW.resource);
    SET same_h = OLD.drone <=> NEW.drone AND OLD.resource <=> NEW.resource
                 AND OLD.installed <=> NEW.installed AND OLD.locked <=> NEW.locked;
    SET same_o = OLD.drone <=> NEW.drone AND OLD.resource <=> NEW.resource AND OLD.event_type <=> NEW.event_type;
    -- Nothing these tables record changed (most often: the place was set)
    IF (was_h OR now_h OR was_o OR now_o) AND NOT (OLD.outcome <=> NEW.outcome AND OLD.event_type <=> NEW.event_type
            AND same_h AND OLD.new_quantity <=> NEW.new_quantity AND OLD.new_credits <=> NEW.new_credits) THEN
        IF now_h THEN
            CALL keep_holding(NEW.id, NEW.drone, NEW.resource, NEW.installed, NEW.locked, NEW.new_quantity);
        END IF;
        -- It counted before and no longer counts where it did: rare, recompute
        IF was_h AND NOT (now_h AND same_h) THEN
            CALL refresh_holding(OLD.drone, OLD.resource, OLD.installed, OLD.locked);
        END IF;
        IF now_o THEN
            CALL keep_order(NEW.id, NEW.drone, NEW.resource, NEW.event_type, NEW.new_credits, NEW.new_quantity);
        END IF;
        IF was_o AND NOT (now_o AND same_o) THEN
            CALL refresh_order(OLD.drone, OLD.resource, OLD.event_type);
        END IF;
    END IF;
END //

DELIMITER ;
