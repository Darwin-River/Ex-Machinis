-- Where code comes from (#324 step 16). Every released script is kept, so
-- the Drone page can show a drone's code (a clone shows the code of the
-- release it descends from). "Copy this code" and "Copy this word" record
-- the drone they copied from; a pasted copy is found by its normalised
-- source hash (comments and spacing removed), and each word by its own.
CREATE TABLE IF NOT EXISTS releases (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL COMMENT 'The drone the release started',
    company_id INT NOT NULL,
    user_id INT NOT NULL,
    source MEDIUMTEXT NOT NULL COMMENT 'The script as released',
    source_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the normalised source',
    copied_from INT NULL COMMENT 'Drone whose code the player copied (Copy this code or word)',
    copied_word VARCHAR(64) NULL COMMENT 'The word they copied, when it was one word',
    same_as INT NULL COMMENT 'The earliest release by another player with the same normalised source',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY releases_agent (agent_id),
    KEY releases_hash (source_hash)
);

CREATE TABLE IF NOT EXISTS release_words (
    release_id INT NOT NULL,
    name VARCHAR(64) NOT NULL,
    word_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the normalised definition',
    PRIMARY KEY (release_id, name),
    KEY release_words_hash (word_hash)
);
