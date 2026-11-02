-- Maker: the starter script (examples/starters/maker.fth) in Lua, for the
-- proof of concept (#380). Release it with the Factory Kit.
-- It buys Metals (A1) at the Moon wherever they are cheapest, makes them
-- into alloys and the alloys into rods, and sells the rods where they pay
-- best: at the Moon if the best bid there is close to what rods fetched
-- at Earth yesterday, else it flies a load to Earth.
-- (Growth is left out: the mock world has no hulls to buy.)

-- Settings: change these numbers to make it your own
rod_load = 12      -- rods it takes to Earth
low_fuel = 40      -- buy fuel below this
keep_fuel = 25     -- it stops making rods below this much fuel
trip_margin = 20   -- flies to Earth only if rods sold there for this much more
batch = 20         -- metals it buys at a time
cash_floor = 300   -- credits it keeps (for fuel)

-- Fuel when low, from whoever sells it cheapest here
function refuel()
  if units(fuel) < low_fuel then buy_here(30, fuel) end
end

-- Metals for a batch, as many as the spare credits pay for
function stock_up()
  if units(metals_a1) >= 2 then return end
  local spare = math.max(credits() - cash_floor, 0)
  local n = math.min(batch, spare // 30)
  if n > 0 then buy_here(n, metals_a1) end
end

-- Metals into rods, two at a time; stop at the first failure, or when the
-- tank is down to keep_fuel
function forge_rods()
  while units(metals_a1) > 1 and units(fuel) > keep_fuel do
    if not make(alloys_a1) then return end
    if not make(rods) then return end
  end
end

-- Sell at most 4 rods an hour, for up to 6 hours
function sell_rods()
  for hour = 1, 6 do
    if units(rods) == 0 then return end
    sell_here(math.min(units(rods), 4), rods)
    wait(60)
  end
end

-- Sell here? Yes if somebody here bids for rods and Earth paid no more
-- than trip_margin over that yesterday
function sell_here_ok()
  local bid = sell_price(rods)
  return bid > 0 and bid + trip_margin >= board_price(rods, earth)
end

-- Out of metals and of money to buy them, with rods aboard
function stuck()
  return units(metals_a1) < 2 and credits() - cash_floor < 60 and units(rods) > 0
end

function haul()
  go(earth)
  sell_rods()
  go(moon)
end

-- What it remembers between rounds (saved and restored with the drone)
rounds = 0
history = {}

function work()
  rounds = rounds + 1
  refuel()
  stock_up()
  forge_rods()
  if units(rods) > 0 and sell_here_ok() then
    sell_rods()
  elseif units(rods) >= rod_load or stuck() then
    haul()
  end
  history[#history % 8 + 1] = credits()   -- the last 8 rounds' credits
end

function launched()
  go(moon)
  while true do work() end
end
