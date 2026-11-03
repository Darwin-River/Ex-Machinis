-- Chemist: a company design for Public Forge, in Lua (economy rounds 1, 4
-- and 5; #380). Release it with the Factory Kit (Engine, Smelter, Chemical
-- Reactor and Spider).
-- It can't mine (#380: the mining gear is too big to carry with the
-- factory tools). It buys Organics (A1) and Inorganics (A1) wherever they
-- are cheapest (any drone's sell orders: the refiners', the station
-- drone's), makes polymers from them (one of each makes a polymer) and
-- sells them into the best buy orders here. It brews its own fuel from the
-- same goods (one organics and two inorganics make 30) and offers what it
-- doesn't need to other drones cheaply, so the miners, who can't brew, buy
-- from it. Copies spread out: a third work the Moon, the rest Mars orbit
-- (polymers sell at the Mars Exchange too).

polymers = 2051

-- Settings
low_fuel = 40      -- brew fuel below this
fuel_keep = 60     -- fuel it keeps; it offers the rest to other drones
fuel_price = 4     -- what it asks other drones for a unit of fuel
batch = 6          -- organics and inorganics it buys at a time
cash_floor = 300   -- credits it keeps

-- The site: the Moon or Mars orbit (by drone number); it sells there too
site_no = 0
function site() if site_no == 0 then return moon else return mars end end

-- The goods for a batch of polymers, and for fuel when low, from the
-- cheapest sellers here (buy_here); then wait an hour
function stock_up()
  if credits() < cash_floor then return end
  if units(organics_a1) < 2 then buy_here(batch, organics_a1) end
  if units(inorganics_a1) < 3 then buy_here(batch, inorganics_a1) end
  wait(60)
end

-- Fuel first, then polymers while the goods last (keeping enough for a brew)
function brew()
  if units(fuel) < low_fuel then make(fuel) end
  while units(organics_a1) > 1 and units(inorganics_a1) > 2 do
    if make(polymers) ~= 0 then return end
  end
end

-- Sell polymers here, at most 15 an hour: buyers here tend to bid less as
-- they stock up
function sell_polymers()
  local n = math.min(units(polymers), 15)
  if n > 0 then sell_here(n, polymers) end
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
-- allows; after equipping the clone the tank is refilled
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

function work()
  stock_up()
  brew()
  sell_polymers()
  reinvest()
end

-- launched() may run again (after a crash, or if the engine restarts):
-- await_kit and unpack pass once the tools are unpacked
function launched()
  await_kit()
  unpack()
  if me() % 3 == 0 then site_no = 0 else site_no = 1 end
  go(site())
  -- Other drones pull the fuel above fuel_keep
  sell_order(fuel_price, fuel_keep, fuel)
  while true do work() end
end
