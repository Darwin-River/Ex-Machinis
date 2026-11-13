-- Longer queries (#607, #608): the share queries (653, 654) and House
-- demand (706) gained the 30-day profit, the carried loss and today's sales,
-- and passed the 2,000 characters queries.script held; the game data stopped
-- loading. The engine's buffer (MAX_QUERIES_SCRIPT_SIZE) is 4,000 to match.
ALTER TABLE queries MODIFY script VARCHAR(4000) CHARACTER SET utf8mb3 DEFAULT NULL;
