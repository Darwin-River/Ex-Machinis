-- Miner: a starter script for Ex Machinis, in Lua. It equips itself with
-- the Mining Kit (Engine, Worm and Refinery), then, over and over:
--   1. mines the richer of kunspar and wolfite at its site and refines it;
--   2. once matured, offers the metals to other drones, priced from the
--      market here (a startup trades only with the House);
--   3. once hold_cap metals are aboard, sells them where the House pays most
--      after the trip (the Moon, Mars or Earth);
--   4. with enough credits, launches a copy of itself.
-- Mining sites never run dry, but a crowded one yields less a run, so it
-- works the Moon, nearest the markets, and moves to Phobos or Deimos when a
-- run there would yield more (move_below). Set crowd_limit to start there
-- when the Moon is busy. Its kit can't brew fuel, so it buys fuel wherever
-- it is cheapest.

-- Settings: change these numbers to make it your own. It matures as it is;
-- the three marked TRY FIRST are the ones to change first
low_fuel = 40      -- TRY FIRST: fuel units below which it buys more (higher: safer, but more credits sit in its tank)
dry_tries = 12     -- dry tries at the Moon before it tries Phobos (large: stay)
grow_at = 7000     -- credits at which a matured drone launches a copy (6500 at least)
work_cash = 1500   -- credits the company keeps per drone, so copies don't starve it
grow_until = 0     -- game day after which it launches no copies (0: never stops;
                   -- a copy takes about 20 game days to earn back its cost)
crowd_limit = 0    -- drones at the Moon past which it starts at a Mars moon (0: never)
move_below = 3     -- units a run yields here below which it moves to a quieter site (0: stay)
rest = 240         -- game minutes to rest when both Mars moons are dry
hold_cap = 40      -- TRY FIRST: metals aboard before it flies off to sell (fewer: sells sooner, more trips)
min_ask = 12       -- lowest asking price per metal, in credits, where nobody bids
hour_value = 4     -- TRY FIRST: credits a game hour of its time is worth (lower: flies further for a better price)
haul_at = 60       -- metals still unsold after selling at which it hauls them onward

-- Fixed numbers the logic uses
local GAME_HOUR = 60            -- game minutes
local MAX_WAIT_HOURS = 6        -- game hours to wait for a purchase to arrive
local LOAD = 20                 -- mineral units mined before each refining
local LOW_DEPOSIT = 5           -- deposit units below which a site counts as dry
local FUEL_FLOOR = 15           -- fuel below which mining, refining and pushing stop
local FULL_TANK = 150           -- fuel units
local FUEL_MONEY = 150          -- credits raised before buying fuel
local FUEL_TOP_UP = 30          -- fuel units it first tries to buy here
local FUEL_BID_PRICE = 7        -- credits it bids per fuel unit where nobody sells
local FUEL_BID_UNTIL = 60       -- fuel units aboard its bid asks for
local FUEL_BID_WAIT = 120       -- game minutes it waits for a courier
local FUEL_TO_LEAVE_MOON = 60   -- fuel units it needs to fly to Phobos
local TRIP_FUEL_CREDITS = 30    -- a flight's fuel, in credits (10 fuel at about 3)
local HAUL_FUEL_LOW = 50        -- fuel below which it tops up before hauling
local HAUL_FUEL = 80            -- fuel it tops up to before hauling
local COPY_COST = 5000          -- credits for a copy's hull and kit
local LAUNCH_FUEL = 90          -- fuel it tops up to before launching a copy
local MIN_LAUNCH_FUEL = 80      -- fuel it needs aboard to launch a copy
local FUEL_AFTER_LAUNCH = 60    -- fuel it tops up to after launching a copy
local COPY_FUEL = 40            -- fuel handed to each new copy
local REPORT_EVERY = 4          -- rounds of work between reports home

-- Its sites, by number
local MOON, PHOBOS, DEIMOS = 0, 1, 2
local SITE_COUNT = 3

-- Saved state (globals are saved, and a copy inherits them)
site_no, misses, copy = 0, 0, false   -- its site; dry tries in a row; is it a copy
started = 0      -- the drone that chose this site (a copy inherits its parent's)
rounds = 0       -- rounds of work done
kits_tried = -1  -- game day it last tried to sell spare kits
metals_sold = {metals_a1, metals_a2, metals_a3, inorganics_a2}
markets = {moon, mars, earth}

-- Places

function place_of_site(number)
  if number == MOON then return moon elseif number == PHOBOS then return phobos else return deimos end
end

function work_site() return place_of_site(site_no) end

-- Its nearest market: the Moon Depot at the Moon, else the Mars Exchange
function market() if work_site() == moon then return moon else return mars end end

-- The quieter Mars moon when the Moon is past crowd_limit, else the Moon
function choose_starting_site()
  local at_moon = crowd(moon)
  if crowd_limit == 0 or at_moon <= crowd_limit then return MOON end
  local site = crowd(phobos) <= crowd(deimos) and PHOBOS or DEIMOS
  set_status(at_moon .. " drones at the Moon: starting at " .. place_name(place_of_site(site)))
  return site
end

-- Fuel

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

function fly_to_market_for_fuel()
  set_status("short of fuel: filling up at " .. place_name(market()))
  go(market())
  raise_cash(FUEL_MONEY); fill_to(FULL_TANK)
  go(work_site())
end

-- Buy fuel here; else bid for it; else fly to the market for it.
-- A startup doesn't bid: only the House trades with it, so no courier comes
function refuel()
  if units(fuel) >= low_fuel then return end
  raise_cash(FUEL_MONEY); buy_here(FUEL_TOP_UP, fuel)
  wait(GAME_HOUR)
  if units(fuel) >= low_fuel then return end
  local startup = house_only()
  if not startup then bid_for_fuel() end
  if units(fuel) < low_fuel then fly_to_market_for_fuel() end
  if not startup then stop_fuel_bid() end
end

-- Mining

-- Of two minerals, the one with more left in the deposit here
function richer(a, b)
  if deposit(a) < deposit(b) then return b end
  return a
end

-- At the Moon, wait for the deposit to refill; after dry_tries, try Phobos
-- if it has fuel and Phobos is less crowded
function wait_at_moon_or_try_phobos()
  if misses < dry_tries or units(fuel) < FUEL_TO_LEAVE_MOON or crowd(phobos) >= crowd(moon) then
    wait(GAME_HOUR)
    return
  end
  site_no, misses = PHOBOS, 0
  set_status("the Moon was dry " .. dry_tries .. " times: trying Phobos")
  go(work_site())
end

-- At a Mars moon, wait an hour first (waiting is free, a hop costs fuel);
-- then rest and hop to the other moon
function wait_or_hop_mars_moons()
  if misses % 2 == 1 then
    set_status("dry here: waiting an hour for it to refill")
    wait(GAME_HOUR)
    return
  end
  wait(rest)
  site_no = PHOBOS + DEIMOS - site_no   -- swap Phobos and Deimos
  set_status("dry here: trying " .. place_name(work_site()))
  go(work_site())
end

-- Refuel first: nobody sells fuel at the Mars moons
function handle_dry_deposit()
  if units(fuel) < low_fuel then refuel(); return end
  misses = misses + 1
  if site_no == MOON then wait_at_moon_or_try_phobos() else wait_or_hop_mars_moons() end
end

-- Mine until n units of a mineral are aboard; a run that brings nothing
-- means others took it first
function dig(mineral, n)
  while units(mineral) < n do
    if deposit(mineral) < LOW_DEPOSIT then
      handle_dry_deposit()
    else
      local before = units(mineral)
      mine(mineral)
      if units(mineral) == before then handle_dry_deposit() else misses = 0 end
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

-- Halfway between the best bid and the cheapest other seller. With no bid,
-- the cheapest seller's price (undercutting drives prices to nothing), never
-- under min_ask; with no seller, half as much again as the bid; 0 where
-- nobody trades it
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
  if place ~= location() then
    local there = trip_to(place)
    value = value - TRIP_FUEL_CREDITS - (there and there.hours or 0) * hour_value
  end
  if place ~= work_site() then
    local back = trip_between(place, work_site())
    value = value - (back and back.hours or 0) * hour_value
  end
  return value
end

-- The market (not `except`) where the metals aboard fetch most after the
-- trip; nil if none comes out ahead
function best_market(except)
  local value = house_value_of_metals()
  local best, best_net = nil, 0
  for _, place in ipairs(markets) do
    if place ~= except and value[place] then
      local net = value_after_round_trip(place, value[place])
      if net > best_net then best, best_net = place, net end
    end
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
  local where = best_market() or market()
  if where ~= location() then set_status("selling " .. metals_aboard() .. " metals at " .. place_name(where)) end
  go(where)
  post_sell_orders()
  sell_every_metal_here()
  if metals_aboard() >= haul_at then haul_unsold_metals() end
  fill_to(FULL_TANK)   -- a full tank saves trips just for fuel
  grow_fleet()
  go(work_site())
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

-- Credits the company needs to launch a copy: the copy, plus work_cash for
-- each drone it will then have
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

-- A crowded site yields less a run: below move_below, move to the Moon,
-- Phobos or Deimos, whichever yields at least 2 more a run now
function move_if_crowded(mineral)
  local here = mine_yield(mineral)
  if move_below == 0 or here >= move_below or units(fuel) < FUEL_TO_LEAVE_MOON then return end
  local place, best = best_yield(mineral, {moon, phobos, deimos})
  if place == work_site() or best < here + 2 then return end
  for n = MOON, DEIMOS do if place_of_site(n) == place then site_no = n end end
  set_status("crowded (" .. here .. " a run): moving to " .. place_name(place))
  go(place)
end

function work()
  refuel()
  local mineral = richer(kunspar, wolfite)
  move_if_crowded(mineral)
  dig(mineral, LOAD)
  refine(mineral)
  sell_metals_at_market()
  report_progress()
end

-- launched() runs again after a crash or an engine restart: a drone keeps
-- the site it chose, while a new copy (which inherits started) chooses afresh
function launched()
  fuel_floor(FUEL_FLOOR)   -- keeps enough fuel to buy more
  equip(mining_kit)        -- a copy waits for the kit it is handed
  if started ~= me() then
    if copy and crowd_limit > 0 then site_no = me() % SITE_COUNT else site_no = choose_starting_site() end
    copy, started = true, me()
  end
  go(work_site())
  post_sell_orders()
  while true do work() end
end
