-- Offerings with a minimum and a maximum (#516). An offering of new shares
-- was all-or-nothing (#485): every share committed before the deadline, or
-- everyone refunded. Now a company offers up to `units` new shares and at
-- least `min_units`: at the deadline, an offering with at least its minimum
-- committed issues what was committed and pays the company for it; short of
-- it, everyone is refunded. Fully committed, it still closes at once.
-- NULL (offerings posted before this) means the minimum is all of them.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'share_offerings' AND column_name = 'min_units');
SET @sql := IF(@have = 0,
    'ALTER TABLE share_offerings ADD COLUMN min_units INT NULL COMMENT ''The fewest new shares it issues at its deadline (#516); NULL: all of them'' AFTER units',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
