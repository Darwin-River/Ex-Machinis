-- A library of useful functions for Ex Machinis drones, in Lua, gathered
-- from the starters and company designs. Copy the ones you need into your
-- script: a drone can't load another file. documents/lua-functions.md says
-- what each one does, and where it came from. The game's own library
-- already gives units, buy_here, sell_here, sell_all, board_price and the
-- goods' names. This file compiles as a whole and does nothing if released.

polymers, sheets = 2051, 3001

cash_floor = 300
my_kit = mining_kit

local GAME_HOUR = 60
local DRY_DEPOSIT = 5
local MOST_SOLD_AN_HOUR = 15
local FEW_IN_STOCK, MANY_IN_STOCK = 4, 16
local FUEL_FOR_A_COURIER_TO_BRING = 20
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local COPY_AT_CREDITS = 7000
local FUEL_TO_LAUNCH, LEAST_FUEL_TO_LAUNCH, FUEL_AFTER_LAUNCH = 90, 80, 60
local FUEL_FOR_COPY = 40
local LONGEST_TRIP_HOURS = 72

function metals() return units(metals_a1) + units(metals_a2) + units(metals_a3) end

-- A run that brings nothing (others took it first) still burns fuel, so it waits as if the deposit were dry
function dig(mineral, n)
  while units(mineral) < n do
    if deposit(mineral) < DRY_DEPOSIT then
      wait(GAME_HOUR)
    else
      local before = units(mineral)
      mine(mineral)
      if units(mineral) == before then wait(GAME_HOUR) end
    end
  end
end

function richer(a, b)
  if deposit(a) < deposit(b) then return b end
  return a
end

function brew_fuel() return make(fuel) end

function top_up(below, amount)
  if units(fuel) < below then buy_here(amount, fuel) end
end

-- Waiting runs no instructions, so it burns no fuel while a courier comes
function call_for_fuel(price, want)
  buy_order(price, want, fuel)
  while units(fuel) < FUEL_FOR_A_COURIER_TO_BRING do wait(2 * GAME_HOUR) end
end

-- Buyers bid less as they stock up
function portion(resource)
  local n = math.min(units(resource), MOST_SOLD_AN_HOUR)
  if n > 0 then sell_here(n, resource) end
end

function offer(price, resource) sell_order(price, 0, resource) end

function offer_midway(resource)
  local bid, cheapest = sell_price(resource), buy_price(resource)
  if bid ~= 0 and cheapest ~= 0 then sell_order((bid + cheapest) // 2, 0, resource) end
end

function sold_everywhere_yesterday(product)
  local total = 0
  for _, sale in ipairs(sales(product, 1)) do total = total + sale.units end
  return total
end

function overstocked(product) return units(product) * 2 > sold_everywhere_yesterday(product) end

function spare() return math.max(credits() - cash_floor, 0) end

function profit(product, input, n) return sell_price(product) - buy_price(input) * n end

-- Undercut while stock sells out, ask more while it piles up: one fixed price was too low once demand outran it
function smart_ask(resource)
  local cheapest = buy_price(resource)
  if cheapest == 0 then return 0 end
  local bid = sell_price(resource)
  if bid == 0 then bid = cheapest // 2 end
  local aboard = units(resource)
  if aboard < FEW_IN_STOCK then return cheapest - 1 end
  if aboard > MANY_IN_STOCK then return bid + 1 end
  return (cheapest + bid) // 2
end

-- Posts only when the price changed: each post costs a fuel
function reoffer(resource)
  local price = smart_ask(resource)
  if price ~= 0 then sell_order_if_changed(price, 0, resource) end
end

function stuck_for(rounds) return ticks() > rounds end

function buy_cheap(n, resource, price)
  local asking, seller, available = best_offer(resource)
  if available == 0 or asking > price then return -1 end
  return pull(math.min(n, available), resource, seller)
end

function forge_rods()
  while units(metals_a1) > 1 do
    if make(alloys_a1) ~= 0 then return end
    if make(rods) ~= 0 then return end
  end
end

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
  push(1, my_kit, newest.drone)
  push(FUEL_FOR_COPY, fuel, newest.drone)
end

-- Waits for the fuel to arrive, not for n: the pull burns a unit, so the tank can end just short
function fill_to(n)
  local had = units(fuel)
  if n <= had then return end
  buy_here(n - had, fuel)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(fuel) > had then return end
    wait(GAME_HOUR)
  end
end

-- Refills after equipping a copy: parents that left with 20 fuel were lost
function reinvest()
  if units(my_kit) > 0 then equip_clone(); fill_to(FUEL_AFTER_LAUNCH); return end
  if units(cruiser_kit) == 0 then
    if credits() < COPY_AT_CREDITS or fleet_full() then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fill_to(FUEL_TO_LAUNCH)
  if units(fuel) < LEAST_FUEL_TO_LAUNCH then return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(my_kit)
  if units(my_kit) > 0 then equip_clone() end
  fill_to(FUEL_AFTER_LAUNCH)
end

function better_place(product, margin)
  local here, quoted = location(), quotes(product)
  local best, best_bid = nil, (quoted[here] and quoted[here].bid or 0) + margin
  for place, q in pairs(quoted) do
    if place ~= here and q.bid >= best_bid and q.bid_units > 0 then best, best_bid = place, q.bid end
  end
  return best
end

function plan_b(product)
  local place = better_place(product, 100)
  if place and trip(place) < LONGEST_TRIP_HOURS then
    set_status("moving to " .. place_name(place) .. ": " .. resource_name(product) .. " pays more there")
    go(place)
  end
end

function can_build(product, n)
  local r = recipe(product)
  if not r then return false, 0 end
  local total = 0
  for input, per in pairs(r.uses) do
    local need = per * n - units(input)
    if need > 0 then
      local cost, got = cost_to_buy(need, input)
      if got < need then return false, 0 end
      total = total + cost
    end
  end
  return true, total
end

function launched() end
