-- Code pushes (#553): a company released with a password can push a new
-- script to its working drones, for a fee. The password is kept only as a
-- hash (bcrypt, App\Services\CodePush). Each push travels from Earth to each
-- drone at the speed of light (the engine stamps its commands), and the
-- drone restarts on the new script with fresh variables. The fee is a share
-- of the company's credits that grows with each push (config forge.push_fee_*).
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'push_password');
SET @sql := IF(@have = 0,
    'ALTER TABLE companies ADD COLUMN push_password VARCHAR(255) NULL COMMENT ''Hash of the password that lets its owner push new code (#553)'', ADD COLUMN pushes INT NOT NULL DEFAULT 0 COMMENT ''Code pushes so far (#553)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'launch_queue' AND column_name = 'push_password');
SET @sql := IF(@have = 0,
    'ALTER TABLE launch_queue ADD COLUMN push_password VARCHAR(255) NULL COMMENT ''Hash of the company''''s push password, set when it launches (#553)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS code_pushes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    number INT NOT NULL COMMENT 'Its first push is 1',
    game_t BIGINT NOT NULL COMMENT 'Game time it left Earth',
    credits_before BIGINT NOT NULL,
    permille INT NOT NULL COMMENT 'Fee as thousandths of its credits',
    fee BIGINT NOT NULL,
    drones INT NOT NULL COMMENT 'Working drones it went to',
    script MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX code_pushes_company (company_id, id)
);
