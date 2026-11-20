-- Winding up a stalled company (#875): a matured company that has sold
-- nothing for 60 game days ends, its credits shared out to its shareholders.
-- What each wind-up paid, a row a company.
CREATE TABLE IF NOT EXISTS company_windups (
    company_id INT UNSIGNED NOT NULL,
    game_t BIGINT NOT NULL COMMENT 'Game time it was wound up',
    per_share BIGINT NOT NULL COMMENT 'Credits each share was paid',
    shares INT NOT NULL COMMENT 'Its shares at the time, the bank''s included',
    credits BIGINT NOT NULL COMMENT 'What it held, less processor tax owed',
    PRIMARY KEY (company_id)
);
