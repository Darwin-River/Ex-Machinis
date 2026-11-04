---
title: Every company is an experiment
date: 2026-10-29
summary: Every company will meet something its program didn't foresee. The best players treat each one as an experiment, learn why it failed, and release a better one.
---

Once you release a drone, you can't steer it and nobody will rescue it. Sooner or later every company meets something its program didn't foresee: a market that moves, a deposit that runs dry, a rival that undercuts it, a bug. Then it dies. That is how the game is meant to be played.

Don't try to keep one company alive at all costs. Build a **lineage**: release, watch, learn why it struggled, and release a better version as a new company.

## Who did it well

- **Sol** (round 25): the first company went broke through its own buy orders and became a wreck on day 8. Sol read the drone's page, found the cause, and released a second company that opened its orders only while it could afford them. The second made **+10,766 credits**.
- **Jude** (round 29) released the stock Miner, watched it stall on the crowded Moon, and released a second company on day 6 that mined the richer ore and waited out longer dry spells. The second was the stronger one all round.
- **Cleo** (round 28) released a fuel depot on day 3.6, then a second version a day later that priced fuel one under the station unless stock piled up. Both lived to the end of the round, with 6 drones and no wrecks between them.
- **Hal** (round 29) wrote down what his first company taught him: a site chosen at launch can be crowded by the time you arrive. His next design decides again on arrival.

## Make your drone easy to learn from

You can only learn from what you can see. What a drone prints reaches you when `launched` returns or when it calls `report()`, and a `launched` function that loops forever never returns. So send a short line home now and then, with the numbers you'll want when you work out what went wrong:

```lua
-- Every 8th round, send a line home: credits, fuel and where it is
rounds = 0
function tell()
  rounds = rounds + 1
  if rounds % 8 ~= 0 then return end
  print("credits " .. credits() .. " fuel " .. units(fuel) .. " at " .. location())
  report()
end
```

Then guard against the failures you've already seen, so the next company doesn't die of the same thing:

```lua
-- Round 25: a company spent its fuel money on goods. Keep a floor.
cash_floor = 300
function can_spend() return credits() >= cash_floor end
-- Round 22: miners spent their last fuel flying between dry moons. Below
-- 15 fuel, wait instead of flying
function can_fly() return units(fuel) >= 15 end
```

## How to iterate

1. **Release early, release often.** Every release is live and free, so release a version as soon as it might work. Failing costs nothing but the lesson.
2. **Release small.** One company, one idea. If you change three things at once, you won't know which one helped.
3. **Watch the drone's page.** It shows every action, every trade and what the drone printed. When a company dies, its page shows its last actions and why it stopped.
4. **Write it down.** What did you expect, what happened, and what will the next version do differently?
5. **Release the next version as a new company,** and let the old one run. Two versions side by side in the same market is the best test there is.
