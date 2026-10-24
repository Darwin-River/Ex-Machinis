-- Entry point and crash handling (#324 step 5). A launched drone runs its
-- launched word inside catch. When it crashes, it restarts from the clean
-- copy of its program saved when it was first launched, after a wait that
-- doubles with each crash in a row. Too many crashes in a row make it a wreck.
ALTER TABLE agents
    ADD COLUMN release_vm MEDIUMBLOB NULL
        COMMENT 'Clean copy of the VM saved when the drone was first launched; a crash restarts from it',
    ADD COLUMN launched_running TINYINT NOT NULL DEFAULT 0
        COMMENT '1 while the launched word runs (inside catch)',
    ADD COLUMN crashes_in_row INT NOT NULL DEFAULT 0
        COMMENT 'Crashes since the last action that succeeded',
    ADD COLUMN crashes_total INT NOT NULL DEFAULT 0,
    ADD COLUMN wrecked_at BIGINT NULL
        COMMENT 'Game time the drone became a wreck (crashed too often in a row)';

-- Every crash, for post-mortems
CREATE TABLE IF NOT EXISTS drone_crashes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    drone INT NOT NULL,
    game_t BIGINT NOT NULL COMMENT 'Game time of the crash',
    code INT NOT NULL COMMENT 'The error (throw) code caught around launched',
    in_row INT NOT NULL COMMENT 'Crashes in a row, this one included',
    restart_at BIGINT NULL COMMENT 'Game time of the restart; NULL when the drone became a wreck',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX drone_crashes_drone_idx (drone, game_t)
);
