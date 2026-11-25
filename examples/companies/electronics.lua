-- Electronics: a company design for Ex Machinis, in Lua, from a newcomer's
-- second company in a playtest; it earned the most of the designs raced
-- then. It produces transceivers, solenoids and computers with the Factory
-- Kit, making their parts one step a round. It names no place: it builds
-- where the House pays most for what it makes, counting the trip.

al1, al2, al3 = alloys_a1, alloys_a2, alloys_a3
conds, semis, supers = 3201, 3211, 3221
xcvrs, solens, comps = 4241, 4271, 4261

credits_kept = 500                -- below this it neither buys nor bids
least_sale_price = 350
finished_goods_before_pausing = 4
hours_per_stay = 240              -- game hours it expects to build at one place
copy_until_age = 24               -- game days
copy_at_credits = 6500

local NAP_WHEN_BUSY = 30
local LONGEST_NAP = 480
local FUEL_FOR_COPY = 40
local FUEL_TO_LAUNCH = 110
local HOURS_TO_WAIT_FOR_DELIVERY = 6
local ROUNDS_BETWEEN_REPORTS = 6
local UNREACHABLE = 999

product, best, nap, rounds = 0, 0, NAP_WHEN_BUSY, 0

function hours_to(place)
  if place == location() then return 0 end
  local trip = trip_to(place)
  return trip and trip.hours or UNREACHABLE
end

function best_factory_place()
  local best_place, best_value = location(), 0
  for _, good in ipairs({xcvrs, solens, comps}) do
    for _, b in ipairs(house_demand(good)) do
      local hours = hours_to(b.place)
      local value = hours == UNREACHABLE and 0 or b.price * hours_per_stay / (hours_per_stay + hours)
      if value > best_value then best_place, best_value = b.place, value end
    end
  end
  return best_place
end

function rich() return credits() >= credits_kept end

function bid(price, want, res)
  local p = math.max(math.min(sell_price(res) + 1, price), 1)
  buy_order_if_changed(p, want, res)
end
function sync_bids()
  if rich() then bid(34, 4, metals_a2); bid(46, 2, metals_a3); return end
  buy_order_if_changed(1, 0, metals_a2)
  buy_order_if_changed(1, 0, metals_a3)
end

function buy_under(n, res, price)
  if not rich() then return end
  local p = buy_price(res)
  if p == 0 or price < p then return end
  buy_here(n, res)
end
function top_to(target, res, price)
  local n = target - units(res)
  if n > 0 then buy_under(n, res, price) end
end

function fuel_step()
  if units(fuel) >= 45 then return false end
  if units(organics_a1) > 0 and units(inorganics_a1) > 1 then make(fuel); return true end
  buy_under(40, fuel, 9)
  return false
end

function parts_needed()
  if product == xcvrs then return 1, 1, 1, 0 end
  if product == solens then return 1, 2, 0, 0 end
  return 0, 1, 2, 1
end

-- Profit per 10 game hours after the 5% sales tax
function score(price, cost, hours) return (price * 95 // 100 - cost) * 10 // hours end
function consider(s, p) if s > best then product, best = p, s end end
function choose()
  best = -32000
  consider(score(sell_price(xcvrs), 271, 28), xcvrs)
  consider(score(sell_price(solens), 312, 30), solens)
  consider(score(sell_price(comps), 402, 34), comps)
end

function goods_held() return units(xcvrs) + units(solens) + units(comps) end
function sell_one(res)
  if units(res) == 0 then return end
  if sell_price(res) < least_sale_price then return end
  sell_here(units(res), res)
end
function sell_step() sell_one(xcvrs); sell_one(solens); sell_one(comps) end

-- One making step at a time, so the market is read again between them
function try(res) return make(res) == 0 end
function mk_al1() return units(metals_a1) > 1 and try(al1) end
function mk_al2() return units(metals_a1) > 0 and units(metals_a2) > 0 and try(al2) end
function mk_al3()
  return units(metals_a1) > 0 and units(metals_a2) > 0 and units(metals_a3) > 0 and try(al3)
end
function mk_rod() if units(al1) > 0 then return try(rods) end return mk_al1() end
function mk_cond() if units(al1) > 1 then return try(conds) end return mk_al1() end
function mk_semi() if units(al2) > 0 then return try(semis) end return mk_al2() end
function mk_super() if units(al3) > 1 then return try(supers) end return mk_al3() end
function make_step()
  local n_rods, n_conds, n_semis, n_supers = parts_needed()
  if n_supers > units(supers) then return mk_super() end
  if n_semis > units(semis) then return mk_semi() end
  if n_conds > units(conds) then return mk_cond() end
  if n_rods > units(rods) then return mk_rod() end
  return try(product)
end

function shop()
  if product == xcvrs then top_to(7, metals_a1, 32); top_to(1, metals_a2, 40); return end
  if product == solens then top_to(10, metals_a1, 32); return end
  top_to(7, metals_a1, 32)
  top_to(3, metals_a2, 40)
  top_to(1, metals_a3, 50)
end

function wip()
  return units(rods) + units(conds) + units(semis) + units(supers) +
         units(al1) + units(al2) + units(al3)
end

function buy_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, HOURS_TO_WAIT_FOR_DELIVERY do
    if units(resource) > 0 then return end
    wait(60)
  end
end
function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, factory_kit, newest.drone)
  push(FUEL_FOR_COPY, fuel, newest.drone)
end
function reinvest()
  if units(factory_kit) > 0 then equip_clone(); return end
  if company_age() > copy_until_age then return end
  if units(cruiser_kit) == 0 then
    if credits() < copy_at_credits or fleet_full() then return end
    buy_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  if units(fuel) < FUEL_TO_LAUNCH then buy_under(80, fuel, 9); return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  buy_one(factory_kit)
  if units(factory_kit) > 0 then equip_clone() end
end

-- A playtest's Foundry sat on 11,000 credits while offerings went unbacked
invest_day = -1
function invest()
  if today() == invest_day then return end
  invest_day = today()
  invest_surplus(company_age() > copy_until_age and 3000 or 8500)
end

function tell()
  rounds = rounds + 1
  if rounds % ROUNDS_BETWEEN_REPORTS ~= 0 then return end
  print("day " .. today() .. " cr " .. credits() .. " fuel " .. units(fuel) ..
        " make " .. product .. " best " .. best .. " stock " .. goods_held() .. " wip " .. wip() ..
        " m1 " .. units(metals_a1) .. " m2 " .. units(metals_a2) .. " m3 " .. units(metals_a3))
  report()
end

function busy() nap = NAP_WHEN_BUSY end
function quiet()
  wait(nap)
  nap = math.min(nap * 2, LONGEST_NAP)
end

function round()
  invest()
  sync_bids()
  if fuel_step() then busy(); return end
  sell_step()
  if goods_held() < finished_goods_before_pausing then
    if make_step() then busy(); return end
    shop()
    if make_step() then busy(); return end
  end
  reinvest()
  quiet()
end

function launched()
  fuel_floor(15)
  equip(factory_kit)
  go(best_factory_place())
  choose()
  while true do
    round()
    if wip() == 0 then choose() end
    tell()
  end
end
