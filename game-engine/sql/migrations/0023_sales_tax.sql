-- A sales tax instead of the daily tax (#380). The daily tax took a share of
-- a company's wealth, goods included, but only credits paid it: a trader
-- holding stock could be eliminated with thousands of credits' worth aboard.
-- Now the seller pays a share of every sale (to a player or a House station)
-- out of what the buyer pays, and the daily tax is off (tax_period 0 in the
-- game data). Each sale's tax is a company_taxes row, and trades and
-- station_offers say what tax each sale paid.
DROP PROCEDURE IF EXISTS add_column_0023;
DELIMITER //
CREATE PROCEDURE add_column_0023(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(255))
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
CALL add_column_0023('house_rules', 'sales_tax_permille', 'INT NOT NULL DEFAULT 0 COMMENT ''Sales tax: thousandths of each sale, paid by the seller''');
CALL add_column_0023('trades', 'tax', 'INT NOT NULL DEFAULT 0 COMMENT ''Sales tax the seller paid out of credits''');
CALL add_column_0023('station_offers', 'tax', 'BIGINT NOT NULL DEFAULT 0 COMMENT ''Sales tax the seller paid out of paid''');
DROP PROCEDURE add_column_0023;
