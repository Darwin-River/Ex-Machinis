-- A company may have at most 8 working drones (#682). In round 69 matured
-- starters copied themselves without limit (454 drones, Bank Maker alone
-- 103), and the engines fell so far behind that the clock sat at its floor:
-- no seedling could mature in a lesson's hour.
-- house_rules.fleet_cap: the most working drones a company may have
-- (0: no cap). The House's own companies are not capped. A launch that
-- would take a company past it is refused (perform returns -20).
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'fleet_cap');
SET @sql := IF(@have = 0,
    'ALTER TABLE house_rules ADD COLUMN fleet_cap INT NOT NULL DEFAULT 8 COMMENT ''Most working drones a company may have; a launch past it is refused with -20 (0: no cap) (#682)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Companies already over the cap have their newest drones scrapped (the
-- migration runs before the engines start). They become wrecks, as when a
-- company dissolves; a seedling's leave the game with what they held.
SET @now := (SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1);
SET @cap := (SELECT fleet_cap FROM house_rules WHERE id = 1);

CREATE TEMPORARY TABLE fleet_cap_scrap (agent_id INT UNSIGNED NOT NULL PRIMARY KEY);
INSERT INTO fleet_cap_scrap (agent_id)
SELECT agent_id FROM (
    SELECT a.agent_id, ROW_NUMBER() OVER (PARTITION BY a.company_id ORDER BY a.agent_id) AS n
    FROM agents a JOIN companies c ON c.company_id = a.company_id
    WHERE a.wrecked_at IS NULL AND c.house = 0 AND c.eliminated_at IS NULL
) ranked
WHERE @cap > 0 AND n > @cap;

UPDATE agents a JOIN fleet_cap_scrap s ON s.agent_id = a.agent_id
SET a.wrecked_at = @now, a.wreck_cause = 'fleet cap', a.launched_running = 0;
UPDATE agents a JOIN fleet_cap_scrap s ON s.agent_id = a.agent_id
JOIN companies c ON c.company_id = a.company_id
SET a.plundered_at = @now
WHERE c.seedling = 1 AND a.plundered_at IS NULL;

DROP TEMPORARY TABLE fleet_cap_scrap;
