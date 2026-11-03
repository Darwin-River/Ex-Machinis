-- Smith: a company design for Public Forge, in Lua (economy rounds 2, 4,
-- 6, 9, 12; #380). Release it with the Factory Kit (Engine, Smelter,
-- Chemical Reactor and Spider).
-- It turns Metals (A1) into rods (two metals make an alloy, an alloy makes
-- a rod, which has sold for several times its metals). It can't mine
-- (#380: the mining gear is too big to carry with the factory tools): it
-- buys its metals, and its fuel, wherever they are cheapest, from any
-- drone's sell orders (the miners', the station drone's). Copies spread
-- out: a third work the Moon and sell their rods at Earth, a short hop
-- away; the rest work Mars orbit, where metals and rods both trade at the
-- Mars Exchange. It sells rods 4 an hour: buyers tend to bid less as they
-- stock up.

-- Settings
rod_load = 12      -- rods it takes to market (4 cargo each)
low_fuel = 40      -- buy fuel below this
batch = 20         -- metals it buys at a time
cash_floor = 300   -- credits it keeps (for fuel)

-- The site: 0 the Moon, else Mars orbit (by drone number); its rod market
site_no = 0
function site() if site_no == 0 then return moon else return mars end end
function rod_market() if site() == moon then return earth else return mars end end

-- Fuel from the cheapest sellers here (buy_here: any drone's sell orders,
-- the station drone's too)
function refuel()
  if units(fuel) < low_fuel then buy_here(30, fuel); wait(60) end
end

-- Credits above cash_floor
function spare() return math.max(credits() - cash_floor, 0) end

-- Metals for a batch, as many as the spare credits pay for (at most 30 a
-- unit), from the cheapest sellers here
function stock_up()
  if units(metals_a1) >= 2 then return end
  local n = math.min(batch, spare() // 30)
  if n > 0 then buy_here(n, metals_a1); wait(60) end
end

-- Out of metals and of money to buy them, with rods aboard: go and sell
-- them, however few (else it would wait for a full load for ever)
function stuck()
  return units(metals_a1) < 2 and spare() < 60 and units(rods) > 0
end

-- Metals (A1) into rods, two at a time; stop at the first failure
function forge_rods()
  while units(metals_a1) > 1 and units(fuel) > 10 do
    if make(alloys_a1) ~= 0 then return end
    if make(rods) ~= 0 then return end
  end
end

-- Sell at most 4 rods an hour, for up to 6 hours (buyers here tend to bid
-- less as they stock up, and bid again later); the rest go next time
function sell_rods()
  for _ = 1, 6 do
    if units(rods) == 0 then return end
    sell_here(math.min(units(rods), 4), rods)
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

-- Fuel first: every action at the market burns a unit
function haul()
  go(rod_market())
  if units(fuel) < 60 then buy_here(30, fuel); wait(120) end
  sell_rods()
  reinvest()
  go(site())
end

function work()
  refuel()
  stock_up()
  forge_rods()
  if units(rods) >= rod_load or stuck() then haul() end
end

-- launched() may run again (after a crash, or if the engine restarts):
-- await_kit and unpack pass once the tools are unpacked
function launched()
  await_kit()
  unpack()
  if me() % 3 == 0 then site_no = 0 else site_no = 1 end
  go(site())
  while true do work() end
end
