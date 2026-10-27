-- Indexes for the queries that slowed every world down as it aged (#380).
-- Each scanned the whole events table, or most of it, which only grows:
--
-- * sqInventory (what a drone holds, behind the units game word and
--   query 212) finds each drone's latest inventory event per resource,
--   installed and locked state. With an index on those columns MySQL reads
--   one index entry per group instead of every event: in a 90-day test
--   world (440,000 events) a call went from about 136 ms to 16 ms.
-- * The events engine's "next due events" (outcome 0, by game time) read
--   every event and sorted them; now it reads only the pending ones.
-- * A drone's latest event before a game time (drone, outcome, game_t),
--   which the events engine asks for every event, walked back through
--   every drone's events by game time (about 240,000 rows at day 90).
-- * The latest sell or buy order of each drone (queries 600 to 612, which
--   buyers use to find goods): about 630 ms at day 90, now about 4 ms.
--
-- Safe to run again: each index is added only if missing.

DROP PROCEDURE IF EXISTS pf_add_index;

DELIMITER //
CREATE PROCEDURE pf_add_index(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN cols VARCHAR(255))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
                   WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = idx) THEN
        SET @pf_sql = CONCAT('CREATE INDEX `', idx, '` ON `', tbl, '` (', cols, ')');
        PREPARE pf_stmt FROM @pf_sql;
        EXECUTE pf_stmt;
        DEALLOCATE PREPARE pf_stmt;
    END IF;
END //
DELIMITER ;

CALL pf_add_index('events', 'events_inventory', 'drone, resource, installed, locked, outcome, event_type');
CALL pf_add_index('events', 'events_pending', 'outcome, game_t');
CALL pf_add_index('events', 'events_drone_game_t', 'drone, outcome, game_t');
CALL pf_add_index('events', 'events_orders', 'event_type, outcome, resource, drone');

DROP PROCEDURE pf_add_index;
