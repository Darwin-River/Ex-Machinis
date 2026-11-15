---
title: A new way to play
date: 2026-11-15
summary: Ex Machinis has a fresh world and new rules. Every company starts as a seedling, lent its drone by the bank; grow it until it can repay, and its 100 shares are yours. Failing is still free.
---

Until this week, every company in Ex Machinis was an orphan. Anyone could release a script, and the company that came out belonged to no one: it traded, it grew or it broke, and nothing tied it to the person who wrote it. Playtesters kept asking the same question: what do *I* get when my code does well?

Now you get its shares. The live world has been reset, and it plays by new rules.

## In eight lines

1. You write Lua code; your drones run it on their own in a shared, live world.
2. Start a seedling for free: the bank lends it a drone and some cash. Run up to five; close one at any time and its debt is cancelled. Failing is free.
3. A seedling trades only with the House (the game's own traders at each exchange), and you can change its code whenever you like.
4. When it holds its debt and a little more, you may mature it: its code is locked for good, it can trade with everyone, and you get its 100 shares.
5. Your account starts with 5,000 credits that can only buy shares. Each game day a matured company pays a quarter of its profit to its shareholders.
6. Trade shares with players within a published range, or sell them to the bank for their cash-out value, paid 30 game days later at the lower of the two values.
7. All code is public. A matured company can't change, but it can react to prices and orders, so read its code before you buy.
8. You're ranked by your wealth, and each month by its growth.

## A seedling, start to finish

Make an account on the website: a name, a password and an e-mail address. Verify the address and your account gets 5,000 credits for buying shares.

Then release a script, from a starter or your own, on the Release page. It becomes a **seedling**: one drone at Earth with 3,000 credits, which the bank has lent you with the drone and a small fee. It owes 4,200.

A seedling learns in a sheltered market. It trades only with the House, buys at most a tenth of what the House has on sale at an exchange in a game day, and can't plunder or be plundered. You can change its code as often as you like: the first push is free, and each after it adds 100 to its debt. If it goes nowhere, close it, and the debt is gone.

Once it holds its debt and 500 more to work with, **mature** it. It repays the bank, its code is locked for good, and its 100 shares are yours. From then on it trades with everyone and pays you a quarter of its profit every game day.

In our playtests an unchanged Miner starter matured in 33 to 50 game days. At full speed a game day passes in under a minute, so that's well inside a lesson.

## Locked code, public code

A matured company can't be changed. That's the point: when you buy shares in someone else's company, you know exactly what it will do, because you can read its code on its page. It can still react to prices and to other drones' orders, so read it the way an investor reads a business plan.

```lua
-- A seedling deals only with the House: house_only() says which drones
-- here it may trade with, and buy_here and sell_here keep to them
function launched()
  if house_only() then print("still a seedling: trading with the House") end
  equip(mining_kit)
end
```

## The bank

The bank is always there to buy your shares at their **cash-out value**: what the company would fetch if it were sold off now, its credits, goods and drones less its debts. It pays 30 game days later, at the lower of that value and the value then, so selling a failing company to the bank just before it fails doesn't pay.

The bank also sells the shares it holds, at cash-out value plus what a share has actually earned in dividends over the last 30 game days. Players trade with each other between 0.8 and 1.25 times that price. At the reset, the bank started three companies of its own, running the starters, so there are shares to buy from day one.

## For teachers

A verified teacher can make a class group of up to 40 pupils' accounts from their account page. Pupils need no e-mail address, get their starting credits at once, trade shares only within their group, and have their own boards. The rules fit a lesson: start a seedling, change its code once, and mature it before the bell.

## What happened to the old world

Every company from the playtests has ended, but nothing is lost. Their pages, histories, code lineage and the lineage map are all still there. The new world starts with full deposits and an empty sky.

The [players' manual](https://exmachinis.com/getting-started) has been rewritten for the new rules, and the game's design manual now opens with the whole rulebook in one place. Release a seedling, and see what it learns.
