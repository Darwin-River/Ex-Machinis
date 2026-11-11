-- The clock floor (#324 step 20). The game engine watches how far behind
-- the engines run and slows the game clock when they can't keep up, down to
-- a floor; at the floor the world is full and new releases wait in
-- launch_queue until there is room. The website shows the clock and the
-- queue from game_clock.
ALTER TABLE game_clock
    ADD COLUMN target_scale DOUBLE NULL COMMENT 'The scale the clock aims for (GAME_TIME_SCALE)',
    ADD COLUMN floor_scale DOUBLE NULL COMMENT 'The least it slows to (GAME_TIME_FLOOR); NULL: it never floats',
    ADD COLUMN lag_seconds DOUBLE NULL COMMENT 'How far behind the engines ran at the last check, in real seconds',
    ADD COLUMN slices_per_minute INT NULL COMMENT 'Drone slices the game engine ran in the last check period',
    ADD COLUMN is_full TINYINT NOT NULL DEFAULT 0 COMMENT '1: at the floor and still behind; new releases queue',
    ADD COLUMN load_checked_at BIGINT NULL COMMENT 'Real time of the last check (unix seconds)';

CREATE TABLE IF NOT EXISTS launch_queue (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    script MEDIUMTEXT NOT NULL,
    copied_from INT NULL COMMENT 'As releases.copied_from',
    copied_word VARCHAR(64) NULL,
    queued_at DATETIME NOT NULL COMMENT 'Real time',
    released_at DATETIME NULL COMMENT 'Real time; NULL while it waits',
    agent_id INT NULL COMMENT 'The drone it became',
    KEY launch_queue_waiting (released_at, id),
    KEY launch_queue_user (user_id)
);

-- The game engine's next-command query looks commands up by delivery time
CREATE INDEX commands_deliver_at ON commands (deliver_at);
