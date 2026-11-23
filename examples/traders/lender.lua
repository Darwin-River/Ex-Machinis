-- Lender: a trading script that backs loan requests (#932). Every few real
-- minutes the game calls trade(market) with the market and your account.
-- Public companies ask to borrow (their code's request_loan), and players
-- with at least market.lend_min_credits credits may back them. This script
-- backs a request when:
--   * its rate, counted a game year, is at least min_per_year (a request's
--     own rate is for the whole loan: 3% over 30 game days is about 36% a
--     game year, per_year);
--   * the company is healthy: not stalled, at risk, failing, dissolving or
--     soon wound up, and it has paid dividends (its record: a company that
--     pays its shareholders has profits to repay you from);
--   * it hasn't defaulted on one of your loans before.
-- It lends at most a fifth of your spare credits to any one company (what it
-- still owes you counts), and never goes below keep_credits. It logs what
-- it did and each default it sees, once.
-- It can't read a company's code for you: look at the company's page before
-- you trust it with much (does its code borrow only what it can repay?).

-- Settings: change these to make it your own
min_per_year = 20    -- least interest a game year it lends at (percent)
keep_credits = 2000  -- credits it never lends
share_per_company = 5   -- lends at most 1/this of spare credits to one company
min_backing = 100    -- smallest amount worth backing
min_dividends = 1    -- least a share must have paid over 30 game days

-- A table of the companies by id, to look each request's company up
function companies_by_id(market)
  local by_id = {}
  for _, c in ipairs(market.companies or {}) do by_id[c.company] = c end
  return by_id
end

-- Healthy, with a record of paying its shareholders
function trustworthy(c)
  if not c then return false end   -- not on the market: not matured, or gone
  if c.stalled or c.at_risk or c.failing or c.dissolves_in or c.winds_up_in then return false end
  return (c.dividends30 or 0) >= min_dividends
end

-- What each company still owes you, and the companies that ever defaulted
function my_exposure()
  local owed, defaulted = {}, {}
  for _, loan in ipairs(my_loans()) do
    if not loan.closed then owed[loan.company] = (owed[loan.company] or 0) + loan.owed end
    if loan.defaulted then defaulted[loan.company] = true end
  end
  return owed, defaulted
end

-- Logs each default the first time it sees it (memory is kept between runs)
function log_new_defaults()
  memory.defaults_seen = memory.defaults_seen or {}
  for _, loan in ipairs(my_loans()) do
    if loan.defaulted and not memory.defaults_seen[loan.id] then
      memory.defaults_seen[loan.id] = true
      log("default:", loan.name, "owes", loan.owed, "of loan", loan.id, "- it won't get another")
    end
  end
end

-- How much to put into one request: what it still needs, what is left of
-- this company's fifth, and what we can spare. 0: skip it
function backing_for(request, owed_by_it, cap, spare)
  local room = cap - (owed_by_it or 0)
  local n = math.min(request.left, room, spare)
  if n < min_backing then return 0 end
  return math.floor(n)
end

function trade(market)
  local me = market.me
  log_new_defaults()
  if me.credits < (market.lend_min_credits or 5000) then
    log("lending needs", market.lend_min_credits, "credits; you have", me.credits)
    return
  end

  local spare = me.credits - keep_credits
  local cap = spare // share_per_company
  local by_id = companies_by_id(market)
  local owed, defaulted = my_exposure()

  -- The best paying requests first
  local requests = loan_requests()
  table.sort(requests, function(a, b) return a.per_year > b.per_year end)

  for _, r in ipairs(requests) do
    local c = by_id[r.company]
    if r.per_year >= min_per_year and r.due_in >= 1 and trustworthy(c) and not defaulted[r.company] then
      local n = backing_for(r, owed[r.company], cap, spare)
      if n > 0 and back_loan(r.id, n) then
        spare = spare - n
        owed[r.company] = (owed[r.company] or 0) + n
        log("backed", r.name, "with", n, "at", r.rate .. "% for", r.term_days, "game days (" .. r.per_year .. "% a year)")
      end
    end
  end
end
