-- The previous event of a drone's holding by game time (#431): the events
-- engine asks, for every inventory event it settles, for the drone's latest
-- successful increment or depletion of that resource and kind up to the
-- event's time (PREVIOUS_EVENT_BY_RESOURCE_INFO, ordered by game_t, id).
-- events_inventory ends in event_type, so MySQL read every such event the
-- drone ever had and sorted them (566 for a station drone's fuel, ~2.6 ms
-- warm, seconds cold). This index ends in game_t (then the id), so it is
-- one step back. The engine names it (FORCE INDEX).
SET @have := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'events' AND index_name = 'events_inventory_time');
SET @sql := IF(@have = 0,
    'CREATE INDEX events_inventory_time ON events (drone, resource, installed, locked, outcome, game_t)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
