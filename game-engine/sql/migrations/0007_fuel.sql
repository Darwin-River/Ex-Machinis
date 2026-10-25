-- Fuel (#324 step 8). Every action burns fuel (game data), and running a
-- drone's computer burns power: the game engine burns 1 fuel per
-- POWER_INSTRUCTIONS instructions. power_paid is how many of the drone's
-- instructions have been paid for so far.
ALTER TABLE agents
    ADD COLUMN power_paid BIGINT NOT NULL DEFAULT 0
        COMMENT 'Instructions already paid for in fuel (agents.instructions minus this is owed)',
    ADD COLUMN wreck_cause VARCHAR(16) NULL
        COMMENT 'Why the drone became a wreck: crashes or fuel';

-- What drones ran before fuel existed is free
UPDATE agents SET power_paid = instructions;
UPDATE agents SET wreck_cause = 'crashes' WHERE wrecked_at IS NOT NULL AND wreck_cause IS NULL;
