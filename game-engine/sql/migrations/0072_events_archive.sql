-- Old settled events move out of events into events_archive (#694 stage 3).
-- A load world slowed from 6,780 to 3,804 turns a minute as events grew
-- from 316,000 to 675,000 rows in 25 minutes, with no code change, and
-- live has weeks of history. The engines read only recent and pending
-- events; the website reads both tables where it shows old history (a
-- drone's timeline and post-mortem, the events page, a hull's age).
--
-- The events engine calls archive_events (db_e.c, db_archive_due: a pass
-- at most once a real minute), a window of ids at a time. An event moves when it is
-- settled (outcome not 0), older than house_rules.events_archive_days game
-- days (and than the oldest event still to settle), its action has nothing
-- left to settle, and nothing the engines look up can need it. For a
-- success, that means a newer success of the same kind at or before the
-- cutoff for each lookup it could answer:
--   * the drone's latest event (credits: PREVIOUS_EVENT_BY_OWNER);
--   * an inventory event (types 1, 2): the latest of its drone, resource,
--     installed, locked (PREVIOUS_EVENT_BY_RESOURCE_INFO, holdings, wrecks,
--     query 615's holdings an hour ago);
--   * an order (types 4, 5): the latest of its drone, resource and type
--     (PREVIOUS_EVENT_BY_SELL_ORDER / BUY_ORDER, latest_orders, the order
--     lock).
-- So at any game time from the cutoff on, the latest event of every kind is
-- still in events: an inventory never loses its base.

-- 0: never archive
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'events_archive_days');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN events_archive_days INT NOT NULL DEFAULT 30 COMMENT ''Game days a settled event stays in events before it moves to events_archive (0: never) (#694)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The same columns as events, in the same order (archive_events copies
-- rows with SELECT *: a column added to events must be added here too).
-- No foreign key (a check would lock the drone's agents row while the game
-- engine updates it) and no auto-increment (ids come from events)
CREATE TABLE IF NOT EXISTS events_archive LIKE events;

-- Only the indexes the history readers use: by action (a drone's
-- timeline), by drone and time (a hull's age), by place and time (local
-- sightings on the events page)
SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'events_archive' AND index_name = 'archive_drone_game_t');
SET @drops := (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', index_name, '`') SEPARATOR ', ')
               FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'events_archive' AND index_name <> 'PRIMARY');
SET @sql := IF(@have = 0,
    CONCAT('ALTER TABLE events_archive ', IFNULL(CONCAT(@drops, ', '), ''),
           'MODIFY id INT UNSIGNED NOT NULL, ',
           'ADD INDEX archive_action (action), ',
           'ADD INDEX archive_drone_game_t (drone, game_t), ',
           'ADD INDEX archive_place_game_t (place, game_t)'),
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- An event's observations (who saw it, and when: the events page, queries
-- 803 and 804) stay when it moves: the cascade from events goes. Nothing
-- else deletes a settled event (the delete of month-old events of a kind,
-- #446, is gone: they move instead), and a pending event has no
-- observations yet
SET @have := (SELECT COUNT(*) FROM information_schema.referential_constraints
              WHERE constraint_schema = DATABASE() AND table_name = 'observations' AND referenced_table_name = 'events');
SET @fk := (SELECT constraint_name FROM information_schema.referential_constraints
            WHERE constraint_schema = DATABASE() AND table_name = 'observations' AND referenced_table_name = 'events' LIMIT 1);
SET @sql := IF(@have > 0, CONCAT('ALTER TABLE observations DROP FOREIGN KEY `', @fk, '`'), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS archive_events;
DROP TRIGGER IF EXISTS events_state_delete;

DELIMITER //

-- As 0031, except while archive_events moves rows: what it moves is never
-- the newest of its kind (by id), so holdings and latest_orders stay as
-- they are, and the recompute (two index reads and an upsert a row, and a
-- lock on the holding the events engine is settling) is skipped. (Compared
-- with 1, not tested for NULL: through PHP's prepared statements a variable
-- never set wasn't NULL here, and every delete skipped the recompute)
CREATE TRIGGER events_state_delete AFTER DELETE ON events FOR EACH ROW
BEGIN
    IF IFNULL(@events_archiving, 0) <> 1 THEN
        IF is_holding(OLD.outcome, OLD.event_type, OLD.drone, OLD.resource) THEN
            CALL refresh_holding(OLD.drone, OLD.resource, OLD.installed, OLD.locked);
        ELSEIF is_order(OLD.outcome, OLD.event_type, OLD.drone, OLD.resource) THEN
            CALL refresh_order(OLD.drone, OLD.resource, OLD.event_type);
        END IF;
    END IF;
END //

-- Moves the archivable events with ids from from_id up to from_id + span
-- (not past the first event younger than the cutoff) from events to
-- events_archive. moved: how many; next_id: where the next window starts,
-- 0 when this one reached the young events (start again from the oldest).
-- The caller runs it in its own transaction, under READ COMMITTED so the
-- reads of events take no locks (the game engine inserts at the end of the
-- table all the time).
CREATE PROCEDURE archive_events(IN from_id INT UNSIGNED, IN span INT UNSIGNED, IN age_s BIGINT,
                                OUT moved INT, OUT next_id INT UNSIGNED)
proc: BEGIN
    DECLARE now_t, cutoff, oldest BIGINT;
    DECLARE ceiling, hi INT UNSIGNED;
    DECLARE k_drone, k_resource INT UNSIGNED;
    DECLARE k_installed, k_locked TINYINT;
    DECLARE done INT DEFAULT 0;
    DECLARE drones CURSOR FOR
        SELECT DISTINCT drone FROM events WHERE id >= from_id AND id < hi AND outcome = 1 AND game_t < cutoff
        AND drone IS NOT NULL;
    DECLARE kinds CURSOR FOR
        SELECT DISTINCT drone, resource, installed, locked FROM events WHERE id >= from_id AND id < hi
        AND outcome = 1 AND game_t < cutoff AND event_type IN (1, 2)
        AND drone IS NOT NULL AND resource IS NOT NULL AND installed IS NOT NULL AND locked IS NOT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET @events_archiving = 0;
        RESIGNAL;
    END;

    SET moved = 0;
    SET next_id = 0;
    IF age_s IS NULL OR age_s <= 0 THEN
        LEAVE proc;
    END IF;
    SET now_t = (SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1);
    SET now_t = IFNULL(now_t, UNIX_TIMESTAMP());
    SET cutoff = now_t - age_s;
    -- Never past the oldest event the events engine still has to settle (as
    -- db_get_outcome_events picks them): if it has fallen behind, what it
    -- settles next must find the events before it
    SET oldest = (SELECT e.game_t FROM events e FORCE INDEX (events_pending) WHERE e.outcome = 0
                  AND (SELECT aborted FROM actions WHERE id = e.action) != 1 ORDER BY e.game_t LIMIT 1);
    IF oldest IS NOT NULL AND oldest < cutoff THEN
        SET cutoff = oldest;
    END IF;

    -- Ids follow game time closely (an action's events are written when it
    -- starts), so stop about where the cutoff is: past both the last event
    -- before it and the first at it (either alone can be an odd one out, an
    -- arrival written days before it happens). Too far only reads a few
    -- young rows; too short leaves rows for a later pass
    SET ceiling = GREATEST(
        IFNULL((SELECT id + 1 FROM events FORCE INDEX (events_game_t_idx) WHERE game_t < cutoff
                ORDER BY game_t DESC LIMIT 1), 0),
        IFNULL((SELECT id FROM events FORCE INDEX (events_game_t_idx) WHERE game_t >= cutoff
                ORDER BY game_t LIMIT 1), 0));
    SET from_id = GREATEST(IFNULL(from_id, 0), (SELECT IFNULL(MIN(id), 0) FROM events));
    IF from_id >= ceiling THEN
        LEAVE proc;
    END IF;
    SET hi = LEAST(from_id + span, ceiling);

    -- The latest success of each drone, and of each inventory kind, in the
    -- window, at the cutoff (by game time, then id): one step back down an
    -- index each. (Asked once a row inside the INSERT, MySQL read the
    -- drone's whole history each time and sorted it: 5 to 10 s a window on
    -- a load world's backlog)
    DROP TEMPORARY TABLE IF EXISTS archive_keep_drone, archive_keep_kind;
    CREATE TEMPORARY TABLE archive_keep_drone (drone INT UNSIGNED PRIMARY KEY, id INT UNSIGNED);
    CREATE TEMPORARY TABLE archive_keep_kind (drone INT UNSIGNED, resource INT UNSIGNED, installed TINYINT,
        locked TINYINT, id INT UNSIGNED, PRIMARY KEY (drone, resource, installed, locked));
    SET done = 0;
    OPEN drones;
    each_drone: LOOP
        FETCH drones INTO k_drone;
        IF done THEN LEAVE each_drone; END IF;
        INSERT INTO archive_keep_drone VALUES (k_drone,
            (SELECT n.id FROM events n FORCE INDEX (events_drone_game_t)
             WHERE n.drone = k_drone AND n.outcome = 1 AND n.game_t <= cutoff
             ORDER BY n.game_t DESC, n.id DESC LIMIT 1));
    END LOOP;
    CLOSE drones;
    SET done = 0;
    OPEN kinds;
    each_kind: LOOP
        FETCH kinds INTO k_drone, k_resource, k_installed, k_locked;
        IF done THEN LEAVE each_kind; END IF;
        INSERT INTO archive_keep_kind VALUES (k_drone, k_resource, k_installed, k_locked,
            (SELECT n.id FROM events n FORCE INDEX (events_inventory_time)
             WHERE n.drone = k_drone AND n.resource = k_resource AND n.installed = k_installed
             AND n.locked = k_locked AND n.outcome = 1 AND n.game_t <= cutoff AND n.event_type IN (1, 2)
             ORDER BY n.game_t DESC, n.id DESC LIMIT 1));
    END LOOP;
    CLOSE kinds;

    INSERT INTO events_archive
    SELECT e.* FROM events e
    WHERE e.id >= from_id AND e.id < hi AND e.outcome <> 0 AND e.game_t < cutoff
      -- its action has nothing left to settle (a failure gives back fuel
      -- and mining yields from the action's earlier events)
      AND NOT (IFNULL(e.action, 0) > 0 AND EXISTS (SELECT 1 FROM events p FORCE INDEX (action_idx)
               JOIN actions x ON x.id = p.action
               WHERE p.action = e.action AND p.outcome = 0 AND IFNULL(x.aborted, 0) <> 1))
      AND (e.outcome <> 1 OR (
          -- the latest of its kind at the cutoff is newer by id too: the
          -- drone's latest success, and an inventory event's latest of its
          -- drone, resource, installed, locked
          (SELECT k.id FROM archive_keep_drone k WHERE k.drone = e.drone) > e.id
          AND (e.event_type NOT IN (1, 2) OR (SELECT k.id FROM archive_keep_kind k WHERE k.drone = e.drone
                  AND k.resource = e.resource AND k.installed = e.installed AND k.locked = e.locked) > e.id)
          -- the latest order of its kind (any newer one up to the cutoff:
          -- by id along events_orders, a step or two)
          AND (e.event_type NOT IN (4, 5) OR EXISTS (SELECT 1 FROM events n FORCE INDEX (events_orders)
                  WHERE n.drone = e.drone AND n.resource = e.resource AND n.event_type = e.event_type
                  AND n.outcome = 1 AND n.game_t >= e.game_t AND n.game_t <= cutoff AND n.id > e.id))
          -- and, belt and braces, not what holdings or latest_orders point at
          AND NOT EXISTS (SELECT 1 FROM holdings h WHERE h.drone = e.drone AND h.resource = e.resource
                  AND h.installed = IFNULL(e.installed, -1) AND h.locked = IFNULL(e.locked, -1) AND h.event_id = e.id)
          AND NOT EXISTS (SELECT 1 FROM latest_orders o WHERE o.drone = e.drone AND o.resource = e.resource
                  AND o.event_type = e.event_type AND o.event_id = e.id)));
    SET moved = ROW_COUNT();

    IF moved > 0 THEN
        SET @events_archiving = 1;
        DELETE e FROM events e JOIN events_archive a ON a.id = e.id
        WHERE e.id >= from_id AND e.id < hi AND a.id >= from_id AND a.id < hi;
        SET @events_archiving = 0;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS archive_keep_drone, archive_keep_kind;

    SET next_id = IF(hi >= ceiling, 0, hi);
END //

DELIMITER ;
