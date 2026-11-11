-- Mining deposits (#324 step 11). Each mineral at each place is a deposit
-- that holds a set amount, runs out as it is mined and refills slowly.
-- What each deposit is (deposits) is game data, replaced on every start;
-- how much it has left (deposit_state) is state. The amount is refilled
-- lazily: whenever it is read, by refill_per_day for the game time since.
CREATE TABLE IF NOT EXISTS deposits (
    object_id INT NOT NULL,
    resource INT NOT NULL,
    capacity INT NOT NULL COMMENT 'Most it holds (a new deposit starts full)',
    refill_per_day INT NOT NULL COMMENT 'Units it regains per game day, up to its capacity',
    PRIMARY KEY (object_id, resource)
);

CREATE TABLE IF NOT EXISTS deposit_state (
    object_id INT NOT NULL,
    resource INT NOT NULL,
    amount DOUBLE NOT NULL COMMENT 'Left at refilled_t (fractions of a unit carry over)',
    refilled_t BIGINT NOT NULL COMMENT 'Game time amount was worked out',
    PRIMARY KEY (object_id, resource)
);
