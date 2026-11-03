-- Markets that move (#380 round 36): every newcomer found the House paid
-- the same 25 to 33 credits a factory-hour for any product, wherever and
-- whenever, so choosing what to make, and where, hardly mattered.
-- Now each exchange's demand (what the House buys: its bid and how much)
-- and supply (what it sells: its price and how much) move over game time,
-- each exchange in its own way:
-- - stations.swing_permille and cycle_days: a slow wave by exchange, tier
--   (a resource's first digit) and side, about cycle_days long (each tier's
--   own length and phase, from a hash), and a smaller, faster one by good;
-- - stations.shock_permille: in each 8-game-day block, one tier and side in
--   four (by hash) has a boom or a slump that rises and falls over the block.
-- Fuel, the hull and the kits keep their prices (station_goods.steady).
-- The game data (game_data.sql) fills these in; the views below work out
-- the prices now, and App\Services\HouseMarket posts the station drones'
-- orders from them.

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'stations' AND column_name = 'swing_permille');
SET @sql := IF(@have = 0,
    'ALTER TABLE stations ADD COLUMN swing_permille INT NOT NULL DEFAULT 0 COMMENT ''How far its prices swing with the slow wave (thousandths)'', ADD COLUMN cycle_days DOUBLE NOT NULL DEFAULT 12 COMMENT ''About how long a wave takes (game days)'', ADD COLUMN shock_permille INT NOT NULL DEFAULT 0 COMMENT ''How far a boom or slump moves a price (thousandths)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'station_goods' AND column_name = 'steady');
SET @sql := IF(@have = 0,
    'ALTER TABLE station_goods ADD COLUMN steady TINYINT NOT NULL DEFAULT 0 COMMENT ''1: its price never moves (fuel, the hull, the kits)'', ADD INDEX station_goods_resource_idx (resource, side)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The station program a station drone runs (its md5): forge:stations gives
-- a station drone the new program when station.fth changes
SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'agents' AND column_name = 'program_md5');
SET @sql := IF(@have = 0,
    'ALTER TABLE agents ADD COLUMN program_md5 CHAR(32) NULL COMMENT ''House station drones: the md5 of the station program it runs''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Each good's factor now (prices move every half game day): 1 plus the
-- tier's wave, the good's own smaller wave and any boom (+) or slump (-),
-- never under 0.5. shock is the boom or slump alone (the news).
CREATE OR REPLACE VIEW house_market_raw AS
SELECT x.station_id, x.resource, x.side, x.base_price, x.base_units, x.from_day, x.steady, x.day,
       IF(x.steady = 1, 0, x.shock) AS shock,
       IF(x.steady = 1, 1, GREATEST(0.5, 1 + x.wave + x.shock)) AS factor
FROM (
    SELECT g.station_id, g.resource, g.side, g.price AS base_price, g.daily_units AS base_units,
           g.from_day, g.steady, t.day,
           s.swing_permille / 1000 * SIN(2 * PI() * (t.day / (s.cycle_days * (0.75 + MOD(h.tier_hash, 50) / 100))
                                                   + MOD(FLOOR(h.tier_hash / 50), 1000) / 1000))
         + s.swing_permille / 3000 * SIN(2 * PI() * (t.day / (s.cycle_days * (0.4 + MOD(h.good_hash, 30) / 100))
                                                   + MOD(FLOOR(h.good_hash / 30), 1000) / 1000)) AS wave,
           IF(MOD(h.block_hash, 4) = 0,
              s.shock_permille / 1000 * IF(MOD(FLOOR(h.block_hash / 4), 3) = 0, -1, 1)
                  * SIN(PI() * (t.day / 8 - FLOOR(t.day / 8))),
              0) AS shock
    FROM station_goods g
    JOIN stations s ON s.id = g.station_id
    CROSS JOIN (SELECT IFNULL((SELECT FLOOR((c.game_anchor + (UNIX_TIMESTAMP() - c.real_anchor) * c.scale - IFNULL(c.epoch, 0)) / 43200) / 2
                               FROM game_clock c WHERE c.id = 1), 0) AS day) t
    CROSS JOIN LATERAL (
        SELECT CONV(LEFT(MD5(CONCAT(g.station_id, '/', FLOOR(g.resource / 1000), '/', g.side)), 8), 16, 10) AS tier_hash,
               CONV(LEFT(MD5(CONCAT(g.station_id, '/', g.resource, '/', g.side)), 8), 16, 10) AS good_hash,
               CONV(LEFT(MD5(CONCAT(g.station_id, '/', FLOOR(g.resource / 1000), '/', g.side, '/', FLOOR(t.day / 8))), 8), 16, 10) AS block_hash
    ) h
) x;

-- The prices and daily units now. What the House buys: its bid and demand
-- both rise with the factor (a boom: more wanted, at more). What it sells:
-- its price rises and its supply falls (a shortage), or the other way (a
-- glut). A bid never tops what any exchange asks for the same good, so no
-- loop runs through the House alone. Prices fit one cell (under 32,768).
CREATE OR REPLACE VIEW house_market AS
SELECT r.station_id, r.resource, r.side, r.base_price, r.base_units, r.from_day, r.steady, r.day,
       r.factor, r.shock,
       IF(r.side = 2,
          LEAST(32767, GREATEST(1, ROUND(r.base_price * r.factor))),
          GREATEST(1, LEAST(32767, ROUND(r.base_price * r.factor),
              IFNULL((SELECT MIN(LEAST(32767, GREATEST(1, ROUND(a.base_price * a.factor))))
                      FROM house_market_raw a WHERE a.resource = r.resource AND a.side = 2), 32767)))) AS price,
       IF(r.side = 2,
          GREATEST(1, ROUND(r.base_units / r.factor)),
          GREATEST(1, ROUND(r.base_units * r.factor))) AS daily_units
FROM house_market_raw r;

-- Net wealth over time (#380 round 36: players kept their own notes): each
-- company's credits and net wealth once a game day, taken on the website's
-- tick (App\Services\Leaderboard::dailyIfDue). The last 60 game days are
-- kept day by day; older ones every 10th day.
CREATE TABLE IF NOT EXISTS company_days (
    company_id INT NOT NULL,
    day INT NOT NULL COMMENT 'Game day (from the world''s first)',
    credits BIGINT NOT NULL,
    net_wealth BIGINT NOT NULL,
    drones INT NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, day),
    INDEX company_days_day_idx (day)
);
