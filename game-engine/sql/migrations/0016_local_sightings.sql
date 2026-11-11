-- Local sightings without a row per drone (#324 step 20, follow-up). An
-- observable event was copied into observations once for every drone at its
-- place, so the table grew as events x drones (12 million rows in 12 real
-- minutes with 1,000 drones at Earth). Now an observable event records where
-- it happened (events.place), each drone when it reached where it is
-- (agents.arrived_t), and a drone sees the events at its current place since
-- it arrived, worked out when asked. observations keeps the acting and
-- affected drones' rows and the Central Database's light-delayed ones.
ALTER TABLE events
    ADD COLUMN place INT NULL COMMENT 'Where an observable event happened (object_id), for local sightings',
    ADD KEY events_place_game_t (place, game_t);

ALTER TABLE agents
    ADD COLUMN arrived_t BIGINT NULL COMMENT 'Game time it reached its current location (object_id)';

-- Drones that already exist have been where they are since now
UPDATE agents SET arrived_t = (SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale)
                               FROM game_clock WHERE id = 1)
WHERE arrived_t IS NULL;
UPDATE agents SET arrived_t = UNIX_TIMESTAMP() WHERE arrived_t IS NULL;

-- Kept up to date here, so every way a drone is made or moved (engines,
-- website, scripts) sets it
DROP TRIGGER IF EXISTS agents_arrived_insert;
DROP TRIGGER IF EXISTS agents_arrived_update;

DELIMITER //
CREATE TRIGGER agents_arrived_insert BEFORE INSERT ON agents FOR EACH ROW
BEGIN
    IF NEW.arrived_t IS NULL THEN
        SET NEW.arrived_t = (SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale)
                             FROM game_clock WHERE id = 1);
        IF NEW.arrived_t IS NULL THEN
            SET NEW.arrived_t = UNIX_TIMESTAMP();  -- no clock yet: game time is real time
        END IF;
    END IF;
END //

CREATE TRIGGER agents_arrived_update BEFORE UPDATE ON agents FOR EACH ROW
BEGIN
    IF NOT (NEW.object_id <=> OLD.object_id) THEN
        SET NEW.arrived_t = (SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale)
                             FROM game_clock WHERE id = 1);
        IF NEW.arrived_t IS NULL THEN
            SET NEW.arrived_t = UNIX_TIMESTAMP();
        END IF;
    END IF;
END //
DELIMITER ;
