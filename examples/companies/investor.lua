-- Investor: a company design for Public Forge, in Lua (#473, the stock
-- market #466). Release it with either kit: it never mines or makes
-- anything. It stays at Earth and lives on shares.
-- Every company's shares pay their holders 0.1% a share of the company's
-- sales income. Once a game day the investor learns what a share of each
-- company pays a day: from the dividends it has had where it holds shares,
-- else from the company's sales a day over the last week (shares_for_sale() lists
-- them), smoothed over about a week. It looks ahead (#475 step 3):
-- - from a company itself it pays up to buy_days of a share's income, and
--   up to grow_days for a company whose income is growing;
-- - from another holder it pays no more than buy_days: no premium for
--   growth, so it doesn't bid up shares between investors;
-- - it offers what it holds at sell_days of a share's income;
-- - once a day it commits to offerings of new shares (#485) worth buy_days
--   of a share's income once filled, counting one more drone's sales, one
--   offering at a time until it fills or lapses (#488).

-- Settings: change these numbers to make it your own
buy_days = 30      -- pays up to this many days of a share's income
grow_days = 60     -- and up to this many for a company that is growing
sell_days = 60     -- asks this many days of a share's income for what it holds
spend = 1500       -- credits it puts into one company at a time
cash_floor = 500   -- credits it keeps
low_fuel = 20      -- buys fuel below this (each order burns one)
min_buy = 25       -- skips buys worth less than this: each costs a unit of fuel

-- What it has learned of each company: a share's income a day, smoothed
-- over about a week (avg), and the latest day's (last)
known = {}
function learn(c, a_day)
  local k = known[c]
  if not k then known[c] = {avg = a_day, last = a_day}; return end
  k.last = a_day
  k.avg = k.avg + (a_day - k.avg) / 7
end

-- Growth: the latest day against the week, from 1 (flat or falling) to 2
function growth(c)
  local k = known[c]
  if not k or k.avg <= 0 then return 1 end
  return math.min(math.max(k.last / k.avg, 1), 2)
end

-- What it pays for a share at most: buy_days of its income, more for a
-- growing company, but only when buying from the company itself
function limit(c, seller)
  local k = known[c]
  if not k then return 0 end
  local days = buy_days
  if seller == c then days = math.min(buy_days * growth(c), grow_days) end
  return k.avg * days
end

-- Once a game day: what each company's shares paid a share. The dividends
-- it had are the surest guide; where it holds none, the company's sales
dividends_had = {}
function study()
  local paid = {}
  for _, h in ipairs(shares_held()) do
    if h.company ~= company() and h.units > 0 then
      local before = dividends_had[h.company]
      if before then paid[h.company] = math.max(h.dividends - before, 0) / h.units end
      dividends_had[h.company] = h.dividends
    end
  end
  local seen = {}
  for _, o in ipairs(shares_for_sale()) do
    if not seen[o.company] then
      seen[o.company] = true
      if not paid[o.company] then learn(o.company, math.max(o.sales, 0) / share_count(o.company)) end
    end
  end
  for c, a_day in pairs(paid) do learn(c, a_day) end
end

-- Buy the shares on offer that are worth their price
function buy()
  for _, o in ipairs(shares_for_sale()) do
    if o.company ~= company() and o.price > 0 and o.price <= limit(o.company, o.seller) then
      local cash = credits() - cash_floor
      local n = math.min(o.units, spend // o.price, cash // o.price)
      if n > 0 and n * o.price >= min_buy then pull_shares(n, o.company, o.seller) end
    end
  end
end

-- Offer what it holds at sell_days of a share's income (twice the last
-- price for a company it knows nothing of yet). It remembers the price it
-- last posted for each, so an unchanged order costs no fuel
posted = {}
function sell()
  for _, h in ipairs(shares_held()) do
    if h.company ~= company() then
      local k = known[h.company]
      local price = k and math.floor(k.avg * sell_days) or 2 * h.price
      price = math.max(price, 1)
      if posted[h.company] ~= price then
        sell_shares(h.company, 0, price)
        posted[h.company] = price
      end
    end
  end
end

-- Offerings of new shares (#485): what a new share is worth over `days`
-- days, on the company's last day of sales spread over its shares once the
-- offering fills, and counting the sales of one more drone (what a raise is
-- usually for)
function offering_worth(o, days)
  local n = math.max(#company_drones(o.company), 1)
  return days * math.max(o.sales, 0) * (n + 1) / n / (o.count + o.units)
end
-- Commit up to `budget` credits to one offering at a time (#488): the one
-- it has already backed while it is open, else the open offering worth its
-- price that is closest to filling. An offering pays only if it fills, so
-- backers that pile into the same one fill it. Skips commitments under 25
-- credits (each burns a unit of fuel). Returns the credits it committed
function back_offerings(budget, days)
  local pick
  for _, o in ipairs(share_offerings()) do
    if o.company ~= company() and o.left > 0 then
      if o.mine > 0 then pick = o; break end
      if o.price <= offering_worth(o, days) and (not pick or o.left < pick.left) then pick = o end
    end
  end
  if not pick then return 0 end
  local n = math.min(pick.left, budget // pick.price)
  if n * pick.price < 25 then return 0 end
  commit_shares(n, pick.company)
  return n * pick.price
end

function report()
  local held, value = 0, 0
  for _, h in ipairs(shares_held()) do
    if h.company ~= company() then held = held + h.units; value = value + h.units * h.price end
  end
  set_status("holds " .. held .. " shares worth " .. value .. ", " .. credits() .. " credits")
end

started = false
function launched()
  go(earth)
  last_day = -1
  while true do
    if units(fuel) < low_fuel then buy_here(40, fuel) end
    if today() ~= last_day then
      study(); sell()
      back_offerings(math.min(spend, credits() - cash_floor), buy_days)
      last_day = today()
    end
    buy()
    report()
    wait(120)
  end
end
