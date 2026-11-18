-- Value trader: a starter trading script. Every few real minutes the game
-- calls trade(market) with the share market and your account. It keeps a
-- pegged bid, a little under what the bank buys at, on the healthy companies
-- whose shares returned most over 30 game days, so other players' sells fill
-- it, and sells what you hold of a company that is dissolving.

-- Settings: change these to make it your own
min_return = 5      -- least a company must have returned over 30 game days (percent)
bid_under = 2       -- how far under the bank's buying price to bid (percent)
lot = 5             -- shares in each bid
max_bids = 3        -- companies it bids for at once
keep_credits = 1000 -- credits it never commits

function healthy(company)
  return not company.dissolves_in and not company.failing and not company.at_risk
end

-- My open orders: orders[company][side], side being "buy" or "sell"
function my_orders_by_company(me)
  local orders = {}
  for _, order in ipairs(me.orders) do
    orders[order.company] = orders[order.company] or {}
    orders[order.company][order.side] = order
  end
  return orders
end

-- Sells into the best bid, or to the bank when nobody bids
function sell_out_of_dissolving_companies(market)
  local me = market.me
  for _, company in ipairs(market.companies) do
    local held = me.holdings[company.company] or 0
    if held > 0 and company.dissolves_in then
      local best_bid = company.bids[1]
      if best_bid then
        sell(company.company, math.min(held, best_bid.units), best_bid.price)
      else
        bank_sell(company.company, held)
      end
      log("leaving", company.name, "(dissolves in", company.dissolves_in, "game days)")
    end
  end
end

-- Healthy companies that returned at least min_return, best first
function best_returners(market)
  local picks = {}
  for _, company in ipairs(market.companies) do
    if healthy(company) and (company.return_percent or 0) >= min_return then picks[#picks + 1] = company end
  end
  table.sort(picks, function(a, b) return a.return_percent > b.return_percent end)
  return picks
end

function number_to_bid_for(picks)
  return math.min(max_bids, #picks)
end

function bid_for_best_returners(picks, my_orders, me)
  local spare = me.credits - keep_credits
  for i = 1, number_to_bid_for(picks) do
    local company = picks[i]
    local orders = my_orders[company.company]
    local already_bidding = orders and orders.buy
    if not already_bidding and spare >= lot * company.buys_at then
      buy_pegged(company.company, lot, "buys_at", -bid_under)
      spare = spare - lot * company.buys_at
      log("bidding for", lot, "of", company.name, "(returned", company.return_percent .. "%)")
    end
  end
end

function is_picked(company_id, picks)
  for i = 1, number_to_bid_for(picks) do
    if picks[i].company == company_id then return true end
  end
  return false
end

function cancel_bids_no_longer_worth_it(picks, my_orders)
  for company_id, orders in pairs(my_orders) do
    if orders.buy and not is_picked(company_id, picks) then cancel(orders.buy.id) end
  end
end

function trade(market)
  local my_orders = my_orders_by_company(market.me)
  memory.runs = (memory.runs or 0) + 1   -- kept between runs: how many times it has run

  sell_out_of_dissolving_companies(market)
  local picks = best_returners(market)
  bid_for_best_returners(picks, my_orders, market.me)
  cancel_bids_no_longer_worth_it(picks, my_orders)
end
