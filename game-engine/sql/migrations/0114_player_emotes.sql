-- How players feel about the game (#1062): each emote a player or visitor
-- submits from the menu bar's emote button, with an optional comment and
-- the game time, kept for analysis. Read only by the operator; nothing a
-- player sees lists other people's emotes.
CREATE TABLE IF NOT EXISTS player_emotes (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT 'The player (users.user_id); null for a visitor',
    player_name VARCHAR(255) NULL COMMENT 'The player''s login when submitted; null for a visitor',
    visitor VARCHAR(36) NULL COMMENT 'An anonymous token kept in the browser, so one visitor''s emotes can be followed',
    emote VARCHAR(16) NOT NULL COMMENT 'Its key: happy, excited, ...',
    emoji VARCHAR(16) NOT NULL COMMENT 'The emoji shown',
    comment VARCHAR(1000) NULL COMMENT 'Why, in the player''s words; optional',
    game_t BIGINT NOT NULL COMMENT 'The game time it was submitted',
    created_at TIMESTAMP NULL,
    KEY player_emotes_user (user_id, id),
    KEY player_emotes_created (created_at)
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT 'How players feel about the game, from the emote button (#1062)';
