-- The schema changes the engines used to make for themselves at start-up
-- (db_upgrade_schema() in db_common.c, removed when migrations arrived).
-- Written to be safe on databases that already have some or all of them.

DROP PROCEDURE IF EXISTS pf_add_column;

DELIMITER //
CREATE PROCEDURE pf_add_column(IN tbl VARCHAR(64), IN col VARCHAR(64), IN def VARCHAR(255))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = col) THEN
        SET @pf_sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', def);
        PREPARE pf_stmt FROM @pf_sql;
        EXECUTE pf_stmt;
        DEALLOCATE PREPARE pf_stmt;
    END IF;
END //
DELIMITER ;

-- Why an action failed (the first reason only)
CALL pf_add_column('actions', 'outcome', 'INT NULL');

-- The action a drone's script waits for, and the unread rest of a paused script
CALL pf_add_column('agents', 'waiting_action', 'INT UNSIGNED NULL');
CALL pf_add_column('agents', 'pending_input', 'LONGTEXT NULL');

-- When a player's command reaches its drone (unix seconds)
CALL pf_add_column('commands', 'deliver_at', 'BIGINT NULL');

DROP PROCEDURE pf_add_column;

-- Scripts longer than 2,048 characters, such as a whole library in one <reset>
ALTER TABLE commands MODIFY code TEXT NOT NULL;

-- The master game clock
CREATE TABLE IF NOT EXISTS game_clock (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    real_anchor BIGINT NOT NULL,
    game_anchor DOUBLE NOT NULL,
    scale DOUBLE NOT NULL DEFAULT 1
);

-- Drone replies on their way to Earth: sent at send_at (unix seconds)
CREATE TABLE IF NOT EXISTS outbox (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    send_at BIGINT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    subject VARCHAR(2048) NULL,
    agent_name VARCHAR(255) NOT NULL,
    body LONGTEXT NOT NULL,
    INDEX send_at_idx (send_at)
);

-- Trades between players (#249): written by the events engine when a push or
-- pull fills another player's market order; read by queries 620 and 621
CREATE TABLE IF NOT EXISTS trades (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resource INT NOT NULL,
    units INT NOT NULL,
    credits INT NOT NULL,
    seller INT UNSIGNED NOT NULL,
    buyer INT UNSIGNED NOT NULL,
    location INT NULL,
    INDEX trades_resource_time_idx (resource, time)
);
