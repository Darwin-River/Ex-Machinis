-- Indexes for the two queries that slowed every world down as it aged
-- (#380). Both scanned the whole events table, which only grows:
--
-- * sqInventory (what a drone holds, behind the units game word and
--   query 212) finds each drone's latest inventory event per resource,
--   installed and locked state. With an index on those columns MySQL reads
--   one index entry per group instead of every event: in a 90-day test
--   world (440,000 events) a call went from about 136 ms to 16 ms.
-- * The events engine's "next due events" (outcome 0, by game time) read
--   every event and sorted them; now it reads only the pending ones.
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

DROP PROCEDURE pf_add_index;
