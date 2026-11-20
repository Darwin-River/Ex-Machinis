-- Downtime doesn't count (#837). On 2 May 2038 every small matured company
-- built from an unchanged starter dissolved in the same minute: the world had
-- stood still for about 100 game days (no drone acted), but game time runs on
-- the wall clock, so the 50-day dissolve timer kept counting and the next
-- bill after the stall ended them all. Each hourly bill now checks whether
-- any drone acted since the last (a new event); an hour in which none did is
-- downtime: broke companies' timers move forward by it, and it is kept here
-- so the wind-up (60 game days without a sale) leaves it out too.
ALTER TABLE compute_clock ADD COLUMN last_event_id BIGINT UNSIGNED NULL
    COMMENT 'The newest event id at the last bill: none newer by the next means the world stood still that hour (#837)';
CREATE TABLE IF NOT EXISTS world_stalls (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    from_t BIGINT NOT NULL COMMENT 'Game time the stall began (the last bill with activity)',
    to_t BIGINT NOT NULL COMMENT 'Game time it ended, so far',
    INDEX world_stalls_to_t (to_t)
);
