-- Miner: a starter script for Public Forge, in Lua (#380). Release it
-- with the Mining Kit (Engine, Worm and Refinery).
-- It mines the richer of kunspar and wolfite at the Moon, refines it, and
-- keeps standing sell orders for its metals, priced halfway between the
-- best bid and the cheapest other seller here; once hold_cap are aboard it
-- sells what nobody bought at the market. Its kit can't brew fuel: it buys
-- it wherever it is cheapest. With grow_at credits it launches a copy; a
-- third of its copies stay at the Moon, the rest work Phobos and Deimos
-- and sell at the Mars Exchange.
-- The same drone as miner.fth, in Lua.

-- Settings: change these numbers to make it your own
low_fuel = 40      -- get fuel when below this
dry_tries = 12     -- dry tries at the Moon before trying Phobos
grow_at = 7000     -- credits at which it buys and launches a copy
                   -- (5500 at least: a hull is 3000, a kit 2000)
rest = 240         -- game minutes to rest when both Mars moons are dry
hold_cap = 16      -- metals it keeps on offer to other drones

-- The site it works: 0 the Moon, 1 Phobos, 2 Deimos. The drone you release
-- works the Moon; copies spread out by drone number
site_no, misses, copy = 0, 0, false

function site()
  if site_no == 0 then return moon elseif site_no == 1 then return phobos else return deimos end
end
-- Where it sells: the Moon Depot at the Moon, else the Mars Exchange
function market() if site() == moon then return moon else return mars end end

-- Its asking price, from the market here: halfway between the best bid and
-- the cheapest other seller; with no bid, a unit under the cheapest seller;
-- with no seller, half as much again as the best bid; 0 where nobody trades it
function ask_price(metal)
  local bid, offer = sell_price(metal), buy_price(metal)
  if bid == 0 then return math.max(offer - 1, 0) end
  if offer == 0 then return bid + bid // 2 end
  return (bid + offer) // 2
end

-- Standing sell orders for its metals, set where the market has prices;
-- a post that would change nothing costs no fuel
metals_sold = {metals_a1, metals_a2, metals_a3, inorganics_a2}
function offer()
  for _, metal in ipairs(metals_sold) do
    local price = ask_price(metal)
    if price > 0 then sell_order_if_changed(price, 0, metal) end
  end
end

function metals()
  local n = 0
  for _, metal in ipairs(metals_sold) do n = n + units(metal) end
  return n
end

function sell_out(resource)
  local n = units(resource)
  if n > 0 then sell_here(n, resource) end
end

-- Where nobody sells fuel, bid for it a little over the exchanges' price: a
-- fuel courier finds the bid in the market; the bid stops once the tank is
-- filled at the market
function refuel()
  if units(fuel) >= low_fuel then return end
  buy_here(30, fuel)
  wait(60)
  if units(fuel) < low_fuel then
    buy_order(7, 60, fuel)
    wait(120)
    if units(fuel) < low_fuel then
      go(market())
      buy_here(30, fuel)
      wait(120)
      go(site())
    end
    buy_order(1, 0, fuel)
  end
end

-- A low deposit: at the Moon, wait for it to refill (after dry_tries dry
-- tries in a row, try Phobos, if fewer drones work there); at Mars, try the
-- other moon, and rest after both are dry. Short of fuel, rest instead
function move_on()
  if site_no == 0 then
    misses = misses + 1
    if misses < dry_tries or units(fuel) < 60 or crowd(phobos) >= crowd(moon) then
      wait(60)
      return
    end
    site_no, misses = 1, 0
    go(site())
    return
  end
  if units(fuel) < 15 then wait(rest); return end
  site_no = 3 - site_no   -- Phobos <-> Deimos
  misses = misses + 1
  if misses % 2 == 0 then wait(rest) end
  go(site())
end

-- Mine until n units of a mineral are aboard, moving on when its deposit
-- here runs low, or a run brings nothing (others took it first)
function dig(mineral, n)
  while units(mineral) < n do
    if deposit(mineral) < 5 then
      move_on()
    else
      local before = units(mineral)
      mine(mineral)
      if units(mineral) == before then move_on() else misses = 0 end
    end
  end
end

-- Of two minerals, the one with more left in the deposit here
function richer(a, b)
  if deposit(a) < deposit(b) then return b end
  return a
end

-- Top the tank up to n here, cheapest first, waiting up to 6 game hours
function fill_to(n)
  local need = n - units(fuel)
  if need <= 0 then return end
  buy_here(need, fuel)
  for _ = 1, 6 do
    if units(fuel) >= n then return end
    wait(60)
  end
end

-- Buy one of a good here unless one is aboard, and wait up to 6 game hours
function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, 6 do
    if units(resource) > 0 then return end
    wait(60)
  end
end

-- Hand the newest copy a Mining Kit and 40 fuel
function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, mining_kit, newest.drone)
  push(40, fuel, newest.drone)
end

-- Growth: with grow_at credits or more, at an exchange, buy a Mining
-- Cruiser kit and a Mining Kit, launch a copy of this drone (it runs this
-- same program, with these variables) and hand it the kit and 40 fuel. A
-- copy costs about 5,000: growing pays early, not in a contest's last weeks
function reinvest()
  if units(mining_kit) > 0 then equip_clone(); fill_to(60); return end
  if units(cruiser_kit) == 0 then
    if credits() < math.max(grow_at, 5500) then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fill_to(90)
  if units(fuel) < 80 then return end
  copy = true   -- the copy gets this too: it knows it is a copy
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(mining_kit)
  if units(mining_kit) > 0 then equip_clone() end
  fill_to(60)
end

-- Sell the metals at the market, top up the tank there, then go back to the
-- site, once hold_cap are aboard: until then other drones may buy them
function sell_metals()
  if metals() < hold_cap then return end
  go(market())
  offer()
  for _, metal in ipairs(metals_sold) do sell_out(metal) end
  if units(fuel) < 50 then fill_to(80) end
  reinvest()
  go(site())
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
  refuel()
  local mineral = richer(kunspar, wolfite)
  dig(mineral, 20)
  refine(mineral)
  sell_metals()
  tell()
end

-- launched() may run again (after a crash, or if the engine restarts):
-- await_kit and unpack pass once the tools are unpacked, and a drone that
-- has already chosen its site keeps it
started = false
function launched()
  await_kit()
  unpack()
  if not started then
    if copy then site_no = me() % 3 else site_no = 0 end
    copy, started = true, true
  end
  go(site())
  offer()
  while true do work() end
end
