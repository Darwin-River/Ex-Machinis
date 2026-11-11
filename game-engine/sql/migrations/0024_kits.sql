-- Two kits (#380): a company starts with a Mining Kit I (5901) or a Factory
-- Kit I (5902), chosen at release. The company remembers which (for the
-- leaderboard's starting package and "Copy this code"), and a release
-- waiting in the launch queue keeps its choice.
DROP PROCEDURE IF EXISTS add_column_0024;
DELIMITER //
CREATE PROCEDURE add_column_0024(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(255))
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
CALL add_column_0024('companies', 'start_kit', 'INT NULL COMMENT ''The kit it started with: 5901 mining, 5902 factory (#380)''');
CALL add_column_0024('launch_queue', 'kit', 'VARCHAR(16) NULL COMMENT ''mining or factory (#380)''');
DROP PROCEDURE add_column_0024;
