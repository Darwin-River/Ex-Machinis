-- The House station drone (#380): the program the House's trading station
-- at each exchange runs. It trades by the players' own rules (sell and buy
-- orders, pulls and pushes, the sales tax), from a Drop Station hull with
-- two modules no player can get: an Import Station brings goods up from the
-- planet surface (perform(5911, n, resource)) and an Export Station sends
-- them down (perform(5921, n, resource)). What it trades and at what prices
-- comes from the exchange's game data, as it stands today (query 630:
-- house_market).
-- - What the exchange buys: once a game day the stock goes down to the
--   surface (but for what it keeps to sell, when the exchange sells the
--   same good).
-- - What the exchange sells: stock brought up to a quarter of a day's
--   supply.
-- Its buy and sell orders are posted for it by App\Services\HouseMarket
-- (#380 round 36): prices and daily units move over game time, about 180
-- orders an exchange, and posting each change as an action took this
-- program days. This program keeps the stock those orders sell from, ships
-- down what they bought, and keeps its own fuel. It was FORTH until FORTH
-- was retired (#380, after round 38).

reserve = 500     -- fuel it keeps for itself (thinking and shipping)

-- The goods: query 630's rows (resource, side 1 buys / 2 sells, price,
-- daily units, units held), by resource and then side, so a good's sell
-- row follows its buy row
goods = {}
local function load_goods() goods = ask(630) end
local function buys(i) return goods[i][2] == 1 end
local function good(i) return goods[i][1] end
local function daily(i) return math.max(goods[i][4], 1) end

local function import(n, resource) if n > 0 then perform(5911, n, resource) end end
local function export(n, resource) if n > 0 then perform(5921, n, resource) end end

-- Selling: a quarter of a day's supply in stock
local function stock(i) return math.max(daily(i) // 4, 1) end

-- The stock it keeps for selling a resource it also buys (most goods): 0
-- when it doesn't sell it. Only what is above that counts as bought, for
-- shipping down
local function keep(i)
  local nxt = goods[i + 1]
  if nxt and nxt[2] == 2 and nxt[1] == good(i) then return stock(i + 1) end
  return 0
end
local function excess(i) return math.max(units(good(i)) - keep(i), 0) end

local function restock(i) import(stock(i) - units(good(i)), good(i)) end

-- Fuel: its reserve, and a quarter of a day's supply on top for sale.
-- Each import takes a while and a round imports about 90 goods, so a round
-- can last days: it tops its fuel up after each import, not once a round
-- (#380 round 37: the Moon Depot's 6,000 a day came out at about 500
-- every two days, and 50 drones ran dry)
fuel_stock = reserve
local function find_fuel()
  fuel_stock = reserve
  for i = 1, #goods do
    if not buys(i) and good(i) == fuel then fuel_stock = stock(i) end
  end
end
local function fuel_up() import(reserve + fuel_stock - units(fuel), fuel) end

-- Each round restocks what sells, from the lowest resource up: fuel and raw
-- goods, what drones need most, come back first after a sale
local function restock_all()
  for i = 1, #goods do
    if not buys(i) and good(i) ~= fuel then
      restock(i)
      fuel_up()
    end
  end
end

-- Once a game day, what it bought goes down to the surface
shipped = -1
local function ship()
  if today() == shipped then return end
  shipped = today()
  for i = #goods, 1, -1 do
    if buys(i) then
      export(excess(i), good(i))
      fuel_up()
    end
  end
end

local function round()
  load_goods()
  find_fuel()
  fuel_up()
  restock_all()
  ship()
end

function launched()
  while true do
    round()
    wait(60)
  end
end
