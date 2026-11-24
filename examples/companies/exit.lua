-- Exit: a company with a sell-out routine (#932). It mines and refines
-- wolfite wherever it yields most an hour now, and sells the metals wherever
-- the House pays most. Every round it asks whether the company is failing;
-- once it is, it sells everything, settles what it owes and closes, so its
-- shareholders get its credits rather than a wreck. A startup that closes
-- hands its credits back to the bank, so only a matured company exits.

failing_after_days_of_falling_credits = 10
failing_after_days_without_a_sale = 20
failing_below_fuel = 20                -- when its credits can't buy 30 more
credits_per_fuel = 3
hours_for_other_drones_to_sell = 6
metals_per_sale = 40
refuel_below = 40
credits_per_game_hour = 4
hours_per_stay = 120
percent_from_best_still_good = 10

ore = wolfite
goods = {metals_a1, metals_a2, inorganics_a2}

local GAME_HOUR = 60
local ORE_PER_REFINING = 18
local FUEL_TOP_UP = 30
local CREDITS_FOR_FUEL = 150
local FUEL_FLOOR = 15
local FIRST_TOOL_ID = 5000
local UNREACHABLE = 999
local CLOSE_STILL_OWES = -24
local CLOSE_NOTHING_TO_CLOSE = -9
local OUT_OF_FUEL = -30

-- Globals survive a restart: once exiting, a restart goes on with the exit
site = 0
day_last_checked = -1
credits_that_day = 0
days_credits_fell = 0
day_of_last_sale = -1
exiting = false

-- Where to work and sell

function hours_to(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  return trip and trip.hours or UNREACHABLE
end

-- Drones on this code split the good sites by drone number rather than all
-- taking the best
function choose_site()
  local rates, top = {}, 0
  for _, s in ipairs(best_sites(ore)) do
    local hours = hours_to(s.place)
    if hours < UNREACHABLE then
      rates[s.place] = s.yield * hours_per_stay / (hours_per_stay + hours)
      top = math.max(top, rates[s.place])
    end
  end
  local good = {}
  for place, rate in pairs(rates) do
    if rate * 100 >= top * (100 - percent_from_best_still_good) then table.insert(good, place) end
  end
  if #good == 0 then return location() end
  table.sort(good)
  return good[me() % #good + 1]
end

function house_value_of(resources)
  local value = {}
  for _, r in ipairs(resources) do
    local aboard = units(r)
    for _, bid in ipairs(aboard > 0 and house_demand(r) or {}) do
      if bid.price > 0 and bid.left > 0 then
        value[bid.place] = (value[bid.place] or 0) + bid.price * math.min(aboard, bid.left)
      end
    end
  end
  return value
end

function market_paying_most_for(resources)
  local best, best_net = nil, 0
  for place, value in pairs(house_value_of(resources)) do
    local net = value - hours_to(place) * credits_per_game_hour
    if net > best_net then best, best_net = place, net end
  end
  return best or location()
end

-- Is it failing?

function count_days_credits_fell()
  if today() == day_last_checked then return end
  local now = credits()
  if day_last_checked >= 0 and now < credits_that_day then days_credits_fell = days_credits_fell + 1 else days_credits_fell = 0 end
  day_last_checked, credits_that_day = today(), now
end

function failing()
  if house_only() then return false end
  if day_of_last_sale < 0 then day_of_last_sale = today() end
  count_days_credits_fell()
  if days_credits_fell >= failing_after_days_of_falling_credits then
    return "credits fell " .. days_credits_fell .. " game days in a row"
  end
  if today() - day_of_last_sale >= failing_after_days_without_a_sale then
    return "nothing sold for " .. failing_after_days_without_a_sale .. " game days"
  end
  if units(fuel) < failing_below_fuel and credits() < FUEL_TOP_UP * credits_per_fuel then return "out of fuel and cash" end
  return false
end

-- The exit routine

function goods_in_hold()
  local list = {}
  for _, item in ipairs(hold()) do
    if item.resource ~= fuel and item.resource < FIRST_TOOL_ID then table.insert(list, item.resource) end
  end
  return list
end

-- Goods only: fuel, kits and modules are its tools
function sell_everything()
  local goods_aboard = goods_in_hold()
  go(market_paying_most_for(goods_aboard))
  for _, r in ipairs(goods_aboard) do sell_all(r) end
end

-- Pays or withdraws each thing it owes. Returns how many are still open
function settle_up()
  local open = 0
  for _, o in ipairs(obligations()) do
    open = open + 1
    if o.kind == "loan" then
      -- repay: all or nothing, the principal plus interest so far
      if credits() >= o.credits then repay(o.id) else print("can't repay loan " .. o.id .. " yet") end
    elseif o.kind == "request" then
      cancel_request(o.id)            -- nobody has backed this part yet
    else
      cancel_contract(o.id)           -- "posted" (escrow back) or "taken" (settled as if due)
    end
  end
  return open
end

function try_to_close()
  local result = close_company()
  if result == 0 then
    set_status("closing: its credits go to the shareholders")
  elseif result == CLOSE_STILL_OWES then
    set_status("still owes: repaying before it closes")
  elseif result == CLOSE_NOTHING_TO_CLOSE then
    set_status("nothing to close (no company of its own, or the House's)")
  end
  return result
end

-- Every drone sees the same company and sells its own goods; the closer
-- waits so the others have time to
function exit_routine()
  sell_everything()
  wait(GAME_HOUR * hours_for_other_drones_to_sell)
  sell_everything()
  if settle_up() > 0 then wait(2 * GAME_HOUR) end
  if try_to_close() ~= 0 then wait(4 * GAME_HOUR) end
  report()
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
  for _, r in ipairs(goods) do n = n + units(r) end
  return n
end

function sell_goods()
  go(market_paying_most_for(goods))
  for _, r in ipairs(goods) do
    if units(r) > 0 and sell_all(r) == 0 then day_of_last_sale = today() end
  end
  refuel()
  go(site)
end

function work()
  refuel()
  while units(ore) < ORE_PER_REFINING do
    local result = mine(ore)
    if result == OUT_OF_FUEL then refuel()
    elseif result ~= 0 then wait(GAME_HOUR); return end
  end
  refine(ore)
  if goods_aboard() >= metals_per_sale then sell_goods() end
end

function check_for_failure()
  local why = failing()
  if not why then return end
  exiting = true
  set_status("failing (" .. why .. "): selling out")
  print("exit: " .. why); report()
end

function launched()
  fuel_floor(FUEL_FLOOR)
  equip(mining_kit)
  if site == 0 then site = choose_site() end
  go(site)
  while true do
    if not exiting then check_for_failure() end
    if exiting then exit_routine() else work() end
  end
end
