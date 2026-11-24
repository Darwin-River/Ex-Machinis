-- Adaptive: a market-aware company design for Ex Machinis, in Lua, written
-- as a state machine (the manual's "Market-aware drones"). It produces rods
-- with the Factory Kit; each round the market decides its mode. It names no
-- place: it works where metals cost least and sells where rods pay most.

credits_kept_for_fuel = 300
rods_per_selling_trip = 12
refuel_below = 40
costs_per_rod = 40                 -- fuel and trips, roughly
metals_per_purchase = 20
credits_per_game_hour = 10         -- what its time is worth when it compares trips

shopping, making, hauling, resting = 0, 1, 2, 3

local GAME_HOUR = 60
local FUEL_TOP_UP = 30
local GUESSED_ROD_PRICE = 225
local RODS_SOLD_AN_HOUR = 4
local HOURS_SELLING_AT_ONE_PLACE = 6
local RESTS_BEFORE_SELLING_ANYWAY = 5
local ROUNDS_BETWEEN_REPORTS = 8

function spare() return math.max(credits() - credits_kept_for_fuel, 0) end

function rod_price()
  local best = 0
  for _, board in ipairs(price_board(rods)) do best = math.max(best, board.price) end
  if best == 0 then return GUESSED_ROD_PRICE end
  return best
end

function worth_buying()
  local p = buy_price(metals_a1)
  if p == 0 then return false end
  return p * 2 + costs_per_rod < rod_price()
end

function rods_pay() return rod_price() > sell_price(alloys_a1) + costs_per_rod end

function cheapest_metals_place()
  local best = where_to_buy(metals_a1, metals_per_purchase, credits_per_game_hour)[1]
  return best and best.place or location()
end

-- A drone's bid that pays the trip, else the House's best bid after it
function best_rod_market()
  local best = where_to_sell(rods, math.max(units(rods), 1), credits_per_game_hour)[1]
  if best and best.net > 0 then return best.place end
  local place, most = nil, 0
  for _, bid in ipairs(house_demand(rods)) do
    if bid.price > most and bid.left > 0 then place, most = bid.place, bid.price end
  end
  return place
end

function refuel()
  if units(fuel) < refuel_below then buy_here(FUEL_TOP_UP, fuel); wait(GAME_HOUR) end
end

function shop()
  refuel()
  if not worth_buying() then become(resting); return end
  local price = buy_price(metals_a1)
  local n = math.min(metals_per_purchase, spare() // price)
  if n > 0 then buy_here(n, metals_a1); wait(GAME_HOUR) end
  if units(metals_a1) > 1 then become(making) else become(resting) end
end

-- One alloy a round, so the market is read again before the next
function make_one()
  if units(metals_a1) > 1 and units(fuel) > 10 then
    make(alloys_a1)
    if rods_pay() then make(rods) else sell_all(alloys_a1) end
    return
  end
  if units(rods) >= rods_per_selling_trip then become(hauling); return end
  become(shopping)
end

-- Buyers bid less as they stock up, and bid again later
function sell_rods_a_few_an_hour()
  for _ = 1, HOURS_SELLING_AT_ONE_PLACE do
    if units(rods) == 0 then return end
    sell_here(math.min(units(rods), RODS_SOLD_AN_HOUR), rods)
    wait(GAME_HOUR)
  end
end

function haul()
  local market = best_rod_market()
  if market then go(market) end
  refuel()
  sell_rods_a_few_an_hour()
  go(cheapest_metals_place())
  become(shopping)
end

-- After a few rests it sells what is aboard rather than wait for ever
function rest()
  wait(2 * GAME_HOUR)
  if units(rods) > 0 and ticks() > RESTS_BEFORE_SELLING_ANYWAY then become(hauling); return end
  if worth_buying() then become(shopping) end
end

function step()
  if in_mode(shopping) then shop(); return end
  if in_mode(making) then make_one(); return end
  if in_mode(hauling) then haul(); return end
  rest()
end

function tell()
  if ticks() % ROUNDS_BETWEEN_REPORTS ~= 0 then return end
  print("mode " .. mode .. "  credits " .. credits() .. " rod price " .. rod_price() ..
        "  metal " .. buy_price(metals_a1))
  report()
end

-- launched() runs again after a crash or a restart: it starts again shopping
function launched()
  fuel_floor(15)
  equip(factory_kit)
  go(cheapest_metals_place())
  become(shopping)
  while true do
    step()
    tick()
    tell()
  end
end
