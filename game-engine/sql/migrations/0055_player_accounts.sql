-- Player accounts (#636, step 1 of the new rules in #635): a player signs up
-- with a name, a password and an e-mail address, and once the address is
-- verified gets credits that can only buy shares. Teachers make class
-- groups of pseudonymous accounts.
-- Everything here is used only while FORGE_NEW_RULES is on; the live game
-- is unchanged until the reset (#642).

-- An account's name, unique without regard to case (the column's collation)
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'login');
SET @sql := IF(@have = 0,
    'ALTER TABLE users ADD COLUMN login VARCHAR(32) NULL COMMENT ''A player account''''s name (#636); NULL for the game''''s own owners'', ADD UNIQUE INDEX users_login_unique (login)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Credits that can only buy shares (#636): an account gets them when its
-- e-mail address is verified, a pupil's when the account is made
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'share_credits');
SET @sql := IF(@have = 0,
    'ALTER TABLE users ADD COLUMN share_credits INT NOT NULL DEFAULT 0 COMMENT ''Starting credits that can only buy shares (#636)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The class group a pupil's account belongs to, if any
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'class_group_id');
SET @sql := IF(@have = 0,
    'ALTER TABLE users ADD COLUMN class_group_id INT UNSIGNED NULL COMMENT ''The class group of a pupil''''s account (#636)'', ADD INDEX users_class_group (class_group_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The API's token, kept as a SHA-256 hash
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'api_token');
SET @sql := IF(@have = 0,
    'ALTER TABLE users ADD COLUMN api_token CHAR(64) NULL COMMENT ''SHA-256 of the account''''s API token (#636)'', ADD UNIQUE INDEX users_api_token_unique (api_token)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- When the account's e-mail address was verified (#636): one account per
-- address, and the starting share credits come with verifying it
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email_verified_at');
SET @sql := IF(@have = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL COMMENT ''When the account''''s e-mail address was verified (#636)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Pupils' accounts in a class group have no e-mail address (their teacher's
-- is verified): e-mail may be empty (MySQL's unique index allows any number
-- of NULLs)
ALTER TABLE users MODIFY email VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS class_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(45) NOT NULL,
    teacher_id INT UNSIGNED NOT NULL COMMENT 'The account that made it (users.user_id)',
    created_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    INDEX class_groups_teacher (teacher_id)
);
