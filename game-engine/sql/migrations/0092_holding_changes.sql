-- Holdings in the game engine's memory (#694 stage 4 step 5, #431,
-- MEMORY_STATE=1). The game engine keeps a copy of `holdings` and answers
-- units() (query 212) from it. To stay complete it must see every change,
-- whoever makes it: settlement (the events triggers, keep_holding and
-- refresh_holding), the website (House orders, plundering, a startup's
-- wind-up, a world reset) and any procedure or hand-made statement.
--
-- So triggers on `holdings` itself write each change to `holding_changes`,
-- in the writer's own transaction: the row's key and its units after the
-- change (NULL: deleted). The engine reads the ids past the highest it has
-- seen, as WAKE_HEAP and EVENT_HEAP read theirs. A change that leaves the
-- units as they were (a newer event with the same count, or an older one
-- that keep_holding ignores) writes nothing.
--
-- The triggers write only while `engine_feeds` says so: a game engine with
-- MEMORY_STATE=1 turns the feed on when it starts, and shard 0 with
-- MEMORY_STATE=0 turns it off and empties the table, so with the flag off
-- nothing grows. The flag is read FOR SHARE, so the engine's switch waits
-- for writers that read it before, and every writer after it sees it.
-- While it is on, shard 0 deletes changes over two real minutes old.
--
-- Only adds: a table, a one-row-per-feed table and three triggers. Rolling
-- back is MEMORY_STATE=0 and a restart; the triggers then write nothing.

CREATE TABLE IF NOT EXISTS holding_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    drone INT UNSIGNED NOT NULL,
    resource INT UNSIGNED NOT NULL,
    installed TINYINT NOT NULL,
    locked TINYINT NOT NULL,
    units INT NULL COMMENT 'The units after the change; NULL: the holdings row was deleted'
) ENGINE = InnoDB COMMENT 'Changes to holdings, for the game engine''s copy in memory (MEMORY_STATE, #694)';

CREATE TABLE IF NOT EXISTS engine_feeds (
    name VARCHAR(32) NOT NULL PRIMARY KEY,
    enabled TINYINT NOT NULL DEFAULT 0 COMMENT '1: the triggers write the feed (a game engine reads it)',
    changed_at DATETIME NULL
) ENGINE = InnoDB COMMENT 'Which change feeds the triggers write (#694 stage 4 step 5)';

INSERT IGNORE INTO engine_feeds (name, enabled) VALUES ('holdings', 0);

DROP TRIGGER IF EXISTS holdings_feed_insert;
DROP TRIGGER IF EXISTS holdings_feed_update;
DROP TRIGGER IF EXISTS holdings_feed_delete;

DELIMITER //

CREATE TRIGGER holdings_feed_insert AFTER INSERT ON holdings FOR EACH ROW
BEGIN
    DECLARE feed TINYINT;
    SELECT enabled INTO feed FROM engine_feeds WHERE name = 'holdings' FOR SHARE;
    IF feed = 1 THEN
        INSERT INTO holding_changes (drone, resource, installed, locked, units)
            VALUES (NEW.drone, NEW.resource, NEW.installed, NEW.locked, NEW.units);
    END IF;
END //

CREATE TRIGGER holdings_feed_update AFTER UPDATE ON holdings FOR EACH ROW
BEGIN
    DECLARE feed TINYINT;
    DECLARE same_key BOOLEAN;
    SET same_key = OLD.drone = NEW.drone AND OLD.resource = NEW.resource
                   AND OLD.installed = NEW.installed AND OLD.locked = NEW.locked;
    IF NOT (same_key AND OLD.units = NEW.units) THEN
        SELECT enabled INTO feed FROM engine_feeds WHERE name = 'holdings' FOR SHARE;
        IF feed = 1 THEN
            IF NOT same_key THEN
                INSERT INTO holding_changes (drone, resource, installed, locked, units)
                    VALUES (OLD.drone, OLD.resource, OLD.installed, OLD.locked, NULL);
            END IF;
            INSERT INTO holding_changes (drone, resource, installed, locked, units)
                VALUES (NEW.drone, NEW.resource, NEW.installed, NEW.locked, NEW.units);
        END IF;
    END IF;
END //

CREATE TRIGGER holdings_feed_delete AFTER DELETE ON holdings FOR EACH ROW
BEGIN
    DECLARE feed TINYINT;
    SELECT enabled INTO feed FROM engine_feeds WHERE name = 'holdings' FOR SHARE;
    IF feed = 1 THEN
        INSERT INTO holding_changes (drone, resource, installed, locked, units)
            VALUES (OLD.drone, OLD.resource, OLD.installed, OLD.locked, NULL);
    END IF;
END //

DELIMITER ;
