-- Several game engines (#433): each shard reports its drone turns a minute
-- here once a minute, and shard 0 adds them up to set the clock's pace and
-- to record the load (game_clock.slices_per_minute).
CREATE TABLE IF NOT EXISTS engine_shards (
    shard INT NOT NULL PRIMARY KEY,
    turns_per_minute INT NOT NULL,
    checked_at BIGINT NOT NULL
) ENGINE = InnoDB;
