-- A founder's note (#998, part 11 of #921): a short text the founder keeps
-- on a company's page, before and after its code is locked. It changes
-- nothing the drones do.
SET @has := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'founder_note');
SET @sql := IF(@has = 0,
    'ALTER TABLE companies ADD COLUMN founder_note VARCHAR(280) NULL COMMENT ''The founder''''s note, plain text (#998)'', ADD COLUMN founder_note_t BIGINT NULL COMMENT ''Game time the note was last edited''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
