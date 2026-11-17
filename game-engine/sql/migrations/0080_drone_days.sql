-- The game's history within a disk budget (#813, after the outage in #811).
-- HistoryBudget (on the website's keeper) deletes the oldest archived
-- events, and their observations, whenever the disk is fuller than
-- forge.history_budget_percent (70). Before it does, each drone's pruned
-- events become one row a game day here, so a drone's page and a hull's age
-- still know about that time. The engines never read events_archive (queries
-- 803 and 804 read events alone), so pruning it doesn't change the game.
CREATE TABLE IF NOT EXISTS drone_days (
    drone INT UNSIGNED NOT NULL,
    day INT NOT NULL COMMENT 'Game days since the world began',
    events INT NOT NULL DEFAULT 0,
    failed INT NOT NULL DEFAULT 0 COMMENT 'Events that failed (outcome < 0)',
    actions INT NOT NULL DEFAULT 0 COMMENT 'Actions they came from (counted per pruned batch)',
    first_t BIGINT NULL,
    last_t BIGINT NULL,
    PRIMARY KEY (drone, day)
);
