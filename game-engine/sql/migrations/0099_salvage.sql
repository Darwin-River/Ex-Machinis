-- Salvage (#929), part 8 of the revised system (#921). A wreck keeps its
-- modules (plunder takes only its hold) and its hull, for salvage: a drone
-- with a Salvager (A1) recovers a module a run, then scrap from the hull,
-- less what the wreck has decayed (2% of what is left a game day).
--
-- agents.scrap_left: scrap its hull still holds (NULL until first salvaged:
-- its hull's mass, at least 10); salvaged_by and salvaged_t: the last drone
-- that salvaged it, and when.
--
-- Only adds columns.

SET @has := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'agents' AND column_name = 'scrap_left');
SET @sql := IF(@has = 0,
    'ALTER TABLE agents ADD COLUMN scrap_left INT NULL COMMENT ''A wreck: scrap its hull still holds (#929); NULL: not yet salvaged'', '
    'ADD COLUMN salvaged_by INT UNSIGNED NULL COMMENT ''A wreck: the last drone that salvaged it (#929)'', '
    'ADD COLUMN salvaged_t BIGINT NULL COMMENT ''A wreck: game time it was last salvaged (#929)''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
