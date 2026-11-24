-- Miner: a starter script for Ex Machinis, in Lua. Its objective is
-- metals: it equips itself with the Mining Kit (Engine, Worm and Refinery),
-- then, over and over:
--   1. mines whichever of its ores (kunspar, wolfite) pays best where it is,
--      and refines it into metals;
--   2. once matured, offers the metals to other drones, priced from the
--      market here (a startup trades only with the House);
--   3. once hold_cap metals are aboard, sells them wherever the House pays
--      most after the trip;
--   4. with enough credits, launches a copy of itself.
-- It names no place: it asks the game where its ores yield most an hour
-- now, counting the trip (a crowded site yields less). Drones on this code
-- split the nearly-as-good sites by drone number, so they spread out. It
-- moves only for a clear gain, and when something fails it chooses again.

-- Settings: change these numbers to make it your own. It matures as it is;
-- the three marked TRY FIRST are the ones to change first
low_fuel = 40      -- TRY FIRST: fuel units below which it buys more (higher: safer, but more credits sit in its tank)
grow_at = 7000     -- credits at which a matured drone launches a copy (6500 at least)
work_cash = 1500   -- credits the company keeps per drone, so copies don't starve it
grow_until = 0     -- game day after which it launches no copies (0: never stops;
                   -- a copy takes about 20 game days to earn back its cost)
stay_hours = 120   -- game hours a stay at a site lasts, over which the trip there is shared
move_margin = 25   -- percent more an hour another site must pay before it moves there
spread = 10        -- percent below the best at which a site still counts as good
look_every = 6     -- rounds of work between looks for a better site
move_below = 3     -- units a run yields here below which it looks at once (0: only now and then)
dry_tries = 6      -- dry tries in a row before it tries elsewhere
rest = 240         -- game minutes to rest when nowhere else is better
hold_cap = 40      -- TRY FIRST: metals aboard before it flies off to sell (fewer: sells sooner, more trips)
min_ask = 12       -- lowest asking price per metal, in credits, where nobody bids
hour_value = 4     -- TRY FIRST: credits a game hour of its time is worth (lower: flies further for a better price)
haul_at = 60       -- metals still unsold after selling at which it hauls them onward

-- Its objective: the ores its Refinery turns into metals, and the metals
ores = {kunspar, wolfite}
metals_sold = {metals_a1, metals_a2, metals_a3, inorganics_a2}

-- Fixed numbers the logic uses
local GAME_HOUR = 60            -- game minutes
local MAX_WAIT_HOURS = 6        -- game hours to wait for a purchase to arrive
local LOAD = 20                 -- ore units mined before each refining
local LOW_DEPOSIT = 5           -- deposit units below which a site counts as dry
local FUEL_FLOOR = 15           -- fuel below which mining, refining and pushing stop
local FULL_TANK = 150           -- fuel units
local FUEL_MONEY = 150          -- credits raised before buying fuel
local FUEL_TOP_UP = 30          -- fuel units it first tries to buy here
local FUEL_BID_PRICE = 7        -- credits it bids per fuel unit where nobody sells
local FUEL_BID_UNTIL = 60       -- fuel units aboard its bid asks for
local FUEL_BID_WAIT = 120       -- game minutes it waits for a courier
local FUEL_TO_MOVE = 60         -- fuel units it needs before it moves to another site
local TRIP_FUEL_CREDITS = 30    -- a flight's fuel, in credits (10 fuel at about 3)
local NO_TRIP = 999             -- game hours for a place it can't find a trip to
local HAUL_FUEL_LOW = 50        -- fuel below which it tops up before hauling
local HAUL_FUEL = 80            -- fuel it tops up to before hauling
local COPY_COST = 5000          -- credits for a copy's hull and kit
local LAUNCH_FUEL = 90          -- fuel it tops up to before launching a copy
local MIN_LAUNCH_FUEL = 80      -- fuel it needs aboard to launch a copy
local FUEL_AFTER_LAUNCH = 60    -- fuel it tops up to after launching a copy
local COPY_FUEL = 40            -- fuel handed to each new copy
local REPORT_EVERY = 4          -- rounds of work between reports home

-- Saved state (globals are saved, and a copy inherits them)
site, misses, copy = 0, 0, false   -- where it works (0: not chosen); dry tries in a row; is it a copy
started = 0      -- the drone that chose this site (a copy inherits its parent's)
rounds = 0       -- rounds of work done
kits_tried = -1  -- game day it last tried to sell spare kits

-- Game hours from here to a place (0 here)
function hours_to(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  return trip and trip.hours or NO_TRIP
end

-- Units an hour over a stay: a run's yield, less the trip there
function site_rate(place, yield)
  local hours = hours_to(place)
  if hours == NO_TRIP then return 0 end
  return yield * stay_hours / (stay_hours + hours)
end

-- The ore that yields most a run at a place now, and that yield
function best_ore_at(place)
  local best, most = ores[1], 0
  for _, ore in ipairs(ores) do
    local yield = mine_yield(ore, place)
    if yield > most then best, most = ore, yield end
  end
  return best, most
end

-- Where its ores are found, rated; of those within spread percent of the
-- best, the one its drone number picks, and its rate (nil: none). except:
-- a place to leave out
function choose_site(except)
  local rated, top = {}, 0
  for _, ore in ipairs(ores) do
    for _, s in ipairs(best_sites(ore)) do
      if s.place ~= except then
        local rate = site_rate(s.place, s.yield)
        if rate > (rated[s.place] or 0) then rated[s.place] = rate end
        top = math.max(top, rate)
      end
    end
  end
  local good = {}
  for place, rate in pairs(rated) do
    if rate * 100 >= top * (100 - spread) then table.insert(good, place) end
  end
  if #good == 0 or top == 0 then return nil, 0 end
  table.sort(good)
  local pick = good[me() % #good + 1]
  return pick, rated[pick]
end

function settle(place)
  if not place or place == site then return end
  site, misses = place, 0
  set_status("moving to " .. place_name(place) .. ": it pays most there now")
  if go(place) ~= 0 then site = location() end   -- couldn't fly: work here and look again later
end

-- Moves only when another site pays move_margin percent more an hour
function look_around(here)
  if units(fuel) < FUEL_TO_MOVE then return end
  local place, rate = choose_site()
  if place and rate * 100 > site_rate(site, here) * (100 + move_margin) then settle(place) end
end

-- Fuel

-- Where the House sells a tankful for least, counting the trip; nil if nowhere
function fuel_stop()
  local best, least = nil, math.huge
  for _, offer in ipairs(house_supply(fuel)) do
    if offer.price > 0 and offer.on_sale >= FUEL_TOP_UP then
      local cost = offer.price * FULL_TANK + hours_to(offer.place) * hour_value
      if cost < least then best, least = offer.place, cost end
    end
  end
  return best
end

-- Top the tank up to n here, cheapest first, waiting up to 6 game hours
function fill_to(n)
  local had = units(fuel)
  local need = n - had
  if need <= 0 then return end
  raise_cash(FUEL_MONEY); buy_here(need, fuel)
  for _ = 1, MAX_WAIT_HOURS do
    if units(fuel) > had then return end
    wait(GAME_HOUR)
  end
end

-- A bid a little over the exchanges' price calls a fuel courier
function bid_for_fuel()
  buy_order(FUEL_BID_PRICE, FUEL_BID_UNTIL, fuel)
  wait(FUEL_BID_WAIT)
end

function stop_fuel_bid() buy_order(1, 0, fuel) end   -- wanting none ends the bid

function fly_for_fuel()
  local stop = fuel_stop()
  if not stop then set_status("no fuel on sale anywhere: waiting"); wait(GAME_HOUR); return end
  set_status("short of fuel: filling up at " .. place_name(stop))
  go(stop)
  raise_cash(FUEL_MONEY); fill_to(FULL_TANK)
  go(site)
end

-- Buy fuel here; else bid for it; else fly to where it's cheapest.
-- A startup doesn't bid: only the House trades with it, so no courier comes
function refuel()
  if units(fuel) >= low_fuel then return end
  raise_cash(FUEL_MONEY); buy_here(FUEL_TOP_UP, fuel)
  wait(GAME_HOUR)
  if units(fuel) >= low_fuel then return end
  local startup = house_only()
  if not startup then bid_for_fuel() end
  if units(fuel) < low_fuel then fly_for_fuel() end
  if not startup then stop_fuel_bid() end
end

-- Mining

-- Waiting is free, a trip costs fuel: it waits, then tries elsewhere
function handle_dry_deposit()
  if units(fuel) < low_fuel then refuel(); return end
  misses = misses + 1
  if misses < dry_tries or units(fuel) < FUEL_TO_MOVE then wait(GAME_HOUR); return end
  local other = choose_site(site)
  if other then settle(other); return end
  set_status("dry here and nowhere better: resting")
  misses = 0
  wait(rest)
end

-- Mine until n units of an ore are aboard; a run that brings nothing
-- means others took it first
function dig(ore, n)
  while units(ore) < n do
    if deposit(ore) < LOW_DEPOSIT then
      handle_dry_deposit()
    else
      local before = units(ore)
      mine(ore)
      if units(ore) == before then handle_dry_deposit() else misses = 0 end
    end
  end
end

-- Selling

function metals_aboard()
  local n = 0
  for _, metal in ipairs(metals_sold) do n = n + units(metal) end
  return n
end

function sell_out(resource)
  local n = units(resource)
  if n > 0 then sell_here(n, resource) end
end

function sell_every_metal_here()
  for _, metal in ipairs(metals_sold) do sell_out(metal) end
end

-- Halfway between the best bid and the cheapest other seller; with no bid,
-- that seller's price, never under min_ask; with no seller, the bid and a
-- half; 0 where nobody trades it
function asking_price(metal)
  local bid, cheapest = sell_price(metal), buy_price(metal)
  if bid == 0 then return cheapest > 0 and math.max(cheapest, min_ask) or 0 end
  if cheapest == 0 then return bid + bid // 2 end
  return (bid + cheapest) // 2
end

-- Standing sell orders for its metals; reposting an unchanged order costs no fuel
function post_sell_orders()
  if house_only() then return end   -- a startup sells straight to the House
  for _, metal in ipairs(metals_sold) do
    local price = asking_price(metal)
    if price > 0 then sell_order_if_changed(price, 0, metal) end
  end
end

-- Credits the House at each place bids for the metals aboard, as many as it still takes today
function house_value_of_metals()
  local value = {}
  for _, metal in ipairs(metals_sold) do
    local aboard = units(metal)
    if aboard > 0 then
      for _, bid in ipairs(house_demand(metal)) do
        if bid.price > 0 and bid.left > 0 then
          value[bid.place] = (value[bid.place] or 0) + bid.price * math.min(aboard, bid.left)
        end
      end
    end
  end
  return value
end

-- A sale's value less the flight there and back to the work site
function value_after_round_trip(place, value)
  if place ~= location() then value = value - TRIP_FUEL_CREDITS - hours_to(place) * hour_value end
  if place ~= site then
    local back = trip_between(place, site)
    value = value - (back and back.hours or 0) * hour_value
  end
  return value
end

-- The exchange (not `except`) where the metals aboard fetch most after the
-- trip, of all the House bids at (a new one counts too); nil if none pays
function best_market(except)
  local best, best_net = nil, 0
  for place, value in pairs(house_value_of_metals()) do
    local net = place ~= except and value_after_round_trip(place, value) or 0
    if net > best_net then best, best_net = place, net end
  end
  return best
end

-- The nearest exchange that bids for its metals
function nearest_market()
  local best, least = location(), math.huge
  for place in pairs(house_value_of_metals()) do
    if hours_to(place) < least then best, least = place, hours_to(place) end
  end
  return best
end

function haul_unsold_metals()
  local next_best = best_market(location())
  if not next_best then return end
  if units(fuel) < HAUL_FUEL_LOW then fill_to(HAUL_FUEL) end
  set_status("hauling " .. metals_aboard() .. " unsold metals to " .. place_name(next_best))
  go(next_best)
  sell_every_metal_here()
end

-- Until hold_cap metals are aboard, other drones may buy them where it works
function sell_metals_at_market()
  if metals_aboard() < hold_cap then return end
  local where = best_market() or nearest_market()
  if where ~= location() then set_status("selling " .. metals_aboard() .. " metals at " .. place_name(where)) end
  go(where)
  post_sell_orders()
  sell_every_metal_here()
  if metals_aboard() >= haul_at then haul_unsold_metals() end
  fill_to(FULL_TANK)   -- a full tank saves trips just for fuel
  grow_fleet()
  go(site)
end

-- Growth

-- Buy one of a good here unless one is aboard, and wait up to 6 game hours
function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, MAX_WAIT_HOURS do
    if units(resource) > 0 then return end
    wait(GAME_HOUR)
  end
end

-- Hand the newest copy a Mining Kit and its fuel
function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, mining_kit, newest.drone)
  push(COPY_FUEL, fuel, newest.drone)
end

-- Credits needed to launch: the copy, and work_cash a drone after it
function grow_line()
  local health = company_health()
  local drones = health and health.working or 1
  return math.max(grow_at, COPY_COST + work_cash * (drones + 1))
end

-- With the fleet full, kits for copies that can't launch tie up credits:
-- sell them, once a game day
function sell_spare_kits(kits)
  if today() == kits_tried then return end
  kits_tried = today()
  for _, kit in ipairs(kits) do
    if units(kit) > 0 then
      set_status("the fleet is full: selling " .. units(kit) .. " spare kits")
      sell_here(units(kit), kit)
    end
  end
end

function growth_has_ended()
  return grow_until > 0 and today() > grow_until and units(cruiser_kit) == 0 and units(mining_kit) == 0
end

-- True once a cruiser kit is aboard
function buy_cruiser_kit()
  if credits() < grow_line() then return false end
  if fleet_full() then return false end
  get_one(cruiser_kit)
  return units(cruiser_kit) > 0
end

-- The copy runs this same program, with these variables
function launch_copy()
  fill_to(LAUNCH_FUEL)
  if units(fuel) < MIN_LAUNCH_FUEL then return end
  copy = true   -- set before launching, so the copy inherits it
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(mining_kit)
  if units(mining_kit) > 0 then equip_clone() end
  fill_to(FUEL_AFTER_LAUNCH)
end

function grow_fleet()
  if house_only() then return end   -- a startup saves for its debt
  if fleet_full() then sell_spare_kits({cruiser_kit, mining_kit}); return end
  if growth_has_ended() then return end
  if units(mining_kit) > 0 then equip_clone(); fill_to(FUEL_AFTER_LAUNCH); return end
  if units(cruiser_kit) == 0 and not buy_cruiser_kit() then return end
  launch_copy()
end

-- The main loop

function report_progress()
  rounds = rounds + 1
  if rounds % REPORT_EVERY ~= 0 then return end
  print("credits " .. credits() .. " fuel " .. units(fuel))
  report()
end

function work()
  refuel()
  local ore, here = best_ore_at(site)
  if rounds % look_every == 0 or here < move_below then look_around(here); ore = best_ore_at(site) end
  set_status("mining " .. resource_name(ore) .. " at " .. place_name(site))
  dig(ore, LOAD)
  refine(ore)
  sell_metals_at_market()
  report_progress()
end

-- launched() runs again after a crash or a restart: a drone keeps its
-- site; a new drone or copy (which inherits started) chooses its own
function launched()
  fuel_floor(FUEL_FLOOR)   -- keeps enough fuel to buy more
  equip(mining_kit)        -- a copy waits for the kit it is handed
  if started ~= me() then
    site = choose_site() or location()
    copy, started = true, me()
  end
  go(site)
  post_sell_orders()
  while true do work() end
end
