-- Trader: a starter script for Public Forge, in Lua (#380). Release it
-- with the Mining Kit (it only uses the Engine).
-- It works the Moon, where the Miner offers its metals to other drones
-- between the best bid and the cheapest other seller there. It buys them,
-- hauls a load to Mars, where buyers have paid more (check the price
-- board), sells it and comes back. It buys its fuel at the exchanges, from
-- whoever sells it cheapest. Copies compete for the miners' metals: the
-- first to buy gets them.
-- The same drone as trader.fth, in Lua.

-- Settings: change these numbers to make it your own
cargo_goal = 60    -- metals it gathers before setting off
min_load = 20      -- ...or this many, after shopping a while
patience = 24      -- looks at the market before it goes with less
trip_fuel = 20     -- fuel it wants aboard before a trip
nap = 120          -- game minutes to rest when nobody sells
cash_floor = 200   -- credits it keeps for fuel: a trader that spends every
                   -- credit on metals can't buy fuel, and is stranded

-- The most it pays a unit, under what it expects to get at Mars
max_price = {[metals_a1] = 25, [metals_a2] = 40, [metals_a3] = 55}
traded = {metals_a1, metals_a2, metals_a3}

function metals()
  local n = 0
  for _, metal in ipairs(traded) do n = n + units(metal) end
  return n
end

function spare() return math.max(credits() - cash_floor, 0) end

-- Buy what the cheapest seller here offers of one metal, if the price is
-- right, there is room in this load and the spare credits pay for it.
-- True if it bought any
function buy(metal)
  local price, seller, avail = best_offer(metal)
  if avail == 0 or price > max_price[metal] then return false end
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

-- Look for sellers; rest a while when nobody sells. Each look counts
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
  go(mars)
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

-- Every 8th round, send a line home
rounds = 0
function tell()
  rounds = rounds + 1
  if rounds % 8 ~= 0 then return end
  print("credits " .. credits() .. " fuel " .. units(fuel))
  report()
end

function work()
  if ready() then haul() else shop() end
  tell()
end

-- launched() may run again (after a crash, or if the engine restarts):
-- unpack passes once the tools are unpacked, and go stays put if already there
function launched()
  unpack()
  go(moon)
  while true do work() end
end
