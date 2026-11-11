---
title: Drones that change their minds
date: 2026-10-29
summary: A released drone can't be changed, but the market changes every day. A state machine lets a drone decide what to do next from what it sees.
---

You can't change a drone after you release it. The market changes all the time anyway: deposits run dry, rivals undercut you, and buyers fill up and stop bidding. A drone that follows one fixed plan (mine, sell, repeat) will sooner or later work hard for nothing.

The companies that did best in our playtests read the market every round and changed what they did. The simplest way to write that is a **state machine**.

## The idea

The drone is always in one **mode**, such as shopping, making, hauling or resting. Each time round its loop, it does one step of that mode's work. Then it either stays in the mode or switches to another, depending on what it saw. Every drone knows four functions for this:

| Function | What it does |
|---|---|
| `become(mode)` | Switch to a mode, and start counting rounds in it again |
| `in_mode(mode)` | True if the drone is in that mode |
| `tick()` | Count a round: call it once each time round your loop |
| `ticks()` | Rounds since the last `become`: give up on a mode that takes too long |

## Who did it well

- **Uma** (round 26) ran a factory at Earth with five modes: *deciding*, *building*, *selling*, *resting* and *growing*. Each time it was deciding, it priced rods, pumps and gear boxes from the live market and built whichever paid best. When nothing paid, it rested. When it was rich, it grew: it bought a hull and launched a copy of itself. It averaged **+11,163 credits** per company, the best of its round, and testers rated its feedback 8 out of 10.
- **Ines** (round 29) ran a Moon factory that, every round, scored twenty recipes by profit per hour from live prices. It switched from Alloys (A3) to Alloys (A2) on its own when prices moved, and averaged **+10,370**.
- The **Adaptive** design that comes with the game is the same pattern at its simplest. Read it in `examples/companies/adaptive.lua`.

## The code

Here is a complete rod maker, cut down from the Adaptive design. It buys Metals (A1) at the Moon only while two of them (a rod's worth) cost well under what a rod fetches at Earth. It makes rods while it has metals, hauls a load to Earth, and rests when nothing pays.

```lua
-- The modes
shopping, making, hauling, resting = 0, 1, 2, 3

-- Worth buying metals? Two make a rod, which fetches about 225 at Earth;
-- allow 40 for fuel and the trip
function worth_buying()
  local price = buy_price(metals_a1)   -- 0: nobody sells it here
  return price > 0 and price * 2 + 40 < 225
end

-- Each mode's step does a little work, then picks the next mode
function shop()
  if not worth_buying() then become(resting); return end
  buy_here(10, metals_a1)
  if units(metals_a1) > 1 then become(making) else become(resting) end
end
function make_one()
  if units(metals_a1) > 1 then make(alloys_a1); make(rods); return end
  if units(rods) > 11 then become(hauling); return end
  become(shopping)
end
function haul()
  go(earth)
  sell_here(units(rods), rods)
  go(moon)
  become(shopping)
end
-- Nothing pays: wait two game hours and look again. After six rests,
-- sell whatever is aboard rather than wait for ever
function rest()
  wait(120)
  if units(rods) > 0 and ticks() > 5 then become(hauling); return end
  if worth_buying() then become(shopping) end
end

-- The machine: run the step for the mode it is in, count the round
function step()
  if in_mode(shopping) then shop()
  elseif in_mode(making) then make_one()
  elseif in_mode(hauling) then haul()
  else rest() end
end
function launched()
  equip(factory_kit)   -- since #506 a drone buys its kit at Earth
  go(moon)
  become(shopping)
  while true do step(); tick() end
end
```

## Tips

- **One small step per round.** Make one rod, not twelve, and then look at the market again. A drone that commits to a long job can't react until the job is done.
- **Always have a way out.** `ticks()` counts rounds in the current mode. A mode that has gone on too long, such as six rests in a row or sixty rounds of building, should hand over to another one.
- **Add a mode, not an `if`.** When you think of something new for the drone to do, like growing, refuelling or moving, give it its own mode and step. The `step` function stays a simple list.
- **Release it and watch.** Releases are free: release a drone that prints what `worth_buying()` says (or shows it with `set_status`), and its page shows what it decides against the real market. Then release a better version.
