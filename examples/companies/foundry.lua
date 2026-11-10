-- Foundry: a company design for Public Forge, in Lua, from newcomer Ines's
-- first company in economy round 29 (#380). It equips itself with the Factory Kit. In the starter-library race (60
-- starters and the library designs, 40 game days) it gained 9,516 over its
-- starter package, against 6,425 for the Machinist, the best design before it.

-- Ines Foundry v1: a market-aware factory (Factory Kit), based at the Moon.
-- Every round it prices every recipe it knows from the live order book here
-- (what the inputs cost to buy, what the product fetches at the best bid),
-- picks the one that earns most per game hour, buys a small batch of inputs,
-- makes one, and sells finished goods in lots. It brews its own fuel when
-- that is cheaper than buying it, and launches clones with spare cash.

polymers, springs, gears, gear_boxes = 2051, 3101, 3111, 4111
inorganics_a3, organics_a3 = 2033, 2043

-- Settings
cash_floor = 300
min_gain = 20      -- profit per 10 game hours below which it rests

-- --- recipes: product, units out, fuel, hours, a1n and a2n (Alloys (A1)
-- and (A2) it makes first), and the inputs it buys: {resource, units}.
-- Fixed data, so locals: they are rebuilt when the drone loads, not saved
local function recipe_of(product, out, fuel_used, hours, a1n, a2n, ins)
  return {product = product, out = out, fuel = fuel_used, hours = hours,
          a1n = a1n, a2n = a2n, ins = ins}
end
local rec = {
  recipe_of(2051, 1, 2, 2, 0, 0, {{2031, 1}, {2041, 1}}),
  recipe_of(2021, 1, 2, 2, 0, 0, {{2011, 2}}),
  recipe_of(2022, 1, 2, 2, 0, 0, {{2011, 1}, {2012, 1}}),
  recipe_of(2023, 2, 2, 2, 0, 0, {{2011, 1}, {2012, 1}, {2013, 1}}),
  recipe_of(2052, 2, 2, 2, 0, 0, {{2031, 1}, {2041, 1}, {2042, 1}}),
  recipe_of(2053, 2, 2, 2, 0, 0, {{2031, 1}, {2042, 1}, {2043, 1}}),
  recipe_of(2042, 2, 2, 2, 0, 0, {{2032, 1}, {2041, 2}}),
  recipe_of(2043, 2, 2, 2, 0, 0, {{2033, 1}, {2042, 2}}),
  -- parts: a1n Alloys (A1) and a2n Alloys (A2), then the part
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
  -- the gear box (last): rod, gear and spring
  recipe_of(4111, 1, 23, 30, 0, 0, {{2011, 8}, {2012, 2}}),
}
goods_count = 8      -- the first 8 recipes are made in one step
gear_box_no = #rec   -- the gear box recipe

-- Recipes are tried last first: on a tie
-- the later recipe wins
function each_recipe()
  local i = #rec + 1
  return function()
    i = i - 1
    if i >= 1 then return i end
  end
end

-- --- the hold, read once a round (query 210: resource, units) ------------
held = {}
function snap()
  held = {}
  for _, item in ipairs(hold()) do held[item.resource] = item.units end
end
function have(res) return held[res] or 0 end

-- --- money ----------------------------------------------------------------
function spare() return math.max(credits() - cash_floor, 0) end

-- --- pricing --------------------------------------------------------------
-- prices are read once a round into two tables (each query costs 1,000
-- instructions, and a drone pays fuel for thinking): what each input costs
-- here, and the best bid here for each product (parts every 4th round)
rounds = 0
inp, bids = {}, {}
local input_goods = {2011, 2012, 2013, 2031, 2032, 2033, 2041, 2042, 2043}
function refresh()
  for _, res in ipairs(input_goods) do inp[res] = buy_price(res) end
  for i in each_recipe() do
    if i <= goods_count or rounds % 4 == 1 then bids[i] = sell_price(rec[i].product) end
  end
end

-- inputs for one batch: their cost, and false if one can't be bought here
function in_cost(i)
  local cost, ok = 0, true
  for _, input in ipairs(rec[i].ins) do
    local price = inp[input[1]] or 0
    if price == 0 then ok = false end
    cost = cost + price * input[2]
  end
  return cost, ok
end

-- profit per 10 game hours of making recipe i here, now; -1 if impossible
function gain(i)
  local r, b = rec[i], bids[i] or 0
  if b == 0 then return -1 end
  local revenue = b * r.out * 95 // 100
  local cost, ok = in_cost(i)
  if not ok then return -1 end
  return (revenue - cost - r.fuel * 2) * 10 // r.hours
end

best, best_g = -1, 0
function choose()
  best, best_g = -1, min_gain
  for i in each_recipe() do
    local g = gain(i)
    if g > best_g then best, best_g = i, g end
  end
end

-- --- inputs ---------------------------------------------------------------
function ready(i)
  for _, input in ipairs(rec[i].ins) do
    if have(input[1]) < input[2] then return false end
  end
  return true
end

-- batches of recipe i the spare credits pay for, at most 4
function batch(i)
  local cost = in_cost(i)
  return math.min(spare() // math.max(cost, 1), 4)
end
-- buy what the inputs of recipe i lack for a batch
function stock_up(i)
  local k = batch(i)
  if k == 0 then return end
  for _, input in ipairs(rec[i].ins) do
    local n = input[2] * k - have(input[1])
    if n > 0 then buy_here(n, input[1]) end
  end
  snap()
end

-- --- making ---------------------------------------------------------------
-- make n of a resource; 0, or the first failure
function n_make(n, res)
  for _ = 1, n do
    local r = make(res)
    if r ~= 0 then return r end
  end
  return 0
end
function make_box()
  local r = n_make(3, alloys_a1)
  if r ~= 0 then return r end
  r = n_make(2, alloys_a2)
  if r ~= 0 then return r end
  for _, part in ipairs({rods, gears, springs}) do
    r = make(part)
    if r ~= 0 then return r end
  end
  return make(gear_boxes)
end
function make_one(i)
  if units(fuel) < 30 then return -2 end
  local r = rec[i]
  if i <= goods_count then return make(r.product) end
  if i == gear_box_no then return make_box() end
  local result = n_make(r.a1n, alloys_a1)
  if result ~= 0 then return result end
  result = n_make(r.a2n, alloys_a2)
  if result ~= 0 then return result end
  return make(r.product)
end

-- a recipe whose inputs are already aboard and still pays, or -1
function leftover()
  for i in each_recipe() do
    if ready(i) and gain(i) > 0 then return i end
  end
  return -1
end

-- --- selling: everything in the recipe list, in lots of 4 or more,
-- or whatever is aboard every 12th round, or at once when credits are
-- below the cash floor (#512: a Foundry spent down to 3 credits, couldn't
-- buy fuel, and wrecked holding a gear box, nozzles and semiconductors)
function sell_goods()
  local short = credits() < cash_floor
  for i in each_recipe() do
    local res = rec[i].product
    local n = have(res)
    if (n > 3 or rounds % 12 == 0 or short) and n > 0 then sell_here(n, res) end
  end
end

-- --- fuel: brew it from 1 organics and 2 inorganics when that is cheaper
function can_brew() return have(organics_a1) > 0 and have(inorganics_a1) > 1 end
-- buying the inputs and brewing is cheaper than buying 30 fuel?
function brew_pays()
  local org, ino = buy_price(organics_a1), buy_price(inorganics_a1)
  if org == 0 or ino == 0 then return false end
  local f = buy_price(fuel)
  if f == 0 then return true end
  return org + ino * 2 < f * 30
end
function refuel()
  if units(fuel) >= 50 then return end
  if not can_brew() and brew_pays() then
    buy_here(1, organics_a1)
    buy_here(2, inorganics_a1)
    snap()
  end
  if can_brew() and make(fuel) == 0 then snap(); return end
  if credits() < cash_floor then sell_goods() end   -- raise the credits first
  buy_here(30, fuel)
  snap()
end

-- --- growth: copied from the Smith design, stops after day 24 ---------
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
function fill_to(n)
  local had = units(fuel)
  local need = n - had
  if need <= 0 then return end
  buy_here(need, fuel)
  for _ = 1, 6 do
    if units(fuel) > had then return end
    wait(60)
  end
end
function reinvest()
  if units(factory_kit) > 0 then equip_clone(); fill_to(60); return end
  if units(cruiser_kit) == 0 then
    if today() > 24 then return end
    if credits() < 7500 then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fill_to(90)
  if units(fuel) < 80 then return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(factory_kit)
  if units(factory_kit) > 0 then equip_clone() end
  fill_to(60)
end

function tell()
  if rounds % 6 ~= 0 then return end
  local what = best
  if best >= 1 then what = rec[best].product end
  print("day " .. today() .. " credits " .. credits() .. " fuel " .. units(fuel) ..
        " best " .. what .. " gain " .. best_g)
  report()
end

function round()
  rounds = rounds + 1
  snap()
  refuel()
  sell_goods()
  refresh()
  local i = leftover()
  if i >= 1 then make_one(i); return end
  choose()
  tell()
  if best < 1 then wait(120); return end
  if not ready(best) then stock_up(best) end
  if ready(best) then make_one(best) else wait(60) end
  reinvest()
end

-- launched() may run again (after a crash, or if the engine restarts):
-- equip passes once the tools are unpacked
function launched()
  fuel_floor(15)   -- make, mine, refine and push stop at 15 fuel: it keeps enough to buy more (#496)
  equip(factory_kit)   -- buys a kit at Earth and unpacks it (a copy waits for the one it is handed; #506)
  go(moon)
  while true do round() end
end
