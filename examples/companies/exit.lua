-- Exit: a company with a sell-out routine (#932). It mines wolfite at the
-- Moon (Mining Kit), refines it and sells the metals at the Moon Depot, like
-- the Miner, but every round it also asks: is this company failing? When
-- it is, it doesn't wait to go broke (after 50 game days with no credits a
-- company dissolves, and its drones become wrecks for others to plunder):
-- it runs its exit routine instead:
--   1. sell every good aboard (sell_all), so nothing is left to plunder;
--   2. pay what it owes (obligations()): repay each loan, withdraw each
--      loan request, cancel each contract;
--   3. close the company (close_company()), which shares its credits among
--      its shareholders by their shares.
-- close_company() returns -24 while the company still owes its lenders or
-- has an open contract: then it goes back to step 2 and tries again later.
-- Each drone runs the same code and sees the same company, so every drone
-- sells its own goods; the one that closes waits a few hours first, so the
-- others have time to.
-- A startup that closes cancels its debt but hands its credits back to the
-- bank, so this routine only runs once the company has matured.
-- Shareholders read a public company's code before they buy: a clear exit
-- routine tells them they will get the credits, not a wreck.

-- Settings: when to give up
down_days = 10     -- game days in a row its credits fell before it calls it failing
quiet_days = 20    -- game days with no sale before it calls it failing
broke_fuel = 20    -- fuel below which it is failing, if credits can't buy 30 more
fuel_cost = 3      -- credits a fuel unit costs, roughly
settle_hours = 6   -- game hours the closer waits for the others to sell

-- Settings: work
hold_cap = 40      -- metals aboard at which it sells them
low_fuel = 40      -- fuel below which it buys more

local LOAD = 18    -- wolfite for one refining run
local goods = {metals_a1, metals_a2, inorganics_a2}

-- Saved state
day_seen = -1      -- the game day it last checked
last_credits = 0   -- the company's credits that day
falls = 0          -- game days in a row its credits fell
last_sale_day = -1 -- the game day its last sale went through
exiting = false    -- once true, it only runs the exit routine

-- --- Is it failing? --------------------------------------------------------

-- Once a game day: did the company's credits fall since yesterday?
function count_falls()
  if today() == day_seen then return end
  local c = credits()
  if day_seen >= 0 and c < last_credits then falls = falls + 1 else falls = 0 end
  day_seen, last_credits = today(), c
end

function failing()
  if house_only() then return false end   -- a startup: never (see above)
  if last_sale_day < 0 then last_sale_day = today() end
  count_falls()
  if falls >= down_days then return "credits fell " .. falls .. " game days in a row" end
  if today() - last_sale_day >= quiet_days then return "nothing sold for " .. quiet_days .. " game days" end
  if units(fuel) < broke_fuel and credits() < 30 * fuel_cost then return "out of fuel and cash" end
  return false
end

-- --- The exit routine ------------------------------------------------------

function sell_everything()
  go(moon)   -- the Moon Depot buys what it mines (go stays put if it can't fly)
  for _, item in ipairs(hold()) do
    -- goods only: fuel and kits and modules (5000 and up) are its tools
    if item.resource ~= fuel and item.resource < 5000 then sell_all(item.resource) end
  end
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
  elseif result == -24 then
    set_status("still owes: repaying before it closes")   -- loans or contracts still open
  elseif result == -9 then
    set_status("nothing to close (no company of its own, or the House's)")
  end
  return result
end

-- Runs again every few hours until the company is gone (its drones become
-- wrecks then, and stop)
function exit_routine()
  sell_everything()
  wait(60 * settle_hours)   -- the other drones sell theirs meanwhile
  sell_everything()         -- and anything that arrived since
  if settle_up() > 0 then wait(120) end   -- settled within a real minute
  if try_to_close() ~= 0 then wait(240) end
  report()
end

-- --- Work ------------------------------------------------------------------

function refuel()
  if units(fuel) >= low_fuel then return end
  raise_cash(150)
  buy_here(30, fuel)
  wait(60)
end

function goods_aboard()
  local n = 0
  for _, r in ipairs(goods) do n = n + units(r) end
  return n
end

function sell_goods()
  for _, r in ipairs(goods) do
    if units(r) > 0 and sell_all(r) == 0 then last_sale_day = today() end
  end
end

function work()
  refuel()
  while units(wolfite) < LOAD do
    local result = mine(wolfite)
    if result == -30 then refuel()
    elseif result ~= 0 then wait(60); return end
  end
  refine(wolfite)
  if goods_aboard() >= hold_cap then sell_goods() end
end

function launched()
  fuel_floor(15)
  equip(mining_kit)
  go(moon)
  while true do
    if not exiting then
      local why = failing()
      if why then
        exiting = true   -- saved: a restart goes on with the exit
        set_status("failing (" .. why .. "): selling out")
        print("exit: " .. why); report()
      end
    end
    if exiting then exit_routine() else work() end
  end
end
