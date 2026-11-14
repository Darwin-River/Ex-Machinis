-- A matured company has 100 shares, not 1,000 (#667): at a few thousand
-- credits of cash-out value, a share was worth 2 to 15 credits and whole-
-- credit prices moved in 15 to 25% steps. Holdings, the bank's shares and
-- players' trades so far become tenths (rounded), and prices ten times as
-- much; credits already paid or due stay as they were. Runs once (migrate.sh
-- records it), so it never divides twice.
UPDATE player_shares SET units = ROUND(units / 10);
DELETE FROM player_shares WHERE units <= 0;
UPDATE bank_shares SET units = ROUND(units / 10);
UPDATE player_share_orders SET units = GREATEST(1, ROUND(units / 10)), price = price * 10;
UPDATE player_share_trades SET units = GREATEST(1, ROUND(units / 10)), price = price * 10;
UPDATE bank_payments SET units = GREATEST(1, ROUND(units / 10)), price_at_sale = price_at_sale * 10;
UPDATE player_dividends SET units = ROUND(units / 10);
