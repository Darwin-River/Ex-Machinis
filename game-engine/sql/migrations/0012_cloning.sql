-- Cloning (#324 step 13). A drone that launches a new drone is its parent:
-- the website shows a drone's parent and clones, and generation counts how
-- many launches back its line goes (0 for a drone released or registered).
-- code_from is the drone whose program the clone got.
-- A company ends when its last drone dies, as well as when it can't pay its
-- tax: end_cause says which.
ALTER TABLE agents
    ADD COLUMN parent_id INT NULL COMMENT 'The drone that launched this one',
    ADD COLUMN generation INT NOT NULL DEFAULT 0 COMMENT 'Launches back to a released or registered drone',
    ADD COLUMN code_from INT NULL COMMENT 'The drone whose program it got at launch';

ALTER TABLE companies
    ADD COLUMN end_cause VARCHAR(16) NULL COMMENT 'Why it ended: tax or drones (its last drone died)';
