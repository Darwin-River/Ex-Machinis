-- Two e-mail notices start on (#876): "A startup of mine can mature" and "A
-- startup of mine is past its credit cap". A startup left alone between
-- rounds sent 40,967 credits to the bank while its player was told nothing.
-- Every verified account gets them on, the ones that already chose
-- settings too (the project lead's choice); anyone can turn them off.
ALTER TABLE notice_settings MODIFY can_mature TINYINT NOT NULL DEFAULT 1 COMMENT 'A startup of theirs can mature (on unless turned off, #876)';
ALTER TABLE notice_settings MODIFY at_cap TINYINT NOT NULL DEFAULT 1 COMMENT 'A startup of theirs sends credits to the bank (on unless turned off, #876)';

INSERT IGNORE INTO notice_settings (user_id, can_mature, at_cap, updated_at)
    SELECT user_id, 1, 1, NOW() FROM users
    WHERE login IS NOT NULL AND email IS NOT NULL AND email_verified_at IS NOT NULL AND class_group_id IS NULL;

UPDATE notice_settings SET can_mature = 1, at_cap = 1;
