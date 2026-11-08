-- New shares to fund growth (#485, part of the stock market #466).
-- A company's shares are no longer fixed at 1,000: each company has its own
-- count, and a share pays sales after tax x shares held / the count.
--
-- A company raises credits by offering new shares (protocol 45: units,
-- price, days): at most as many as it already has, one offering at a time.
-- Other companies commit to it (protocol 46: units, company); their credits
-- are taken when they commit and held here. When commitments cover every
-- share offered, the shares are created and given to the committers, the
-- company is paid, and its count grows. If the deadline passes first, or the
-- company withdraws the offering (45 with 0 units), every commitment is
-- refunded. The events engine settles both (db_shares.c).

SET @have := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'share_count');
SET @sql := IF(@have = 0,
    'ALTER TABLE companies ADD COLUMN share_count INT NOT NULL DEFAULT 1000 COMMENT ''Its shares in all (#485)''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- A company's open offering of new shares: one at a time
CREATE TABLE IF NOT EXISTS share_offerings (
    company INT UNSIGNED NOT NULL PRIMARY KEY,
    units INT NOT NULL COMMENT 'New shares offered',
    price INT NOT NULL COMMENT 'Credits a share',
    deadline_t BIGINT NOT NULL COMMENT 'Game time it lapses (and refunds) if not covered',
    drone INT UNSIGNED NULL,
    action INT UNSIGNED NULL,
    placed_t BIGINT NOT NULL,
    CONSTRAINT share_offerings_company FOREIGN KEY (company) REFERENCES companies (company_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- What each company has committed to an open offering, and paid
CREATE TABLE IF NOT EXISTS share_commitments (
    company INT UNSIGNED NOT NULL COMMENT 'The offering company',
    investor INT UNSIGNED NOT NULL,
    units INT NOT NULL,
    credits BIGINT NOT NULL COMMENT 'Held at the exchange until the offering closes or lapses',
    drone INT UNSIGNED NULL,
    action INT UNSIGNED NULL,
    placed_t BIGINT NOT NULL,
    PRIMARY KEY (company, investor),
    INDEX share_commitments_investor (investor),
    CONSTRAINT share_commitments_offering FOREIGN KEY (company) REFERENCES share_offerings (company) ON DELETE CASCADE
) ENGINE = InnoDB;
