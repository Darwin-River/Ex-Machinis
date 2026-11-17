-- The archiver catches up (#818). After the disk filled (#811) and the engines
-- restarted, the live world's archiver logged no pass for hours while 24.8
-- million of its 25 million events were older than the 30 game days it keeps.
-- A pass starts from the oldest event after a restart, and its windows were
-- 2,000 ids wide: through the 11 million ids already archived, each held a
-- handful of rows. Now a window is the next 2,000 events that exist. The
-- procedure is otherwise as migration 0072 made it.
DROP PROCEDURE IF EXISTS archive_events;

DELIMITER //

-- Moves the archivable events from from_id over the next span events
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
    -- The window ends after the next `span` events that exist, not `span`
    -- ids (#818): after a restart a pass starts from the oldest event, and
    -- through ids already archived (11 million on the live world) a window of
    -- 2,000 ids held a handful of rows, so a pass crawled for hours
    SET hi = LEAST(ceiling, IFNULL((SELECT n.id FROM events n WHERE n.id >= from_id ORDER BY n.id LIMIT 1 OFFSET span), ceiling));

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
