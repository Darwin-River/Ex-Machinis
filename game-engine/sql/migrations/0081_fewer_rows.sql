-- Store less of the game's history (#814, after the outage in #811).
--
-- The engine no longer writes an observation row for the drone an event
-- affects: query 803 finds a drone's own events by events.drone. Rows
-- already written stay until the history budget (#813) prunes them with
-- their events.
--
-- And two indexes that only repeated the primary key go: id_UNIQUE on
-- events and on observations (every lookup by id uses PRIMARY). Dropping a
-- secondary index is an in-place change in MySQL 8: quick, and the table
-- stays readable and writable.
SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'events' AND index_name = 'id_UNIQUE');
SET @sql := IF(@have > 0, 'ALTER TABLE events DROP INDEX id_UNIQUE, ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'observations' AND index_name = 'id_UNIQUE');
SET @sql := IF(@have > 0, 'ALTER TABLE observations DROP INDEX id_UNIQUE, ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'events_archive' AND index_name = 'id_UNIQUE');
SET @sql := IF(@have > 0, 'ALTER TABLE events_archive DROP INDEX id_UNIQUE, ALGORITHM=INPLACE, LOCK=NONE', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
