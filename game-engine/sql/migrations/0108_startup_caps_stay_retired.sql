-- The startups' caps stay retired (#1013). 0097 (#923) set
-- startup_buy_permille to 1000 (all the House has on sale) and
-- startup_credit_cap_permille to 0 (no cap), but every deploy reloads
-- game_data.sql, which inserted the house_rules row without them, so they
-- took 0087's defaults again (100 and 2000). The game data now writes them;
-- their defaults change too, and the row is put right now.
ALTER TABLE house_rules
    MODIFY startup_buy_permille INT NOT NULL DEFAULT 1000 COMMENT 'Most of the House''s stock of a good at one exchange a startup may buy in a game day, in thousandths (#637; 1000: no cap since #923)',
    MODIFY startup_credit_cap_permille INT NOT NULL DEFAULT 0 COMMENT 'Most credits a startup may hold, in thousandths of its debt (#672; 0: no cap since #923)';
UPDATE house_rules SET startup_buy_permille = 1000, startup_credit_cap_permille = 0 WHERE id = 1;
