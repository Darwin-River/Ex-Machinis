-- Double-cell money (#324 step 9). Which of a protocol's parameters are
-- taken off the stack as doubles (two cells): bit 0 is parameter 1. The
-- double-price orders (17, 18) take their price as a double.
ALTER TABLE protocols
    ADD COLUMN double_params INT NOT NULL DEFAULT 0
        COMMENT 'Bit mask of the parameters taken as doubles (bit 0 = parameter 1)';
