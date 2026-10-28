-- House station drones (#380): the House trades as drones, by the players'
-- rules. Its company is marked so the leaderboard leaves it out, and each
-- station drone knows the exchange (stations.id) it stands in for.
DROP PROCEDURE IF EXISTS add_column_0025;
DELIMITER //
CREATE PROCEDURE add_column_0025(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(255))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = t AND column_name = c) THEN
        SET @sql = CONCAT('ALTER TABLE ', t, ' ADD COLUMN ', c, ' ', def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;
CALL add_column_0025('companies', 'house', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''1 for the House (#380): not a player''');
CALL add_column_0025('agents', 'station_id', 'INT NULL COMMENT ''The exchange a House station drone stands in for (#380)''');
DROP PROCEDURE add_column_0025;
