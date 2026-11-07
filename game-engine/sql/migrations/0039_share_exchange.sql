-- The share exchange at Earth (#469, step 2 of the stock market #466).
-- Shares trade the way goods do: a company posts standing orders, and
-- another company's drone responds to one by pulling (buying) shares from a
-- sell order or pushing (selling) shares into a buy order, at the order's
-- price and within its limits. The exchange is at Earth: every request
-- travels there at the speed of light and takes effect when it arrives.
--
-- Protocols: 41 post a sell order (company, keep, price), 42 post a buy
-- order (company, want, price), 43 pull shares (units, company, seller),
-- 44 push shares (units, company, buyer). Each records a request here and
-- has one Share event (type 14) at its arrival time; the events engine
-- settles the request with that event (db_shares.c).

-- The requests on their way to Earth: one per action
CREATE TABLE IF NOT EXISTS share_requests (
    action INT UNSIGNED NOT NULL PRIMARY KEY,
    company INT UNSIGNED NOT NULL COMMENT 'The company the drone acts for',
    drone INT UNSIGNED NOT NULL,
    kind TINYINT NOT NULL COMMENT '1 post a sell order, 2 post a buy order, 3 pull (buy), 4 push (sell)',
    share_company INT UNSIGNED NOT NULL COMMENT 'Whose shares',
    counterparty INT UNSIGNED NULL COMMENT 'The company whose order a pull or push answers',
    units INT NOT NULL COMMENT 'Pull, push: how many. Orders: the kept minimum or wanted maximum',
    price INT NOT NULL DEFAULT 0 COMMENT 'Orders: credits a share; 0 withdraws the order',
    placed_t BIGINT NOT NULL,
    INDEX share_requests_company (company)
) ENGINE = InnoDB;

-- The standing orders at Earth: a company has at most one sell and one buy
-- order for each company's shares. A sell order sells while the company
-- holds more than limit_units (it keeps that many); a buy order buys while
-- it holds fewer (it wants that many)
CREATE TABLE IF NOT EXISTS share_orders (
    company INT UNSIGNED NOT NULL,
    share_company INT UNSIGNED NOT NULL,
    side TINYINT NOT NULL COMMENT '1 sell, 2 buy',
    price INT NOT NULL COMMENT 'Credits a share',
    limit_units INT NOT NULL COMMENT 'Sell: the shares it keeps. Buy: the shares it wants',
    drone INT UNSIGNED NULL COMMENT 'The drone that posted it (NULL: the House''s own)',
    action INT UNSIGNED NULL,
    placed_t BIGINT NOT NULL,
    PRIMARY KEY (company, share_company, side),
    INDEX share_orders_book (share_company, side, price),
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
    action INT UNSIGNED NULL COMMENT 'The pull or push that made it',
    game_t BIGINT NOT NULL,
    INDEX share_trades_company (share_company, id),
    INDEX share_trades_seller (seller),
    INDEX share_trades_buyer (buyer)
) ENGINE = InnoDB;

-- When the House last re-posted its sell orders (one row)
CREATE TABLE IF NOT EXISTS share_exchange (
    id INT NOT NULL PRIMARY KEY,
    cleared_t BIGINT NOT NULL DEFAULT 0
) ENGINE = InnoDB;
INSERT IGNORE INTO share_exchange (id, cleared_t) VALUES (1, 0);
