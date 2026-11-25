-- Maker: a starter script for Ex Machinis, in Lua. It produces rods. It
-- buys Metals (A1) wherever they cost least, makes them into alloys and the
-- alloys into rods with its Factory Kit, and sells the rods wherever they
-- pay most. It names no place: every "where" is a question to the game.

-- Settings: change these to make it your own. It matures as it is; start
-- with the three marked TRY FIRST.
rods_per_selling_trip = 12          -- TRY FIRST: fewer sells sooner, in more trips (4 cargo a rod)
metals_per_purchase = 20            -- TRY FIRST: more means fewer trips, but more credits tied up
credits_more_elsewhere_to_fly = 20  -- TRY FIRST: a rod, before it flies off to sell
refuel_below = 40                   -- fuel units
stop_making_below_fuel = 25
copy_at_credits = 7000              -- once matured
credits_kept_per_drone = 1500
last_copy_day = 0                   -- 0: no last day
credits_kept_for_fuel = 300         -- when the tank is low
credits_kept_otherwise = 60
metals_per_rod = 2

local HOUR = 60
local FUEL_FLOOR = 15
local FUEL_TOP_UP = 30
local CREDITS_FOR_FUEL = 150
local REFUEL_AFTER_HAUL_BELOW = 60
local CREDITS_FOR_METALS = 60
local GUESSED_METAL_PRICE = 30
local MOST_OF_HOUSE_STOCK_PERCENT = 10
local LEAST_FROM_HOUSE = 5
local TRIP_FUEL_CREDITS = 30
local CREDITS_PER_HOUR_AWAY = 10
local PERCENT_KEPT_AFTER_TAX = 95
local RODS_SOLD_AN_HOUR = 4
local HOURS_SELLING_AT_ONE_PLACE = 6
local FAILED_MAKES_BEFORE_PAUSE = 3
local COPY_COST = 5000
local TOP_UP_BEFORE_LAUNCH_BELOW = 80
local FUEL_TO_LAUNCH = 90
local FUEL_FOR_COPY = 40
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local ROUNDS_BETWEEN_REPORTS = 4

-- Globals survive a restart, and a copy inherits them
failed_makes_in_a_row = 0
day_spare_kits_sold = -1
rounds = 0
copy = false

-- Fuel and credits

function buy_fuel_then_wait(minutes)
  raise_cash(CREDITS_FOR_FUEL); buy_here(FUEL_TOP_UP, fuel)
  wait(minutes)
end

function refuel()
  if units(fuel) < refuel_below then buy_fuel_then_wait(HOUR) end
end

function spare()
  local kept = units(fuel) < refuel_below and credits_kept_for_fuel or credits_kept_otherwise
  return math.max(credits() - kept, 0)
end

function after_sales_tax(amount)
  return amount * PERCENT_KEPT_AFTER_TAX // 100
end

function round_trip_cost(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  local hours = trip and trip.hours * 2 or 0
  return TRIP_FUEL_CREDITS + hours * CREDITS_PER_HOUR_AWAY
end

function best_house_bid_for_a_rod()
  local best = 0
  for _, bid in ipairs(house_demand(rods)) do
    if bid.price > 0 and bid.left > 0 then best = math.max(best, after_sales_tax(bid.price)) end
  end
  return best
end

-- Buying metals

function metals_the_house_will_sell(offer)
  local share_of_stock = math.max(LEAST_FROM_HOUSE, offer.on_sale * MOST_OF_HOUSE_STOCK_PERCENT // 100)
  return math.min(metals_per_purchase, offer.on_sale, share_of_stock)
end

-- The trip's cost is shared over what it may buy there
function cheapest_house_metals()
  local best_place, best_cost, best_price = nil, math.huge, 0
  for _, offer in ipairs(house_supply(metals_a1)) do
    local can_buy = metals_the_house_will_sell(offer)
    if offer.price > 0 and can_buy >= metals_per_rod then
      local cost = offer.price + round_trip_cost(offer.place) / can_buy
      if cost < best_cost then best_place, best_cost, best_price = offer.place, cost, offer.price end
    end
  end
  return best_place, best_cost, best_price
end

function go_to_cheapest_house_metals()
  local place, cost, price = cheapest_house_metals()
  if not place then
    set_status("no metals on sale from the House: waiting for tomorrow's")
    wait(4 * HOUR)
    return nil
  end
  if cost * metals_per_rod >= best_house_bid_for_a_rod() then
    set_status("rods sell for " .. best_house_bid_for_a_rod() .. ", their metals cost "
      .. math.floor(cost * metals_per_rod) .. ": waiting")
    wait(4 * HOUR)
    return nil
  end
  if place ~= location() then
    set_status("buying metals at " .. place_name(place))
    go(place)
  end
  return price
end

function cheapest_metals_anywhere()
  local best = where_to_buy(metals_a1, metals_per_purchase, CREDITS_PER_HOUR_AWAY)[1]
  return best and best.place
end

-- A startup picks its House exchange in buy_metals
function go_to_cheapest_metals()
  if house_only() then return end
  local place = cheapest_metals_anywhere()
  if place and place ~= location() then
    set_status("buying metals at " .. place_name(place))
    go(place)
  end
end

function buy_metals_here(price)
  if price <= 0 then price = GUESSED_METAL_PRICE end
  local amount = math.min(metals_per_purchase, spare() // price)
  if amount > 0 then
    buy_here(amount, metals_a1)
    wait(HOUR)
  end
end

function buy_metals()
  if units(metals_a1) >= metals_per_rod then return end
  if spare() < CREDITS_FOR_METALS then
    set_status("short of credits for metals")
    wait(2 * HOUR)
    return
  end
  if not house_only() and buy_price(metals_a1) == 0 then go_to_cheapest_metals() end
  local price = buy_price(metals_a1)
  if house_only() then
    price = go_to_cheapest_house_metals()
    if not price then return end
  end
  buy_metals_here(price)
end

-- Making rods

function make_a_rod()
  local result = make(alloys_a1)
  if result == 0 then result = make(rods) end
  return result
end

function pause_after_repeated_failures(code)
  failed_makes_in_a_row = failed_makes_in_a_row + 1
  if failed_makes_in_a_row >= FAILED_MAKES_BEFORE_PAUSE then
    set_status("making failed " .. failed_makes_in_a_row .. " times in a row (" .. code .. "): waiting 4 game hours")
    wait(4 * HOUR)
  end
end

function make_rods()
  while units(metals_a1) >= metals_per_rod and units(fuel) > stop_making_below_fuel do
    local result = make_a_rod()
    if result ~= 0 then pause_after_repeated_failures(result); return end
    failed_makes_in_a_row = 0
  end
end

-- Selling rods

-- Buyers bid less as they stock up, and bid again later
function sell_rods_here_a_few_an_hour()
  for _ = 1, HOURS_SELLING_AT_ONE_PLACE do
    if units(rods) == 0 then return end
    sell_here(math.min(units(rods), RODS_SOLD_AN_HOUR), rods)
    wait(HOUR)
  end
end

function best_rod_market()
  return where_to_sell(rods, math.max(units(rods), 1), CREDITS_PER_HOUR_AWAY)[1]
end

-- A startup sells only to the House
function house_rods()
  local aboard, best_place, best_net = units(rods), nil, 0
  for _, bid in ipairs(house_demand(rods)) do
    local sold = math.min(aboard, bid.left)
    if bid.price > 0 and sold > 0 then
      local net = after_sales_tax(bid.price * sold) - round_trip_cost(bid.place)
      if net > best_net then best_place, best_net = bid.place, net end
    end
  end
  return best_place
end

function rods_sell_best_here()
  if house_only() then return house_rods() == location() end
  local bid_here = sell_price(rods)
  local best = best_rod_market()
  return bid_here > 0 and (not best or best.place == location() or bid_here + credits_more_elsewhere_to_fly >= best.price)
end

function market_for_rods()
  if house_only() then return house_rods() end
  local best = best_rod_market()
  if best and best.net > 0 then return best.place end
  return house_rods()
end

function stuck_with_rods()
  return units(metals_a1) < metals_per_rod and spare() < CREDITS_FOR_METALS and units(rods) > 0
end

function haul_rods()
  local market = market_for_rods()
  if not market then
    set_status("no bid for " .. units(rods) .. " rods pays the trip: waiting")
    wait(2 * HOUR)
    return
  end
  set_status("hauling " .. units(rods) .. " rods to " .. place_name(market))
  local result = go(market)
  if result ~= 0 then
    set_status("couldn't fly to " .. place_name(market) .. " (" .. result .. "): selling here if anyone buys")
    sell_rods_here_a_few_an_hour()
    wait(2 * HOUR)
    return
  end
  if units(fuel) < REFUEL_AFTER_HAUL_BELOW then buy_fuel_then_wait(2 * HOUR) end
  sell_rods_here_a_few_an_hour()
  go_to_cheapest_metals()
end

function sell_or_haul_rods()
  if units(rods) > 0 and rods_sell_best_here() then
    sell_rods_here_a_few_an_hour()
  elseif units(rods) >= rods_per_selling_trip or stuck_with_rods() then
    haul_rods()
  end
end

-- Growing

function buy_one(good)
  if units(good) > 0 then return end
  buy_here(1, good)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(good) > 0 then return end
    wait(HOUR)
  end
end

function credits_needed_to_grow()
  local health = company_health()
  local drones = health and health.working or 1
  return math.max(copy_at_credits, COPY_COST + credits_kept_per_drone * (drones + 1))
end

function sell_kits_a_full_fleet_cant_use()
  if today() == day_spare_kits_sold then return end
  day_spare_kits_sold = today()
  for _, kit in ipairs({cruiser_kit, factory_kit}) do
    if units(kit) > 0 then
      set_status("the fleet is full: selling " .. units(kit) .. " spare kits")
      sell_here(units(kit), kit)
    end
  end
end

function past_last_copy_day()
  return last_copy_day > 0 and today() > last_copy_day and units(cruiser_kit) == 0 and units(factory_kit) == 0
end

function have_kits_for_a_copy()
  buy_one(cruiser_kit)
  buy_one(factory_kit)
  return units(cruiser_kit) > 0 and units(factory_kit) > 0
end

function fuel_up_for_launch()
  if units(fuel) < TOP_UP_BEFORE_LAUNCH_BELOW then
    raise_cash(CREDITS_FOR_FUEL); buy_here(FUEL_TO_LAUNCH - units(fuel), fuel); wait(HOUR)
  end
end

function launch_copy()
  copy = true
  if launch(me(), cruiser_kit) ~= 0 then return end
  local copies = my_clones()
  local newest = copies[#copies]
  if newest then
    push(1, factory_kit, newest.drone)
    push(FUEL_FOR_COPY, fuel, newest.drone)
  end
end

-- A startup keeps its credits for its debt
function grow()
  if house_only() then return end
  if fleet_full() then sell_kits_a_full_fleet_cant_use(); return end
  if past_last_copy_day() then return end
  if credits() < credits_needed_to_grow() then return end
  if not have_kits_for_a_copy() then return end
  fuel_up_for_launch()
  launch_copy()
end

-- Working

function report_now_and_then()
  rounds = rounds + 1
  if rounds % ROUNDS_BETWEEN_REPORTS ~= 0 then return end
  print("credits " .. credits() .. " rods " .. units(rods) .. " fuel " .. units(fuel))
  report()
end

function work()
  refuel()
  buy_metals()
  make_rods()
  sell_or_haul_rods()
  grow()
  report_now_and_then()
end

-- launched() runs again after a crash or a restart, so each step does only
-- what still needs doing
function launched()
  fuel_floor(FUEL_FLOOR)
  equip(factory_kit)
  go_to_cheapest_metals()
  while true do work() end
end
