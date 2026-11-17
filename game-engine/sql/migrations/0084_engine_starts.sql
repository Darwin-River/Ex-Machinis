-- Engine restarts on the charts (#750). A restart sends every Lua drone back
-- to its launched function, and drone pages said "The engine restarted",
-- but /world-events had no record of it, so charts couldn't mark it. The
-- game engine now adds a row here each time it starts (db_record_engine_start),
-- and WorldEvents shows them as 'restart' marks, one a game day.
CREATE TABLE IF NOT EXISTS engine_starts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    engine VARCHAR(40) NOT NULL,
    real_time BIGINT NOT NULL COMMENT 'Unix time it started',
    game_t BIGINT NOT NULL COMMENT 'Game time it started',
    INDEX engine_starts_game_t (game_t)
);

-- A deploy starts the engines before it migrates, so the start that brought
-- this table in couldn't be noted: note it now (a few seconds late)
INSERT INTO engine_starts (engine, real_time, game_t)
SELECT 'game-engine', UNIX_TIMESTAMP(), ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale)
FROM game_clock WHERE id = 1;
