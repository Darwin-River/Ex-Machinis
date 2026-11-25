---
title: Watch first, then fly a drone in a few lines
date: 2026-11-25
summary: The home page now shows the game live, and you can fly a first drone in your browser before you make an account. It's a shuttle in a few lines of Lua, and its route is three settings you can change.
---

Until this week, the first thing you saw on exmachinis.com was a script: about 400 lines of Lua, waiting to be edited. Most people who arrived from a post never got past it. They came to see a game, and they met homework.

Now the home page shows the game as it is right now: a live map of the solar system, with every player's drone at work around its moon or planet and the flights under way, plus how many players and drones there are and a chart of what was traded each day. Every one of those drones runs a few lines of code that a player wrote. Press **Join the Economy**, and you can fly one of your own in your browser. No account needed.

## What, and where

Your first drone is the **Shuttle**. It digs ore on the Moon, refines it into metals, flies to Earth, sells them and buys fuel. Then it does it all again.

Look at the top of its code and you'll see the idea this post is about: **settings**. A setting is a variable at the top of a program that says *where* or *how much*, kept apart from the code that says *what to do*. The loop below never names a place. It reads the settings instead. So to send the drone somewhere else, you change one line at the top, and the rest of the program stays exactly as it was.

Programmers do this everywhere. A game keeps its volume and controls in a settings file, and a website keeps its address apart from its code. When the "where" lives in one place, you can change your mind without rewriting anything.

## The Shuttle in Lua

```lua
ore = wolfite        -- what it digs
mine_at = moon       -- where it digs
sell_at = earth      -- where it sells, and buys fuel
digs = 4             -- hours of digging a trip
goods = {metals_a1, metals_a2, inorganics_a2}

function launched()
  equip(mining_kit)              -- buys a Mining Kit at Earth
  while true do
    go(mine_at)
    for dig = 1, digs do
      mine(ore)                  -- an hour's digging
    end
    refine(ore)                  -- the ore into goods
    go(sell_at)
    for _, good in ipairs(goods) do
      sell_here(units(good), good)
    end
    buy_here(20, fuel)           -- fuel for the next trip
  end
end
```

`equip` buys a Mining Kit and unpacks it into an Engine, a Worm (for digging) and a Refinery. The `while true do` loop runs forever:

1. `go(mine_at)` flies to the Moon.
2. The `for` loop runs `mine(ore)` four times, an hour each.
3. `refine` turns 18 Wolfite into metals.
4. `go(sell_at)` flies to Earth, and the second `for` loop sells each good in `goods`.
5. `buy_here(20, fuel)` fills the tank for the next trip.

Press **Test drive** and you'll see what it would do, step by step, against the live world, changing nothing in it. When we ran it, it bought its kit, flew to the Moon in 2.5 game hours, dug and refined, and made its first sale at Earth about 12 game hours in. At the normal pace that's under half a real minute. The House's prices move every day, so your numbers will differ a little from ours.

Like it? Press **Keep my drone**. A free account saves it, and the next page starts it for real as your startup.

## Try it

1. Under the code, the **Choose a route** table lists the best places to dig and to sell for each ore your kit can dig, with the round trip and the profit to expect from today's prices. Press **Use this route** on one, or just change `sell_at = earth` to `sell_at = moon`. Then press **Test drive** again.
2. Watch the trip get shorter. Selling at the Moon skips both flights: in our test drive it sold at 9:28 game hours instead of 11:56. Is the Moon paying more or less than Earth for your metals today?
3. If your change breaks the drone (a place it can't dig, or a good the House won't buy there), the test drive shows the line that failed. Change it back, or try another route. A test drive costs nothing, and neither does a startup that crashes. Break, learn, repeat.

When your startup holds enough to repay the bank, press **Claim my 100 shares**: the company is yours, and buying shares in other players' companies opens up. Along the way you'll earn badges, from Test Pilot for your first test drive to Climber for the whole path. The [Badges page](https://exmachinis.com/badges) lists them all.
