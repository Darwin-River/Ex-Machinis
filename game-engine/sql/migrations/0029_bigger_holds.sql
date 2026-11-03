-- Bigger holds (#380 round 37): a new drone's hull (1, the Mosquitto) and
-- the Cruisers carry 1,000 instead of 500. With the mining gear unpacked and
-- a tank of fuel, a miner had about 100 free and a trader 60 metals a trip,
-- with no room for the fuel a long trip needs. The Cruisers' rows also come
-- from game_data.sql (build_game_data.py's HULL_SIZES); hull 1 is only here.
UPDATE hulls SET cargo_capacity = 1000 WHERE id = 1 AND cargo_capacity < 1000;
UPDATE hulls SET cargo_capacity = 1000 WHERE name LIKE '%Cruiser%' AND cargo_capacity < 1000;
