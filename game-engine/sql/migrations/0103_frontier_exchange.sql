-- The frontier exchange, Ceres Station (#973, a tuning change per DV-03).
-- The exchange itself is game data (STATIONS and its goods in
-- game-engine/sql/game-data/build_game_data.py), and its station drone is
-- made by php artisan forge:stations when the app starts; this migration
-- changes nothing, and is here so that the world-event mark
-- 'frontier-exchange' (config/world-events.php) is dated by when the
-- server applied it.
SELECT 1;
