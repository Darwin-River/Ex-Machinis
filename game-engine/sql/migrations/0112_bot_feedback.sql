-- The AI player cohort's feedback log (DV-04, #1052): each player's
-- end-of-round report (experience and recommendations) and the cohort's
-- weekly reviews, searchable by the operator. Read and written only with
-- the operator's key: nothing a player can reach shows it.
CREATE TABLE IF NOT EXISTS bot_feedback (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    operator_id INT NOT NULL COMMENT 'The operator (users.user_id)',
    user_id INT NULL COMMENT 'The Claude player; null for a cohort review',
    persona VARCHAR(16) NOT NULL COMMENT 'Its persona ID (C-01...), or cohort',
    round VARCHAR(10) NOT NULL COMMENT 'The ISO week, 2026-W48',
    kind VARCHAR(16) NOT NULL COMMENT 'report (a player) or review (the cohort)',
    experience TEXT NOT NULL,
    recommendations TEXT NOT NULL,
    ratings VARCHAR(500) NULL COMMENT 'JSON: {fun: 7, ...}, optional',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY bot_feedback_one (operator_id, persona, round, kind),
    KEY bot_feedback_round (operator_id, round)
) COMMENT 'The AI player cohort''s feedback log (#1052)';
