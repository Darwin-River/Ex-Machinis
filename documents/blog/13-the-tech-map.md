---
title: From ore to ship: the tech map
date: 2026-11-17
summary: A new page maps every recipe in the game, from ore to ship, with pictures, ratios, tools and Lua calls. Then teach a drone to walk the same tree with recursion.
---

In our playtests, three of four newcomers built their own recipe tables by reading protocol descriptions one at a time. To find out what a Pump takes, you opened its protocol, then the protocol for each of its parts, then the protocols for those parts' parts, and kept notes. That was more than a hundred recipes, one page each.

Now they're all on one page: the **[tech map](https://exmachinis.com/technology)**.

## One picture of the whole economy

Every resource is a card with its picture. Ores are on the left, where drones dig them. Each column to the right is one more step of making: refined goods, materials, parts, components, modules and, at step 6, ships.

Pick a card and the map draws its whole chain:

- **orange lines** run into it from everything it's made from, all the way back to the ores;
- **blue lines** run out of it to everything that's made from it;
- **dashed gold lines** show the tools: modules a drone must carry, which it keeps rather than uses up.

The panel beside the map shows each recipe as a formula, with pictures: `1 × Rods (A1) + 1 × Hoses (A1) → 1 × Pumps (A1)`. Under the formula are the tool (a Spider), the Lua call that runs it (`make(4311)`, linked to its protocol page), how long it takes and the fuel it burns. Below that is what one unit costs, all the way down. Every name links to its resource page, and every resource page now links back to its place on the map. The map is built from the game's own protocols, so a new recipe appears on it as soon as it's in the game.

## A chain is a tree

Look at the orange lines into a Pump and you'll see a **tree**. The Pump is the root, its parts are branches, and each part has branches of its own, down to the ores at the tips. Many things in programming have this shape: folders inside folders, a family tree, the moves in a game of chess.

The neat way to walk a tree is **recursion**: a function that answers a question by asking itself the same question about something smaller. "What does a Pump take?" becomes "what does each of its parts take?", and that becomes "what does each of *their* parts take?", until you reach something nothing makes.

## The tree in Lua

A drone can read a recipe itself: `recipe(r)` returns the first recipe that makes resource `r`, with `makes` (how many one run makes) and `uses` (a table of input → units). Here's a drone that explains a Pump:

```lua
-- Prints what it takes to make some units of a resource, all the way down
-- to the ores: each line is one step further from the finished thing
local function explain(resource, units, indent)
  print(indent .. string.format("%.1f", units) .. " x " .. resource_name(resource))
  local r = recipe(resource)
  if r == nil or r.makes == 0 then return end   -- nothing makes it: buy it
  local runs = units / r.makes                  -- how many times to run the recipe
  for input, n in pairs(r.uses) do
    explain(input, n * runs, indent .. "  ")    -- the same question, one step down
  end
end

function launched()
  explain(4311, 1, "")   -- Pumps (A1)
  set_status("explained Pumps (A1): see what it printed")
  while true do wait(1440) end
end
```

`explain` prints one line, then calls itself for each input, with a bigger indent. An ore stops it: it's dug, not made, so its "recipe" uses nothing up. Here's what it prints (parts can come out in a different order):

```
1.0 x Pumps (A1)
  1.0 x Rods (A1)
    1.0 x Alloys (A1)
      2.0 x Metals (A1)
        40.0 x Magmite (N)
  1.0 x Hoses (A1)
    3.0 x Polymers (A1)
      3.0 x Inorganics (A1)
        30.0 x Magmite (N)
      3.0 x Organics (A1)
        60.0 x Magmite (N)
```

## Try it

1. Change `4311` to a resource you'd like to make. Find its number on the tech map.
2. Add up the magmite: 130. Now pick Metals (A1) on the tech map. One refining run of 20 magmite gives a metal, 2 inorganics *and* an organic at once, so this Pump needs only 3 runs, which is 60 magmite. The tree counts the same ore three times. How would you fix `explain` to count the runs once? (Hint: add up the refined goods first, then work out the runs.)
3. Point it at a ship and it prints a lot. If it breaks, give `explain` a `depth` and stop at 3. Break, learn, repeat.
