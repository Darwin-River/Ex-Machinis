-- Seedlings are now startups (#855): the common name for a new company. The
-- columns and tables the earlier migrations named for seedlings take the new
-- name; each step is skipped once done, so the migration is safe to run again.
DROP PROCEDURE IF EXISTS rename_for_startups;
DELIMITER //
CREATE PROCEDURE rename_for_startups(tbl VARCHAR(64), old_name VARCHAR(64), new_def VARCHAR(512))
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
               AND table_name = tbl AND column_name = old_name) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' CHANGE COLUMN ', old_name, ' ', new_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL rename_for_startups('companies', 'seedling',
    'startup TINYINT NOT NULL DEFAULT 0 COMMENT ''1 while a startup (#637, #855)''');
CALL rename_for_startups('house_rules', 'seedling_buy_permille',
    'startup_buy_permille INT NOT NULL DEFAULT 100 COMMENT ''Most of the House''''s stock of a good at one exchange a startup may buy in a game day, in thousandths (#637)''');
CALL rename_for_startups('house_rules', 'seedling_credit_cap_permille',
    'startup_credit_cap_permille INT NOT NULL DEFAULT 2000 COMMENT ''Most credits a startup may hold, in thousandths of its debt; above it they go to the bank (#672)''');
DROP PROCEDURE rename_for_startups;

-- What each startup bought from the House, and what it sent the bank over its cap
SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'seedling_buys') > 0,
    'RENAME TABLE seedling_buys TO startup_buys', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'seedling_skims') > 0,
    'RENAME TABLE seedling_skims TO startup_skims', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE()
                AND table_name = 'startup_skims' AND index_name = 'seedling_skims_company') > 0,
    'ALTER TABLE startup_skims RENAME INDEX seedling_skims_company TO startup_skims_company', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
