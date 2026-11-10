-- Trader: a starter script for Public Forge, in Lua (#380). It buys an
-- Engine at Earth, all it needs, and trades with the rest of its credits.
-- It works the Moon, where the miners offer their metals to other drones.
-- The House's prices move (each exchange its own way: booms, slumps,
-- shortages and gluts), so it doesn't trade at fixed prices: for each metal
-- it looks at what it sold for at Mars and at Earth over the last game day
-- (the price board) and buys here only when that, less the sales tax,
-- beats the seller's price by enough to pay for the trip. With a load
-- aboard it flies wherever the load fetches most, sells it and comes back.
-- It buys its fuel at the exchanges, from whoever sells it cheapest.
-- (Round 36: with fixed prices, all 12 Traders ended slightly down.)

-- Settings: change these numbers to make it your own
cargo_goal = 150   -- metals it gathers before setting off (the hold
                   -- carries 1,000 since round 37)
min_load = 40      -- ...or this many, after shopping a while
patience = 24      -- looks at the market before it goes with less
trip_fuel = 25     -- fuel it wants aboard before a trip
trip_cost = 200    -- what a round trip costs (fuel, time): spread over a load
min_margin = 2     -- least profit a unit, after the trip and the tax
nap = 120          -- game minutes to rest when nothing pays
cash_floor = 200   -- credits it keeps for fuel: a trader that spends every
                   -- credit on metals can't buy fuel, and is stranded

traded = {metals_a1, metals_a2, metals_a3}
markets = {mars, earth}

function metals()
  local n = 0
  for _, metal in ipairs(traded) do n = n + units(metal) end
  return n
end

function spare() return math.max(credits() - cash_floor, 0) end

-- Where a metal fetches most, and what (yesterday's price there, less the
-- 5% sales tax); 0 where it didn't trade
function best_market(metal)
  local where, best = mars, 0
  for _, place in ipairs(markets) do
    local p = board_price(metal, place) * 95 // 100
    if p > best then where, best = place, p end
  end
  return where, best
end

-- The most it pays a unit here: what it fetches, less its share of the
-- trip and the margin
function max_price(metal)
  local _, worth = best_market(metal)
  return worth - trip_cost // cargo_goal - min_margin
end

-- Buy what the cheapest seller here offers of one metal, if the price is
-- right, there is room in this load and the spare credits pay for it.
-- True if it bought any
function buy(metal)
  local price, seller, avail = best_offer(metal)
  if avail == 0 or price > max_price(metal) then return false end
  local n = math.min(avail, cargo_goal - metals(), spare() // math.max(price, 1))
  if n <= 0 then return false end
  return pull(n, metal, seller) == 0
end

-- Top up the tank here, from the cheapest seller
function fuel_up()
  if units(fuel) < trip_fuel then
    buy_here(trip_fuel, fuel)
    wait(120)
  end
end

-- Sell at most 15 of each metal an hour, for up to 6 hours: buyers bid
-- less as they stock up. What is left goes on the next trip
function sell_metals()
  for hour = 1, 6 do
    if metals() == 0 then return end
    for _, metal in ipairs(traded) do
      local n = math.min(units(metal), 15)
      if n > 0 then sell_here(n, metal) end
    end
    wait(60)
  end
end

-- Where the load aboard fetches most
function destination()
  local value = {}
  for _, metal in ipairs(traded) do
    local n = units(metal)
    if n > 0 then
      for _, place in ipairs(markets) do
        value[place] = (value[place] or 0) + n * board_price(metal, place)
      end
    end
  end
  local where, best = mars, -1
  for _, place in ipairs(markets) do
    if (value[place] or 0) > best then where, best = place, value[place] or 0 end
  end
  return where
end

-- Look for sellers; rest a while when nothing pays. Each look counts
-- toward going with a smaller load
looks = 0
function shop()
  fuel_up()
  local got = false
  for _, metal in ipairs(traded) do
    if buy(metal) then got = true end
  end
  looks = looks + 1
  if not got then wait(nap) end
end

function haul()
  fuel_up()
  go(destination())
  sell_metals()
  fuel_up()
  go(moon)
  looks = 0
end

-- A full load, or a smaller one after waiting long enough, or whatever it
-- has once its money is spent
function ready()
  local n = metals()
  return n >= cargo_goal or (n >= min_load and looks >= patience) or (n > 0 and spare() < 20)
end

-- Every 4th round, send a line home
rounds = 0
function tell()
  rounds = rounds + 1
  if rounds % 4 ~= 0 then return end
  print("day " .. today() .. " credits " .. credits() .. " fuel " .. units(fuel) .. " metals " .. metals())
  report()
end

function work()
  if ready() then haul() else shop() end
  tell()
end

-- launched() may run again (after a crash, or if the engine restarts):
-- unpack passes once the tools are unpacked, and go stays put if already there
function launched()
  fuel_floor(15)   -- make, mine, refine and push stop at 15 fuel: it keeps enough to buy more (#496)
  -- It needs only an Engine to fly (#506): it buys one at Earth (577 or so)
  -- and keeps the rest of its credits to trade with
  while units(engine_module) == 0 do
    if units(mining_kit) > 0 or units(factory_kit) > 0 then unpack() else buy_here(1, engine_module); wait(60) end
  end
  go(moon)
  while true do work() end
end
