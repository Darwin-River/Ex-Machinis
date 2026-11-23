-- The House's prices of #964 (a narrower spread on made goods) and #963
-- (the Moon Depot pays full price for ores and refined goods), tuning
-- changes per DV-03. The changes themselves are game data (BID_SHARES,
-- ASK_MARKUP and BID_PLACE_FACTOR in game-engine/sql/game-data/
-- build_game_data.py), loaded on every start; this migration changes
-- nothing, and is here so that the world-event marks 'made-goods-spread'
-- and 'moon-full-price' (config/world-events.php) are dated by when the
-- server applied it.
SELECT 1;
