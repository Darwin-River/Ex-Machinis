-- The account trading script (#768): one Lua script a player account may
-- run on the share market, every few real minutes, through the website's
-- keeper (App\Services\TradeScripts), in the lua-trade sandbox.

CREATE TABLE IF NOT EXISTS player_trade_scripts (
    user_id INT UNSIGNED NOT NULL,
    code MEDIUMTEXT NOT NULL,
    enabled TINYINT NOT NULL DEFAULT 0 COMMENT 'Runs only when switched on',
    memory MEDIUMTEXT NULL COMMENT 'What the script kept last run: a Lua chunk returning a table',
    last_run_t BIGINT NULL COMMENT 'Game time of its last run',
    last_real_t BIGINT NULL COMMENT 'Real (Unix) time of its last run',
    runs INT NOT NULL DEFAULT 0,
    log MEDIUMTEXT NULL COMMENT 'Its last runs: JSON lines, newest last',
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (user_id)
);
