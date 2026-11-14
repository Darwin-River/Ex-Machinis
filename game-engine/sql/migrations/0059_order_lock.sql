-- The minimum order life (#639, step 4 of the new rules in #635): a drone of
-- a seedling or a player's matured company can't withdraw, cut or worsen
-- (a buy order lower, a sell order higher) its standing order for this many
-- game hours after posting it, so a distress order can't lure rescuers and
-- then vanish. The events engine refuses such an order with outcome -19.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'order_lock_hours');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN order_lock_hours INT NOT NULL DEFAULT 12 COMMENT ''Game hours a new-rules order stands before it can be withdrawn, cut or worsened (#639)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
