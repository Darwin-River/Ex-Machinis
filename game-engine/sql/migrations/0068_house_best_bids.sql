-- Each good's best House bid anywhere, once a game day (#685). Cash-out
-- values a company's goods at their average over the last 7 game days
-- (App\Services\PlayerShares::bestBids). house_market is a view of today's
-- prices only, so the days before are kept here; PlayerShares::recordBestBids
-- adds today's row on the website's tick and keeps 30 game days.
CREATE TABLE IF NOT EXISTS house_best_bids (
    day INT NOT NULL COMMENT 'Game day',
    resource INT UNSIGNED NOT NULL,
    price INT NOT NULL COMMENT 'The best listed House bid for it that day, at any exchange',
    PRIMARY KEY (day, resource)
) ENGINE = InnoDB;
