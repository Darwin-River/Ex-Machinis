-- Electronics: a company design for Public Forge, in Lua, from newcomer
-- Elif's second company in economy round 33 (#380). It equips itself with the Factory Kit. In the
-- starter-library race (60 starters and the library designs, 40 game days)
-- it gained 9,524 over its starter package, against 6,425 for the
-- Machinist, the best design before it.

-- Elif 2: Moon electronics factory (transceivers, solenoids, computers).
-- It picks the product that scores best at the Moon's bids, makes its
-- parts one step a round (alloys, rods, conductors, semiconductors,
-- superconductors), buys metals only under set prices, bids for Metals
-- (A2) and (A3) while it has money, and sells finished goods when they
-- fetch at least min_sale. Busy, it looks again in 30 minutes; idle, it
-- waits twice as long each time, up to 8 game hours.

al1, al2, al3 = alloys_a1, alloys_a2, alloys_a3
conds, semis, supers = 3201, 3211, 3221
xcvrs, solens, comps = 4241, 4271, 4261

-- Settings
cash_floor = 500   -- credits it keeps; below this it neither buys nor bids
min_sale = 350     -- least it sells a finished good for
max_stock = 4      -- finished goods aboard at which it stops making more

product, best, nap, rounds = 0, 0, 30, 0

function rich() return credits() >= cash_floor end

-- A standing bid: a credit over the best other bid here, at most price
function bid(price, want, res)
  local p = math.max(math.min(sell_price(res) + 1, price), 1)
  buy_order_if_changed(p, want, res)
end
function sync_bids()
  if rich() then bid(34, 4, metals_a2); bid(46, 2, metals_a3); return end
  buy_order_if_changed(1, 0, metals_a2)
  buy_order_if_changed(1, 0, metals_a3)
end

-- Buy n here while rich, if the cheapest seller asks price or less
function buy_under(n, res, price)
  if not rich() then return end
  local p = buy_price(res)
  if p == 0 or price < p then return end
  buy_here(n, res)
end
-- Buy up to target aboard, at price or less
function top_to(target, res, price)
  local n = target - units(res)
  if n > 0 then buy_under(n, res, price) end
end

-- Below 45 fuel: brew it if the goods are aboard (true: a busy round),
-- else buy 40 at 9 or less
function fuel_step()
  if units(fuel) >= 45 then return false end
  if units(organics_a1) > 0 and units(inorganics_a1) > 1 then make(fuel); return true end
  buy_under(40, fuel, 9)
  return false
end

-- Parts one product needs: rods, conductors, semiconductors, superconductors
function needs()
  if product == xcvrs then return 1, 1, 1, 0 end
  if product == solens then return 1, 2, 0, 0 end
  return 0, 1, 2, 1
end

-- Profit per 10 game hours: 95% of the bid, less the goods' cost, over the hours
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
  if sell_price(res) < min_sale then return end
  sell_here(units(res), res)
end
function sell_step() sell_one(xcvrs); sell_one(solens); sell_one(comps) end

-- One making step at a time; true when it made something
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
  local n_rods, n_conds, n_semis, n_supers = needs()
  if n_supers > units(supers) then return mk_super() end
  if n_semis > units(semis) then return mk_semi() end
  if n_conds > units(conds) then return mk_cond() end
  if n_rods > units(rods) then return mk_rod() end
  return try(product)
end

-- Metals for the product, at set prices
function shop()
  if product == xcvrs then top_to(7, metals_a1, 32); top_to(1, metals_a2, 40); return end
  if product == solens then top_to(10, metals_a1, 32); return end
  top_to(7, metals_a1, 32)
  top_to(3, metals_a2, 40)
  top_to(1, metals_a3, 50)
end

-- Work in progress: parts and alloys aboard
function wip()
  return units(rods) + units(conds) + units(semis) + units(supers) +
         units(al1) + units(al2) + units(al3)
end

-- Growth: up to day 24, with 6,500 credits, buy a hull and a Factory Kit,
-- launch a copy of this drone and hand it the kit and 40 fuel
function get_one(resource)
  if units(resource) > 0 then return end
  buy_here(1, resource)
  for _ = 1, 6 do
    if units(resource) > 0 then return end
    wait(60)
  end
end
function equip_clone()
  local clones = my_clones()
  local newest = clones[#clones]
  if not newest then return end
  push(1, factory_kit, newest.drone)
  push(40, fuel, newest.drone)
end
function reinvest()
  if units(factory_kit) > 0 then equip_clone(); return end
  if today() > 24 then return end
  if units(cruiser_kit) == 0 then
    if credits() < 6500 then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  if units(fuel) < 110 then buy_under(80, fuel, 9); return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(factory_kit)
  if units(factory_kit) > 0 then equip_clone() end
end

function tell()
  rounds = rounds + 1
  if rounds % 6 ~= 0 then return end
  print("day " .. today() .. " cr " .. credits() .. " fuel " .. units(fuel) ..
        " make " .. product .. " best " .. best .. " stock " .. goods_held() .. " wip " .. wip() ..
        " m1 " .. units(metals_a1) .. " m2 " .. units(metals_a2) .. " m3 " .. units(metals_a3))
  report()
end

-- Busy: look again in 30 minutes. Idle: wait, twice as long each time, up
-- to 8 game hours
function busy() nap = 30 end
function quiet()
  wait(nap)
  nap = math.min(nap * 2, 480)
end

function round()
  sync_bids()
  if fuel_step() then busy(); return end
  sell_step()
  if goods_held() < max_stock then
    if make_step() then busy(); return end
    shop()
    if make_step() then busy(); return end
  end
  reinvest()
  quiet()
end

-- launched() may run again (after a crash, or if the engine restarts):
-- equip passes once the tools are unpacked
function launched()
  fuel_floor(15)   -- make, mine, refine and push stop at 15 fuel: it keeps enough to buy more (#496)
  equip(factory_kit)   -- buys a kit at Earth and unpacks it (a copy waits for the one it is handed; #506)
  go(moon)
  choose()
  while true do
    round()
    if wip() == 0 then choose() end
    tell()
  end
end
