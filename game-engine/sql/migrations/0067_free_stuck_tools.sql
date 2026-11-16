-- Free the tools left locked by aborted actions (#711, #712).
--
-- A recipe locks its tool while it works (Make Alloys locks the Smelter two
-- game seconds in and unlocks it at the end; mining locks the Worm). Until
-- round 70 the events engine aborted an action on any database error,
-- passing ones too (a deadlock with the website's tick, a lost connection,
-- the full disk of days 2161 to 2170). An action aborted between the lock
-- and the unlock left its tool locked for good, and every later make or mine
-- failed with -2: round 70 found 4 to 7 of 8 makers dead in several matured
-- companies, and a miner whose every mine failed from day 2171. The engine
-- now retries such a batch instead.
--
-- Here, each locked holding that no pending event will unlock goes back to
-- the drone's free stock: one event adds it to the unlocked units, another
-- empties the locked ones. The holdings triggers keep holdings in step, and
-- cargo is unchanged (the same units, the same mass). Safe to run again: a
-- second run finds nothing locked.

SET @now := (SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1);

DROP TEMPORARY TABLE IF EXISTS stuck_tools;
CREATE TEMPORARY TABLE stuck_tools AS
SELECT h.drone, h.resource, h.installed, h.units AS locked_units,
       IFNULL((SELECT f.units FROM holdings AS f
               WHERE f.drone = h.drone AND f.resource = h.resource
                 AND f.installed = h.installed AND f.locked = 0), 0) AS free_units,
       a.object_id AS place
FROM holdings AS h
JOIN agents AS a ON a.agent_id = h.drone
WHERE h.locked = 1 AND h.units > 0 AND h.installed IN (0, 1)
  AND NOT EXISTS (SELECT 1 FROM events AS e JOIN actions AS x ON x.id = e.action
                  WHERE e.drone = h.drone AND e.resource = h.resource AND e.locked = 1
                    AND e.outcome = 0 AND x.aborted = 0);

INSERT INTO events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, game_t, place)
SELECT 1, NULL, 1, 1, drone, resource, installed, 0, free_units + locked_units, @now, place FROM stuck_tools;

INSERT INTO events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, game_t, place)
SELECT 2, NULL, 1, 1, drone, resource, installed, 1, 0, @now, place FROM stuck_tools;

SELECT COUNT(*) AS tools_freed FROM stuck_tools;
DROP TEMPORARY TABLE stuck_tools;
