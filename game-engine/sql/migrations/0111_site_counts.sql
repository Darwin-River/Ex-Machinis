-- Counts the site keeps (#1045, part of #1039): plain running totals with no
-- names, addresses or visitors in them, such as how many guest test drives
-- have run (#1042). Read through the operator's API.
CREATE TABLE IF NOT EXISTS site_counts (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    n BIGINT NOT NULL DEFAULT 0
) COMMENT 'Running totals the site keeps, with no names in them (#1045)';
