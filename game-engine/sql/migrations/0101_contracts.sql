-- Contracts between companies (#928), part 7 of the revised system (#921).
-- A matured company's code posts a buy order with a due date and a deposit
-- (post_contract(resource, units, price, due_in, deposit)); the price of all
-- its units is held in escrow from its credits. Any other matured company
-- may take it (take_contract(id)), putting up the deposit, and then only it
-- may fill it, with deliver(id, n) at the buyer's place (protocol 37, which
-- moves goods but is not a market trade). On the due date, or once all is
-- delivered, what was delivered is paid from the escrow; for the rest the
-- buyer gets its credits back and that share of the seller's deposit.
-- Contracts are anonymous: listings show units, price, due date, deposit
-- and the poster's kept and broken contracts, never who.
--
-- contracts: each contract; contract_actions: cancel_contract from the code,
-- which the website's tick settles.

CREATE TABLE IF NOT EXISTS contracts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    buyer_company INT UNSIGNED NOT NULL,
    buyer_drone INT UNSIGNED NOT NULL COMMENT 'The drone that receives, at the place',
    place INT NOT NULL COMMENT 'Where it is delivered: the buyer drone''s place when posted',
    resource INT NOT NULL,
    units INT NOT NULL,
    price INT NOT NULL COMMENT 'Credits a unit',
    deposit BIGINT NOT NULL COMMENT 'What the seller puts up when it takes it',
    escrow BIGINT NOT NULL COMMENT 'Credits held from the buyer: units x price, less what has been paid or refunded',
    posted_t BIGINT NOT NULL COMMENT 'Game time',
    due_t BIGINT NOT NULL COMMENT 'Game time',
    seller_company INT UNSIGNED NULL COMMENT 'Who took it; NULL: open',
    taken_t BIGINT NULL,
    deposit_held BIGINT NOT NULL DEFAULT 0 COMMENT 'The seller''s deposit, held until settled',
    delivered INT NOT NULL DEFAULT 0,
    pending_drone INT UNSIGNED NULL COMMENT 'The drone whose deliver(id, n) is on its way: it counts against this contract',
    settled_t BIGINT NULL COMMENT 'Game time it was settled; NULL: open',
    outcome VARCHAR(16) NULL COMMENT 'kept, broken (part or none delivered), expired (never taken), cancelled',
    KEY contracts_open (settled_t, place, resource),
    KEY contracts_buyer (buyer_company, settled_t),
    KEY contracts_seller (seller_company, settled_t)
) ENGINE = InnoDB COMMENT 'Contracts between companies (#928)';

CREATE TABLE IF NOT EXISTS contract_actions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL COMMENT 'The company whose code asked',
    requested_t BIGINT NOT NULL,
    done_t BIGINT NULL,
    outcome VARCHAR(32) NULL,
    KEY contract_actions_due (done_t, id)
) ENGINE = InnoDB COMMENT 'cancel_contract from a company''s code, settled by the website (#928)';
