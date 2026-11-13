-- The game engine takes the House's station drones' commands first (#633):
-- an index finds those drones without scanning every drone on each batch.
SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'agents' AND index_name = 'agents_station');
SET @sql := IF(@have = 0, 'ALTER TABLE agents ADD INDEX agents_station (station_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
