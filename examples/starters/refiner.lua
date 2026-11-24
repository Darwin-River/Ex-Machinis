-- Refiner: a starter script for Ex Machinis, in Lua. It produces what
-- factories need to brew fuel and make polymers: organics, inorganics and
-- metals, refined from yalspar and vladnium. It mines wherever its ores pay
-- most an hour now and sells wherever the House pays most.
-- It names no place: every "where" is a question to the game.

-- Settings: change these to make it your own. It matures as it is; start
-- with the three marked TRY FIRST.
refuel_below = 40                 -- TRY FIRST: fuel units (higher: safer, but more credits sit in its tank)
goods_per_sale = 48               -- TRY FIRST: fewer sells sooner, in more trips
credits_per_game_hour = 4         -- TRY FIRST: what its time is worth on a trip (lower: sells further away)
copy_at_credits = 7000            -- once matured
credits_kept_per_drone = 1500
last_copy_day = 0                 -- 0: no last day
hours_per_stay = 120              -- game hours it expects to stay at a site
percent_better_to_move = 25
percent_from_best_still_good = 10
rounds_between_looks = 6
look_now_if_run_below = 3         -- units a run of yalspar
dry_hours_before_moving = 6
minutes_to_rest_when_stuck = 240
lowest_ask = 12                   -- credits a unit

ores = {yalspar, vladnium}
ore_per_refining = {[yalspar] = 16, [vladnium] = 12}
goods_sold = {metals_a1, metals_a2, organics_a1, inorganics_a1, inorganics_a2}

local GAME_HOUR = 60
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local DRY_DEPOSIT = 5
local FUEL_FLOOR = 15
local FULL_TANK = 150
local FUEL_AFTER_SELLING = 100
local CREDITS_FOR_FUEL = 150
local FUEL_TOP_UP = 30
local FUEL_BID_PRICE = 7
local FUEL_BID_UNITS = 60
local MINUTES_TO_WAIT_FOR_COURIER = 120
local FUEL_NEEDED_TO_MOVE = 60
local TRIP_FUEL_CREDITS = 30
local UNREACHABLE = 999
local COPY_COST = 5000
local FUEL_TO_LAUNCH = 90
local LEAST_FUEL_TO_LAUNCH = 80
local FUEL_AFTER_LAUNCH = 60
local FUEL_FOR_COPY = 40
local ROUNDS_BETWEEN_REPORTS = 4

-- Globals survive a restart, and a copy inherits them
site, dry_hours, copy = 0, 0, false
started = 0
rounds = 0
day_spare_kits_sold = -1

-- Choosing where to mine

function hours_to(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  return trip and trip.hours or UNREACHABLE
end

function units_an_hour(place, yield)
  local hours = hours_to(place)
  if hours == UNREACHABLE then return 0 end
  return yield * hours_per_stay / (hours_per_stay + hours)
end

function yield_of_all_ores_at(place)
  local total = 0
  for _, ore in ipairs(ores) do total = total + mine_yield(ore, place) end
  return total
end

function rate_sites(except)
  local yields, rates, top = {}, {}, 0
  for _, ore in ipairs(ores) do
    for _, s in ipairs(best_sites(ore)) do yields[s.place] = (yields[s.place] or 0) + s.yield end
  end
  for place, yield in pairs(yields) do
    if place ~= except then
      rates[place] = units_an_hour(place, yield)
      top = math.max(top, rates[place])
    end
  end
  return rates, top
end

-- Drones on this code split the good sites by drone number rather than all
-- taking the best; not math.random, which a rebuilt drone would roll anew
function choose_site(except)
  local rates, top = rate_sites(except)
  local good = {}
  for place, rate in pairs(rates) do
    if rate * 100 >= top * (100 - percent_from_best_still_good) then table.insert(good, place) end
  end
  if #good == 0 or top == 0 then return nil, 0 end
  table.sort(good)
  local pick = good[me() % #good + 1]
  return pick, rates[pick]
end

function move_to(place)
  if not place or place == site then return end
  site, dry_hours = place, 0
  set_status("moving to " .. place_name(place) .. ": it pays most there now")
  if go(place) ~= 0 then site = location() end
end

function move_if_clearly_better()
  if units(fuel) < FUEL_NEEDED_TO_MOVE then return end
  local place, rate = choose_site()
  local rate_here = units_an_hour(site, yield_of_all_ores_at(site))
  if place and rate * 100 > rate_here * (100 + percent_better_to_move) then move_to(place) end
end

-- Fuel

function cheapest_fuel_stop()
  local best, least = nil, math.huge
  for _, offer in ipairs(house_supply(fuel)) do
    if offer.price > 0 and offer.on_sale >= FUEL_TOP_UP then
      local cost = offer.price * FULL_TANK + hours_to(offer.place) * credits_per_game_hour
      if cost < least then best, least = offer.place, cost end
    end
  end
  return best
end

function fill_tank_to(target)
  local had = units(fuel)
  if target <= had then return end
  raise_cash(CREDITS_FOR_FUEL); buy_here(target - had, fuel)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(fuel) > had then return end
    wait(GAME_HOUR)
  end
end

function bid_for_a_fuel_courier()
  set_status("no fuel on sale here: bidding for some")
  buy_order(FUEL_BID_PRICE, FUEL_BID_UNITS, fuel)
  wait(MINUTES_TO_WAIT_FOR_COURIER)
end

function withdraw_fuel_bid() buy_order(1, 0, fuel) end

function fly_for_fuel()
  local stop = cheapest_fuel_stop()
  if not stop then set_status("no fuel on sale anywhere: waiting"); wait(GAME_HOUR); return end
  set_status("short of fuel: filling up at " .. place_name(stop))
  go(stop)
  fill_tank_to(FULL_TANK)
  go(site)
end

-- Only the House trades with a startup, so a startup's bid brings no courier
function refuel()
  if units(fuel) >= refuel_below then return end
  raise_cash(CREDITS_FOR_FUEL); buy_here(FUEL_TOP_UP, fuel)
  wait(GAME_HOUR)
  if units(fuel) >= refuel_below then return end
  local startup = house_only()
  if not startup then bid_for_a_fuel_courier() end
  if units(fuel) < refuel_below then fly_for_fuel() end
  if not startup then withdraw_fuel_bid() end
end

-- Mining and refining

function rest_when_stuck()
  set_status("dry here and nowhere better: resting")
  dry_hours = 0
  wait(minutes_to_rest_when_stuck)
end

function handle_dry_deposit()
  if units(fuel) < refuel_below then refuel(); return end
  dry_hours = dry_hours + 1
  if dry_hours < dry_hours_before_moving or units(fuel) < FUEL_NEEDED_TO_MOVE then wait(GAME_HOUR); return end
  local elsewhere = choose_site(site)
  if elsewhere then move_to(elsewhere) else rest_when_stuck() end
end

function mine_until_aboard(ore, wanted)
  while units(ore) < wanted do
    if deposit(ore) < DRY_DEPOSIT then
      handle_dry_deposit()
    else
      local before = units(ore)
      mine(ore)
      if units(ore) == before then handle_dry_deposit() else dry_hours = 0 end
    end
  end
end

function mine_and_refine_every_ore_found_here()
  for _, ore in ipairs(ores) do
    if mine_yield(ore) > 0 then
      set_status("mining " .. resource_name(ore) .. " at " .. place_name(site))
      mine_until_aboard(ore, ore_per_refining[ore])
      refine(ore)
    end
  end
end

-- Selling

function goods_aboard()
  local n = 0
  for _, good in ipairs(goods_sold) do n = n + units(good) end
  return n
end

function sell_all_goods_here()
  for _, good in ipairs(goods_sold) do
    if units(good) > 0 then sell_here(units(good), good) end
  end
end

-- Undercutting the cheapest seller drives prices to nothing
function asking_price(good)
  local bid, cheapest = sell_price(good), buy_price(good)
  if bid == 0 then return cheapest > 0 and math.max(cheapest, lowest_ask) or 0 end
  if cheapest == 0 then return bid + bid // 2 end
  return (bid + cheapest) // 2
end

function post_sell_orders()
  if house_only() then return end
  for _, good in ipairs(goods_sold) do
    local price = asking_price(good)
    if price > 0 then sell_order_if_changed(price, 0, good) end
  end
end

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

function value_after_round_trip(place, value)
  if place ~= location() then value = value - TRIP_FUEL_CREDITS - hours_to(place) * credits_per_game_hour end
  if place ~= site then
    local back = trip_between(place, site)
    value = value - (back and back.hours or 0) * credits_per_game_hour
  end
  return value
end

function best_market()
  local best, best_net = nil, 0
  for place, value in pairs(house_value_of_goods()) do
    local net = value_after_round_trip(place, value)
    if net > best_net then best, best_net = place, net end
  end
  return best
end

function nearest_market()
  local nearest, least = location(), math.huge
  for place in pairs(house_value_of_goods()) do
    if hours_to(place) < least then nearest, least = place, hours_to(place) end
  end
  return nearest
end

function sell_goods_when_hold_is_full()
  if goods_aboard() < goods_per_sale then return end
  local where = best_market() or nearest_market()
  set_status("selling " .. goods_aboard() .. " refined goods at " .. place_name(where))
  go(where)
  post_sell_orders()
  sell_all_goods_here()
  fill_tank_to(FUEL_AFTER_SELLING)
  grow_fleet()
  go(site)
end

-- Growing

function buy_one(good)
  if units(good) > 0 then return end
  buy_here(1, good)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(good) > 0 then return end
    wait(GAME_HOUR)
  end
end

function equip_newest_copy()
  local copies = my_clones()
  local newest = copies[#copies]
  if not newest then return end
  push(1, mining_kit, newest.drone)
  push(FUEL_FOR_COPY, fuel, newest.drone)
end

function credits_needed_to_grow()
  local health = company_health()
  local drones = health and health.working or 1
  return math.max(copy_at_credits, COPY_COST + credits_kept_per_drone * (drones + 1))
end

function sell_kits_a_full_fleet_cant_use()
  if today() == day_spare_kits_sold then return end
  day_spare_kits_sold = today()
  for _, kit in ipairs({cruiser_kit, mining_kit}) do
    if units(kit) > 0 then
      set_status("the fleet is full: selling " .. units(kit) .. " spare kits")
      sell_here(units(kit), kit)
    end
  end
end

function past_last_copy_day()
  return last_copy_day > 0 and today() > last_copy_day and units(cruiser_kit) == 0 and units(mining_kit) == 0
end

function have_a_hull()
  if units(cruiser_kit) > 0 then return true end
  if credits() < credits_needed_to_grow() or fleet_full() then return false end
  buy_one(cruiser_kit)
  return units(cruiser_kit) > 0
end

function launch_copy()
  fill_tank_to(FUEL_TO_LAUNCH)
  if units(fuel) < LEAST_FUEL_TO_LAUNCH then return end
  copy = true
  set_status("launching a copy")
  if launch(me(), cruiser_kit) ~= 0 then return end
  buy_one(mining_kit)
  if units(mining_kit) > 0 then equip_newest_copy() end
  fill_tank_to(FUEL_AFTER_LAUNCH)
end

-- A startup keeps its credits for its debt
function grow_fleet()
  if house_only() then return end
  if fleet_full() then sell_kits_a_full_fleet_cant_use(); return end
  if past_last_copy_day() then return end
  if units(mining_kit) > 0 then equip_newest_copy(); fill_tank_to(FUEL_AFTER_LAUNCH); return end
  if have_a_hull() then launch_copy() end
end

-- Working

function report_now_and_then()
  rounds = rounds + 1
  if rounds % ROUNDS_BETWEEN_REPORTS ~= 0 then return end
  print("credits " .. credits() .. " fuel " .. units(fuel))
  report()
end

function time_to_look_around()
  return rounds % rounds_between_looks == 0 or mine_yield(yalspar) < look_now_if_run_below
end

function work()
  refuel()
  if time_to_look_around() then move_if_clearly_better() end
  mine_and_refine_every_ore_found_here()
  sell_goods_when_hold_is_full()
  report_now_and_then()
end

-- launched() runs again after a crash or a restart. A copy inherits its
-- parent's started, so it chooses a site of its own
function launched()
  fuel_floor(FUEL_FLOOR)
  equip(mining_kit)
  if started ~= me() then
    site = choose_site() or location()
    copy, started = true, me()
  end
  go(site)
  post_sell_orders()
  while true do work() end
end
