-- Settle pending events that no action will ever run (#731). The Event
-- Engine's batch query takes events at outcome 0 whose action's aborted
-- flag is not 1; for an event with no action, an action that is gone, or
-- one whose flag is NULL, that test is NULL, so the event is skipped, but
-- walked past on every batch. After 0070 settled the aborted actions'
-- events, the batch still examined about 148,000 rows a call on the live
-- database. These are marked -9 (aborted) like the others. In chunks; safe
-- to run again.
DROP PROCEDURE IF EXISTS settle_orphan_events;
DELIMITER //
CREATE PROCEDURE settle_orphan_events()
BEGIN
    DECLARE n INT DEFAULT 1;
    WHILE n > 0 DO
        UPDATE events AS e
        SET e.outcome = -9
        WHERE e.outcome = 0
          AND (e.action IS NULL
               OR NOT EXISTS (SELECT 1 FROM actions AS x WHERE x.id = e.action AND x.aborted IS NOT NULL))
        LIMIT 20000;
        SET n = ROW_COUNT();
    END WHILE;
END //
DELIMITER ;
CALL settle_orphan_events();
DROP PROCEDURE settle_orphan_events;
