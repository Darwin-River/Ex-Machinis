-- Systems shop, in Lua (#380) (Farid's design
-- from economy round 33, the best in the starter-library race). Release it
-- with the Factory Kit.
-- Pumps, pistons and valves: every exchange bids for them. A pump or a
-- piston is a rod and a hose (2 Metals A1, 3 Organics A1, 3 Inorganics A1);
-- a valve is a rod and a pipe (8 Metals A1). Each round it prices the three
-- from the live market where it is, builds one of the best, sells it and
-- looks again. It brews its own fuel from the same organics and inorganics.

polys, hoses, pipes = 2051, 3301, 3311
pumps, pistons, valves = 4311, 4321, 4301

-- Settings
cash_floor = 300   -- credits it keeps
low_fuel = 50      -- brew or buy fuel below this
min_score = 60     -- least profit per system worth building
batch = 2          -- systems' worth of goods it buys at a time

function spare() return math.max(credits() - cash_floor, 0) end
-- No seller: price it out
function cost(res)
  local p = buy_price(res)
  if p == 0 then return 999 end
  return p
end
function net(product) return sell_price(product) * 95 // 100 end

-- Profit per system, from the live prices here
function scores()
  local met, org, ino = cost(metals_a1), cost(organics_a1), cost(inorganics_a1)
  local hose = met * 2 + org * 3 + ino * 3
  return {
    [pumps] = net(pumps) - hose,
    [pistons] = net(pistons) - hose,
    [valves] = net(valves) - met * 8,
  }
end

best_score = 0
function choose()
  local best, all = nil, scores()   -- one look at the market a round
  best_score = min_score
  for _, product in ipairs({pumps, pistons, valves}) do
    local score = all[product]
    if score > best_score then best, best_score = product, score end
  end
  return best
end

-- Goods one system needs: metals, organics, inorganics
function needs(product)
  if product == valves then return {[metals_a1] = 8} end
  return {[metals_a1] = 2, [organics_a1] = 3, [inorganics_a1] = 3}
end

-- With fewer than per of a good aboard, buy up to batch systems' worth
function stock_up(per, res)
  if per == 0 or units(res) >= per then return end
  local n = math.min(per * batch - units(res), spare() // cost(res))
  if n > 0 then buy_here(n, res) end
end

function have(need)
  for res, n in pairs(need) do
    if units(res) < n then return false end
  end
  return true
end

-- Make something until n are aboard; false if a step fails
function ensure(n, res)
  while units(res) < n do
    if make(res) ~= 0 then return false end
  end
  return true
end
function rod_ok() return units(rods) > 0 or (ensure(1, alloys_a1) and make(rods) == 0) end
function hose_ok() return units(hoses) > 0 or (ensure(3, polys) and make(hoses) == 0) end
function pipe_ok() return units(pipes) > 0 or (ensure(3, alloys_a1) and make(pipes) == 0) end
function build(product)
  if not rod_ok() then return end
  local part_ok
  if product == valves then part_ok = pipe_ok() else part_ok = hose_ok() end
  if part_ok then make(product) end
end

function sell_goods()
  for _, product in ipairs({pumps, pistons, valves}) do sell_all(product) end
end

-- Fuel: brew it (1 organics + 2 inorganics make 30) when the goods can be
-- had; else buy it here under 20; else fly to the Moon, where chemists sell
function brew()
  if units(organics_a1) < 1 then buy_here(2, organics_a1) end
  if units(inorganics_a1) < 2 then buy_here(3, inorganics_a1) end
  if units(organics_a1) < 1 or units(inorganics_a1) < 2 then return false end
  return make(fuel) == 0
end
function refuel()
  if units(fuel) >= low_fuel then return end
  if credits() >= 60 and brew() then return end
  local p = buy_price(fuel)
  if p > 0 and p < 20 then buy_here(75 - units(fuel), fuel); return end
  if location() == moon then buy_order_if_changed(12, 60, fuel); wait(120); return end
  if units(fuel) > 12 then go(moon) end
end

idle = 0
function other_site() if location() == earth then return moon else return earth end end
function rest()
  idle = idle + 1
  if idle > 4 and units(fuel) > 25 then idle = 0; go(other_site()); return end
  wait(120)
end

-- Growth: with 7,000 credits before day 24, at an exchange, buy a hull and
-- a Factory Kit, launch a copy of this drone and equip it
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
function fuel_to(n)
  while units(fuel) < n do
    if not brew() then fill_to(n); return end
  end
end
function reinvest()
  if units(factory_kit) > 0 then equip_clone(); fuel_to(60); return end
  if units(cruiser_kit) == 0 then
    if today() >= 24 or credits() < 7000 then return end
    get_one(cruiser_kit)
    if units(cruiser_kit) == 0 then return end
  end
  fuel_to(90)
  if units(fuel) < 80 then return end
  if launch(me(), cruiser_kit) ~= 0 then return end
  get_one(factory_kit)
  if units(factory_kit) > 0 then equip_clone() end
  fuel_to(60)
end

rounds = 0
function tell(product)
  rounds = rounds + 1
  if rounds % 3 ~= 0 then return end
  print("day " .. today() .. " at " .. location() .. " making " .. (product or 0) ..
        " score " .. best_score .. " credits " .. credits() .. " fuel " .. units(fuel))
  report()
end

function work()
  refuel()
  local product = choose()
  if not product then tell(nil); rest(); return end
  local need = needs(product)
  for _, res in ipairs({metals_a1, organics_a1, inorganics_a1}) do stock_up(need[res] or 0, res) end
  if not have(need) then tell(product); rest(); return end
  idle = 0
  build(product)
  sell_goods()
  tell(product)
  reinvest()
end

-- launched() may run again (after a crash, or if the engine restarts):
-- await_kit and unpack pass once the tools are unpacked
function launched()
  fuel_floor(15)   -- make, mine, refine and push stop at 15 fuel: it keeps enough to buy more (#496)
  await_kit()
  unpack()
  while true do work() end
end
