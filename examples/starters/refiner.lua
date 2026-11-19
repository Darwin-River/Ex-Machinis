-- Refiner: a starter script for Ex Machinis, in Lua. It equips itself
-- with the Mining Kit (Engine, Worm and Refinery), mines yalspar and
-- vladnium and refines them into Organics (A1), Inorganics (A1) and (A2),
-- and Metals (A1) and (A2): what factories need to brew fuel and make
-- polymers. It keeps standing sell orders for its goods, sells what nobody
-- bought at the market, and buys its fuel. Copies spread out: a third work
-- the Moon, the rest Phobos and Deimos, selling at the Mars Exchange.

-- Settings: change these numbers to make it your own
low_fuel = 40      -- fuel (units) below which it buys more
dry_tries = 12     -- dry tries in a row at the Moon before it tries Phobos
grow_at = 7000     -- credits at which a matured company launches a copy
                   -- (at least 6500: a hull 3000, a kit 2000, 1500 to work with)
work_cash = 1500   -- credits kept for each drone the company will have after a launch
grow_until = 0     -- game day after which it launches no copies (0: no limit);
                   -- a copy takes about 20 game days to pay back
rest = 240         -- game minutes to rest when both Mars moons are dry
hold_cap = 48      -- refined goods (units) it keeps on offer before a selling trip
min_ask = 12       -- least asking price (credits) where nobody bids
hour_value = 4     -- credits a game hour of its time is worth (lower: sells further away)

-- Fixed numbers the plan uses
local HOUR = 60                    -- game minutes
local FUEL_FLOOR = 15              -- fuel actions leave in the tank, to buy more
local CASH_FOR_FUEL = 150          -- credits raised before buying fuel
local FUEL_TO_BUY = 30             -- units bought when low
local FUEL_BID_PRICE = 7           -- credits a unit, bid where nobody sells fuel
local FUEL_BID_UNITS = 60
local FUEL_TO_TRY_PHOBOS = 60
local LOW_DEPOSIT = 5              -- mining runs left at which a deposit is dry
local DELIVERY_HOURS = 6           -- game hours it waits for a purchase to arrive
local COPY_COST = 5000             -- a hull and a kit
local FUEL_FOR_COPY = 40
local TANK_TO_LAUNCH, FUEL_NEEDED_TO_LAUNCH = 90, 80
local TANK_AFTER_LAUNCH = 60
local TANK_AFTER_SELLING = 100
local YALSPAR_LOAD, VLADNIUM_LOAD = 16, 12
local REPORT_EVERY = 4             -- work rounds between reports home
local TRIP_FUEL_CREDITS = 30       -- a flight's fuel, in credits (10 fuel at about 3)
local MOON_SITE, PHOBOS_SITE, DEIMOS_SITE, SITE_COUNT = 0, 1, 2, 3

local goods_sold = {metals_a1, metals_a2, organics_a1, inorganics_a1, inorganics_a2}
local markets = {moon, mars, earth}

-- The drone you release works the Moon; copies spread out by drone number
site_number, dry_count, copy = MOON_SITE, 0, false

function work_site()
  if site_number == MOON_SITE then return moon
  elseif site_number == PHOBOS_SITE then return phobos
  else return deimos end
end

-- The Moon Depot at the Moon, else the Mars Exchange
function selling_place()
  if work_site() == moon then return moon else return mars end
end

-- Halfway between the best bid and the cheapest other seller. With no bid,
-- the cheapest seller's price, never under min_ask (undercutting a unit
-- each drove prices down to 2); with no seller, half as much again as the
-- best bid; 0 where nobody trades it
function asking_price(good)
  local best_bid, cheapest_offer = sell_price(good), buy_price(good)
  if best_bid == 0 then
    return cheapest_offer > 0 and math.max(cheapest_offer, min_ask) or 0
  end
  if cheapest_offer == 0 then return best_bid + best_bid // 2 end
  return (best_bid + cheapest_offer) // 2
end

-- An unchanged order costs no fuel to post again
function post_sell_orders()
  if house_only() then return end   -- a startup sells straight to the House
  for _, good in ipairs(goods_sold) do
    local price = asking_price(good)
    if price > 0 then sell_order_if_changed(price, 0, good) end
  end
end

function goods_aboard()
  local total = 0
  for _, good in ipairs(goods_sold) do total = total + units(good) end
  return total
end

function sell_out(good)
  local aboard = units(good)
  if aboard > 0 then sell_here(aboard, good) end
end

-- Buy here; failing that, bid for fuel (a courier may fill the bid); failing
-- that, fetch it from the market. The bid is withdrawn either way
function refuel()
  if units(fuel) >= low_fuel then return end
  set_status("low on fuel (" .. units(fuel) .. "): buying some here")
  raise_cash(CASH_FOR_FUEL); buy_here(FUEL_TO_BUY, fuel)
  wait(HOUR)
  if units(fuel) >= low_fuel then return end
  set_status("no fuel on sale here: bidding for some")
  buy_order(FUEL_BID_PRICE, FUEL_BID_UNITS, fuel)
  wait(2 * HOUR)
  if units(fuel) < low_fuel then
    set_status("still low on fuel: going to " .. place_name(selling_place()) .. " for some")
    go(selling_place())
    raise_cash(CASH_FOR_FUEL); buy_here(FUEL_TO_BUY, fuel)
    wait(2 * HOUR)
    go(work_site())
  end
  buy_order(1, 0, fuel)   -- wants 0: withdraws the bid
end

-- Wait for the Moon to refill, unless it has been dry dry_tries times in a
-- row and Phobos is less crowded and in reach
function wait_at_moon_or_try_phobos()
  dry_count = dry_count + 1
  if dry_count < dry_tries or units(fuel) < FUEL_TO_TRY_PHOBOS or crowd(phobos) >= crowd(moon) then
    set_status("the Moon is dry: waiting for it to refill (" .. dry_count .. " of " .. dry_tries .. " tries)")
    wait(HOUR)
    return
  end
  site_number, dry_count = PHOBOS_SITE, 0
  set_status("the Moon was dry " .. dry_tries .. " times: trying Phobos")
  go(work_site())
end

-- Fly to the other Mars moon, resting once both have been dry. Refuel
-- first, or it could rest at a dry moon with too little fuel to leave
function try_other_mars_moon()
  if units(fuel) < low_fuel then refuel(); return end
  site_number = PHOBOS_SITE + DEIMOS_SITE - site_number
  dry_count = dry_count + 1
  if dry_count % 2 == 0 then
    set_status("both Mars moons are dry: resting " .. rest .. " game minutes")
    wait(rest)
  end
  set_status("dry here: trying " .. place_name(work_site()))
  go(work_site())
end

function move_on_from_dry_site()
  if site_number == MOON_SITE then wait_at_moon_or_try_phobos() else try_other_mars_moon() end
end

-- A run that brings nothing means others took the ore first
function dig(mineral, wanted)
  while units(mineral) < wanted do
    if deposit(mineral) < LOW_DEPOSIT then
      move_on_from_dry_site()
    else
      local before = units(mineral)
      mine(mineral)
      if units(mineral) == before then move_on_from_dry_site() else dry_count = 0 end
    end
  end
end

-- Waits for the fuel to arrive, not to reach the target: computing burns a
-- unit or two after delivery
function fill_to(target)
  local had = units(fuel)
  local missing = target - had
  if missing <= 0 then return end
  raise_cash(CASH_FOR_FUEL); buy_here(missing, fuel)
  for _ = 1, DELIVERY_HOURS do
    if units(fuel) > had then return end
    wait(HOUR)
  end
end

function get_one(good)
  if units(good) > 0 then return end
  buy_here(1, good)
  for _ = 1, DELIVERY_HOURS do
    if units(good) > 0 then return end
    wait(HOUR)
  end
end

function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, mining_kit, newest.drone)
  push(FUEL_FOR_COPY, fuel, newest.drone)
end

-- Credits the company needs before launching a copy
function grow_line()
  local health = company_health()
  local drones = health and health.working or 1
  return math.max(grow_at, COPY_COST + work_cash * (drones + 1))
end

-- Kits that can never launch are credits tied up for good, so a full
-- fleet sells them, once a game day
kits_sold_on_day = -1
function sell_spare_kits(kits)
  if today() == kits_sold_on_day then return end
  kits_sold_on_day = today()
  for _, kit in ipairs(kits) do
    if units(kit) > 0 then
      set_status("the fleet is full: selling " .. units(kit) .. " spare kits")
      sell_here(units(kit), kit)
    end
  end
end

function past_grow_until_with_no_kit()
  return grow_until > 0 and today() > grow_until and units(cruiser_kit) == 0 and units(mining_kit) == 0
end

-- A kit still aboard from a failed hand-over goes to the copy first; a
-- hull already bought launches whenever the fuel allows
function reinvest()
  if house_only() then return end   -- a startup keeps its credits for its debt
  if fleet_full() then sell_spare_kits({cruiser_kit, mining_kit}); return end
  if past_grow_until_with_no_kit() then return end
  if units(mining_kit) > 0 then equip_clone(); fill_to(TANK_AFTER_LAUNCH); return end
  if units(cruiser_kit) == 0 then
    if credits() < grow_line() then return end
    if fleet_full() then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fill_to(TANK_TO_LAUNCH)
  if units(fuel) < FUEL_NEEDED_TO_LAUNCH then return end
  copy = true   -- the copy inherits this, so it knows it is one
  set_status("launching a copy")
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(mining_kit)
  if units(mining_kit) > 0 then equip_clone() end
  fill_to(TANK_AFTER_LAUNCH)
end

-- Credits the House at each place bids for the goods aboard, as many as it still takes today
function house_value_of_goods()
  local value = {}
  for _, good in ipairs(goods_sold) do
    local aboard = units(good)
    if aboard > 0 then
      for _, bid in ipairs(house_demand(good)) do
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

-- The market where the goods aboard fetch most after the trip (the Moon's
-- bids sag as it fills; Earth's often pay more); nil if none comes out ahead
function best_market()
  local value = house_value_of_goods()
  local best, best_net = nil, 0
  for _, place in ipairs(markets) do
    if value[place] then
      local net = value_after_round_trip(place, value[place])
      if net > best_net then best, best_net = place, net end
    end
  end
  return best
end

-- Until hold_cap goods are aboard, other drones may buy them here
function sell_when_hold_is_full()
  if goods_aboard() < hold_cap then return end
  local where = best_market() or selling_place()
  set_status("selling " .. goods_aboard() .. " refined goods at " .. place_name(where))
  go(where)
  post_sell_orders()
  for _, good in ipairs(goods_sold) do sell_out(good) end
  fill_to(TANK_AFTER_SELLING)
  reinvest()
  go(work_site())
end

rounds_worked = 0
function report_home_now_and_then()
  rounds_worked = rounds_worked + 1
  if rounds_worked % REPORT_EVERY ~= 0 then return end
  print("credits " .. credits() .. " fuel " .. units(fuel))
  report()
end

function mine_and_refine(mineral, mineral_name, load)
  set_status("mining " .. mineral_name .. " at " .. place_name(work_site()))
  dig(mineral, load)
  set_status("refining " .. mineral_name)
  refine(mineral)
end

function work()
  refuel()
  mine_and_refine(yalspar, "yalspar", YALSPAR_LOAD)
  mine_and_refine(vladnium, "vladnium", VLADNIUM_LOAD)
  sell_when_hold_is_full()
  report_home_now_and_then()
end

-- launched() may run again after a crash or restart: a drone that has
-- chosen its site keeps it. A copy inherits started holding its parent's
-- number, so it chooses afresh
started = 0
function launched()
  fuel_floor(FUEL_FLOOR)
  equip(mining_kit)   -- a copy waits for the kit its parent hands it
  if started ~= me() then
    if copy then site_number = me() % SITE_COUNT else site_number = MOON_SITE end
    copy, started = true, me()
  end
  go(work_site())
  post_sell_orders()
  while true do work() end
end
