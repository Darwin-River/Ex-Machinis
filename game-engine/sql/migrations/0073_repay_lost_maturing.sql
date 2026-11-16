-- Take the debt a seedling kept when it matured during a sale (#756).
--
-- Round 72's Bram 72-1 (company 225 on the live server) matured while its
-- drone was selling: the website took its 4,200 debt in one UPDATE, and the
-- events engine then wrote back the total it had worked out from the credits
-- it read before, so the repayment was lost. The engine now adds each
-- trade's change instead (db_add_user_credits).
--
-- Only that company, by its id and name, so another world is untouched, and
-- only once (migrate.sh records it).

UPDATE companies SET credits = credits - 4200
WHERE company_id = 225 AND name = 'Bram 72-1' AND seedling = 0 AND matured_t IS NOT NULL;
