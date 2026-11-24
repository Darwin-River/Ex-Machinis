-- Machinist: it produces gear boxes with the Factory Kit. A gear box takes
-- a rod, a gear and a spring: 8 Metals (A1) and 2 Metals (A2) through 5
-- alloys. It can't mine (the mining gear is too big to carry with the
-- factory tools), so it buys its metals wherever they cost least after the
-- trip, and sells its gear boxes wherever they pay most.

gear_boxes_per_selling_trip = 4
refuel_below = 60
credits_kept_for_fuel = 300
credits_per_game_hour = 4
copy_at_credits = 7000

springs, gears, gear_boxes = 3101, 3111, 4111

local GAME_HOUR = 60
local METALS_A1_PER_BOX, METALS_A2_PER_BOX = 8, 2
local BOXES_STOCKED_FOR = 2
local MOST_PAID_FOR_METALS_A1, MOST_PAID_FOR_METALS_A2 = 30, 45
local LEAST_SPARE_TO_KEEP_BUYING = 400
local FUEL_TOP_UP = 30
local LEAST_FUEL_TO_START_A_BOX = 30
local BOXES_SOLD_AN_HOUR = 2
local HOURS_SELLING_AT_ONE_PLACE = 6
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local FUEL_FOR_COPY = 40
local FUEL_AFTER_LAUNCH = 60
local FUEL_TO_LAUNCH, LEAST_FUEL_TO_LAUNCH = 90, 80
local FUEL_FLOOR = 15
local STEPS_TO_A_GEAR_BOX = {alloys_a1, alloys_a1, alloys_a1, alloys_a2, alloys_a2, rods, gears, springs, gear_boxes}

function refuel()
  if units(fuel) < refuel_below then buy_here(FUEL_TOP_UP, fuel); wait(GAME_HOUR) end
end

function spare() return math.max(credits() - credits_kept_for_fuel, 0) end

function short_of_metals()
  return units(metals_a1) < METALS_A1_PER_BOX or units(metals_a2) < METALS_A2_PER_BOX
end

function cheapest_metals_place()
  local best = where_to_buy(metals_a1, METALS_A1_PER_BOX, credits_per_game_hour)[1]
  return best and best.place
end

function go_to_cheapest_metals()
  local place = cheapest_metals_place()
  if place and place ~= location() then go(place) end
end

function buy_up_to(target, metal, most_paid)
  local n = math.min(math.max(target - units(metal), 0), spare() // most_paid)
  if n > 0 then buy_here(n, metal) end
end

function stock_up()
  if not short_of_metals() then return end
  if buy_price(metals_a1) == 0 then go_to_cheapest_metals() end
  buy_up_to(METALS_A1_PER_BOX * BOXES_STOCKED_FOR, metals_a1, MOST_PAID_FOR_METALS_A1)
  buy_up_to(METALS_A2_PER_BOX * BOXES_STOCKED_FOR, metals_a2, MOST_PAID_FOR_METALS_A2)
  wait(GAME_HOUR)
end

-- Without this it would wait for a full load for ever
function stuck_with_boxes()
  return short_of_metals() and spare() < LEAST_SPARE_TO_KEEP_BUYING and units(gear_boxes) > 0
end

-- A gear box burns about 23 fuel, so it never starts one with less than 30
function build_a_gear_box()
  if short_of_metals() or units(fuel) < LEAST_FUEL_TO_START_A_BOX then return end
  for _, step in ipairs(STEPS_TO_A_GEAR_BOX) do
    if make(step) ~= 0 then return end
  end
end

-- Buyers bid less as they stock up, and bid again later
function sell_boxes_here_a_few_an_hour()
  for _ = 1, HOURS_SELLING_AT_ONE_PLACE do
    if units(gear_boxes) == 0 then return end
    sell_here(math.min(units(gear_boxes), BOXES_SOLD_AN_HOUR), gear_boxes)
    wait(GAME_HOUR)
  end
end

function market_for_boxes()
  local best = where_to_sell(gear_boxes, units(gear_boxes), credits_per_game_hour)[1]
  return best and best.place or location()
end

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
  push(1, factory_kit, newest.drone)
  push(FUEL_FOR_COPY, fuel, newest.drone)
end

-- It waits for the fuel to arrive, not to reach n: the pull burns a unit
function fill_tank_to(n)
  local had = units(fuel)
  if n <= had then return end
  buy_here(n - had, fuel)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(fuel) > had then return end
    wait(GAME_HOUR)
  end
end

function have_a_hull()
  if units(cruiser_kit) > 0 then return true end
  if credits() < copy_at_credits or fleet_full() then return false end
  buy_one(cruiser_kit)
  return units(cruiser_kit) > 0
end

-- A parent that left with 20 fuel after equipping its copy was lost, so it
-- refills its tank afterwards
function grow()
  if units(factory_kit) > 0 then equip_newest_copy(); fill_tank_to(FUEL_AFTER_LAUNCH); return end
  if not have_a_hull() then return end
  fill_tank_to(FUEL_TO_LAUNCH)
  if units(fuel) < LEAST_FUEL_TO_LAUNCH then return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  buy_one(factory_kit)
  if units(factory_kit) > 0 then equip_newest_copy() end
  fill_tank_to(FUEL_AFTER_LAUNCH)
end

function haul_boxes()
  refuel()
  go(market_for_boxes())
  if units(fuel) < refuel_below then buy_here(FUEL_TOP_UP, fuel); wait(2 * GAME_HOUR) end
  sell_boxes_here_a_few_an_hour()
  grow()
  go_to_cheapest_metals()
end

function work()
  refuel()
  stock_up()
  build_a_gear_box()
  if units(gear_boxes) >= gear_boxes_per_selling_trip or stuck_with_boxes() then haul_boxes() end
end

-- launched() runs again after a crash or a restart: equip passes once the
-- tools are unpacked
function launched()
  fuel_floor(FUEL_FLOOR)
  equip(factory_kit)
  go_to_cheapest_metals()
  while true do work() end
end
