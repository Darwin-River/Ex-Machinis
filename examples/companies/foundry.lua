-- Foundry: a market-aware factory (Factory Kit), from a newcomer's first
-- company in a playtest. Every round it prices every recipe it knows from
-- the order book where it works (what the inputs cost, what the product
-- fetches), makes whichever earns most an hour, and sells what it made in
-- lots. It works wherever metals cost least after the trip, and moves when
-- that changes. It brews its own fuel when that is cheaper than buying it.

credits_kept_for_fuel = 300
least_gain_per_10_hours = 20
credits_per_game_hour = 4
rounds_between_looks_for_a_base = 24
copy_at_credits = 7500
last_copy_age = 24                    -- game days
invest_above_while_growing = 9000
invest_above_after = 3000

polymers, springs, gears, gear_boxes = 2051, 3101, 3111, 4111
inorganics_a3, organics_a3 = 2033, 2043

local GAME_HOUR = 60
local PERCENT_KEPT_AFTER_TAX = 95
local CREDITS_PER_FUEL_USED = 2
local MOST_BATCHES = 4
local LOT_SIZE = 4
local ROUNDS_BETWEEN_SELLING_EVERYTHING = 12
local ROUNDS_BETWEEN_PRICING_PARTS = 4
local ROUNDS_BETWEEN_REPORTS = 6
local LEAST_FUEL_TO_MAKE = 30
local FUEL_KEPT = 50
local FUEL_TOP_UP = 30
local FUEL_BREWED = 30
local FUEL_FOR_COPY = 40
local FUEL_AFTER_LAUNCH = 60
local FUEL_TO_LAUNCH, LEAST_FUEL_TO_LAUNCH = 90, 80
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local FUEL_FLOOR = 15
local CANT_MAKE = -2
local METALS_FOR_A_BASE = 20

-- Fixed data, so locals: rebuilt when the drone loads, not saved
local function recipe_of(product, out, fuel_used, hours, alloys_a1_first, alloys_a2_first, inputs)
  return {product = product, out = out, fuel = fuel_used, hours = hours,
          alloys_a1_first = alloys_a1_first, alloys_a2_first = alloys_a2_first, inputs = inputs}
end
local recipes = {
  recipe_of(2051, 1, 2, 2, 0, 0, {{2031, 1}, {2041, 1}}),
  recipe_of(2021, 1, 2, 2, 0, 0, {{2011, 2}}),
  recipe_of(2022, 1, 2, 2, 0, 0, {{2011, 1}, {2012, 1}}),
  recipe_of(2023, 2, 2, 2, 0, 0, {{2011, 1}, {2012, 1}, {2013, 1}}),
  recipe_of(2052, 2, 2, 2, 0, 0, {{2031, 1}, {2041, 1}, {2042, 1}}),
  recipe_of(2053, 2, 2, 2, 0, 0, {{2031, 1}, {2042, 1}, {2043, 1}}),
  recipe_of(2042, 2, 2, 2, 0, 0, {{2032, 1}, {2041, 2}}),
  recipe_of(2043, 2, 2, 2, 0, 0, {{2033, 1}, {2042, 2}}),
  recipe_of(3011, 1, 5, 6, 1, 0, {{2011, 2}}),
  recipe_of(3001, 1, 7, 8, 2, 0, {{2011, 4}}),
  recipe_of(3201, 1, 7, 8, 2, 0, {{2011, 4}}),
  recipe_of(3021, 1, 7, 8, 2, 0, {{2011, 4}}),
  recipe_of(3111, 1, 7, 8, 2, 0, {{2011, 4}}),
  recipe_of(3121, 1, 7, 8, 2, 0, {{2011, 4}}),
  recipe_of(3311, 1, 9, 10, 3, 0, {{2011, 6}}),
  recipe_of(3211, 1, 5, 6, 0, 1, {{2011, 1}, {2012, 1}}),
  recipe_of(3101, 1, 7, 8, 0, 2, {{2011, 2}, {2012, 2}}),
  recipe_of(3031, 1, 7, 8, 0, 2, {{2011, 2}, {2012, 2}}),
  recipe_of(3331, 1, 11, 12, 1, 3, {{2011, 5}, {2012, 3}}),
  recipe_of(4111, 1, 23, 30, 0, 0, {{2011, 8}, {2012, 2}}),
}
local ONE_STEP_GOODS = 8
local GEAR_BOX = #recipes
local INPUT_GOODS = {2011, 2012, 2013, 2031, 2032, 2033, 2041, 2042, 2043}

-- Globals survive a restart
rounds = 0
held = {}
input_prices, product_bids = {}, {}
best, best_gain = -1, 0
invest_day = -1

-- On a tie the later recipe wins
function each_recipe()
  local i = #recipes + 1
  return function()
    i = i - 1
    if i >= 1 then return i end
  end
end

-- Reading the hold once a round saves a query each time it's checked
function read_hold()
  held = {}
  for _, item in ipairs(hold()) do held[item.resource] = item.units end
end
function have(res) return held[res] or 0 end

function spare() return math.max(credits() - credits_kept_for_fuel, 0) end

-- Where to work

function cheapest_metals_place()
  local best_offer = where_to_buy(metals_a1, METALS_FOR_A_BASE, credits_per_game_hour)[1]
  return best_offer and best_offer.place
end

function move_to_cheapest_metals()
  local place = cheapest_metals_place()
  if place and place ~= location() then
    set_status("moving to " .. place_name(place) .. ": metals cost least there now")
    go(place)
  end
end

-- Pricing: each query costs instructions, so prices are read once a round

function read_prices()
  for _, res in ipairs(INPUT_GOODS) do input_prices[res] = buy_price(res) end
  for i in each_recipe() do
    if i <= ONE_STEP_GOODS or rounds % ROUNDS_BETWEEN_PRICING_PARTS == 1 then product_bids[i] = sell_price(recipes[i].product) end
  end
end

function inputs_cost(i)
  local cost, all_on_sale = 0, true
  for _, input in ipairs(recipes[i].inputs) do
    local price = input_prices[input[1]] or 0
    if price == 0 then all_on_sale = false end
    cost = cost + price * input[2]
  end
  return cost, all_on_sale
end

function gain_per_10_hours(i)
  local r, bid = recipes[i], product_bids[i] or 0
  if bid == 0 then return -1 end
  local revenue = bid * r.out * PERCENT_KEPT_AFTER_TAX // 100
  local cost, all_on_sale = inputs_cost(i)
  if not all_on_sale then return -1 end
  return (revenue - cost - r.fuel * CREDITS_PER_FUEL_USED) * 10 // r.hours
end

function choose_recipe()
  best, best_gain = -1, least_gain_per_10_hours
  for i in each_recipe() do
    local gain = gain_per_10_hours(i)
    if gain > best_gain then best, best_gain = i, gain end
  end
end

-- Inputs

function inputs_aboard(i)
  for _, input in ipairs(recipes[i].inputs) do
    if have(input[1]) < input[2] then return false end
  end
  return true
end

function batches_affordable(i)
  return math.min(spare() // math.max(inputs_cost(i), 1), MOST_BATCHES)
end

function stock_up(i)
  local k = batches_affordable(i)
  if k == 0 then return end
  for _, input in ipairs(recipes[i].inputs) do
    local missing = input[2] * k - have(input[1])
    if missing > 0 then buy_here(missing, input[1]) end
  end
  read_hold()
end

-- Making

function make_several(n, res)
  for _ = 1, n do
    local result = make(res)
    if result ~= 0 then return result end
  end
  return 0
end

function make_a_gear_box()
  local result = make_several(3, alloys_a1)
  if result == 0 then result = make_several(2, alloys_a2) end
  for _, part in ipairs({rods, gears, springs, gear_boxes}) do
    if result ~= 0 then return result end
    result = make(part)
  end
  return result
end

function make_one(i)
  if units(fuel) < LEAST_FUEL_TO_MAKE then return CANT_MAKE end
  local r = recipes[i]
  if i <= ONE_STEP_GOODS then return make(r.product) end
  if i == GEAR_BOX then return make_a_gear_box() end
  local result = make_several(r.alloys_a1_first, alloys_a1)
  if result == 0 then result = make_several(r.alloys_a2_first, alloys_a2) end
  if result ~= 0 then return result end
  return make(r.product)
end

function recipe_with_inputs_aboard_that_pays()
  for i in each_recipe() do
    if inputs_aboard(i) and gain_per_10_hours(i) > 0 then return i end
  end
  return -1
end

-- Selling at once below the cash floor: a Foundry once spent down to 3
-- credits, couldn't buy fuel, and wrecked holding what it had made
function sell_goods()
  local short_of_cash = credits() < credits_kept_for_fuel
  local sell_everything = rounds % ROUNDS_BETWEEN_SELLING_EVERYTHING == 0
  for i in each_recipe() do
    local res = recipes[i].product
    local n = have(res)
    if n > 0 and (n >= LOT_SIZE or sell_everything or short_of_cash) then sell_here(n, res) end
  end
end

-- Fuel: brewed from 1 organics and 2 inorganics when that is cheaper

function can_brew() return have(organics_a1) > 0 and have(inorganics_a1) > 1 end

function brewing_is_cheaper()
  local organics, inorganics = buy_price(organics_a1), buy_price(inorganics_a1)
  if organics == 0 or inorganics == 0 then return false end
  local fuel_price = buy_price(fuel)
  return fuel_price == 0 or organics + inorganics * 2 < fuel_price * FUEL_BREWED
end

function refuel()
  if units(fuel) >= FUEL_KEPT then return end
  if not can_brew() and brewing_is_cheaper() then
    buy_here(1, organics_a1)
    buy_here(2, inorganics_a1)
    read_hold()
  end
  if can_brew() and make(fuel) == 0 then read_hold(); return end
  if credits() < credits_kept_for_fuel then sell_goods() end
  buy_here(FUEL_TOP_UP, fuel)
  read_hold()
end

-- Growing, in its first last_copy_age game days

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
  if company_age() > last_copy_age or credits() < copy_at_credits or fleet_full() then return false end
  buy_one(cruiser_kit)
  return units(cruiser_kit) > 0
end

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

-- A Foundry once sat on 11,000 credits while others' offerings went unbacked
function invest_spare_credits()
  if today() == invest_day then return end
  invest_day = today()
  invest_surplus(company_age() > last_copy_age and invest_above_after or invest_above_while_growing)
end

function report_now_and_then()
  if rounds % ROUNDS_BETWEEN_REPORTS ~= 0 then return end
  local what = best >= 1 and recipes[best].product or best
  print("day " .. today() .. " credits " .. credits() .. " fuel " .. units(fuel) ..
        " best " .. what .. " gain " .. best_gain)
  report()
end

function work()
  rounds = rounds + 1
  if rounds % rounds_between_looks_for_a_base == 0 then move_to_cheapest_metals() end
  invest_spare_credits()
  read_hold()
  refuel()
  sell_goods()
  read_prices()
  local leftover = recipe_with_inputs_aboard_that_pays()
  if leftover >= 1 then make_one(leftover); return end
  choose_recipe()
  report_now_and_then()
  if best < 1 then wait(2 * GAME_HOUR); return end
  if not inputs_aboard(best) then stock_up(best) end
  if inputs_aboard(best) then make_one(best) else wait(GAME_HOUR) end
  grow()
end

-- launched() runs again after a crash or a restart: equip passes once the
-- tools are unpacked
function launched()
  fuel_floor(FUEL_FLOOR)
  equip(factory_kit)
  move_to_cheapest_metals()
  while true do work() end
end
