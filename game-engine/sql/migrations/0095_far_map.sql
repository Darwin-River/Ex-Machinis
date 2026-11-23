-- Resources by place (#922). Metals (A3) come only from far away: the
-- minerals that refine to them are found only beyond the Earth and Mars
-- systems and the inner planets, on a few bodies at a time (their homes),
-- and the homes move each season (every 4 real weeks), the next season's
-- published a real week ahead.
--
-- The game data (build_game_data.py) loads far_minerals (how many homes each
-- such mineral has and how rich they are) and far_homes (every body it can
-- be found on), and leaves those minerals out of abundancies and deposits.
-- The website (App\Services\FarMap) picks each season's homes into far_map,
-- and adds the current season's to abundancies and deposits, at start-up
-- (after the game data is loaded) and on its keeper.
--
-- Only adds: four tables.

CREATE TABLE IF NOT EXISTS far_minerals (
    resource INT NOT NULL PRIMARY KEY,
    homes INT NOT NULL COMMENT 'How many bodies it is found on at a time',
    multiplier INT NOT NULL COMMENT 'Its abundance where it is found'
) ENGINE = InnoDB COMMENT 'Minerals found only far away, in homes that move each season (#922); game data';

CREATE TABLE IF NOT EXISTS far_homes (
    resource INT NOT NULL,
    object_id INT NOT NULL,
    PRIMARY KEY (resource, object_id)
) ENGINE = InnoDB COMMENT 'Every far body a far mineral can be found on (#922); game data';

CREATE TABLE IF NOT EXISTS far_seasons (
    season INT NOT NULL PRIMARY KEY,
    starts_at DATETIME NOT NULL COMMENT 'Real time',
    ends_at DATETIME NOT NULL COMMENT 'Real time'
) ENGINE = InnoDB COMMENT 'The far map''s seasons (#922)';

CREATE TABLE IF NOT EXISTS far_map (
    season INT NOT NULL,
    resource INT NOT NULL,
    object_id INT NOT NULL,
    multiplier INT NOT NULL,
    PRIMARY KEY (season, resource, object_id)
) ENGINE = InnoDB COMMENT 'Where each far mineral is found in each season, the current one and the next (#922)';
