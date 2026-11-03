-- Machinist: a company design for Public Forge, in Lua (economy rounds 10,
-- 11, 14; #380). Release it with the Factory Kit (Engine, Smelter, Chemical
-- Reactor and Spider).
-- It builds gear boxes, which station drones at Earth and Mars buy (check
-- the price board, board_price or query 680, for what they fetch). A gear
-- box takes a rod, a gear and a spring: 8 Metals (A1) and 2 Metals (A2)
-- through 5 alloys. It can't mine (#380: the mining gear is too big to
-- carry with the factory tools): it buys the metals at the Moon, and its
-- fuel, wherever they are cheapest, from the miners' sell orders or the
-- Moon Depot's. With a few gear boxes it flies to Earth, sells them 2 an
-- hour and comes back. A gear box burns about 23 fuel: it never starts one
-- with less than 30, and never leaves with less than low_fuel.

springs, gears, gear_boxes = 3101, 3111, 4111

-- Settings
box_load = 4       -- gear boxes it takes to Earth
low_fuel = 60      -- buy fuel below this
cash_floor = 300   -- credits it keeps for fuel

-- Fuel from the cheapest sellers here (buy_here: any drone's sell orders,
-- the station drone's too)
function refuel()
  if units(fuel) < low_fuel then buy_here(30, fuel); wait(60) end
end

-- Credits above cash_floor
function spare() return math.max(credits() - cash_floor, 0) end

-- The metals for two gear boxes, as many as the spare credits pay for (at
-- most 30 and 45 a unit), from the cheapest sellers here; then wait an hour
function short() return units(metals_a1) < 8 or units(metals_a2) < 2 end
function stock_up()
  if not short() then return end
  local n = math.min(math.max(16 - units(metals_a1), 0), spare() // 30)
  if n > 0 then buy_here(n, metals_a1) end
  n = math.min(math.max(4 - units(metals_a2), 0), spare() // 45)
  if n > 0 then buy_here(n, metals_a2) end
  wait(60)
end

-- Short of metals and of money to buy them, with gear boxes aboard: go and
-- sell them, however few (else it would wait for a full load for ever)
function stuck() return short() and spare() < 400 and units(gear_boxes) > 0 end

-- One gear box from 8 Metals (A1) and 2 Metals (A2); stops at a failure.
-- It burns about 23 fuel: never start one with less than 30 (round 20)
function build()
  if units(metals_a1) < 8 or units(metals_a2) < 2 then return end
  if units(fuel) < 30 then return end
  for _, step in ipairs({alloys_a1, alloys_a1, alloys_a1, alloys_a2, alloys_a2,
                         rods, gears, springs, gear_boxes}) do
    if make(step) ~= 0 then return end
  end
end

-- Sell at most 2 gear boxes an hour, for up to 6 hours
function sell_boxes()
  for _ = 1, 6 do
    if units(gear_boxes) == 0 then return end
    sell_here(math.min(units(gear_boxes), 2), gear_boxes)
    wait(60)
  end
end

-- Growth (#380): with 7,000 credits or more, at an exchange, buy a Mining
-- Cruiser kit and a Factory Kit I, launch a clone of this drone (it runs
-- this same program), and hand it the kit and 40 fuel

-- Buy one of a good here unless one is aboard, and wait (up to 6 game
-- hours) for it, in case nobody here has one for sale yet
function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, 6 do
    if units(resource) > 0 then return end
    wait(60)
  end
end

-- Hand the newest clone a factory kit and 40 fuel
function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, factory_kit, newest.drone)
  push(40, fuel, newest.drone)
end

-- Top the tank up to n here, cheapest first, waiting (up to 6 game hours)
-- (Waits for the fuel, not for n: computing burns a unit or two just
-- after it arrives, and waiting for n then sat out all 6 hours)
function fill_to(n)
  local need = n - units(fuel)
  if need <= 0 then return end
  buy_here(need, fuel)
  for _ = 1, 6 do
    if units(fuel) >= n then return end
    wait(60)
  end
end

-- One kit at a time. A hull already bought is launched whenever the fuel
-- allows; after equipping the clone the tank is refilled (round 19 lost 8
-- parents that left with 20 fuel)
function reinvest()
  if units(factory_kit) > 0 then equip_clone(); fill_to(60); return end
  if units(cruiser_kit) == 0 then
    if credits() < 7000 then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fill_to(90)
  if units(fuel) < 80 then return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(factory_kit)
  if units(factory_kit) > 0 then equip_clone() end
  fill_to(60)
end

function haul()
  refuel()
  go(earth)
  if units(fuel) < 60 then buy_here(30, fuel); wait(120) end
  sell_boxes()
  reinvest()
  go(moon)
end

function work()
  refuel()
  stock_up()
  build()
  if units(gear_boxes) >= box_load or stuck() then haul() end
end

-- launched() may run again (after a crash, or if the engine restarts):
-- await_kit and unpack pass once the tools are unpacked
function launched()
  await_kit()
  unpack()
  go(moon)
  while true do work() end
end
