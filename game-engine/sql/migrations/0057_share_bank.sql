-- Players' shares and the bank (#638, step 3 of the new rules in #635).
-- Players own matured companies' shares (player_shares, from #637). The bank
-- always buys them at the company's cash-out value, paying 30 game days
-- later at the lower of the value then and on the day of the sale, and sells
-- only shares it bought back. Players trade with each other through a book
-- of standing orders, within a published range around the bank's price.
-- Used only while FORGE_NEW_RULES is on.

-- The shares the bank holds, bought back from players
CREATE TABLE IF NOT EXISTS bank_shares (
    company_id INT UNSIGNED NOT NULL,
    units INT NOT NULL,
    PRIMARY KEY (company_id)
);

-- What the bank owes for shares sold to it, paid when due
CREATE TABLE IF NOT EXISTS bank_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    units INT NOT NULL,
    price_at_sale INT NOT NULL COMMENT 'Cash-out value a share on the day of the sale',
    sold_t BIGINT NOT NULL COMMENT 'Game time of the sale',
    due_t BIGINT NOT NULL COMMENT 'Game time it is paid',
    paid_t BIGINT NULL,
    paid INT NULL COMMENT 'Credits paid: units times the lower of the two values',
    PRIMARY KEY (id),
    INDEX bank_payments_due (paid_t, due_t),
    INDEX bank_payments_user (user_id)
);

-- Players' standing orders for shares: side 'sell' or 'buy'
CREATE TABLE IF NOT EXISTS player_share_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    side CHAR(4) NOT NULL,
    units INT NOT NULL COMMENT 'Shares still wanted or offered',
    price INT NOT NULL COMMENT 'Credits a share',
    created_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    INDEX player_share_orders_book (company_id, side, price)
);

-- Every share trade a player made: with another player, or with the bank
CREATE TABLE IF NOT EXISTS player_share_trades (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    seller_id INT UNSIGNED NULL COMMENT 'NULL: the bank sold',
    buyer_id INT UNSIGNED NULL COMMENT 'NULL: the bank bought',
    units INT NOT NULL,
    price INT NOT NULL,
    game_t BIGINT NOT NULL,
    PRIMARY KEY (id),
    INDEX player_share_trades_company (company_id, game_t)
);
