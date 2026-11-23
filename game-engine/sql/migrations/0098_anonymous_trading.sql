-- Anonymous trading and the planets (#927), part 6 of the revised system (#921).
--
-- * order_handle_key: the secret behind order handles. A drone names
--   another company's order by its handle (a keyed hash of the drone,
--   resource and side, above 1,000,000,000), never by its drone: the order
--   books (queries 600 to 615) return handles, and the game engine turns a
--   handle back into the order's drone where the acting drone is
--   (db_trade_target). Only the game's own SQL reads the key.
-- * planet_spreads: how far the planets (the House) have stepped back from
--   a good at an exchange, as players trade it among themselves there
--   (0 to 1000 thousandths; HouseMarket widens its spread by it).

CREATE TABLE IF NOT EXISTS order_handle_key (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    secret CHAR(64) NOT NULL
) ENGINE = InnoDB COMMENT 'The key behind order handles (#927): never shown to players';

INSERT IGNORE INTO order_handle_key (id, secret) VALUES (1, SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256));

CREATE TABLE IF NOT EXISTS planet_spreads (
    station_id INT UNSIGNED NOT NULL,
    resource INT NOT NULL,
    permille SMALLINT NOT NULL DEFAULT 0 COMMENT 'How far the planets have stepped back: 0 (not at all) to 1000',
    updated_t BIGINT NOT NULL COMMENT 'Game time',
    PRIMARY KEY (station_id, resource)
) ENGINE = InnoDB COMMENT 'The planets step back where players trade among themselves (#927)';
