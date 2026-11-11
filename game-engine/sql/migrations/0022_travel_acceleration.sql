-- Trip times for drones' queries (#380): the Game Engine stores the travel
-- acceleration here (game-engine.conf's TRAVEL_ACCELERATION) along with every
-- body's position (objects.x_coord ...), so queries 704 and 705 can work out
-- a trip's time as travel does: 2 * sqrt(distance / acceleration).
SET @has := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'game_clock' AND column_name = 'travel_acceleration');
SET @sql := IF(@has = 0, 'ALTER TABLE game_clock ADD COLUMN travel_acceleration DOUBLE NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
