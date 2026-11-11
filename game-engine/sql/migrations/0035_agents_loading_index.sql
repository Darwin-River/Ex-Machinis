-- Drones whose script is still loading (no release_vm yet), found by index
-- (#454, part of #428). The game engine takes their commands first, so a new
-- release loads at once. It found them by sorting every due command, about
-- one per drone: 25 ms a batch on a 3,000-ship world, about once a second.
-- Now it asks for the loading drones' commands by this index, then the rest
-- in id order, and stops at the batch size.
SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'agents' AND index_name = 'agents_loading');
SET @sql := IF(@have = 0, 'CREATE INDEX agents_loading ON agents ((release_vm IS NULL))', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
