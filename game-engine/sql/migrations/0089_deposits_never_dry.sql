-- Mining sites never run dry (#866). A mine action takes what its place's
-- abundance gives, however many drones work there: round 77's new Miners
-- sat at dry deposits for most of their first 40 game days while about 350
-- drones, mostly matured companies' fleets, worked the same three sites.
-- house_rules.deposits_deplete turns the old rule back on.
DROP PROCEDURE IF EXISTS add_deposits_deplete;
DELIMITER //
CREATE PROCEDURE add_deposits_deplete()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                   AND table_name = 'house_rules' AND column_name = 'deposits_deplete') THEN
        ALTER TABLE house_rules ADD COLUMN deposits_deplete TINYINT NOT NULL DEFAULT 0
            COMMENT '1: a mine action takes from a deposit that can run dry and refills (#324); 0: never dry (#866)';
    END IF;
END //
DELIMITER ;
CALL add_deposits_deplete();
DROP PROCEDURE add_deposits_deplete;

-- Every deposit reads full again
DELETE FROM deposit_state;
