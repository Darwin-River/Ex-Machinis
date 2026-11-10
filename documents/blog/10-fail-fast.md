---
title: Break, learn, repeat
date: 2026-11-04
summary: There's no test world any more. Every release is live, free, and fast: a game day passes in under a minute. Release a first draft, watch what goes wrong, and release a better one.
---

Until now, Public Forge had two worlds: the live one, where a drone you released ran for good, and a test world, where you could try a script first. In round 39 all four newcomers said the same thing about the test world: it was too slow to tell them anything, and they released their drones without seeing a full test. Players were spending their time testing instead of playing.

So the test world is gone. **The whole game is the test world now.** Every release is live, and it costs nothing.

## A game day in under a minute

The live game now runs at the test world's old pace: a game day every 48 real seconds. A trip to the Moon takes about 5 seconds, a trip to Mars a minute or two, and a 40-day season about half an hour. You don't wait to find out whether your drone works: you watch it work, or fail, in the time it takes to make a coffee.

## The loop: break, learn, repeat

1. **Release a first draft.** Small and simple is fine: a miner that mines and sells, a factory that makes one thing.
2. **Watch it.** Its page shows where it is, what it carries, its last actions and how each went, its trades and what it says. Make it tell you what it's thinking:

```lua
function launched()
  equip(mining_kit)   -- since #506 a drone buys its kit at Earth
  while true do
    if units(fuel) < 20 then
      set_status("low on fuel: buying")
      buy_here(30, fuel)
    else
      set_status("mining kunspar at " .. place_name())
      go(moon)
      mine(kunspar)
    end
  end
end
```

3. **Release a better version** as a new company. Your first drone keeps running (or dies trying), and the new one learns from it. A released drone can't be changed, so each version is a fresh start.
4. **Repeat.** Ten versions in an evening is a good evening.

## Failing is free

A company that goes broke, a drone that runs out of fuel or crashes five times: none of it costs you anything but the lesson. Every release starts with the same credits and kit, and a company whose drones have all died drops off the leaderboard.

What does cost you is the time a broken drone spends doing nothing while you don't notice. So:

- **Make it say what it's doing.** `set_status(text)` puts a line on its page; `print` and `report()` send longer notes.
- **Read the post-mortem.** A dead drone's page says why it died, with its last actions and the fuel it had left.
- **Change one thing at a time.** If version 4 adds a plan B and a new product, and it does worse, you won't know which to blame.
- **Keep the good versions running.** A company that earns keeps earning while you try the next idea.

The best players in our test rounds weren't the ones with the cleverest first script. They were the ones whose drones told them what was going wrong, early.
