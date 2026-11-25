-- Trader: a starter drone for Ex Machinis. It produces a margin on metals.
-- It buys metals from miners wherever they are on sale cheapest, when
-- another place paid enough more for them over the last game day, hauls
-- them there and sells them. It names no place: the order books and the
-- price board say where, so it follows the miners wherever they go.

-- Settings: change these to make it your own
metals_for_a_full_load = 150
metals_for_a_short_load = 40
looks_before_a_short_load = 24
fuel_for_a_trip = 25
trip_cost_spread_over_a_load = 200    -- credits
least_profit_a_metal = 2              -- credits, after the trip and the tax
minutes_to_rest_when_nothing_pays = 120
credits_kept_for_fuel = 200
credits_per_game_hour = 4             -- what a game hour of its time is worth when it chooses where to buy

traded = {metals_a1, metals_a2, metals_a3}

local PERCENT_KEPT_AFTER_TAX = 95
local MOST_SOLD_A_METAL_AN_HOUR = 15
local HOURS_SELLING_AT_ONE_PLACE = 6
local GAME_HOUR = 60
local MINUTES_TO_WAIT_FOR_FUEL = 120
local NEARLY_BROKE = 20
local ROUNDS_BETWEEN_REPORTS = 4
local GAME_DAY = 1440
local FUEL_FLOOR = 15
local STARTUP_STATUS = "A startup trades only with the House: nothing to trade. Release a Miner instead"

-- Globals survive a restart
looks_since_last_trip = 0
rounds = 0
buying_at = 0

function metals_aboard()
  local total = 0
  for _, metal in ipairs(traded) do total = total + units(metal) end
  return total
end

function spare_credits() return math.max(credits() - credits_kept_for_fuel, 0) end

function best_price_elsewhere_after_tax(metal)
  local best = 0
  for _, board in ipairs(price_board(metal)) do
    if board.place ~= buying_at then best = math.max(best, board.price * PERCENT_KEPT_AFTER_TAX // 100) end
  end
  return best
end

function highest_price_worth_paying(metal)
  return best_price_elsewhere_after_tax(metal) - trip_cost_spread_over_a_load // metals_for_a_full_load - least_profit_a_metal
end

function buy_metal_if_it_pays(metal)
  local price, seller, available = best_offer(metal)
  if available == 0 or price > highest_price_worth_paying(metal) then return false end
  local room = metals_for_a_full_load - metals_aboard()
  local affordable = spare_credits() // math.max(price, 1)
  local amount = math.min(available, room, affordable)
  if amount <= 0 then return false end
  return pull(amount, metal, seller) == 0
end

function fuel_up()
  if units(fuel) < fuel_for_a_trip then
    buy_here(fuel_for_a_trip, fuel)
    wait(MINUTES_TO_WAIT_FOR_FUEL)
  end
end

-- Buyers bid less as they stock up; what is left goes on the next trip
function sell_load_here_a_little_an_hour()
  for _ = 1, HOURS_SELLING_AT_ONE_PLACE do
    if metals_aboard() == 0 then return end
    for _, metal in ipairs(traded) do
      local amount = math.min(units(metal), MOST_SOLD_A_METAL_AN_HOUR)
      if amount > 0 then sell_here(amount, metal) end
    end
    wait(GAME_HOUR)
  end
end

function place_paying_most_for_load()
  local load_value = {}
  for _, metal in ipairs(traded) do
    local amount = units(metal)
    for _, board in ipairs(price_board(metal)) do
      if amount > 0 and board.place ~= buying_at then
        load_value[board.place] = (load_value[board.place] or 0) + amount * board.price
      end
    end
  end
  local best, best_value = nil, 0
  for place, value in pairs(load_value) do
    if value > best_value then best, best_value = place, value end
  end
  return best
end

function choose_buying_place()
  local best = where_to_buy(metals_a1, metals_for_a_short_load, credits_per_game_hour)[1]
  return best and best.place or location()
end

function shop_for_metals()
  fuel_up()
  local bought_any = false
  for _, metal in ipairs(traded) do
    if buy_metal_if_it_pays(metal) then bought_any = true end
  end
  looks_since_last_trip = looks_since_last_trip + 1
  if not bought_any then wait(minutes_to_rest_when_nothing_pays) end
end

-- The miners move, so it chooses afresh where to buy after every trip
function haul_load_and_come_back()
  fuel_up()
  local market = place_paying_most_for_load()
  if market then go(market) end
  sell_load_here_a_little_an_hour()
  fuel_up()
  buying_at = choose_buying_place()
  go(buying_at)
  looks_since_last_trip = 0
end

function ready_to_set_off()
  local load = metals_aboard()
  return load >= metals_for_a_full_load
    or (load >= metals_for_a_short_load and looks_since_last_trip >= looks_before_a_short_load)
    or (load > 0 and spare_credits() < NEARLY_BROKE)
end

function report_now_and_then()
  rounds = rounds + 1
  if rounds % ROUNDS_BETWEEN_REPORTS ~= 0 then return end
  print("day " .. today() .. " credits " .. credits() .. " fuel " .. units(fuel) .. " metals " .. metals_aboard())
  report()
end

function work()
  if ready_to_set_off() then haul_load_and_come_back() else shop_for_metals() end
  report_now_and_then()
end

-- The House's prices leave a trader no margin, and a startup trades only with the House
function wait_while_a_startup()
  while house_only() do
    set_status(STARTUP_STATUS)
    wait(GAME_DAY)
  end
end

function get_an_engine()
  while units(engine_module) == 0 do
    if units(mining_kit) > 0 or units(factory_kit) > 0 then
      unpack()
    else
      buy_here(1, engine_module)
      wait(GAME_HOUR)
    end
  end
end

-- launched() runs again after a crash or a restart: each step is safe to repeat
function launched()
  wait_while_a_startup()
  fuel_floor(FUEL_FLOOR)
  get_an_engine()
  if buying_at == 0 then buying_at = choose_buying_place() end
  go(buying_at)
  while true do work() end
end
