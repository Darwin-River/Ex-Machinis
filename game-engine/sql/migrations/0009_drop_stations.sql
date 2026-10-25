-- House drop stations (#324 step 10). What each station trades is game data
-- (stations, station_goods: replaced by game_data.sql on every start); its
-- budget is state (station_state); players' offers and orders wait in
-- station_offers until their batch clears; station_batches records each
-- cleared batch once, so a restart never clears (or pays) twice.

CREATE TABLE IF NOT EXISTS stations (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    object_id INT NOT NULL COMMENT 'Where it is (objects.object_id)',
    budget_cap BIGINT NOT NULL DEFAULT 0 COMMENT 'Most credits it holds for buying',
    refill_per_day BIGINT NOT NULL DEFAULT 0 COMMENT 'Credits added per game day, up to the cap',
    INDEX stations_object_idx (object_id)
);

CREATE TABLE IF NOT EXISTS station_goods (
    station_id INT UNSIGNED NOT NULL,
    resource INT NOT NULL,
    side TINYINT NOT NULL COMMENT '1: the station buys it, 2: it sells it',
    price INT NOT NULL COMMENT 'Buying: the price for an empty batch (the curve halves it per daily_units); selling: the price',
    daily_units INT NOT NULL COMMENT 'Buying: daily demand (the curve); selling: units it sells per game day',
    from_day INT NOT NULL DEFAULT 0 COMMENT 'Game day (from the world''s first) it starts trading this',
    PRIMARY KEY (station_id, resource, side)
);

CREATE TABLE IF NOT EXISTS station_state (
    station_id INT UNSIGNED NOT NULL PRIMARY KEY,
    budget BIGINT NOT NULL,
    refilled_t BIGINT NOT NULL COMMENT 'Game time of its last refill'
);

CREATE TABLE IF NOT EXISTS station_offers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    station_id INT UNSIGNED NOT NULL,
    side TINYINT NOT NULL COMMENT '1: an offer to sell to the station, 2: an order to buy from it',
    resource INT NOT NULL,
    units INT NOT NULL,
    drone INT NOT NULL,
    company_id INT NOT NULL,
    action_id INT NOT NULL,
    batch_t BIGINT NOT NULL COMMENT 'Game time its batch clears',
    filled INT NULL COMMENT 'Units bought or delivered (NULL until cleared)',
    price INT NULL,
    paid BIGINT NULL COMMENT 'Credits paid to (offers) or by (orders) the company',
    cleared_t BIGINT NULL,
    INDEX station_offers_batch_idx (batch_t, station_id, resource, side),
    INDEX station_offers_drone_idx (drone)
);

CREATE TABLE IF NOT EXISTS station_batches (
    station_id INT UNSIGNED NOT NULL,
    resource INT NOT NULL,
    side TINYINT NOT NULL,
    batch_t BIGINT NOT NULL,
    units INT NOT NULL COMMENT 'Offered or ordered in all',
    filled INT NOT NULL,
    price INT NOT NULL,
    cleared_t BIGINT NOT NULL,
    PRIMARY KEY (station_id, resource, side, batch_t)
);
