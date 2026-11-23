-- The House's narrower spread on made goods (#964, a tuning change per
-- DV-03). The change itself is game data (BID_SHARES and ASK_MARKUP in
-- game-engine/sql/game-data/build_game_data.py), loaded on every start; this
-- migration changes nothing, and is here so that the world-event mark
-- 'made-goods-spread' (config/world-events.php) is dated by when the
-- server applied it.
SELECT 1;
