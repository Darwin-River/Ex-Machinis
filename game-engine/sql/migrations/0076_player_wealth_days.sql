-- Short seasons (#775): each account's wealth when it was first seen in a
-- real day (UTC, YYYY-MM-DD). The season board ranks what players made
-- since the day began, in place of the monthly growth board (round 72: its
-- "+47,390%" over a 5,000 floor meant nothing to a newcomer). Used only
-- while FORGE_NEW_RULES is on.
CREATE TABLE IF NOT EXISTS player_wealth_days (
    user_id INT UNSIGNED NOT NULL,
    day CHAR(10) NOT NULL,
    start_wealth BIGINT NOT NULL,
    PRIMARY KEY (day, user_id),
    INDEX player_wealth_days_user (user_id, day)
);
