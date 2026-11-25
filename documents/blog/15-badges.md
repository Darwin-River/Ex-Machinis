---
title: Badges, and how the game notices them
date: 2026-11-25
summary: Drones, companies and players now earn badges, 49 of them, from a first refining to five companies in one line of code. They give no advantage. Here's how the game spots them without rereading its history, and how your drone can use the same trick.
---

Some moments in Ex Machinis deserve a mark: a drone's first sale, a company that repays the bank, a player who grows five companies, each from the code of the one before. Until now they passed without a word. Now they earn **badges**.

There are 49 of them, for drones, companies and players. The **[Badges page](https://exmachinis.com/badges)** describes every one: what earns it, its tiers (bronze, silver and gold for the ones that count something) and how many have earned it. Your badges show on your account page and your public player page, a company's on its page and a drone's on its page. Every badge links back to its description.

## What they are, and what they aren't

Badges are recognition and nothing else. No rule in the game reads them: no credits, no discounts, no head start. A badge is kept for good, even if what earned it is gone: a drone that crashed after its first sale keeps **First Sale**.

Everyone earns them by the same rules. Buying your own company's shares or lending to it earns nothing, and the trading badges count only trades between different players. In a class, a pupil's badges show to the pupil and their teacher, and nowhere public.

Some favourites:

- **First Crash** (you): a drone's first crash. Break, learn, repeat.
- **Self-Starter** (drone): it crashed, restarted, and was still working a game day later.
- **Smelter** (drone): its first refining.
- **Far Traveller** (drone): it arrived, mined or traded at 5 different places, then 10.
- **Never Ran Dry** (drone): 30 game days of work without being found with an empty tank.
- **Deep Hauler** (drone): it mined a far mineral and sold Metals (A3).
- **Top Ten** (company): in the leaderboard's top 10 by net worth.
- **Season Winner** (you): you grew the most, in percent, in a day on the Players board.
- **Lineage** (you): five of your companies in one line of code descent, each grown from the one before.

## How the game notices: a watermark

The game keeps a record of everything that happens: every trade, every refining, every flight. On a busy day that's millions of rows. The badges are checked every 15 real minutes, and checking means asking questions like "has this drone ever sold anything?" If the game read the whole history every 15 minutes to answer, it would spend its time rereading the past instead of running the present.

So it doesn't. It keeps a **watermark**: the ID of the last record it looked at. Records get IDs in order, so everything after the watermark is new. Each pass reads only the new records, awards what they earn, and moves the watermark up to the last one it read. The next pass starts from there. The history is read once, a slice at a time, however long the game runs.

What the watermark can't answer alone, like "how many different places has this drone been?", goes in a small table of its own: one row for each drone and place, added as the passes see them. Counting a drone's rows answers the question without rereading anything.

The same idea is everywhere in programming. Your e-mail app fetches only the messages after the last one it has. A video game saves your progress at a checkpoint, not by replaying the whole level.

## The same trick in Lua

Your drone can use it too. `events_affecting()` lists the events that affected your drone, newest first, and `event(id)` tells you when each happened (`day` and `seconds` into it). Keep the time of the newest event you've handled in a global, and each time look only at the ones after it:

```lua
seen_t, seen_id = -1, 0   -- the watermark: the newest event this drone has handled
places = {}               -- the places it has been, place = true
visited = 0

function catch_up()
  local newest_t, newest_id
  for _, id in ipairs(events_affecting()) do        -- newest first
    local e = event(id)
    if e then
      local t = e.day * 86400 + e.seconds
      if t < seen_t or (t == seen_t and id <= seen_id) then break end   -- old from here on
      if not newest_t then newest_t, newest_id = t, id end
      if e.type == 3 and e.location and not places[e.location] then     -- 3: moved
        places[e.location] = true
        visited = visited + 1
        print("new place: " .. place_name(e.location) .. " (" .. visited .. " so far)")
      end
    end
  end
  if newest_t then seen_t, seen_id = newest_t, newest_id end           -- move the watermark up
end
```

Call `catch_up()` once a trip. `seen_t`, `seen_id`, `places` and `visited` are plain-data globals, so your drone keeps them through an engine restart (see [What your drone remembers](https://exmachinis.com/manual#what-your-drone-remembers)). Without the watermark, the loop would print every place again on every trip, and spend more of its turn on the same old events each time.

The `break` is what makes it a watermark. The list is newest first, so the first old event means everything after it is old too. Stopping there means the work each time depends on what's new, not on how long the drone has lived.

## Try it

1. Press **Join the Economy** on the home page and **Test drive** the Shuttle. Signed in, that's **Test Pilot**. Without an account, you get it when you sign up.
2. Keep it and found your startup. On its first trip the Shuttle earns **Lift-off** (its first trip), **First Strike** (its first dig), **Smelter** (its first refining) and **First Sale**, and you earn **Founder**. Watch them appear on its page.
3. Go for **Far Traveller**: change `mine_at` and `sell_at` (the route table under the code suggests good pairs), or add the `catch_up()` above and watch your drone count its own places.
4. If it crashes, there's a badge for that too. Fix it, push the fix (**Fixer**), and keep going.

The [Badges page](https://exmachinis.com/badges) has the rest.
