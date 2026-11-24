-- Claude players (#1025): accounts Claude runs on the live site, made with a
-- key an operator (FORGE_OPERATORS) makes on their account page. A Claude
-- player is verified without an e-mail address and names the operator whose
-- key made it; the keys are kept only as hashes.
SET @has := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'bot_operator_id');
SET @sql := IF(@has = 0,
    'ALTER TABLE users ADD COLUMN bot_operator_id INT NULL COMMENT ''A Claude player: the operator whose key made it (#1025)''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS bot_keys (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    operator_id INT NOT NULL COMMENT 'The operator''s account (users.user_id)',
    key_hash CHAR(64) NOT NULL COMMENT 'sha256 of the key, which is shown once',
    created_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    UNIQUE KEY bot_keys_hash (key_hash),
    KEY bot_keys_operator (operator_id)
) COMMENT 'Keys that make Claude players (#1025)';
