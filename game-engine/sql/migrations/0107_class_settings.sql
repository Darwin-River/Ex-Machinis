-- Class settings (#1003, part 11 of #921): on the teacher's class page,
-- takeovers within the class on or off, and backing loans only within the
-- class. Both on by default.
SET @has := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'class_groups' AND column_name = 'takeovers');
SET @sql := IF(@has = 0,
    'ALTER TABLE class_groups ADD COLUMN takeovers TINYINT NOT NULL DEFAULT 1 COMMENT ''1: a pupil may buy all of a classmate''''s company (#1003)'', ADD COLUMN loans_within TINYINT NOT NULL DEFAULT 1 COMMENT ''1: pupils back only classmates'''' loans, and theirs only pupils of the class''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
