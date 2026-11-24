-- New on the exchange (#996, part 11 of #921): a notice, off until a
-- player turns it on, in the daily e-mail, of companies that offered their
-- shares for the first time and loan requests posted since it last told.
DROP PROCEDURE IF EXISTS add_notice_column;
DELIMITER //
CREATE PROCEDURE add_notice_column(col VARCHAR(64), def VARCHAR(255))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                   AND table_name = 'notice_settings' AND column_name = col) THEN
        SET @sql = CONCAT('ALTER TABLE notice_settings ADD COLUMN ', col, ' ', def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL add_notice_column('listings', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''A company offers its shares for the first time, or asks for a loan''');
CALL add_notice_column('listings_since', 'BIGINT NULL COMMENT ''Game time: listings after this are not told yet''');
DROP PROCEDURE add_notice_column;
