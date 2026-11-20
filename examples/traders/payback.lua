-- Payback trader: a simple trading script for a class. Every few real
-- minutes the game calls trade(market) with the share market and your
-- account, and it follows two rules:
--   * BUY a share when its dividends pay its price back in under
--     max_payback game days (a share that paid 30 credits over the last 30
--     game days pays 1 a day, so at 35 it pays back in 35 days), if the
--     company isn't in any trouble;
--   * SELL what you hold of a company that is about to end: dissolving
--     (out of credits) or soon wound up (selling nothing). A company merely
--     "at risk" (one drone low on fuel) or stalled for a while is kept: it
--     may hold a fortune, and selling it to the bank is final.
-- Watch its log on the Trading desk or your account page, then change a
-- setting and see what happens. Break, learn, repeat.

-- Settings
max_payback = 1000  -- buy when a share pays back in fewer than this (game days); live
                    -- shares pay back in hundreds to thousands of game days
sell_before = 10    -- sell when it winds up within this many game days
lot = 2             -- shares to buy at a time
max_held = 10       -- most shares to hold of any one company
keep_credits = 500  -- credits it never spends

dividend_period = 30   -- dividends30 is what a share paid over this many game days

function dividends_a_day(company)
  return (company.dividends30 or 0) / dividend_period
end

-- Game days a share's dividends take to pay back its price (math.huge when it pays none)
function payback_days(company, price)
  local per_day = dividends_a_day(company)
  if per_day <= 0 then return math.huge end
  return price / per_day
end

-- About to end: sell now, while the shares are still worth something
function ending(company)
  return company.dissolves_in ~= nil or (company.winds_up_in ~= nil and company.winds_up_in <= sell_before)
end

-- Any trouble at all: don't buy more
function in_trouble(company)
  return ending(company) or company.at_risk or company.failing or company.stalled or company.winds_up_in ~= nil
end

-- Sells into the best bid, or to the bank when nobody bids
function sell_shares_of(company, held)
  local best_bid = company.bids[1]
  if best_bid then
    sell(company.company, math.min(held, best_bid.units), best_bid.price)
  else
    bank_sell(company.company, held)
  end
  log("selling", company.name, "(it is about to end)")
end

-- Buys from the cheapest offer, or from the bank when nobody offers, if the
-- shares pay back soon enough and we can afford them. Returns the credits left
function buy_if_it_pays_back(company, held, spare)
  local cheapest_ask = company.asks[1]
  local price = cheapest_ask and cheapest_ask.price or company.sells_at
  local days = payback_days(company, price)
  local amount = math.min(lot, max_held - held)
  if cheapest_ask then amount = math.min(amount, cheapest_ask.units) end
  if days >= max_payback or amount <= 0 or spare < amount * price then return spare end

  if cheapest_ask then
    buy(company.company, amount, price)
  elseif company.bank_holds >= amount then
    bank_buy(company.company, amount)
  else
    return spare
  end
  log("buying", amount, "of", company.name, "at", price, "(pays back in", math.floor(days), "game days)")
  return spare - amount * price
end

function trade(market)
  local me = market.me
  local spare = me.credits + me.share_credits - keep_credits

  for _, company in ipairs(market.companies) do
    local held = me.holdings[company.company] or 0
    if held > 0 and ending(company) then
      sell_shares_of(company, held)
    elseif not in_trouble(company) and held < max_held then
      spare = buy_if_it_pays_back(company, held, spare)
    end
  end
end
