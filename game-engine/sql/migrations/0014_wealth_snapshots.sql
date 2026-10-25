-- The leaderboard (#324 step 18) ranks player accounts by net wealth, all
-- time and Rising over the last real week. Rising compares today's wealth
-- with a snapshot from a week ago: the website takes one every real hour.
CREATE TABLE IF NOT EXISTS wealth_snapshots (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    taken_at DATETIME NOT NULL COMMENT 'Real time',
    wealth BIGINT NOT NULL COMMENT 'Net wealth then (see App\\Services\\Leaderboard)',
    KEY wealth_snapshots_user (user_id, taken_at),
    KEY wealth_snapshots_time (taken_at)
);
