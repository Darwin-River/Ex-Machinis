-- The share exchange at Earth (#469, step 2 of the stock market #466).
--
-- A drone offers (protocol 41) or bids for (42) a company's shares, for its
-- own company. The order travels to Earth at the speed of light (arrives_t)
-- and stands there: a company has at most one offer and one bid for each
-- company's shares, the newest to arrive replacing the one before, and an
-- order for 0 units withdrawing it. Once a game hour the exchange clears
-- (db_shares.c): for each company's shares, the highest bids buy from the
-- cheapest offers at the seller's price, as far as the seller holds the
-- shares and the buyer has the credits. The House offers the shares it
-- collects from failed companies.

CREATE TABLE IF NOT EXISTS share_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company INT UNSIGNED NOT NULL COMMENT 'The company placing it (the drone''s)',
    drone INT UNSIGNED NULL COMMENT 'The drone that sent it (NULL: the House''s own)',
    action INT UNSIGNED NULL,
    share_company INT UNSIGNED NOT NULL COMMENT 'Whose shares',
    side TINYINT NOT NULL COMMENT '1 offer (sell), 2 bid (buy)',
    units INT NOT NULL COMMENT 'Still wanted; 0 withdraws the standing order',
    price INT NOT NULL COMMENT 'Credits a share: the least it sells for, the most it pays',
    placed_t BIGINT NOT NULL COMMENT 'Game time it was sent',
    arrives_t BIGINT NOT NULL COMMENT 'Game time it reaches Earth',
    INDEX share_orders_book (share_company, side, arrives_t),
    INDEX share_orders_company (company, share_company, side),
    CONSTRAINT share_orders_company FOREIGN KEY (company) REFERENCES companies (company_id) ON DELETE CASCADE,
    CONSTRAINT share_orders_shares FOREIGN KEY (share_company) REFERENCES companies (company_id) ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS share_trades (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    share_company INT UNSIGNED NOT NULL,
    seller INT UNSIGNED NOT NULL,
    buyer INT UNSIGNED NOT NULL,
    units INT NOT NULL,
    price INT NOT NULL,
    game_t BIGINT NOT NULL,
    INDEX share_trades_company (share_company, id),
    INDEX share_trades_seller (seller),
    INDEX share_trades_buyer (buyer)
) ENGINE = InnoDB;

-- When the exchange last cleared (one row)
CREATE TABLE IF NOT EXISTS share_exchange (
    id INT NOT NULL PRIMARY KEY,
    cleared_t BIGINT NOT NULL DEFAULT 0
) ENGINE = InnoDB;
INSERT IGNORE INTO share_exchange (id, cleared_t) VALUES (1, 0);
