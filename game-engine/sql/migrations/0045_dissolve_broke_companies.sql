-- A company broke for 50 game days dissolves (#547). Since #544 a drone that
-- runs dry is stranded, not wrecked. A company is broke while its credits
-- less the processor tax it owes are 0 or below; broke_since is when that
-- began (NULL when it isn't broke). Broke for house_rules.dissolve_days game
-- days straight, it dissolves: it ends (end_cause 'dissolved') and every
-- working drone becomes a wreck (wreck_cause 'dissolved') for anyone to
-- plunder. db_compute.c checks once a game hour.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'broke_since');
SET @sql := IF(@have = 0,
    'ALTER TABLE companies ADD COLUMN broke_since BIGINT NULL COMMENT ''Game time it became broke: credits less processor tax owed at 0 or below (#547)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'dissolve_days');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN dissolve_days INT NOT NULL DEFAULT 50 COMMENT ''Game days broke before a company dissolves (#547); 0: never''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
