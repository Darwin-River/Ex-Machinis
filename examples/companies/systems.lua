-- Systems shop: a company design for Ex Machinis, in Lua, from a design
-- that won the starter-library race. Release it with the Factory Kit.
-- It produces pumps, pistons and valves, which every exchange buys. Each
-- round it prices the three from the market where it is, builds the one
-- that pays best, sells it and looks again; it brews its own fuel from the
-- same organics and inorganics. When nothing pays where it is for a while,
-- it moves to wherever the House pays most for systems after the trip.

polymers, hoses, pipes = 2051, 3301, 3311
pumps, pistons, valves = 4311, 4321, 4301
products = {pumps, pistons, valves}

cash_floor = 300
refuel_below = 50
least_profit_a_system = 60
systems_worth_of_inputs_per_purchase = 2
credits_per_game_hour = 10
idle_rounds_before_moving = 4

local GAME_HOUR = 60
local FUEL_FLOOR = 15
local PRICE_WHEN_NOBODY_SELLS = 999
local PERCENT_KEPT_AFTER_TAX = 95
local LEAST_CREDITS_TO_BREW = 60
local HIGHEST_FUEL_PRICE = 20
local FUEL_TARGET = 75
local FUEL_BID_PRICE = 12
local FUEL_BID_UNITS = 60
local FUEL_NEEDED_TO_MOVE = 25
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local COPY_AT_CREDITS = 7000
local LAST_DAY_TO_GROW = 24
local FUEL_TO_LAUNCH = 90
local LEAST_FUEL_TO_LAUNCH = 80
local FUEL_AFTER_LAUNCH = 60
local FUEL_FOR_COPY = 40
local INVEST_ABOVE_WHILE_GROWING = 9000
local INVEST_ABOVE_LATER = 3000
local ROUNDS_BETWEEN_REPORTS = 3
local UNREACHABLE = 999

-- Globals survive a restart
best_score = 0
idle_rounds = 0
invest_day = -1
rounds = 0

function spare() return math.max(credits() - cash_floor, 0) end

function cost_here(resource)
  local price = buy_price(resource)
  if price == 0 then return PRICE_WHEN_NOBODY_SELLS end
  return price
end

function after_tax_here(product) return sell_price(product) * PERCENT_KEPT_AFTER_TAX // 100 end

function profit_per_system()
  local metal, organic, inorganic = cost_here(metals_a1), cost_here(organics_a1), cost_here(inorganics_a1)
  local hose_and_rod = metal * 2 + organic * 3 + inorganic * 3
  return {
    [pumps] = after_tax_here(pumps) - hose_and_rod,
    [pistons] = after_tax_here(pistons) - hose_and_rod,
    [valves] = after_tax_here(valves) - metal * 8,
  }
end

function choose()
  local best, profits = nil, profit_per_system()
  best_score = least_profit_a_system
  for _, product in ipairs(products) do
    if profits[product] > best_score then best, best_score = product, profits[product] end
  end
  return best
end

function inputs_for(product)
  if product == valves then return {[metals_a1] = 8} end
  return {[metals_a1] = 2, [organics_a1] = 3, [inorganics_a1] = 3}
end

function stock_up(per_system, resource)
  if per_system == 0 or units(resource) >= per_system then return end
  local amount = math.min(per_system * systems_worth_of_inputs_per_purchase - units(resource), spare() // cost_here(resource))
  if amount > 0 then buy_here(amount, resource) end
end

function have_all(inputs)
  for resource, n in pairs(inputs) do
    if units(resource) < n then return false end
  end
  return true
end

function make_until_aboard(n, resource)
  while units(resource) < n do
    if make(resource) ~= 0 then return false end
  end
  return true
end

function have_a_rod() return units(rods) > 0 or (make_until_aboard(1, alloys_a1) and make(rods) == 0) end
function have_a_hose() return units(hoses) > 0 or (make_until_aboard(3, polymers) and make(hoses) == 0) end
function have_a_pipe() return units(pipes) > 0 or (make_until_aboard(3, alloys_a1) and make(pipes) == 0) end

function build(product)
  if not have_a_rod() then return end
  local part_ready
  if product == valves then part_ready = have_a_pipe() else part_ready = have_a_hose() end
  if part_ready then make(product) end
end

function sell_systems()
  for _, product in ipairs(products) do sell_all(product) end
end

-- Fuel

function hours_to(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  return trip and trip.hours or UNREACHABLE
end

function brew()
  if units(organics_a1) < 1 then buy_here(2, organics_a1) end
  if units(inorganics_a1) < 2 then buy_here(3, inorganics_a1) end
  if units(organics_a1) < 1 or units(inorganics_a1) < 2 then return false end
  return make(fuel) == 0
end

function cheapest_fuel_stop()
  local best, least = nil, math.huge
  for _, offer in ipairs(house_supply(fuel)) do
    local hours = hours_to(offer.place)
    if offer.price > 0 and offer.on_sale > 0 and hours < UNREACHABLE then
      local cost = offer.price * FUEL_TARGET + hours * credits_per_game_hour
      if cost < least then best, least = offer.place, cost end
    end
  end
  return best
end

-- Only the House trades with a startup, so a startup's bid brings no courier
function refuel()
  if units(fuel) >= refuel_below then return end
  if credits() >= LEAST_CREDITS_TO_BREW and brew() then return end
  local price = buy_price(fuel)
  if price > 0 and price < HIGHEST_FUEL_PRICE then buy_here(FUEL_TARGET - units(fuel), fuel); return end
  if not house_only() then buy_order_if_changed(FUEL_BID_PRICE, FUEL_BID_UNITS, fuel); wait(2 * GAME_HOUR) end
  if units(fuel) >= refuel_below then return end
  local stop = cheapest_fuel_stop()
  if stop and units(fuel) > FUEL_NEEDED_TO_MOVE / 2 then go(stop) end
end

-- Moving when nothing pays here

function place_paying_most_for_systems()
  local best, most = nil, 0
  for _, product in ipairs(products) do
    for _, bid in ipairs(house_demand(product)) do
      local hours = hours_to(bid.place)
      local net = bid.price - hours * credits_per_game_hour
      if bid.place ~= location() and bid.left > 0 and hours < UNREACHABLE and net > most then best, most = bid.place, net end
    end
  end
  return best
end

function rest()
  idle_rounds = idle_rounds + 1
  if idle_rounds > idle_rounds_before_moving and units(fuel) > FUEL_NEEDED_TO_MOVE then
    idle_rounds = 0
    local place = place_paying_most_for_systems()
    if place then
      set_status("nothing pays here: moving to " .. place_name(place))
      go(place)
      return
    end
  end
  wait(2 * GAME_HOUR)
end

-- Growing

function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(resource) > 0 then return end
    wait(GAME_HOUR)
  end
end

function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, factory_kit, newest.drone)
  push(FUEL_FOR_COPY, fuel, newest.drone)
end

function fill_to(n)
  local had = units(fuel)
  if n <= had then return end
  buy_here(n - had, fuel)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(fuel) > had then return end
    wait(GAME_HOUR)
  end
end

function fuel_to(n)
  while units(fuel) < n do
    if not brew() then fill_to(n); return end
  end
end

function reinvest()
  if units(factory_kit) > 0 then equip_clone(); fuel_to(FUEL_AFTER_LAUNCH); return end
  if units(cruiser_kit) == 0 then
    if company_age() >= LAST_DAY_TO_GROW or credits() < COPY_AT_CREDITS or fleet_full() then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fuel_to(FUEL_TO_LAUNCH)
  if units(fuel) < LEAST_FUEL_TO_LAUNCH then return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(factory_kit)
  if units(factory_kit) > 0 then equip_clone() end
  fuel_to(FUEL_AFTER_LAUNCH)
end

-- Idle credits earn nothing: a Foundry once sat on 11,000 while other companies' offerings went unbacked
function invest()
  if today() == invest_day then return end
  invest_day = today()
  invest_surplus(company_age() > LAST_DAY_TO_GROW and INVEST_ABOVE_LATER or INVEST_ABOVE_WHILE_GROWING)
end

function report_now_and_then(product)
  rounds = rounds + 1
  if rounds % ROUNDS_BETWEEN_REPORTS ~= 0 then return end
  print("day " .. today() .. " at " .. place_name(location()) .. " making " .. (product or 0) ..
        " score " .. best_score .. " credits " .. credits() .. " fuel " .. units(fuel))
  report()
end

function work()
  invest()
  refuel()
  local product = choose()
  if not product then report_now_and_then(nil); rest(); return end
  local inputs = inputs_for(product)
  for _, resource in ipairs({metals_a1, organics_a1, inorganics_a1}) do stock_up(inputs[resource] or 0, resource) end
  if not have_all(inputs) then report_now_and_then(product); rest(); return end
  idle_rounds = 0
  build(product)
  sell_systems()
  report_now_and_then(product)
  reinvest()
end

function launched()
  fuel_floor(FUEL_FLOOR)
  equip(factory_kit)
  while true do work() end
end
