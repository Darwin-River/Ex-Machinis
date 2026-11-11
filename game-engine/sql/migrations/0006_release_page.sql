-- Release page (#324 step 6). Invite-only accounts post a script on the
-- website; with email switched off, drones' replies are kept here instead of
-- being emailed, and the website shows them.

-- Invitation codes: an account can only be opened with an unused one when
-- the world is invite-only
CREATE TABLE IF NOT EXISTS invites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    note VARCHAR(255) NULL COMMENT 'Who the invitation is for',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_by INT UNSIGNED NULL COMMENT 'users.user_id of the account opened with it',
    used_at TIMESTAMP NULL,
    UNIQUE KEY invites_code_unique (code)
);

-- A drone's replies when email is switched off (EMAIL_ENABLED = 0). A reply
-- reaches Earth at the speed of light: the website shows it once game time
-- reaches game_t.
CREATE TABLE IF NOT EXISTS drone_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    game_t BIGINT NOT NULL COMMENT 'Game time the reply reaches Earth',
    subject VARCHAR(2048) NULL,
    body LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX drone_messages_agent_idx (agent_id, game_t)
);
