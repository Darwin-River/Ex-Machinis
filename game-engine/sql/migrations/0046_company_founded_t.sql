-- Every company's founding time (#548: the leaderboard shows age in game
-- days). founded_t existed (0011) but nothing set it since the tax office's
-- grace period went. A release now sets it; a company without one gets the
-- game time of its drones' first event, or failing that now.
UPDATE companies AS c
SET c.founded_t = (SELECT MIN(e.game_t) FROM events AS e JOIN agents AS a ON a.agent_id = e.drone
                   WHERE a.company_id = c.company_id)
WHERE c.founded_t IS NULL;

UPDATE companies AS c, game_clock AS g
SET c.founded_t = ROUND(g.game_anchor + (UNIX_TIMESTAMP() - g.real_anchor) * g.scale)
WHERE c.founded_t IS NULL AND g.id = 1;
