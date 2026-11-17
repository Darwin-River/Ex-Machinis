-- The trading notices (#761, part 2): the six e-mails round 72's traders
-- asked for, beside the four of 0085. Each is off until the player turns it
-- on; all but fills go in the daily e-mail, and fills come at most hourly.
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

CALL add_notice_column('at_cap', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''A seedling of theirs sends credits to the bank''');
CALL add_notice_column('fills', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''A share order of theirs filled (hourly at most)''');
CALL add_notice_column('orders', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''An order of theirs out of range, or selling below cash-out''');
CALL add_notice_column('bank_paid', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''The bank paid them for shares''');
CALL add_notice_column('cash_out', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''A holding moved 15% in cash-out, or ended''');
CALL add_notice_column('price_alerts', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''Their House price alerts''');
CALL add_notice_column('fills_after', 'INT UNSIGNED NULL COMMENT ''Share trades after this id are not told yet''');
CALL add_notice_column('last_fills_at', 'TIMESTAMP NULL');
CALL add_notice_column('bank_since', 'BIGINT NULL COMMENT ''Game time: bank payments after this are not told yet''');
CALL add_notice_column('holdings_since', 'BIGINT NULL COMMENT ''Game time: held companies ending after this are not told yet''');
DROP PROCEDURE add_notice_column;

-- A player's House price alerts: tell me when the House bids more than a
-- price for a good (at one place, or anywhere)
CREATE TABLE IF NOT EXISTS notice_alerts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    resource INT UNSIGNED NOT NULL,
    place INT UNSIGNED NULL COMMENT 'objects.object_id, or NULL for anywhere',
    above INT NOT NULL COMMENT 'Tell when the House bids more than this',
    created_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    INDEX notice_alerts_user (user_id)
);
