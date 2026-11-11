-- Shares and dividends (#467, step 1 of the stock market #466). Each company
-- has 1,000 shares; each pays its holder 0.1% of the company's sales income
-- after sales tax (House and drone-to-drone sales alike). A company starts
-- holding all its own, so nothing changes until it parts with some.
--
-- shares: who holds how many of each company's shares, and the dividends
-- each holder has had from that company in all. When a company ends, the
-- shares it held go to the House (the government), which goes on being paid
-- their dividends; selling them comes with the exchange (step 2).

CREATE TABLE IF NOT EXISTS shares (
    company INT UNSIGNED NOT NULL,
    holder INT UNSIGNED NOT NULL,
    units INT NOT NULL,
    dividends BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (company, holder),
    INDEX shares_holder (holder),
    CONSTRAINT shares_company FOREIGN KEY (company) REFERENCES companies (company_id) ON DELETE CASCADE,
    CONSTRAINT shares_holder_company FOREIGN KEY (holder) REFERENCES companies (company_id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- Every company so far holds its own
INSERT IGNORE INTO shares (company, holder, units) SELECT company_id, company_id, 1000 FROM companies;

DROP TRIGGER IF EXISTS companies_shares_insert;
DROP TRIGGER IF EXISTS companies_shares_end;

DELIMITER //

-- A new company holds all its own shares
CREATE TRIGGER companies_shares_insert AFTER INSERT ON companies FOR EACH ROW
BEGIN
    INSERT IGNORE INTO shares (company, holder, units) VALUES (NEW.company_id, NEW.company_id, 1000);
END //

-- A company that ends loses the shares it held: they go to the House
CREATE TRIGGER companies_shares_end AFTER UPDATE ON companies FOR EACH ROW
BEGIN
    DECLARE gov INT UNSIGNED;
    IF OLD.eliminated_at IS NULL AND NEW.eliminated_at IS NOT NULL THEN
        SET gov = (SELECT MIN(c.company_id) FROM companies c WHERE c.house = 1);
        IF gov IS NOT NULL AND gov <> NEW.company_id THEN
            INSERT INTO shares (company, holder, units)
                SELECT moved.company, gov, moved.units FROM shares AS moved WHERE moved.holder = NEW.company_id
            ON DUPLICATE KEY UPDATE shares.units = shares.units + VALUES(units);
            DELETE FROM shares WHERE holder = NEW.company_id;
        END IF;
    END IF;
END //

DELIMITER ;
