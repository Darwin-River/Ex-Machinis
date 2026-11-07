-- Investor: a company design for Public Forge, in Lua (#473, the stock
-- market #466). Release it with either kit: it never mines or makes
-- anything. It stays at Earth and lives on shares.
-- Every company's shares pay their holders 0.1% a share of the company's
-- sales income. shares_for_sale() lists the sell orders at the Earth
-- exchange with each company's sales over the last game day, so the
-- investor knows what a share pays a day. It buys shares that pay for
-- themselves within buy_days days, and offers what it holds at sell_days
-- days of their dividends, or half as much again as their last price.

-- Settings: change these numbers to make it your own
buy_days = 12      -- buys shares that pay back within this many game days
sell_days = 25     -- asks this many days of dividends for what it holds
spend = 1500       -- credits it puts into one company at a time
cash_floor = 500   -- credits it keeps
low_fuel = 20      -- buys fuel below this (each order burns one)

-- What a company's shares pay a day, per share, from its sales over the last day
function per_share(sales) return math.max(sales, 0) / 1000 end

-- Buy the shares on offer that pay back fastest
function buy()
  for _, o in ipairs(shares_for_sale()) do
    local a_day = per_share(o.sales)
    if o.company ~= company() and a_day > 0 and o.price <= a_day * buy_days then
      local cash = credits() - cash_floor
      local n = math.min(o.units, spend // o.price, cash // o.price)
      if n > 0 then pull_shares(n, o.company, o.seller) end
    end
  end
end

-- Offer what it holds: sell_days of dividends where it can tell (the
-- company's own offer lists its sales), else half as much again as the
-- last price
function sell()
  local sales = {}
  for _, o in ipairs(shares_for_sale()) do sales[o.company] = o.sales end
  for _, h in ipairs(shares_held()) do
    if h.company ~= company() then
      local price = math.floor(per_share(sales[h.company] or 0) * sell_days)
      price = math.max(price, h.price + h.price // 2, 1)
      sell_shares(h.company, 0, price)
    end
  end
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
    buy()
    if today() ~= last_day then sell(); last_day = today() end
    report()
    wait(120)
  end
end
