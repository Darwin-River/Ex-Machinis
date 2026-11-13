-- The first hull's name (#380 round 64): "Mosquitto" was a typo for
-- Mosquito. The base schema (exmachinis_tables.sql) spells it right too.
UPDATE hulls SET name = 'Mosquito' WHERE id = 1 AND name = 'Mosquitto';
