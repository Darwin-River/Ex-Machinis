-- Lua drones (#380): a drone's program may be Lua instead of FORTH.
-- language: 'forth' (a VM image, as before) or 'lua'.
-- lua_script: a Lua drone's released program, gathered from its pieces.
-- lua_state: its plain-data globals, saved whenever it pauses for an action,
-- so a restarted engine (or a clone) can carry them on.
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'agents' AND column_name = 'language');
SET @sql := IF(@have = 0,
    'ALTER TABLE agents ADD COLUMN language VARCHAR(8) NOT NULL DEFAULT ''forth'', ADD COLUMN lua_script MEDIUMTEXT NULL, ADD COLUMN lua_state MEDIUMTEXT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
