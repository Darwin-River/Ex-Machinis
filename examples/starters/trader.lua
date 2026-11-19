-- Trader: a starter drone for Ex Machinis. It buys an Engine at Earth, then
-- buys metals from the miners at the Moon whenever Mars or Earth paid enough
-- more for them over the last game day, hauls them to whichever pays most,
-- sells them and comes back. Change the settings below to make it your own.

-- Settings
cargo_goal = 150   -- metals to gather before setting off (units)
min_load = 40      -- metals it sets off with once it has run out of patience (units)
patience = 24      -- looks at the market before it sets off with less than a full load
trip_fuel = 25     -- fuel to have aboard before each trip (units)
trip_cost = 200    -- what a round trip costs in fuel and time, spread over a load (credits)
min_margin = 2     -- least profit a metal must make after the trip and the tax (credits a unit)
nap = 120          -- rest when nothing is worth buying (game minutes)
cash_floor = 200   -- credits kept for fuel: spent on metals, it would be stranded (credits)

traded = {metals_a1, metals_a2, metals_a3}   -- the metals it buys and sells
markets = {mars, earth}                      -- where it sells them

-- Other numbers it uses
share_kept_after_sales_tax = 95   -- percent of a sale a seller keeps (the tax is 5%)
most_sold_per_metal_an_hour = 15  -- buyers bid less as they stock up, so it sells slowly (units)
selling_hours = 6                 -- hours it sells for; what is left goes on the next trip
selling_pause = 60                -- between hours of selling (game minutes)
refuel_pause = 120                -- after buying fuel (game minutes)
engine_shopping_pause = 60        -- between tries to buy an Engine (game minutes)
nearly_broke = 20                 -- spare credits under which it sets off with what it has
report_every = 4                  -- rounds of work between reports home
startup_recheck_pause = 1440     -- between checks on whether it may trade: a game day (game minutes)
fuel_reserve = 15                 -- make, mine, refine and push stop here, to keep fuel to buy more (units)

-- Its status line as a startup (a status line holds 80 characters)
startup_message = "A startup trades only with the House: nothing to trade. Release a Miner instead"

-- What it has been doing (kept if the engine restarts)
looks_since_last_trip = 0
rounds_worked = 0

function metals_aboard()
  local total = 0
  for _, metal in ipairs(traded) do total = total + units(metal) end
  return total
end

function spare_credits() return math.max(credits() - cash_floor, 0) end

-- The market where a metal sold for most over the last game day, and that
-- price less the sales tax (0 where it didn't trade)
function best_market_for(metal)
  local best_market, best_price = mars, 0
  for _, market in ipairs(markets) do
    local price = board_price(metal, market) * share_kept_after_sales_tax // 100
    if price > best_price then best_market, best_price = market, price end
  end
  return best_market, best_price
end

-- What it fetches, less this unit's share of the trip and the margin
function highest_price_worth_paying(metal)
  local _, worth = best_market_for(metal)
  return worth - trip_cost // cargo_goal - min_margin
end

-- Buys what the cheapest seller here offers of a metal, if the price is
-- right and the load and spare credits have room. True if it bought any
function buy_metal_if_worth_it(metal)
  local price, seller, available = best_offer(metal)
  if available == 0 or price > highest_price_worth_paying(metal) then return false end
  local room_in_load = cargo_goal - metals_aboard()
  local affordable = spare_credits() // math.max(price, 1)
  local amount = math.min(available, room_in_load, affordable)
  if amount <= 0 then return false end
  return pull(amount, metal, seller) == 0
end

-- Tops up the tank here, from the cheapest seller
function fuel_up()
  if units(fuel) < trip_fuel then
    buy_here(trip_fuel, fuel)
    wait(refuel_pause)
  end
end

function sell_load_here()
  for hour = 1, selling_hours do
    if metals_aboard() == 0 then return end
    for _, metal in ipairs(traded) do
      local amount = math.min(units(metal), most_sold_per_metal_an_hour)
      if amount > 0 then sell_here(amount, metal) end
    end
    wait(selling_pause)
  end
end

-- The market where the load aboard sold for most over the last game day
function market_paying_most_for_load()
  local load_value = {}
  for _, metal in ipairs(traded) do
    local amount = units(metal)
    if amount > 0 then
      for _, market in ipairs(markets) do
        load_value[market] = (load_value[market] or 0) + amount * board_price(metal, market)
      end
    end
  end
  local best_market, best_value = mars, -1
  for _, market in ipairs(markets) do
    local value = load_value[market] or 0
    if value > best_value then best_market, best_value = market, value end
  end
  return best_market
end

-- Each look counts toward setting off with a smaller load
function shop_for_metals()
  fuel_up()
  local bought_any = false
  for _, metal in ipairs(traded) do
    if buy_metal_if_worth_it(metal) then bought_any = true end
  end
  looks_since_last_trip = looks_since_last_trip + 1
  if not bought_any then wait(nap) end
end

function haul_load_and_return()
  fuel_up()
  go(market_paying_most_for_load())
  sell_load_here()
  fuel_up()
  go(moon)
  looks_since_last_trip = 0
end

-- A full load, or a smaller one after waiting long enough, or whatever it
-- has once its money is spent
function ready_to_set_off()
  local load = metals_aboard()
  return load >= cargo_goal
    or (load >= min_load and looks_since_last_trip >= patience)
    or (load > 0 and spare_credits() < nearly_broke)
end

function report_home_now_and_then()
  rounds_worked = rounds_worked + 1
  if rounds_worked % report_every ~= 0 then return end
  print("day " .. today() .. " credits " .. credits() .. " fuel " .. units(fuel) .. " metals " .. metals_aboard())
  report()
end

function work_one_round()
  if ready_to_set_off() then haul_load_and_return() else shop_for_metals() end
  report_home_now_and_then()
end

-- The House's prices leave a trader no margin, and a startup may trade
-- only with the House: say so, rather than nap for ever
function wait_while_a_startup()
  while house_only() do
    set_status(startup_message)
    wait(startup_recheck_pause)
  end
end

-- An Engine is all it needs to fly; the rest of its credits are for trading
function get_an_engine()
  while units(engine_module) == 0 do
    if units(mining_kit) > 0 or units(factory_kit) > 0 then
      unpack()
    else
      buy_here(1, engine_module)
      wait(engine_shopping_pause)
    end
  end
end

-- May run again after a crash or an engine restart: each step is safe to repeat
function launched()
  wait_while_a_startup()
  fuel_floor(fuel_reserve)
  get_an_engine()
  go(moon)
  while true do work_one_round() end
end
