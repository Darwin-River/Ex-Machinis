-- The leaderboard ranks drones (#367 step 2): each released drone stands
-- for its company, whose net wealth counts its clones too. Rising compares
-- each company with its snapshot from a week ago, so snapshots are kept per
-- company now (user_id stays for the older ones).
ALTER TABLE wealth_snapshots
    MODIFY user_id INT NULL,
    ADD COLUMN company_id INT NULL COMMENT 'The company (#367 step 2); NULL in a player snapshot',
    ADD KEY wealth_snapshots_company (company_id, taken_at);
