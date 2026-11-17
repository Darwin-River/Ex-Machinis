-- Opt-in e-mail notices (#761), sent through the mail settings the account
-- verification uses (#636). A player picks which they want on the account
-- page; all are off until they do. Pupils have no e-mail address: their
-- teacher may turn on a daily class summary for each class group instead.

-- What each player asked for, and when each was last sent
CREATE TABLE IF NOT EXISTS notice_settings (
    user_id INT UNSIGNED NOT NULL,
    can_mature TINYINT NOT NULL DEFAULT 0 COMMENT 'A seedling of theirs can mature',
    at_risk TINYINT NOT NULL DEFAULT 0 COMMENT 'A company of theirs is at risk, stalled or dissolving',
    wrecked TINYINT NOT NULL DEFAULT 0 COMMENT 'A drone of theirs was wrecked',
    digest TINYINT NOT NULL DEFAULT 0 COMMENT 'A weekly digest',
    last_daily_at TIMESTAMP NULL COMMENT 'The last daily notice (one a real day at most)',
    last_digest_at TIMESTAMP NULL COMMENT 'The last digest, or when it was turned on',
    wrecks_since BIGINT NULL COMMENT 'Game time: wrecks after this are not told yet',
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (user_id)
);

-- What was told, so a notice isn't sent twice (a seedling's "can mature" once,
-- a company at risk once a real day)
CREATE TABLE IF NOT EXISTS notices_sent (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL COMMENT 'Who it went to (a teacher, for a class summary)',
    kind VARCHAR(16) NOT NULL,
    subject INT UNSIGNED NOT NULL COMMENT 'The company or drone it was about',
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX notices_sent_lookup (user_id, kind, subject, sent_at)
);

-- A teacher's daily class summary, one switch a class group
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'class_groups' AND column_name = 'notices');
SET @sql := IF(@have = 0,
    'ALTER TABLE class_groups ADD COLUMN notices TINYINT NOT NULL DEFAULT 0 COMMENT ''The teacher gets a daily class summary (#761)'', ADD COLUMN last_summary_at TIMESTAMP NULL, ADD COLUMN wrecks_since BIGINT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
