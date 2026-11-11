-- Instruction metering (#324 step 3): the total number of FORTH instructions
-- each drone's script has run, plus the flat costs of its queries and actions.
-- Power (fuel) will be charged on it.
ALTER TABLE agents ADD COLUMN instructions BIGINT NOT NULL DEFAULT 0;
