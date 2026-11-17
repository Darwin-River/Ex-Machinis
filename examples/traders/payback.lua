-- Payback trader: a simple trading script for a class (#779). Like every
-- trading script it runs on your account every few real minutes: the game
-- calls trade(market) with the share market and your account.
--
-- Its two rules, one line each to change:
--   * BUY a share when its dividends pay its price back in under
--     max_payback game days (a share that paid 30 credits over the last 30
--     game days pays 1 a day, so at 35 it pays back in 35 days);
--   * SELL what you hold of a company at risk, failing or dissolving.
--
-- Try it, watch its log on the Trading desk or your account page, then
-- change a setting and see what happens. Break, learn, repeat.

-- Settings
max_payback = 40    -- buy when a share pays back in fewer game days than this
lot = 2             -- shares to buy at a time
max_held = 10       -- most shares of any one company
keep_credits = 500  -- credits it never spends

-- Game days a share's dividends take to pay back a price (math.huge when it pays none)
function payback_days(c, price)
  local per_day = (c.dividends30 or 0) / 30
  if per_day <= 0 then return math.huge end
  return price / per_day
end

-- Is it in trouble?
function in_trouble(c)
  return c.at_risk or c.failing or c.dissolves_in ~= nil
end

function trade(market)
  local me = market.me
  local spare = me.credits + me.share_credits - keep_credits

  for _, c in ipairs(market.companies) do
    local held = me.holdings[c.company] or 0

    -- Rule 2: sell what we hold of a company in trouble
    if held > 0 and in_trouble(c) then
      if c.bids[1] then
        sell(c.company, math.min(held, c.bids[1].units), c.bids[1].price)
      else
        bank_sell(c.company, held)
      end
      log("selling", c.name, "(in trouble)")

    -- Rule 1: buy the cheapest shares on offer when they pay back soon enough
    elseif not in_trouble(c) and held < max_held then
      local ask = c.asks[1]
      local price = ask and ask.price or c.sells_at
      local days = payback_days(c, price)
      local n = math.min(lot, max_held - held)
      if ask then n = math.min(n, ask.units) end
      if days < max_payback and n > 0 and spare >= n * price then
        if ask then
          buy(c.company, n, price)
        elseif c.bank_holds >= n then
          bank_buy(c.company, n)
        else
          n = 0
        end
        if n > 0 then
          spare = spare - n * price
          log("buying", n, "of", c.name, "at", price, "(pays back in", math.floor(days), "game days)")
        end
      end
    end
  end
end
