-- Badges (#1048): recognition for what drones, companies and players
-- achieve, awarded from the game's own records on the website's tick, with
-- no advantage in the game. One row an award: what earned it (kind and its
-- id), which badge and which tier (1 bronze, 2 silver, 3 gold; 1 for a
-- badge without tiers), and the game time it was awarded.
CREATE TABLE IF NOT EXISTS badges (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(8) NOT NULL COMMENT 'drone, company or player',
    subject_id INT UNSIGNED NOT NULL COMMENT 'agents.agent_id, companies.company_id or users.user_id',
    badge VARCHAR(32) NOT NULL COMMENT 'Badges::CATALOG key',
    tier TINYINT UNSIGNED NOT NULL DEFAULT 1,
    game_t BIGINT NOT NULL COMMENT 'Game time it was awarded',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY badges_once (kind, subject_id, badge, tier),
    KEY badges_recent (created_at)
) COMMENT 'Badges awarded (#1048): recognition only';
