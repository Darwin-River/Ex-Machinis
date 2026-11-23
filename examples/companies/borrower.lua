-- Borrower: a public company that borrows to grow (#932). It mines wolfite
-- at the Moon (Mining Kit), refines it and sells the metals at the Moon
-- Depot. Once a game day it looks at its finances:
--   * it asks for a loan (request_loan) only to fund a copy of itself, and
--     only when a copy would earn the loan back, with interest, within the
--     term: it estimates that from what each drone earned a day over the
--     last 7 game days (earnings_per_day);
--   * it borrows only what it lacks: the copy's cost less its spare credits;
--   * when nobody backs its request for a few game days, it withdraws it
--     (cancel_request) and asks again a little dearer, up to a highest rate;
--   * it repays early (repay) as soon as it can spare the credits, which
--     costs less interest: only the interest due so far is paid;
--   * obligations() tells it what it owes and what it has asked for.
-- Lenders read a public company's code before they back it: a borrower
-- that only borrows what it can pay back is one they can trust.
--
-- request_loan returns -23 when the company isn't public: only a matured
-- company whose shares are held by more than one holder (the bank counts)
-- may borrow. A startup, or a company its player owns outright, gets -23;
-- this script then grows from its own credits only, and asks again every
-- 10 game days.

-- Settings: borrowing
start_rate = 4     -- percent interest for the whole loan it asks first
rate_step = 1      -- how much dearer each new ask is when nobody backs it
max_rate = 10      -- the dearest it will pay (percent for the whole loan)
term = 30          -- game days until a loan is due
wait_days = 3      -- game days unbacked before it withdraws and asks dearer
ramp_days = 5      -- game days a new copy takes to start earning
keep_per_drone = 1500  -- credits it keeps for each drone, never lent out or spent

-- Settings: work
hold_cap = 40      -- metals aboard at which it sells them
low_fuel = 40      -- fuel below which it buys more

local LOAD = 18                 -- wolfite for one refining run
local COPY_COST = 5000          -- a cruiser kit and a Mining Kit, roughly
local goods = {metals_a1, metals_a2, inorganics_a2}

-- Saved state
rate = start_rate  -- the rate of its next ask
asked_day = -1     -- the game day it last asked
looked_day = -1    -- the game day it last looked at its finances
not_public_day = -100   -- the game day it last got -23

-- --- Reading its finances --------------------------------------------------

function drones() local f = fleet(); return math.max(f.drones + f.coming, 1) end

-- Credits above what its drones need to keep working
function spare() return credits() - keep_per_drone * (drones() + 1) end

-- The loans it owes ({request id = credits owed}) and its open request, if any
function read_obligations()
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

-- --- Borrowing and repaying ------------------------------------------------

-- Repays a loan as soon as it is owed less than what it can spare
function repay_early(loans)
  for id, owed in pairs(loans) do
    if spare() >= owed then
      repay(id)   -- all of this request's loans, with the interest due so far
      print("repaying loan " .. id .. ": " .. owed .. " credits")
      rate = start_rate   -- the next loan starts cheap again
    end
  end
end

function ask_for_loan()
  local amount = COPY_COST - spare()
  if amount < 1 or not copy_pays_back(amount, rate) then return end
  local id = request_loan(amount, rate, term)
  if id == -23 then
    not_public_day = today()
    set_status("not public: it grows from its own credits")
  elseif id > 0 then
    asked_day = today()
    set_status(string.format("asked for %d at %d%% for %d game days", amount, rate, term))
  end
end

-- Unbacked for wait_days: withdraw it and ask again, a little dearer
function raise_rate(request)
  if today() - asked_day < wait_days then return end
  cancel_request(request.id)
  if rate + rate_step > max_rate then
    set_status("nobody lends at " .. max_rate .. "%: growing from its own credits")
    asked_day = today()   -- try again later at the same rate
    return
  end
  rate = rate + rate_step
  ask_for_loan()
end

-- Once a game day, by the first drone only: copies share the company's
-- loans, and two drones raising the same request would trip each other up
function finance()
  if today() == looked_day or house_only() or lineage().generation > 0 then return end
  looked_day = today()
  local loans, request = read_obligations()
  repay_early(loans)
  if request then raise_rate(request); return end
  if next(loans) == nil and today() - not_public_day >= 10 then ask_for_loan() end
end

-- --- Growing ---------------------------------------------------------------

function get_one(r)
  if units(r) > 0 then return end
  buy_here(1, r)
  for _ = 1, 6 do
    if units(r) > 0 then return end
    wait(60)
  end
end

-- With the copy's cost spare (its own or borrowed), launch a copy
function grow()
  if house_only() or fleet_full() then return end
  if spare() < COPY_COST and units(cruiser_kit) == 0 then return end
  get_one(cruiser_kit)
  if units(cruiser_kit) == 0 then return end
  buy_here(90 - math.min(units(fuel), 90), fuel)
  if units(fuel) < 80 or launch(me(), cruiser_kit) ~= 0 then return end
  get_one(mining_kit)
  local clones = my_clones()
  local newest = clones[#clones]
  if newest and units(mining_kit) > 0 then
    push(1, mining_kit, newest.drone)
    push(40, fuel, newest.drone)
  end
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

function work()
  refuel()
  while units(wolfite) < LOAD do
    local result = mine(wolfite)
    if result == -30 then refuel()
    elseif result ~= 0 then wait(60); return end
  end
  refine(wolfite)
  if goods_aboard() >= hold_cap then
    for _, r in ipairs(goods) do sell_all(r) end
    finance()
    grow()
    report()
  end
end

function launched()
  fuel_floor(15)
  equip(mining_kit)   -- a copy waits for the kit it is handed
  go(moon)
  while true do work() end
end
