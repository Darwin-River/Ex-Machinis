-- Adaptive: a market-aware company design for Public Forge, in Lua (#380),
-- written as a state machine. It equips itself with the Factory Kit. The players'
-- manual walks through it
-- (Chapter 9, "Market-aware drones").
-- It turns Metals (A1) into rods at the Moon, like the Smith, but it reads
-- the market every round and decides what to do:
-- - it buys metals only while two of them cost well under what a rod
--   fetched at Earth yesterday (the price board, board_price), and from
--   whoever sells them cheapest;
-- - it sells alloys at the Moon instead of making rods when the Moon pays
--   nearly as much for an alloy as Earth pays for a rod;
-- - when nothing pays, it rests, and after a while it sells what it has.
-- A state machine keeps one mode at a time (shopping, making, hauling,
-- resting). Each round it does that mode's work, and the market decides
-- which mode comes next.

-- Settings
cash_floor = 300   -- credits it keeps (for fuel)
rod_load = 12      -- rods it takes to Earth (4 cargo each)
low_fuel = 40      -- buy fuel below this
overheads = 40     -- fuel and trip costs per rod, roughly

-- The modes. The game words become, in_mode, tick and ticks keep track of them
shopping, making, hauling, resting = 0, 1, 2, 3

-- --- Reading the market ---------------------------------------------------

-- Credits above cash_floor
function spare() return math.max(credits() - cash_floor, 0) end

-- What a rod fetched at Earth over the last game day, or a guess of 225
-- when none traded there
function rod_price()
  local p = board_price(rods, earth)
  if p == 0 then return 225 end
  return p
end

-- Worth buying metals? Two make a rod: only while two, plus overheads, cost
-- less than a rod fetches
function worth_buying()
  local p = buy_price(metals_a1)
  if p == 0 then return false end
  return p * 2 + overheads < rod_price()
end

-- Does a rod pay more than selling its alloy at the Moon (sell_price: the
-- best buy order here, whoever placed it)?
function rods_pay() return rod_price() > sell_price(alloys_a1) + overheads end

-- --- The modes ------------------------------------------------------------

-- Buys from the cheapest sellers here (buy_here: any drone's sell orders,
-- the station drone's too)
function shop()
  if units(fuel) < low_fuel then buy_here(30, fuel); wait(60) end
  if not worth_buying() then become(resting); return end
  local price = buy_price(metals_a1)
  local n = 0
  if price > 0 then n = math.min(20, spare() // price) end
  if n > 0 then buy_here(n, metals_a1); wait(60) end
  if units(metals_a1) > 1 then become(making) else become(resting) end
end

-- One alloy a round, then a rod or a sale, so the market is read again
-- before the next one
function make_one()
  if units(metals_a1) > 1 and units(fuel) > 10 then
    make(alloys_a1)
    if rods_pay() then make(rods) else sell_all(alloys_a1) end
    return
  end
  if units(rods) >= rod_load then become(hauling); return end
  become(shopping)
end

-- Sell at most 4 rods an hour, for up to 6 hours: buyers here tend to
-- bid less as they stock up, and bid again later
function sell_rods()
  for _ = 1, 6 do
    if units(rods) == 0 then return end
    sell_here(math.min(units(rods), 4), rods)
    wait(60)
  end
end

function haul()
  go(earth)
  if units(fuel) < 60 then buy_here(30, fuel); wait(120) end
  sell_rods()
  go(moon)
  become(shopping)
end

-- Nothing pays: wait two game hours, then look again. After six rests,
-- sell what is aboard rather than wait for ever
function rest()
  wait(120)
  if units(rods) > 0 and ticks() > 5 then become(hauling); return end
  if worth_buying() then become(shopping) end
end

-- --- The machine ----------------------------------------------------------
function step()
  if in_mode(shopping) then shop(); return end
  if in_mode(making) then make_one(); return end
  if in_mode(hauling) then haul(); return end
  rest()
end

-- Every 8th round in a mode, send a line home: the mode and what it sees
function tell()
  if ticks() % 8 ~= 0 then return end
  print("mode " .. mode .. "  credits " .. credits() .. " rod price " .. rod_price() ..
        "  metal " .. buy_price(metals_a1))
  report()
end

-- launched() may run again (after a crash, or if the engine restarts):
-- unpack passes once the tools are unpacked, and it starts again shopping
function launched()
  fuel_floor(15)   -- make, mine, refine and push stop at 15 fuel: it keeps enough to buy more (#496)
  equip(factory_kit)   -- buys a kit at Earth and unpacks it (a copy waits for the one it is handed; #506)
  go(moon)
  become(shopping)
  while true do
    step()
    tick()
    tell()
  end
end
