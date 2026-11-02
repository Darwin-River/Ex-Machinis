-- The standard library of a Lua drone (#380): the game words of the FORTH
-- library (vm_library.c) with the same meanings, names with _ for -, on top
-- of the engine's perform(protocol, ...) and query(id, ...).
-- Action words return perform's result: 0 when the action worked, else a
-- negative code. Money and big numbers are plain numbers (no doubles).

-- Places
earth, moon, mars, phobos, deimos = 3, 10, 4, 11, 12

-- Resources
fuel, magmite, kunspar, yalspar, vladnium, wolfite = 2061, 1000, 1100, 1110, 1120, 1130
mining_kit, factory_kit, cruiser_kit = 5901, 5902, 6001
metals_a1, metals_a2, metals_a3 = 2011, 2012, 2013
inorganics_a1, organics_a1 = 2031, 2041
inorganics_a2, organics_a2 = 2032, 2042
alloys_a1, alloys_a2, alloys_a3 = 2021, 2022, 2023
rods = 3011

-- Actions
function travel(place) return perform(5001, place) end
function mine(mineral) return perform(mineral + 10000) end
function refine(mineral) return perform(mineral + 20000) end
function make(resource) return perform(resource + 10000) end
function wait(minutes) return perform(3, minutes) end
function push(n, resource, drone) return perform(10, n, resource, drone) end
function pull(n, resource, drone) return perform(11, n, resource, drone) end
function launch(drone, kit) return perform(12, drone, kit) end
function plunder(wreck) return perform(34, wreck) end
function jettison(n, resource) return perform(1, n, resource) end
function broadcast(text) return perform(40, text) end

-- Queries: ask returns the rows (a table of rows, each a table of columns)
function ask(id, ...) return query(id, ...) or {} end
local function first(id, ...)
  local rows = query(id, ...)
  return rows and rows[1] and rows[1][1] or 0
end
function me() return first(205) end
function location() return first(202, me()) end
function units(resource) return first(212, resource) end
function company() return first(201, me()) end
function credits() return first(101, company()) end
function today() return first(207) end
function crowd(place) return first(721, place) end
-- Unpacks the kit aboard; 0 when there is none (already unpacked: a drone
-- whose engine restarted runs launched() again, so this must be harmless)
engine_module = 5001
function unpack_kit()
  if units(factory_kit) > 0 then return perform(25902) end
  if units(mining_kit) > 0 then return perform(25901) end
  return 0
end
unpack = unpack_kit

-- Waits, an hour at a time, until the drone has a kit or its unpacked
-- tools (a clone starts with neither, until its parent hands it a kit)
function await_kit()
  while units(factory_kit) == 0 and units(mining_kit) == 0 and units(engine_module) == 0 do
    wait(60)
  end
end

-- Units left in a deposit here (0 if none)
function deposit(mineral)
  for _, row in ipairs(ask(640)) do
    if row[1] == mineral then return row[2] end
  end
  return 0
end

-- Travel unless already there
function go(place)
  if place == location() then return 0 end
  return travel(place)
end

-- Game hours to fly to a place (-1 if unknown)
function trip(place)
  local rows = ask(704, place)
  if rows[1] then return rows[1][3] end
  return -1
end

-- The cheapest other drone's sell order here: price, drone, units it will sell
function best_offer(resource)
  local mine = me()
  for _, o in ipairs(ask(610, resource)) do
    if o[4] ~= mine then return o[1], o[4], math.max(o[3] - o[2], 0) end
  end
  return 0, 0, 0
end

-- The best other drone's buy order here: price, drone, units it will buy
function best_bid(resource)
  local mine = me()
  for _, o in ipairs(ask(600, resource)) do
    if o[4] ~= mine then return o[1], o[4], math.max(o[2] - o[3], 0) end
  end
  return 0, 0, 0
end

function sell_price(resource)
  local price, _, n = best_bid(resource)
  if n == 0 then return 0 end
  return price
end

function buy_price(resource)
  local price, _, n = best_offer(resource)
  if n == 0 then return 0 end
  return price
end

-- Units of a resource sold at a place on the game day days_ago days ago
function sold(resource, days_ago, place)
  for _, row in ipairs(ask(682, resource, days_ago)) do
    if row[1] == place then return row[2] end
  end
  return 0
end

-- What a good traded for, a unit, at a place over the last game day (the
-- price board, query 680); 0 if none traded there
function board_price(resource, place)
  for _, row in ipairs(ask(680, resource)) do
    if row[1] == place and row[2] > 0 then return row[3] // row[2] end
  end
  return 0
end

-- Buy up to n here, cheapest first, from whoever sells; 0 when it bought any
function buy_here(n, resource)
  local result = -12
  for _ = 1, 6 do
    if n <= 0 then break end
    local price, drone, avail = best_offer(resource)
    if avail == 0 then break end
    local take = math.min(n, avail)
    if pull(take, resource, drone) == 0 then n = n - take; result = 0
    else n = n // 2; if n == 0 then break end end
  end
  return result
end

-- Sell up to n here, best price first, to whoever buys; 0 when it sold any
function sell_here(n, resource)
  local result = -12
  for _ = 1, 6 do
    if n <= 0 then break end
    local price, drone, want = best_bid(resource)
    local take = math.min(n, want, units(resource))
    if take == 0 then break end
    if push(take, resource, drone) == 0 then n = n - take; result = 0
    else n = n // 2; if n == 0 then break end end
  end
  return result
end

buy_from_station, sell_to_station = buy_here, sell_here

function sell_all(resource)
  local n = units(resource)
  if n > 0 then sell_here(n, resource) end
end

-- Orders stay up until the drone posts another for the same resource and
-- side; each post costs a fuel. The _if_changed forms skip a post that
-- would change nothing. A clone starts with no orders of its own.
my_orders, my_orders_of = {}, 0
local function orders_mine()
  local id = me()
  if my_orders_of ~= id then my_orders, my_orders_of = {}, id end
end
function sell_order(price, keep, resource)
  local r = perform(7, price, keep, resource)
  if r == 0 then orders_mine(); my_orders["s" .. resource] = {price, keep} end
  return r
end
function buy_order(price, want, resource)
  local r = perform(8, price, want, resource)
  if r == 0 then orders_mine(); my_orders["b" .. resource] = {price, want} end
  return r
end
function sell_order_if_changed(price, keep, resource)
  orders_mine()
  local o = my_orders["s" .. resource]
  if o and o[1] == price and o[2] == keep then return 0 end
  return sell_order(price, keep, resource)
end
function buy_order_if_changed(price, want, resource)
  orders_mine()
  local o = my_orders["b" .. resource]
  if o and o[1] == price and o[2] == want then return 0 end
  return buy_order(price, want, resource)
end

-- A state machine: mode, and how many rounds in it
mode, ticks_in_mode = 0, 0
function become(m) mode, ticks_in_mode = m, 0 end
function in_mode(m) return mode == m end
function tick() ticks_in_mode = ticks_in_mode + 1 end
function ticks() return ticks_in_mode end

-- Messages: event IDs of broadcasts seen here or known at Earth, newest first
message_event = 13
local function events(id, type)
  local ids = {}
  for i, row in ipairs(ask(id, -1, -1, type, -1, -1, -1, -1, -1, -1)) do ids[i] = row[1] end
  return ids
end
function events_here(type) return events(803, type) end
function events_earth(type) return events(804, type) end
function event_drone(event) return first(820, event) end
function message(event)
  local rows = ask(844, event)
  return rows[1] and rows[1][1] or nil
end

-- The drones this one launched: a list of {drone, wrecked}
function my_clones()
  local list = {}
  for i, row in ipairs(ask(670)) do list[i] = {drone = row[1], wrecked = row[2] == 1} end
  return list
end

function stdlib() return 16 end
