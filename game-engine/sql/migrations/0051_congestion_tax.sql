-- The congestion tax (#620): while the game clock runs slower than its
-- target because the engines can't keep up, the processor tax rises by how
-- many times slower it runs, up to house_rules.congestion_cap (1 or less
-- turns it off). A company pays its share of that rise by how much its drones
-- thought over the last game day, all of it from congestion_full_at
-- instructions a day (0: everyone pays all of it), so a newcomer's lone drone
-- pays little more and a busy fleet pays all of it. The events engine records the rate it billed at last in
-- compute_clock, for the website, the API and drones (query 651).
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'congestion_cap');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN congestion_cap DOUBLE NOT NULL DEFAULT 10 COMMENT ''Most times the processor tax rises while the clock runs slowed (#620); 1 or less turns it off''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'congestion_full_at');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN congestion_full_at BIGINT NOT NULL DEFAULT 5000000 COMMENT ''Instructions a game day from which a company pays all the congestion (#620); 0: everyone does''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'compute_clock' AND column_name = 'rate_now');
SET @sql := IF(@have = 0,
    'ALTER TABLE compute_clock ADD COLUMN rate_now INT NULL COMMENT ''Credits a million instructions at the last bill, congestion included (#620)'', ADD COLUMN congestion DOUBLE NULL COMMENT ''Times the base rate at the last bill (#620)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
