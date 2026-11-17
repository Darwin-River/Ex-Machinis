-- Tools in use without four events a use (#814, after the outage in #811).
-- A mine, refine or make moved its tool from unlocked to locked and back:
-- four inventory events a use, a quarter of the game data's effects, every
-- one stored, indexed, archived and pruned. Now an action writes two events
-- that leave no history: lock tool (15) and unlock tool (16). Lock tool
-- succeeds when the drone's unlocked units of the tool, less its locks in
-- force, cover it (else -2, as before) and adds a row here; unlock tool
-- takes the action's row away; an aborted action loses its rows. A
-- decrement of an unlocked holding fails -2 if it would leave fewer units
-- than the locks on it, so a tool in use can't be sold, given or scrapped.
-- Settled lock and unlock events are deleted by the archiver's pass, not
-- archived. Old actions' four-event locks still settle as they always did.
CREATE TABLE IF NOT EXISTS tool_locks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    drone INT UNSIGNED NOT NULL,
    resource INT UNSIGNED NOT NULL,
    installed TINYINT NOT NULL DEFAULT 0,
    units INT NOT NULL,
    action INT UNSIGNED NOT NULL,
    game_t BIGINT NULL,
    INDEX tool_locks_holding (drone, resource, installed),
    INDEX tool_locks_action (action)
);

INSERT INTO event_types (id, name) VALUES (15, 'Lock tool'), (16, 'Unlock tool')
    ON DUPLICATE KEY UPDATE name = VALUES(name);
