-- Crowded mining sites yield less (#974, a tuning change per DV-03). A
-- mining run still never comes up dry (#866), but when more drones than a
-- site's capacity mined the same mineral there in the last game hour, each
-- run yields its abundance x capacity / crowd, at least 1 unit. A startup's
-- drone counts as half a drone, so a newcomer at a busy site loses less.
--
-- house_rules.crowd_capacity: drones a site's mineral serves at full yield
-- (0: crowding off); crowd_startup_permille: a startup drone's weight, in
-- thousandths of a drone. Set here as column defaults, since the game data
-- rewrites house_rules with its own columns only.
--
-- mining_runs: each drone's latest run of each mineral at each place, with
-- its weight. The engine records a run, sums the weights of the runs in the
-- last game hour, and drops that site's older rows, so the table holds only
-- the drones mining now.

SET @has := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'house_rules' AND column_name = 'crowd_capacity');
SET @sql := IF(@has = 0,
    'ALTER TABLE house_rules ADD COLUMN crowd_capacity INT NOT NULL DEFAULT 10 COMMENT ''Drones a site''''s mineral serves at full yield in a game hour; past it each run yields abundance x capacity / crowd, at least 1 (#974); 0: off'', '
    'ADD COLUMN crowd_startup_permille INT NOT NULL DEFAULT 500 COMMENT ''A startup drone''''s weight in the crowd, in thousandths of a drone (#974)''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS mining_runs (
    object_id INT NOT NULL COMMENT 'Where it mined',
    resource INT NOT NULL COMMENT 'The mineral',
    drone INT UNSIGNED NOT NULL COMMENT 'The drone that mined',
    game_t BIGINT NOT NULL COMMENT 'Game time of its latest run there',
    weight INT NOT NULL DEFAULT 1000 COMMENT 'Its weight in the crowd, in thousandths of a drone',
    PRIMARY KEY (object_id, resource, drone),
    KEY mining_runs_site_time (object_id, resource, game_t)
) ENGINE = InnoDB COMMENT 'Drones mining each mineral at each place in the last game hour (#974)';
