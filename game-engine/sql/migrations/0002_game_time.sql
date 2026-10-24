-- Game time everywhere (#327). Times the game schedules or compares are kept
-- in game seconds (game_t), read from the game clock, so they stay right if
-- the clock's rate changes. The real-time columns (events.timestamp,
-- observations.time, trades.time) are still filled for the website and for
-- housekeeping.
--
-- Game seconds count like unix seconds: a new world's game time starts at the
-- real time it was created, then runs at the clock's scale. Existing rows are
-- given their real time as a starting point.

ALTER TABLE events ADD COLUMN game_t BIGINT NULL, ADD INDEX events_game_t_idx (game_t);
UPDATE events SET game_t = UNIX_TIMESTAMP(timestamp) WHERE game_t IS NULL AND timestamp IS NOT NULL;

ALTER TABLE observations ADD COLUMN game_t BIGINT NULL, ADD INDEX observations_drone_game_t_idx (drone, game_t);
UPDATE observations SET game_t = UNIX_TIMESTAMP(time) WHERE game_t IS NULL AND time IS NOT NULL;

ALTER TABLE trades ADD COLUMN game_t BIGINT NULL, ADD INDEX trades_resource_game_t_idx (resource, game_t);
UPDATE trades SET game_t = UNIX_TIMESTAMP(time) WHERE game_t IS NULL;

-- commands.deliver_at now holds game seconds (it held unix seconds, which a
-- new world's game time starts equal to)
ALTER TABLE commands MODIFY deliver_at BIGINT NULL
    COMMENT 'Game time (game seconds) when the command reaches its drone at the speed of light; set by the game engine.';

-- The world's first game second: drone dates count days from it
ALTER TABLE game_clock ADD COLUMN epoch DOUBLE NULL;
UPDATE game_clock SET epoch = game_anchor WHERE epoch IS NULL;

-- Every change of the clock's rate, so charts can map game time to real time
CREATE TABLE IF NOT EXISTS game_clock_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    real_time BIGINT NOT NULL,
    game_time DOUBLE NOT NULL,
    scale DOUBLE NOT NULL
);
