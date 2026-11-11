-- Broadcasts (#380): a drone posts a short message (protocol 40); every
-- drone can read it once light has carried it from where it was sent to
-- where the reader is (queries 690 and 691).
CREATE TABLE IF NOT EXISTS broadcasts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action_id INT UNSIGNED NULL,
    drone INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NULL,
    object_id INT NULL,
    game_t BIGINT NOT NULL,
    body VARCHAR(80) NOT NULL,
    KEY broadcasts_game_t (game_t),
    KEY broadcasts_company (company_id, game_t)
);
