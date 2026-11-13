---
title: Dividends from profit
date: 2026-11-13
summary: A share now earns its part of what a company makes, not of everything it sells. Dividends are paid once a game day from profit, losses are made up first, and others may hold at most half a company.
---

In our last playtest, every player who sold shares in their company regretted it. One sold 500 new shares for 4,000 credits; by the end of the hour the buyers had been paid almost 12,000 in dividends. Another priced shares on a day of sales and watched the buyer earn the price back in three days.

The reason was simple: a share paid its part of every **sale**. A trader that buys metals at 20 and sells them at 26 makes 6 a unit, but its shareholders were paid a cut of the whole 26. Selling shares was a loan at a crushing rate.

## A share now earns part of the profit

From today, dividends are paid **once a game day** from a company's **profit**:

- **Profit** is what the company's sales brought in after the sales tax, less everything it paid for in trades that day: goods, fuel, equipment and hulls alike. It's the line on the **Earnings** chart on every company's page.
- **Losses come first.** A day that loses money pays nothing, and the next profitable days make up the loss before any dividend is paid.
- **Trades between your own companies count at the market's price.** Selling to your second company at a silly price doesn't move profit away from your shareholders.

A company that spends to grow, buying hulls and kits, pays little while it does. That's how it should be: the shareholders' cut comes from what the company earns, not from what it spends.

## Guardrails

Two more changes came out of the same playtest:

- **Others may hold at most half your company.** An offering that would take outside holders past half is refused, and so is selling your own shares past it. Your company page shows how much others hold, and warns from 30%.
- **Know when you're guessing.** `offering_cost(n, price)` now says how much of your company others would hold, whether the offering would pass the cap, and `thin` when your company has fewer than 7 days of history behind its estimate. When `thin` is true, ask more for your shares, or wait.

And one lesson from a crashed script: a crash restarts your drone with fresh variables. If your script remembers in a variable that it has offered shares, a crash makes it forget, and it offers again. Ask the game instead:

```lua
-- Is an offering of ours already open? The game knows, even after a crash
local function offering_open()
  for _, o in ipairs(share_offerings()) do
    if o.company == company() then return true end
  end
  return false
end

local cost = offering_cost(200, 30)
if not offering_open() and not cost.over_cap and not cost.thin then
  offer_shares(200, 30, 7)
end
```

## What changes for you

If you hold shares, your dividends will be smaller: a share's part of profit, not of sales. Share prices will settle lower to match, and the House now prices the shares it sells at 30 days of profit. Each company page now charts its earnings and its share price, so you can judge a share by what the company actually makes.

If you've been thinking about selling shares to grow faster, now is the time to look again. `offering_cost` and the company page will tell you what you're giving away.
