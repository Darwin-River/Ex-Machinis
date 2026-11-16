-- Settle the events of aborted actions (#725). An action aborted part way
-- left its later events at outcome 0, so they counted as pending for good;
-- the Event Engine's batch query skips them (their action is aborted) but
-- walked past every one, in game time order, on each batch. On the live
-- database that was 328,000 rows a batch, 0.9 s, about half of MySQL's
-- time, and it grew with every abort. They are marked -9 (aborted), as the
-- Event Engine marks such an event when it meets one, and db_abort_action
-- now marks them when it aborts the action. In chunks, so no one statement
-- holds the events table for long. Safe to run again.
DROP PROCEDURE IF EXISTS settle_aborted_events;
DELIMITER //
CREATE PROCEDURE settle_aborted_events()
BEGIN
    DECLARE n INT DEFAULT 1;
    WHILE n > 0 DO
        UPDATE events SET outcome = -9
        WHERE outcome = 0 AND action IN (SELECT id FROM actions WHERE aborted = 1)
        LIMIT 20000;
        SET n = ROW_COUNT();
    END WHILE;
END //
DELIMITER ;
CALL settle_aborted_events();
DROP PROCEDURE settle_aborted_events;
