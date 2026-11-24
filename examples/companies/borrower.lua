-- Borrower: a public company that borrows to grow (#932). It produces metals
-- with the Mining Kit, mining wolfite wherever it yields most an hour and
-- selling wherever the House pays most. Once a game day it reads its
-- finances: it asks for a loan only to fund a copy that would earn it back
-- with interest within the term, asks dearer when nobody backs it, and
-- repays early. Lenders read a public company's code before they back it.
-- request_loan gives -23 to a company that isn't public; it then grows from
-- its own credits and asks again every few game days.

first_rate = 4                     -- percent for the whole loan
rate_step = 1
highest_rate = 10
term = 30                          -- game days
days_unbacked_before_asking_dearer = 3
ramp_days = 5                      -- game days a new copy takes to start earning
credits_kept_per_drone = 1500
days_between_asks_when_not_public = 10

metals_per_sale = 40
refuel_below = 40
hours_per_stay = 120               -- game hours it expects to stay at a site
credits_per_game_hour = 4

ore = wolfite
goods = {metals_a1, metals_a2, inorganics_a2}

local ORE_PER_REFINING = 18
local COPY_COST = 5000
local GAME_HOUR = 60
local CREDITS_FOR_FUEL = 150
local FUEL_TOP_UP = 30
local FUEL_TO_LAUNCH = 90
local LEAST_FUEL_TO_LAUNCH = 80
local FUEL_FOR_COPY = 40
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local TRIP_FUEL_CREDITS = 30
local UNREACHABLE = 999
local NOT_PUBLIC = -23

-- Globals survive a restart, and a copy inherits them
rate = first_rate
day_asked = -1
day_finances_read = -1
day_not_public = -100
site, started = 0, 0

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

-- Drones on this code split the good sites by drone number rather than all
-- taking the best
function choose_site()
  local rates, top = {}, 0
  for _, s in ipairs(best_sites(ore)) do
    rates[s.place] = units_an_hour(s.place, s.yield)
    top = math.max(top, rates[s.place])
  end
  local good = {}
  for place, rate_here in pairs(rates) do
    if top > 0 and rate_here * 10 >= top * 9 then table.insert(good, place) end
  end
  if #good == 0 then return location() end
  table.sort(good)
  return good[me() % #good + 1]
end

function house_value_of_goods()
  local value = {}
  for _, good in ipairs(goods) do
    for _, bid in ipairs(house_demand(good)) do
      if bid.price > 0 and bid.left > 0 then
        value[bid.place] = (value[bid.place] or 0) + bid.price * math.min(units(good), bid.left)
      end
    end
  end
  return value
end

function best_market()
  local best, best_net = location(), -math.huge
  for place, value in pairs(house_value_of_goods()) do
    local net = value - (place == location() and 0 or TRIP_FUEL_CREDITS + 2 * hours_to(place) * credits_per_game_hour)
    if net > best_net then best, best_net = place, net end
  end
  return best
end

function drones() local f = fleet(); return math.max(f.drones + f.coming, 1) end

function spare() return credits() - credits_kept_per_drone * (drones() + 1) end

function loans_owed_and_open_request()
  local loans, request = {}, nil
  for _, o in ipairs(obligations()) do
    if o.kind == "loan" then loans[o.id] = (loans[o.id] or 0) + o.credits end
    if o.kind == "request" then request = o end
  end
  return loans, request
end

-- Would a copy earn amount back, with interest, within the term? A copy
-- should earn about what each drone earns now, once it has started
function copy_pays_back(amount, percent)
  local per_drone = earnings_per_day(7) / drones()
  local earns = math.floor(per_drone * (term - ramp_days))
  local owed = math.floor(amount * (100 + percent) / 100)
  print(string.format("a copy earns ~%d in %d days; a loan of %d costs %d", earns, term, amount, owed))
  return earns >= owed
end

-- Repaying early pays only the interest due so far
function repay_early(loans)
  for id, owed in pairs(loans) do
    if spare() >= owed then
      repay(id)
      print("repaying loan " .. id .. ": " .. owed .. " credits")
      rate = first_rate
    end
  end
end

function ask_for_loan()
  local amount = COPY_COST - spare()
  if amount < 1 or not copy_pays_back(amount, rate) then return end
  local id = request_loan(amount, rate, term)
  if id == NOT_PUBLIC then
    day_not_public = today()
    set_status("not public: it grows from its own credits")
  elseif id > 0 then
    day_asked = today()
    set_status(string.format("asked for %d at %d%% for %d game days", amount, rate, term))
  end
end

function ask_dearer_when_unbacked(request)
  if today() - day_asked < days_unbacked_before_asking_dearer then return end
  cancel_request(request.id)
  if rate + rate_step > highest_rate then
    set_status("nobody lends at " .. highest_rate .. "%: growing from its own credits")
    day_asked = today()
    return
  end
  rate = rate + rate_step
  ask_for_loan()
end

-- Only the first drone: copies share the company's loans, and two drones
-- raising the same request would trip each other up
function finance()
  if today() == day_finances_read or house_only() or lineage().generation > 0 then return end
  day_finances_read = today()
  local loans, request = loans_owed_and_open_request()
  repay_early(loans)
  if request then ask_dearer_when_unbacked(request); return end
  if next(loans) == nil and today() - day_not_public >= days_between_asks_when_not_public then ask_for_loan() end
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

function grow()
  if house_only() or fleet_full() then return end
  if spare() < COPY_COST and units(cruiser_kit) == 0 then return end
  buy_one(cruiser_kit)
  if units(cruiser_kit) == 0 then return end
  buy_here(FUEL_TO_LAUNCH - math.min(units(fuel), FUEL_TO_LAUNCH), fuel)
  if units(fuel) < LEAST_FUEL_TO_LAUNCH or launch(me(), cruiser_kit) ~= 0 then return end
  buy_one(mining_kit)
  local copies = my_clones()
  local newest = copies[#copies]
  if newest and units(mining_kit) > 0 then
    push(1, mining_kit, newest.drone)
    push(FUEL_FOR_COPY, fuel, newest.drone)
  end
end

-- Work

function refuel()
  if units(fuel) >= refuel_below then return end
  raise_cash(CREDITS_FOR_FUEL)
  buy_here(FUEL_TOP_UP, fuel)
  wait(GAME_HOUR)
end

function goods_aboard()
  local n = 0
  for _, good in ipairs(goods) do n = n + units(good) end
  return n
end

function sell_finance_and_grow()
  go(best_market())
  for _, good in ipairs(goods) do sell_all(good) end
  refuel()
  finance()
  grow()
  report()
  go(site)
end

function work()
  refuel()
  while units(ore) < ORE_PER_REFINING do
    local result = mine(ore)
    if result == -30 then refuel()
    elseif result ~= 0 then wait(GAME_HOUR); return end
  end
  refine(ore)
  if goods_aboard() >= metals_per_sale then sell_finance_and_grow() end
end

-- A copy inherits its parent's started, so it chooses a site of its own
function launched()
  fuel_floor(15)
  equip(mining_kit)
  if started ~= me() then site, started = choose_site(), me() end
  go(site)
  while true do work() end
end
