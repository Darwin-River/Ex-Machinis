-- Value trader: a starter trading script for Ex Machinis (#768). It runs on
-- your account, not on a company: every few real minutes the game calls
-- trade(market) with the share market and your account, and it trades your
-- shares under the same rules as you.
--
-- Its plan: keep a pegged bid on the healthy companies whose shares returned
-- the most over 30 game days (their cash-out growth plus dividends), a little
-- under what the bank buys at, so other players' sells fill it; and sell what
-- you hold of a company that is dissolving before it ends.

-- Settings: change these to make it your own
min_return = 5      -- percent over 30 game days a company must have returned
bid_under = 2       -- how far under the bank's buying price to bid, in percent
lot = 5             -- shares in each bid
max_bids = 3        -- companies it bids for at once
keep_credits = 1000 -- credits it never commits

-- Is this company worth holding?
function healthy(c)
  return not c.dissolves_in and not c.failing and not c.at_risk
end

-- My open orders, by company and side
function my_orders(me)
  local mine = {}
  for _, o in ipairs(me.orders) do
    mine[o.company] = mine[o.company] or {}
    mine[o.company][o.side] = o
  end
  return mine
end

function trade(market)
  local me = market.me
  local mine = my_orders(me)
  memory.runs = (memory.runs or 0) + 1

  -- 1. Get out of a company that is dissolving: sell to the best bid, or to the bank
  for _, c in ipairs(market.companies) do
    local held = me.holdings[c.company] or 0
    if held > 0 and c.dissolves_in then
      if c.bids[1] then
        sell(c.company, math.min(held, c.bids[1].units), c.bids[1].price)
      else
        bank_sell(c.company, held)
      end
      log("leaving", c.name, "(dissolves in", c.dissolves_in, "game days)")
    end
  end

  -- 2. Bid for the best returners, a little under the bank's buying price
  local picks = {}
  for _, c in ipairs(market.companies) do
    if healthy(c) and (c.return_percent or 0) >= min_return then picks[#picks + 1] = c end
  end
  table.sort(picks, function(a, b) return a.return_percent > b.return_percent end)
  local spare = me.credits - keep_credits
  for i = 1, math.min(max_bids, #picks) do
    local c = picks[i]
    local o = mine[c.company]
    if not (o and o.buy) and spare >= lot * c.buys_at then
      buy_pegged(c.company, lot, "buys_at", -bid_under)
      spare = spare - lot * c.buys_at
      log("bidding for", lot, "of", c.name, "(returned", c.return_percent .. "%)")
    end
  end

  -- 3. Drop bids for companies that are no longer worth it
  for company, o in pairs(mine) do
    if o.buy then
      local still = false
      for i = 1, math.min(max_bids, #picks) do if picks[i].company == company then still = true end end
      if not still then cancel(o.buy.id) end
    end
  end
end
