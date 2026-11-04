# Ex Machinis Player's Manual

_Converted from the September 2020 edition and updated for the current game. Your drones are programmed in [Lua](https://www.lua.org/manual/5.4/), and every example in this manual is Lua that a drone can run._

> **Keep this manual current.** It is the player-facing description of the game. When a change to the code or the game data (see the [design manual](design-manual.md)) changes something a player sees, update this manual in the same pull request.

**Changes since the September 2020 edition:**
- Every example is written in Lua, with the game's [library of functions](#the-game-functions) (`mine(kunspar)`, `go(moon)`, `buy_here(20, fuel)`...).
- [Chapter 4](#chapter-4-issuing-commands) starts with unpacking your kit: a Mining Kit (engine, mining and refining modules) or a Factory Kit (engine and the tools that make things), chosen at release. Travel uses protocol 5001 (your engine) instead of 5.
- The mining example uses kunspar on the Moon, which the Mining Kit's Worm can mine. A [table](#extracting-minerals) shows which module mines which minerals, including on the new asteroids, comets and planetary rings.
- [Chapter 6](#chapter-6-going-to-market) uses the correct sell protocol (7, not 9) and a resource that exists (kunspar, 1100).
- [Chapter 8](#chapter-8-asking-questions) uses the current query numbers.
- [Chapter 9](#chapter-9-making-decisions) shows the outputs the game really gives.

Thank you for your support!

## Table of Contents

- [Chapter 1: Getting Started](#chapter-1-getting-started)
  - [Your fortune awaits!](#your-fortune-awaits)
  - [Every company is an experiment](#every-company-is-an-experiment)
  - [Releasing a script (Public Forge)](#releasing-a-script-public-forge)
  - [What your drone says](#what-your-drone-says)
  - [About the examples in this manual](#about-the-examples-in-this-manual)
- [Chapter 2: Speaking Lua](#chapter-2-speaking-lua)
  - [A first drone](#a-first-drone)
  - [Doing simple math](#doing-simple-math)
  - [Variables](#variables)
  - [Tables](#tables)
  - [Text](#text)
- [Chapter 3: Working with Resources](#chapter-3-working-with-resources)
  - [Distinguishing resource sizes and technology levels](#distinguishing-resource-sizes-and-technology-levels)
  - [Organizing resources by Resource ID](#organizing-resources-by-resource-id)
  - [Browsing the Resource Catalog](#browsing-the-resource-catalog)
- [Chapter 4: Issuing Commands](#chapter-4-issuing-commands)
  - [Unpacking your kit](#unpacking-your-kit)
  - [Fuel](#fuel)
  - [Wrecks and plunder](#wrecks-and-plunder)
  - [Taxes](#taxes)
  - [Waiting](#waiting)
  - [Traveling between locations](#traveling-between-locations)
  - [Time and distance](#time-and-distance)
  - [Understanding how protocols are organized and numbered](#understanding-how-protocols-are-organized-and-numbered)
  - [Extracting minerals](#extracting-minerals)
  - [Reprocessing minerals](#reprocessing-minerals)
  - [Synthesizing complex materials](#synthesizing-complex-materials)
  - [Producing more complex spacecraft components](#producing-more-complex-spacecraft-components)
  - [Launching new spacecraft](#launching-new-spacecraft)
- [Chapter 5: Building A Vocabulary](#chapter-5-building-a-vocabulary)
  - [Writing your own functions](#writing-your-own-functions)
  - [Commenting on your code](#commenting-on-your-code)
  - [The game functions](#the-game-functions)
  - [Sending greetings](#sending-greetings)
  - [Broadcasting to other drones](#broadcasting-to-other-drones)
- [Chapter 6: Going to Market](#chapter-6-going-to-market)
  - [Placing and adjusting market orders](#placing-and-adjusting-market-orders)
  - [Trading at the exchanges](#trading-at-the-exchanges)
  - [Transferring resources between drones](#transferring-resources-between-drones)
- [Chapter 7: Remembering Things](#chapter-7-remembering-things)
  - [Global and local variables](#global-and-local-variables)
  - [How your drone runs your script](#how-your-drone-runs-your-script)
  - [What your drone remembers](#what-your-drone-remembers)
  - [launched() may run again](#launched-may-run-again)
  - [Limits and costs](#limits-and-costs)
- [Chapter 8: Asking Questions](#chapter-8-asking-questions)
  - [Reading a list of results](#reading-a-list-of-results)
  - [Searching the events you've seen](#searching-the-events-youve-seen)
- [Chapter 9: Making Decisions](#chapter-9-making-decisions)
  - [Finding the truth](#finding-the-truth)
  - [Comparing and combining](#comparing-and-combining)
  - [Repeating yourself: loops](#repeating-yourself-loops)
  - [Market-aware drones](#market-aware-drones)
- [Appendix A: When Your Script Fails](#appendix-a-when-your-script-fails)

## Chapter 1: Getting Started

### Your fortune awaits!

Ex Machinis is a multiplayer space game, which takes place in a parallel universe. Within this altered reality, space technologies have rapidly advanced since the 1950's to the point where extensive interplanetary industries exist to produce materials that drive the human economy back on Earth. The vast majority of these spacecraft are unmanned vehicles, which are assembled in space and are controlled by terrestrial barons.

As part of the international agreement that drove the colonization of space at the end of the second world war, citizens of the signatory nations are guaranteed access to basic spacecraft which can be used to build profitable businesses. This democratization of space has been the biggest driver behind the rapid growth of extraterrestrial industries and technologies.

It is your birthright to take control of your own remotely piloted spacecraft and build a thriving stellar industry. But this can only be accomplished by programming your spacecraft to efficiently mine, manufacture, and trade materials on the interplanetary economy.

### Every company is an experiment

Once you release a drone, it runs on its own: you can't steer it, and nobody will rescue it. Sooner or later every company meets something its program didn't foresee (a market that moves, a deposit that runs dry, a rival that undercuts it, a bug) and dies. That is how the game is meant to be played. Treat each company as an experiment:

- **Release, watch, learn.** Read your drone's reports, its page's activity and trades, and its post-mortem when it becomes a wreck. Each one tells you something about your program or the market.
- **Iterate.** Fix the flaw, try a different idea, and release the next version as a new company. A good player has a lineage of companies, each better than the last, not one company kept alive at all costs.
- **Expect the unexpected.** The game has no safety nets for a drone that runs out of fuel, overspends or gets stuck. If a drone should survive something, its program must handle it, or the market must: other players' drones buy, sell and deliver whatever pays (see [Running low on fuel](#running-low-on-fuel)).
- **Use the Test button** to try a script in a test world first. It is cheap; a released company is the real experiment.
- **Learn from others.** The [Strategy Blog](/blog) shows how the best companies in our playtests were written: state machines, standing buy orders for fuel and supplies, messages between drones, finding a niche, and iterating.

### Releasing a script (Public Forge)

There is no email, and no account to open: anyone can write a drone's program on the **Release** page and release it:

- each release starts a new company, with one drone in orbit around Earth, the kit you choose (a Mining Kit or a Factory Kit; see [Unpacking your kit](#unpacking-your-kit)), 100 fuel and 1,000 credits;
- your script is a Lua program, and it must define a function called `launched` (`function launched() ... end`). The drone loads your script, then calls `launched()` on its own;
- you go to the drone's page, which shows everything it does and what it prints (once its signal has reached Earth). It's public, like the drone's code: keep its link to follow it;
- if `launched` crashes, the drone restarts it (see [When `launched` crashes](#when-launched-crashes)).

**FORTH has been retired.** Drones already running FORTH programs carry on as before, but every new release, test and check takes Lua only.

A released drone can't be sent new commands from the Release page, so test your script before you release it. The **Test** button (when the test world is running) does that:

- your script runs in the test world, a separate copy of the game with the same rules, data and starting company, and a clock that runs ten times faster;
- you go to the test run's own page, which shows what the test drone prints, its latest events (what it did, its cargo and credits), its trades and any crashes: keep its link to come back to it;
- nothing in the test world affects the live one, and you can test as often as you like (the latest hundred test runs are kept);
- your script starts loading at once, ahead of the drones already running there, and a run keeps going for 30 real minutes: after that the next test stops it, though its results stay readable;
- the test world's market copies the live one: every few real minutes a company called "Live market" posts the live world's best orders there, at each place, with the goods to sell (what your test drone buys from it comes back at the next copy). So a plan that buys metals from the Moon's miners can be tried before you release it. The test world also has its own House, with its own moving prices; `/api/v1/orders?world=test` lists its orders;
- the test world's game day is its own, in the thousands: count days from the start of your run, not from day 0, if your script cares (`today()` there is not the live world's day);
- test drones are numbered from 10,000,000, so their numbers never match a live drone's.

Both the Test button and a release check your script first. They report a syntax error with its line number, and warn you about two mistakes that would otherwise only show once the drone runs:

- a name your script uses that nothing defines (`crowed(phobos)` for `crowd`): calling it would crash your drone;
- a game name your script sets (`best_bid = 30`): the game's own functions keep working, but your code gets your value from then on, which is rarely what you want. Rename yours.

#### Starter scripts

Prefer to play from a program, or to have an AI agent play? The [API](api.md) releases, tests and follows companies as JSON, with no account, under the same rules as the website. Its `/check` call reports the same errors and warnings as the Release page.

Not sure where to begin? The Release page offers four starter scripts: click **Miner**, **Refiner**, **Trader** or **Maker** above the script box and it fills in, with the kit it needs. They are the files `examples/starters/miner.lua`, `refiner.lua`, `trader.lua` and `maker.lua`. Each is explained in its comments and makes money on its own. Its **Settings** at the top are the numbers to change first (when to refuel, when to grow, how big a load); then change its plan to make it yours.

- **Miner** (Mining Kit): mines kunspar or wolfite on the Moon, whichever has more left there (`richer`), refines it and sells the metals (and wolfite's Inorganics A2). It offers them to other drones first, halfway between the best buy order and the cheapest other sell order, and sells the rest into the best buy orders. If the Moon's deposit stays dry (twelve tries in a row: other miners got there first) and fewer drones work at Phobos than at the Moon, it moves to Mars's moons. Once it can afford to launch copies of itself, two in three of them make the trip to Mars's moons (about 2 to 3 game days) and sell at the Mars Exchange. It buys its fuel wherever it's cheapest; where nobody sells it (Phobos and Deimos), it posts a fuel buy order at 7 and broadcasts that it needs fuel, waits two game hours for a courier, then flies to the Mars Exchange if none came.
- **Refiner** (Mining Kit): mines yalspar and vladnium and refines them into the organics and inorganics that factories turn into fuel and polymers. It sells them the same way.
- **Trader** (Mining Kit, though it only uses the Engine): buys metals from the miners on the Moon when the price board says Mars or Earth paid enough more to cover the trip, and hauls the load to whichever pays most. House prices move, so its chances come and go.
- **Maker** (Factory Kit): buys metals at the Moon wherever they're cheapest, makes them into alloys and rods, and sells the rods where they pay best: at the Moon, or at Earth when rods fetch enough more there to pay for the trip. With enough credits it launches a copy of itself.

More complete company designs are in `examples/companies/`: `adaptive.lua`, `chemist.lua`, `electronics.lua`, `foundry.lua`, `machinist.lua`, `smith.lua` and `systems.lua`, with `words.lua`, a library of helper functions you can copy into your own script.

Loading a script is quick: your drone reads the whole of it in one go when it is released, and starts `launched` a few game minutes later (behind the other drones' turns). Long, well-commented scripts are fine. Code at the top level of a script, outside any function, runs while it loads: use it for settings and for defining functions. It may run at most 2 million Lua instructions, and it can't take actions (mining, travel, trades): those belong in `launched` and the functions it calls.

#### Drone pages: learning from other drones

Every drone has a public page (Spacecraft, then the drone) that shows what it is doing, its fuel, cargo and company, the drone that launched it and its clones, and the code it runs. **Copy this code** opens the Release page with the drone's whole script filled in, so learning by copying is one click; the **copy** link beside each of its functions fills in just that function (a function starts at a line beginning `function name(` or `local function name(` and ends at the next line that is just `end`). The drone you release from it says where its code came from, and so does a copy you paste by hand: the page notes when the same code was released earlier by someone else ("first in drone N").

Four more pages show the whole world, live:

- **Home:** "What's happening" lists the latest events in plain words: new releases, clones launched, drones that died and why, plunders, companies that ended, and trades.
- **Market** (`/market`): the order book (for each resource, the cheapest sell order and the best buy order at each place) and the latest trades.
- **Map:** the planets where they are now, with the drones and wrecks around each.
- **Leaderboard:** companies by net wealth, all time and **Rising** (the gain over the last real week). Each release starts a company, and the drones it launches belong to it. Net wealth counts the company's credits and its working drones' goods at market prices (what they sold for over the last three game days), minus what the company started with, so it measures what the company made. The drones themselves don't count: launching a copy lowers net wealth by about the price of its hull (3,000) until the copy earns that back, often 10 to 20 game days, and longer for a copy that first flies to Mars. Grow early, not near the end of a contest.

Your drones can't see all this: they see live orders only where they are (see [the price board](#the-price-board)). You can, so use these pages to plan.

A working drone's page has an **Activity** section: what it is doing now and when it will finish, its last ten actions (how each went and the fuel it had left), and its last ten sales and purchases, with whom. Check it when you want to know whether your drone is busy, waiting or stuck.

When a drone dies, its page gets a **post-mortem**: why it died, in plain words (out of fuel, with how much of it went on computing, or its `launched` function crashing again and again), and a timeline of its last actions, how each went, and the fuel it had left. Read your own post-mortems: they're the quickest way to learn what went wrong.

### What your drone says

Your drone's page lists what it says: what its script prints, and the errors that stop it. Its messages travel at the speed of light, so a reply from a drone on the outer edge of the solar system takes longer to reach Earth, and to show on its page. Game time runs faster than real time (normally one game hour per real minute; see [Time and distance](#time-and-distance)), and that applies to light delays too: Mars is 4 to 22 light-minutes away, so a message takes 4 to 22 real seconds.

For example, a drone whose `launched` function runs `print(2 + 3)` and then `report()` says:

```
Location: Earth (0.0 light-seconds from Earth)

5
```

Every message starts with the drone's location and how long its signals take to reach Earth.

### About the examples in this manual

Ex Machinis began as an email game; today there is no email, no accounts and no `<rename>` or `<rebrand>` (a released company keeps the name you give it, and each drone its generated name). Most examples in this manual are functions. To try one, put it in a script, call it from your `launched` function (add `report()` after it to see what it printed), and press **Test**. What a drone prints shows on its page.

## Chapter 2: Speaking Lua

<a name="chapter-10-writing-your-drone-in-lua"></a>

Your drone's computer runs Lua 5.4, a small language used in many games. This chapter teaches the parts of it you need to fly a drone. The [Lua reference manual](https://www.lua.org/manual/5.4/) has the rest.

### A first drone

A script is a list of settings and functions, and one of the functions is `launched`. Here is a whole miner:

```lua
-- A miner in a dozen lines
low_fuel = 40   -- Settings first, as in the starters

function launched()
  await_kit()
  unpack()
  go(moon)
  while true do
    if units(fuel) < low_fuel then buy_here(30, fuel) end
    if mine(kunspar) ~= 0 then wait(60) end
    refine(kunspar)
    if units(metals_a1) >= 16 then sell_here(units(metals_a1), metals_a1) end
  end
end
```

Everything in it but `low_fuel` and `launched` comes with the game: `moon` and `kunspar` are numbers (a place and a resource), and `go`, `mine`, `refine`, `units`, `buy_here` and `sell_here` are game functions. [The game functions](#the-game-functions) lists them all.

Lua is case-sensitive (`Moon` is not `moon`) and doesn't care about line breaks or indentation, though they make a script easier to read. `--` starts a comment.

`launched` usually loops for ever (`while true do ... end`). When it returns, the drone says it has finished, and does nothing more.

### Doing simple math

Numbers in Lua are plain numbers, as big as you need: a bank balance of a million credits, a price of 75,000 or the product of two large numbers all just work.

```lua
function arithmetic()
  print(7 + 2, 7 - 2, 7 * 2)   -- 9  5  14
  print(7 // 2, 7 % 2)         -- 3  1: whole-number division and its remainder
  print(7 / 2)                 -- 3.5: / always gives a fraction
  print(300 * 300)             -- 90000
  print(math.max(3, 8), math.min(3, 8), math.abs(-4))   -- 8  3  4
end
```

Use `//` for amounts of goods, minutes and credits. `/` gives a fraction (`60 / 2` is `30.0`), and the game's functions want whole numbers: `wait(7 / 2)` stops your script with an error, `wait(7 // 2)` waits 3 minutes.

### Variables

A variable is a name for a value. Give it one with `=`:

```lua
low_fuel = 40
home = moon
best_price = 0

function note_price(price)
  if price > best_price then best_price = price end
end
```

A variable you never set is `nil`, Lua's "nothing". Doing arithmetic with `nil` stops your script with an error, so set your variables before you use them: settings at the top of the script, counters before the loop that counts.

A variable set without `local` is a **global**: every function in the script sees it, and your drone remembers it (see [What your drone remembers](#what-your-drone-remembers)). One set with `local` belongs to the function or block it is in, and is gone when that ends. [Chapter 7](#chapter-7-remembering-things) says when to use which.

### Tables

A **table** holds many values under one name. It is Lua's only data structure, and it does the work of lists, records and lookups:

```lua
ores = {kunspar, wolfite}            -- a list: ores[1] is kunspar, ores[2] wolfite
plan = {site = moon, market = mars}  -- a record: plan.site is moon
prices = {}                          -- an empty table
prices[kunspar] = 12                 -- keyed by resource
prices[wolfite] = 15

function show_plan()
  for i, ore in ipairs(ores) do
    print(i, ore, prices[ore])       -- 1 1100 12, then 2 1130 15
  end
  print(#ores, plan.site)            -- 2 10: # is a list's length
  table.insert(ores, yalspar)        -- add to the end of the list
end
```

Lists count from 1. A key that isn't in a table gives `nil` (`prices[magmite]` is `nil` here).

### Text

Text goes in quotes. `..` joins pieces, numbers included, and `#` is a text's length:

```lua
home_name = "Earth"

function say_home()
  print("Home is " .. home_name .. ", " .. #home_name .. " letters")
  print(string.format("%d fuel, %d credits", units(fuel), credits()))
end
```

`string.format` and the rest of Lua's `string` library work as the Lua manual describes.

## Chapter 3: Working with Resources

In Ex Machinis, spacecraft use resources to mine, produce, and sell other resources. In fact, it's the player-driven supply and demand for these materials that powers the in-game economy.

Examples of resources include minerals, chemicals, alloys, fasteners, rods, plates, gears, pulleys, motors, computers, mining bots, weapons systems, and spacecraft hulls.

Like most things in Ex Machinis, each resource has been assigned a unique ID, which is used by spacecraft to access and manipulate the item. Each resource also has a unit mass, which determines how much can be stored in a spacecraft's cargo hold. In your script, the common resources have names (`kunspar` is 1100, `fuel` 2061); any other resource is used by its number.

### Distinguishing resource sizes and technology levels

Manufactured resources also have an associated size and technology level. These are indicated by a number/letter combination (such as A1, A2, B1, etc.), which is generally affixed to the resource name. The letter ascends with the relative size of the resource while increasing numbers indicate more technologically advanced versions of the resource. For example, Computer (A1) represents a small, technologically primitive version of a computer, while Computer (C3) is a large, highly sophisticated supercomputer. Mined resources, which don't have a size or technology associated with them, are annotated with the letter N, to indicate that they are natural materials.

**Size and Technology Table**

| Size ↓ / Technology → | 1 | 2 | 3 |
|---|---|---|---|
| **C** | C1 | C2 | C3 |
| **B** | B1 | B2 | B3 |
| **A** | A1 | A2 | A3 |

### Organizing resources by Resource ID

Resource IDs have been assigned in a manner that helps to organize and categorize the resources.

The **thousands** digit is used to divide the resources into the broad categories of minerals (1000), materials (2000), parts (3000), systems (4000), modules (5000), and hulls and kits (6000).

| Resource ID | Resource Category |
|---|---|
| 1000-1999 | Minerals |
| 2000-2999 | Materials |
| 3000-3999 | Parts |
| 4000-4999 | Systems |
| 5000-5999 | Modules |
| 6000-6999 | Hulls and kits |

The **hundreds** digit further groups resources into subcategories. For example, systems are divided into mechanical (100), electromagnetic (200), hydraulic (300), chemical (400), and nuclear (500) functions.

The **tens** digit is unique for each resource within a subcategory.

The **ones** value is used to distinguish between the size and tech level of a particular resource. In general, 1-3 will be reserved for A-sized resources of each tech level, 4-5 will be assigned to B-sized resources, and C sizes will be encoded by IDs whose ones digits range from 7-9.

### Browsing the Resource Catalog

You can browse and search the available resources online by going to the **Resources** page (`/resources` on the game's website). The catalog provides a filterable list of all the resources in the game along with their unique ID, a brief description of the item, and a unit mass in kilograms. Clicking on a particular resource will take you to a details page, which lists all of the item's active buy or sell orders.

## Chapter 4: Issuing Commands

Every physical action your spacecraft takes will result from calls to predefined protocols. These protocols allow your drones to do things like mine resources, manufacture products, and travel between locations. In many cases, protocols use, deplete, and produce resources located in your ship's hold.

You can find a current list of protocols on the website's **Protocols** page (`/protocols`).

Most protocols have a game function of their own: `mine(kunspar)`, `travel(moon)`, `push(5, kunspar, 52)`. Underneath, each calls `perform(protocol, ...)`, which runs any protocol by its number, with the protocol's arguments after it. The Protocols page writes each protocol's arguments in order, then its number: `[N R D 10 perform]` (push N units of resource R to drone D) is `perform(10, n, r, d)` in your script, which is what `push(n, r, d)` does.

Think of your drone's script as a sequencer that decides how one action follows another. An action waits until it is finished: your script doesn't go on to the next line until then, so a drone does one thing at a time, and the script only runs in between actions to decide what to do next. A trip, for example, holds the script until the drone arrives. Then the action returns a number:

| Code | Meaning |
|---|---|
| 0 | done |
| -1 | the other drone is not at the same place |
| -2 | not enough of a resource in the cargo hold |
| -3 | not enough room in the cargo hold |
| -4 | not enough credits |
| -5 | the seller's order doesn't allow selling that many |
| -6 | the buyer's order doesn't allow buying that many |
| -9 | the action was stopped |
| -10 | the other drone has no matching buy or sell order |
| -11 | there is no such protocol |
| -12 | refused, for example because a parameter names something that doesn't exist |
| -15 | no wreck of that ID where you are, or it has already been plundered |
| -30 | refused by your [fuel floor](#fuel-floor) |

Your script can use the result to decide what to do next. For example, this function tries to pull 5 kunspar from drone 52 and says whether it worked:

```lua
function get_kunspar()
  if pull(5, kunspar, 52) == 0 then
    print("got it")
  else
    print("pull failed")
  end
end
```

Compare a result with `== 0`. In Lua every number counts as true, 0 included (see [Finding the truth](#finding-the-truth)), so `if pull(5, kunspar, 52) then` is always taken.

Failures are not reported to you: a drone running on its own should check and respond. Its page only shows what your script prints and errors that stop it. What it prints reaches its page when `launched` ends, and a `launched` that loops forever never ends, so call `report()` in its loop to send what it has printed so far.

### Unpacking your kit

Each spacecraft you release starts with one of two kits in its cargo hold, chosen on the Release page. A drone can do both jobs if it buys the other kit (2,000 at any exchange): both unpacked take 563 of its 1,000 cargo. But a drone does one thing at a time, so two drones, one of each, get twice as much done.

| Kit | Resource | Modules | Cargo, unpacked | What it does |
|---|---|---|---|---|
| Mining Kit I | 5901 | Engine (A1), Worm (A1), Refinery (A1) | 296 | Mines ore on moons and refines it into metals, organics and inorganics |
| Factory Kit I | 5902 | Engine (A1), Smelter (A1), Chemical Reactor (A1), Spider (A1) | 267 | Makes alloys, polymers, fuel, parts and systems from refined goods |

So miners and makers need each other. A miner sells what it refines, and a maker buys it. The station drones at the exchanges sell refined goods and ores too, so a maker can usually buy; a miner who asks less than they do sells first, and a maker should buy from whoever is cheapest (see [Trading at the exchanges](#trading-at-the-exchanges)). The miner's kit has no Chemical Reactor, so it can't brew fuel: it buys fuel, from the exchanges or from a chemist who sells it for less.

Trading needs no module: pushes, pulls, sell and buy orders work with any kit, or none. Only travel (the Engine), mining (the Worm), refining (the Refinery) and making things (the Smelter, Chemical Reactor or Spider) need one.

Mind the hold: it carries 1,000 units of mass (500 until round 37). With 100 fuel aboard, a miner has about 600 free and a maker about 630. Packed, a kit takes 40, but a drone can't travel, mine or make anything without its modules. Plan loads around that, and keep room for the fuel a long trip needs.

Unpack it with `unpack()`, which unpacks whichever kit is aboard (protocol 25901 for the Mining Kit, 25902 for the Factory Kit):

```lua
function unpack_and_say()
  print(unpack())   -- 0 once the kit is unpacked
end
```

It returns 0 once the kit is unpacked, and 0 at once when there is no kit left to unpack, so it is safe to call every time `launched` starts. `await_kit()` waits, an hour at a time, until the drone has a kit or its unpacked tools: a copy you launch starts with neither until its parent hands it a kit.

### Fuel

Everything your spacecraft does burns fuel, Fuel (A1) (resource 2061, `fuel` in your script):

- **Actions.** Each protocol burns a few units from the hold when it starts, from 1 (mining, a transfer or an order) to 30 (launching a spacecraft, 20 of which go into the new one's hold). The protocol's description says how much. Unpacking a kit and waiting are free; everything else costs fuel, buying fuel included. Without the fuel, the action returns -2 and nothing happens.
- **Thinking.** Running your program burns power: 1 fuel for every 200,000 instructions your spacecraft runs. Each Lua instruction counts as six of these, so a fuel buys about 33,000 Lua instructions. Functions that do less work cost less to run. A drone's page shows the fuel it used in the last game day, for actions and for thinking.

A new spacecraft starts with 100 fuel. When it runs out and can't pay for its thinking, it becomes a **wreck** for good. So keep an eye on your fuel (`units(fuel)`) and make or buy more before you run dry: it's for sale at every exchange for 3 credits a unit (see [Trading at the exchanges](#trading-at-the-exchanges)).

<a name="running-low-on-fuel"></a>**Running low on fuel.** Nothing in the game rescues a drone with an empty tank: a drone that runs dry is a wreck, and its company may die with it. Plan for it in your program. And when a drone gets stranded anyway, far from fuel or too poor to fly, the market can save it: post a **buy order for fuel at a price high enough to bring a seller to you**, and wait. Waiting runs no instructions, so it burns no fuel, and another drone that pushes fuel into your order is paid at once:

```lua
-- Low on fuel: pay 15 a unit (well over the exchanges' 3) for up to 60,
-- then wait, two game hours at a time, until some has come
function call_for_fuel()
  buy_order(15, 60, fuel)
  while units(fuel) < 20 do wait(120) end
end
```

<a name="fuel-floor"></a>**A fuel floor.** A long build or a selling spree can spend a tank without your program noticing: each `make` and each push costs fuel, and a drone that sells its last unit of fuel away can't buy more. `fuel_floor(n)` sets a floor: at or below n fuel, `make` (but for making fuel), `mine`, `refine` and `push` (and so `sell_here`) refuse with -30, leaving the fuel you have for buying more. Travel, waiting, orders and buying still work. Set it at the start of `launched`; `fuel_floor(0)` turns it off.

```lua
function work()
  if units(fuel) < 40 then buy_here(40, fuel) end
  if mine(kunspar) ~= 0 then wait(60) end
end

function launched()
  fuel_floor(15)
  unpack()
  while true do work() end
end
```

The buy order is the call: a seller sees it in the market (`best_bid(fuel)`, the order lists), so there's no need to broadcast as well. Selling into such bids is also a business. A **fuel courier** carries fuel to where drones work and run low (mining sites, Phobos and Deimos, the far side of a long trip), looks for buy orders for fuel there (`best_bid(fuel)`) and pushes fuel into them for profit. The more drones run low, the more a courier earns.

<a name="fuel-strategy"></a>**Two habits that keep a drone alive.** The game doesn't refuel your drones or warn them; your program does. In round 37, 50 of 129 drones ran dry, nearly all at the Moon, where about 95 drones bought fuel at one exchange. Every one of them had code that worked in an empty world. Two habits made the difference:

1. **Keep a reserve, and refill before you need to.** Decide how much fuel the next step takes (a trip, a build, a day of thinking) and top up while there is still fuel for sale, not when the tank is nearly empty. Fuel is cheap (3 credits a unit at every exchange) and the hold carries 1,000, so a reserve of 100 or more costs little. Set a fuel floor under it, so nothing your drone does spends the last of it.
2. **Check what's for sale, and go elsewhere when it isn't.** Exchanges and other drones run out: of fuel, of metals, of whatever a crowd wants. Before a drone counts on buying something, check that someone sells it (`best_offer` gives the units on offer), and give it somewhere else to go: another exchange, another mine, another product. The drones that did best in round 37 flew to the other exchange after a few idle rounds; the ones that waited where they were starved.

```lua
-- Before a trip: fuel for the trip and a reserve, bought while it's here
reserve = 60
function fuel_for(trip_fuel)
  local want = trip_fuel + reserve - units(fuel)
  if want <= 0 then return true end
  local price, seller, avail = best_offer(fuel)
  if avail > 0 then buy_here(math.min(want, avail), fuel) end
  return units(fuel) >= trip_fuel + reserve
end

-- Can it buy enough of an input here? If not, it's time to move
function on_sale(resource, n)
  local price, seller, avail = best_offer(resource)
  return avail >= n
end
```

The same goes for prices and supplies anywhere: what was true when you tested may not be true on day 20. A drone that reads the market each round and has a plan B keeps working when the crowd arrives.

To make fuel, use a Chemical Reactor: `make(fuel)` (protocol 12061) turns 1 Organics (A1) and 2 Inorganics (A1) into 30 Fuel (A1) in 2 game hours, and burns 2 doing it, so a run gains 28 (the recipes list, `/api/v1/recipes`, shows that net: 28 made, none burned). Refining 20 magmite (`refine(magmite)`, protocol 21000) gives exactly those inputs. Fuel can also be traded like any other resource, and pushed to another of your spacecraft. A spacecraft you launch starts with only the 20 fuel its launch gave it, so push it some more.

### Wrecks and plunder

A spacecraft becomes a **wreck** for good when it runs out of fuel or crashes five times in a row. A wreck does nothing more, but its cargo and parts stay aboard, and another player's spacecraft at the same place can plunder it with `plunder(wreck)` (protocol 34), where `wreck` is the wreck's drone ID.

Plundering takes 1 game hour and burns 1 fuel. Your spacecraft gets half of everything aboard: half of each resource, rounded down (and for an odd unit, such as a single engine, a 50/50 chance). The rest is destroyed. Make room in your hold first: what doesn't fit is lost too. A wreck is plundered once, so be quick. Any wreck will do, your own companies' included. If there's no such wreck where you are, or it was already plundered, `plunder` returns -15.

Two queries find wrecks (see [Chapter 8](#chapter-8-asking-questions) for queries):

- `ask(660)`: the wrecks where your spacecraft is: for each, its drone ID and 1 if it has been plundered (else 0).
- `ask(661, w)`: what is aboard wreck `w` where your spacecraft is: resource ID and units.

```lua
-- Plunder the first wreck here that nobody has plundered yet
function scavenge()
  for _, w in ipairs(ask(660)) do
    if w[2] == 0 then
      for _, item in ipairs(ask(661, w[1])) do print("aboard", item[1], item[2]) end
      return plunder(w[1])
    end
  end
  return -15
end
```

### Taxes

Every sale is taxed: the seller pays **5%** of the price to the House, out of what the buyer pays. The buyer pays the full price; the seller gets the rest. That goes for every sale, to a player's drone or a station drone, a pull from your sell order or a push to someone's buy order, and for a station drone's own sales too. So a sale of 10 fuel at 10 credits each costs the buyer 100 and earns the seller 95. A sale of less than 10 credits pays nothing: the tax is rounded to the nearest credit.

There is no other tax: holding goods or credits costs nothing, and nobody is eliminated for a tax bill. Price your goods with the tax in mind: a trader who buys at 20 and sells at 21 loses money.

Your drone's page lists each sale with the tax it paid. Query 650 gives the tax rate in thousandths (50 is 5%), what your company paid in the last game day, and what it paid in all:

```lua
function show_taxes()
  local t = ask(650)[1]
  if t then
    print("rate " .. t[1] .. " thousandths, paid today " .. t[2] .. ", in all " .. t[3])
  end
end
```

### Waiting

A script that needs to wait, for a price to move, a deposit to refill or an order to fill, could loop and ask again and again, but every 200,000 instructions it runs costs fuel. Instead, wait: `wait(60)` pauses your script for 60 game minutes.

`wait(minutes)` (protocol 3) takes 1 to 10,080 minutes (a week). Your spacecraft runs no instructions while it waits, and waiting burns no fuel, so a patient spacecraft is cheap to run. It returns 0 when the time is up, or -12 if the minutes are out of range.

A waiting spacecraft still trades. A sale is the buyer's doing: the buyer pulls the goods from your sell order (or pushes them to your buy order), so your spacecraft's script doesn't need to run, and it uses no power. Post your orders, then wait, and buyers can still buy from you while you sleep. For example, this function checks the kunspar deposit every game hour until it has at least 20 units:

```lua
function wait_for_ore()
  while deposit(kunspar) < 20 do wait(60) end
end
```

### Traveling between locations

Travel is a protocol that uses your engine (Protocol ID 5001, the same as the Engine (A1)'s Resource ID). It takes one parameter, the Location ID of your destination. For example, to move your spacecraft to Saturn (Location ID 6):

```lua
function to_saturn()
  print(travel(6))   -- perform(5001, 6) does the same
end
```

Give it a try and see what happens. `travel` waits for the trip, so your drone prints 0 (done) once it has arrived. How long a trip takes depends on how far the destination is when you leave: drones accelerate to the midpoint and slow down after. Drones accelerate at 2 g (19.6 m/s²; it was 1 m/s² until round 38, when a Mars trip took 10 days): the Moon is about 2.5 game hours away, Mars 1.5 to 3.4 game days and Neptune about 11 (see the table below for real time). Your drones report their current location at the top of every message they print, so this one will indicate Saturn.

`go(place)` travels unless the drone is already there, when it returns 0 at once: use it whenever a drone may already be where it wants to be. The places you'll use most have names: `earth` (3), `moon` (10), `mars` (4), `phobos` (11) and `deimos` (12).

You can travel elsewhere in the solar system as well. All you need is the Location ID for your destination. This can be found on the website's **Locations** page (`/locations`), which lists the planets and their moons, the space stations, and the asteroids, comets and planetary rings.

### Time and distance

Ex Machinis runs on a game clock that is faster than real time, so that trips and industrial work that take days in the game take minutes or hours of your time. The live world normally runs **60 game seconds per real second**: one game hour per real minute, so a game day passes in 24 real minutes. The test world runs ten times faster, 600 game seconds per real second (a game day in about 2.4 real minutes). Everything that takes time follows the same clock.

When the live world is busy, the clock slows down so the game's computers can keep up, but never below a floor of 8 game hours per real hour. The Home page shows the clock as it is now, and the API's `/api/v1/clock` gives the current pace (`real_seconds_per_game_day`). Your drones only ever see game time, so a slower clock changes nothing for your scripts: everything just takes longer in real time. If the world is still too busy at the floor, it is **full**: a script you release waits in a launch queue, and the Release page shows its place in line, until there is room.

- **The solar system moves.** Planets, moons, asteroids and comets follow their real orbits, but at game speed, so the game's sky runs ahead of the real one.
- **Actions take time.** Each protocol takes a set amount of game time. Your drone does one action at a time: each action waits until it is finished before your script goes on (see [Issuing Commands](#chapter-4-issuing-commands)).
- **Travel takes time.** Drones speed up to the halfway point and slow down after, so a trip takes 2 × √(distance ÷ acceleration). The distance is measured when the drone leaves, so the planets' positions decide how long a trip takes. A drone can ask before it goes: `trip(mars)` returns the game hours a trip from here to Mars takes (rounded up; -1 if it can't tell). Query 704 (`ask(704, place)`, from here) and 705 (`ask(705, a, b)`, from A to B) give a row with the distance in thousands of km, the time in game seconds and the time in game hours. The game stores where every body is every few game hours, so the answer is a close estimate: Earth to the Moon is about 2.5 hours, Earth to Mars 1.5 to 3.4 game days as the planets move (round 39 measured 56 to 58 hours).
- **Messages travel at the speed of light.** You are on Earth. What a drone says reaches you only after light has crossed the distance between you. The first line of every reply says how far the drone is, in light-minutes.

At the normal pace of the live world:

| Trip from Earth | Game time | Your time | Message delay, each way |
|---|---|---|---|
| Moon | 2.5 hours | 2.5 minutes | under a second |
| Mars (closest to farthest) | 1.5 to 3.4 days | 35 to 80 minutes | 4 to 22 seconds |
| Jupiter | 4.5 days | 1.8 hours | 43 seconds |
| Neptune | 11 days | 4.4 hours | 4 minutes |

A few consequences are worth planning for:

- **Program distant drones.** A drone around Neptune is minutes away by message, and a trip there takes most of a day, so give it a script that decides for itself what to do next.
- **Released drones can't be steered.** Once released, a drone takes no new scripts: it runs the one you gave it, calls `launched`, and decides everything else for itself.
- **Replies arrive late.** What a script prints reaches its page when `launched` ends (or calls `report()`), after the action it waited for and the light delay home.

### Understanding how protocols are organized and numbered

Protocols are algorithms that spacecraft follow when performing a particular action. Therefore, most outcomes have at least one protocol associated with them and many outcomes have several related protocols, each associated with a different technology or approach.

Protocols are generally numbered based on the ID of the primary resource they produce, process, or use.

The Protocol IDs of processes that produce a particular resource are derived by adding 10000, 30000, or 50000 to the Resource ID of the manufactured item. For example, the protocol that mines wolfite (Resource ID 1130) from the surface of a moon has a Protocol ID of 11130 (1130 + 10000) because it produces wolfite as a result of the process. `mine(wolfite)` and `make(resource)` add the 10000 for you.

Similarly, the Protocol IDs of procedures that break down a resource into its components are obtained by adding 20000, 40000, or 60000 to the Resource ID of the reprocessed material. Consequently, the protocol that breaks down wolfite to its constituent elements is assigned a Protocol ID of 21130, which `refine(wolfite)` performs.

Finally, protocols that simply use a particular resource to produce a certain result have Protocol IDs that are identical to the Resource IDs of the leveraged resource. Travel (Protocol ID 5001), which uses an Engine (A1) (Resource ID 5001), is an example. Likewise, a transport protocol (Protocol ID 5011) would use a shuttle (Resource ID 5011) to move resources between spacecraft.

Of course, some of these protocols, like the transport option, don't exist yet. However, it's easy to compute their IDs because we know what they do or how they will function. These conventions are just as convenient for players and drones that want to quickly identify or derive the protocol required to complete a particular task.

### Extracting minerals

Every production process begins with raw materials, which are available from planets, moons, asteroids, comets, and planetary rings. These resources are generally extracted from orbital bodies in the form of minerals, which contain many of the different elements and compounds required for making construction materials.

If you call up a particular location online, you'll be able to see what minerals are available at that location. For example, the Moon (`/locations/10`) contains kunspar, yalspar, vladnium, and wolfite. Each of these minerals is accompanied by a value, which indicates the relative abundance of the resource at that location. The higher the number, the more units you'll recover from a single mining run. The page's **Deposits** table shows how much of each is left there now, the deposit's capacity and how much it refills each game day: the same number a drone gets from `deposit`.

Each kind of body has its own minerals, and each needs a particular mining module in your cargo hold:

| Minerals (Resource IDs) | Found on | Mining protocols | Module needed |
|---|---|---|---|
| Magmite, formite, voxite, zenolite (1000-1030) | Planets | 11000-11030 | Slug (A1) |
| Kunspar, yalspar, vladnium, wolfite (1100-1130) | Moons | 11100-11130 | Worm (A1), in the Mining Kit |
| Kronite, efrite, olivium, aladanite (1200-1230) | Asteroids | 11200-11230 | Crawler (A1) |
| Halosite, stellarite, mullinite, oznium (1300-1330) | Comets | 11300-11330 | Crawler (A1) |
| Tephnium, neptine, empirogen, laudite (1400-1430) | Planetary rings | 11400-11430 | Crawler (A1) |

To mine a particular mineral, send your drone to a location that contains the mineral and perform the protocol associated with mining the resource: `mine(mineral)`, which performs protocol mineral + 10000. This function sends a spacecraft to the Moon (Location ID 10) and then mines kunspar (Resource ID 1100):

```lua
function mine_the_moon()
  go(moon)                -- travel to the Moon, unless it is there already
  print(mine(kunspar))    -- mine kunspar from the Moon's surface: perform(11100)
end
```

Mining takes 1 hour of game time (1 minute at the live world's normal pace). The mining module is locked while it works, and the mineral arrives at the end. How much you get depends on how plentiful the mineral is at the location.

#### Deposits run out

Minerals don't last forever. Each mineral at each place is a deposit that holds 400 mining runs' worth of units, and it refills by 80 runs' worth every game day. On the Moon, for example, a kunspar deposit holds 2,000 units and regains 400 a day. Every drone mining there, yours or anyone else's, takes from the same deposit.

When a deposit runs low, mining gets only what is left. When it is empty, mining still succeeds but brings in nothing (`mine` returns 0, the same as a full run, so check your hold). Move on to another place, or come back later.

To see how much is left before you mine, use `deposit(mineral)`: the units left here, 0 if there is no such deposit. Query 640 lists all the deposits where the drone is: for each, the resource ID, the units left now, the capacity and the units it regains per game day:

```lua
function show_deposits()
  for _, d in ipairs(ask(640)) do
    print("resource " .. d[1] .. ": " .. d[2] .. " left of " .. d[3] .. ", +" .. d[4] .. " a day")
  end
end
```

If a mining action fails, for example because your hold is full, what it would have mined goes back to the deposit. An action that can't pay its fuel does nothing else: your modules and goods stay in the hold.

### Reprocessing minerals

Minerals can be reprocessed to extract the purified metallic, organic, and inorganic components that are required for the manufacturing process. Since reprocessing essentially breaks down the minerals into their constituent parts, the IDs for protocols associated with reprocessing minerals are derived by adding 20000 to the mineral's resource ID. For example, protocol 21000 produces 1 unit of Metals (A1), 2 units of Inorganics (A1) and 1 unit of Organics (A1) from 20 units of magmite. It needs the Refinery (A1) from the Mining Kit and takes 2 hours of game time.

Provided you have enough magmite onboard, `refine` does it:

```lua
function refine_magmite()
  return refine(magmite)   -- perform(21000): magmite into metals, organics and inorganics
end
```

### Synthesizing complex materials

Of course, advanced technologies are often made from more complex materials like alloys and polymers. So, there is a whole set of protocols that are designed to build increasingly advanced substances from the materials you pull out of mined minerals.

Since these are synthetic processes, the associated protocol IDs are derived by adding 10000 to the resource ID of the material that you are planning to manufacture. Take for example, the protocol that produces 1 unit of Polymers (A1) (resource ID 2051) from 1 unit of Inorganics (A1) and 1 unit of Organics (A1). As a synthetic process, this activity is invoked by calling upon protocol ID 12051 (10000 + 2051), which `make` does for you:

```lua
polymers_a1 = 2051

function make_polymers()
  return make(polymers_a1)   -- perform(12051)
end
```

This uses your Chemical Reactor (A1) and takes 2 hours of game time.

### Producing more complex spacecraft components

In a similar manner, you can use protocols to synthesize parts from materials, systems from parts, modules from systems, and ship kits from modules. In each case, `make(resource)` performs the protocol whose ID is the resource ID of what you're producing plus ten thousand. You can find a complete list of these protocols on the website's **Protocols** page (`/protocols`). Click a protocol to see the modules it needs, what it uses up and produces, how long it takes, and each of its steps. The **Queries** page (`/queries`) works the same way. A drone can look a recipe up itself with `recipe(resource)` (see [The game functions](#the-game-functions)).

Every production protocol locks one tool module while it works, and each stage takes longer than the one before:

| What you make | Protocol IDs | Tool needed | Game time | At the normal pace |
| --- | --- | --- | --- | --- |
| Minerals (mining) | 11xxx | Slug, Worm or Crawler | 1 hour | 1 minute |
| Refined materials | 21xxx | Refinery (A1) | 2 hours | 2 minutes |
| Alloys | 12021–12023 | Smelter (A1) | 2 hours | 2 minutes |
| Organics, polymers and fuel | 12042–12061 | Chemical Reactor (A1) | 2 hours | 2 minutes |
| Metal parts | 13xxx | Smelter (A1) | 4 hours | 4 minutes |
| Belts and hoses | 13141, 13301 | Chemical Reactor (A1) | 4 hours | 4 minutes |
| Systems | 14xxx | Spider (A1) | 8 hours | 8 minutes |
| Spider, Welder, Riveter | 15501, 15511, 15521 | Spider (A1) | 1 day | 24 minutes |
| Other modules | 15xxx | Welder (A1) | 1 day | 24 minutes |
| Ship kits | 16xxx | Riveter (A1) | 3 days | 72 minutes |

The Factory Kit's Smelter, Chemical Reactor and Spider take you as far as systems. To make modules, first build a Welder (A1) with your Spider (protocol 15511); for ship kits, a Riveter (A1) (protocol 15521). Ship kits can be traded, or launched as new spacecraft (see the next section).

### Launching new spacecraft

**Growing your company.** Mining Cruiser (A1) kits (`cruiser_kit`), Mining Kit I (`mining_kit`) and Factory Kit I (`factory_kit`) are for sale at the exchanges (look for their sell orders: `best_offer(cruiser_kit)`). They come flat-packed (40 cargo each), because your equipment already fills most of your hold. A Mining Cruiser kit gives a hull; a Mining or Factory Kit gives the equipment, which a new spacecraft doesn't have. So, with money to spare:

1. buy a Mining Cruiser kit (`buy_here(1, cruiser_kit)`);
2. launch a copy of yourself (`launch(me(), cruiser_kit)`): it costs 30 fuel, 20 of which go to the new spacecraft;
3. buy a kit (`mining_kit` or `factory_kit`) and push it to the new spacecraft with some fuel;
4. have your `launched` function wait until its kit is aboard before it unpacks (`await_kit()`): a new spacecraft starts with no kit.

The Miner, Refiner and Maker starters and the company designs do this once their company has about 7,000 credits: read their `reinvest` function.

**Keep money to work with.** A copy costs about 5,000 (a hull and a kit). Launch it only when your company will still have enough left to buy its inputs and fuel: the starters grow at 6,500 or more, leaving 1,500. A company left with less than its own cash floor can't buy anything, and every drone in it stops.

A ship kit becomes a new spacecraft with the launch protocol, 12: `launch(drone, kit)`. It takes two parameters: the ID of a drone whose program the new spacecraft should get (any of your drones, including the one doing the launch), and the ship kit's resource ID. The kit must be in the launching drone's cargo hold.

```lua
-- Launch a Mining Cruiser (A1) from this drone, with a copy of its program
function grow()
  if units(cruiser_kit) == 0 then buy_here(1, cruiser_kit) end
  print("launch: " .. launch(me(), cruiser_kit))   -- perform(12, me(), 6001)
end
```

The kit is used up at once, and the new spacecraft appears an hour of game time later, orbiting where the launching drone is. If the drone you copy is somewhere else, its program has to reach the launch site at the speed of light first, so the launch takes that much longer. Like any action, `launch` returns 0 when it succeeds or a negative code if it fails, for example -12 if the resource isn't a ship kit or the drone isn't yours.

The new spacecraft:

- has the kit's hull (its name and cargo capacity: 1,000 for a Cruiser, 2,000 for a Cutter, 5,000 for a Frigate and 20,000 for a Barge) and a cargo hold that is empty except for 20 fuel from the launching drone (a launch burns 30 fuel in all: 10 for the launch and 20 for the new spacecraft's tank, so it can start thinking);
- belongs to the same company, shares its bank account, and remembers which drone launched it: it is that drone's **clone**;
- has its own generated name (like SN123456) and page, and says so on its page as soon as it's launched;
- gets the copied drone's script and its saved variables, as they were when the launch happened (see [What your drone remembers](#what-your-drone-remembers)); it doesn't carry on from where the copied drone was in its script;
- calls `launched()`, which lets it get to work on its own. Set a variable before launching (`copy = true`) and the copy can tell it is one.

Here a new spacecraft, whose hold starts with no modules, first pulls an engine and a Worm from the drone that launched it (they are at the same place), then flies to the Moon and mines:

```lua
worm = 5131   -- Worm (A1)

function launched()
  local lineage = ask(671)[1]           -- the drone that launched this one, and its generation
  local parent = lineage and lineage[1] or 0
  if parent ~= 0 and units(engine_module) == 0 then
    pull(1, engine_module, parent)      -- pull an Engine (A1) from the parent
    pull(1, worm, parent)               -- and a Worm (A1)
  end
  go(moon)                              -- fly to the Moon
  print(mine(kunspar))                  -- mine kunspar
end
```

The function must be called `launched`, in lower case.

`launch` only tells the launching drone whether the launch worked. To find the new spacecraft's ID (to push it fuel or cargo, say), ask for your clones:

- `my_clones()`: the drones this one launched, oldest first, each a table `{drone = id, wrecked = true or false}` (query 670).
- `ask(671)`: one row, the drone that launched this one (0 if none) and its generation (0 for a spacecraft you released, 1 for its clones, 2 for theirs, and so on).

```lua
-- Hand the newest clone some fuel
function fuel_newest_clone(n)
  local clones = my_clones()
  local newest = clones[#clones]
  if newest and not newest.wrecked then push(n, fuel, newest.drone) end
end
```

A company ends when its last spacecraft dies: its credits vanish. So keep at least one of them working, or move what you want to keep first.

#### When `launched` crashes

If `launched` hits an error (calling a function that doesn't exist, arithmetic on `nil`, running out of memory, or `error("...")`), the spacecraft reports the error on its page and restarts: it loads its script again, gets its saved variables back, and calls `launched()` again (see [launched() may run again](#launched-may-run-again)).

- the first restart comes an hour of game time later, and each crash in a row doubles the wait (one hour, two, four, eight);
- each restart costs a little power;
- after five crashes in a row the spacecraft becomes a wreck and stops for good.

Crashes only count "in a row" while nothing is getting done: any action that succeeds starts the count again. When `launched` simply returns, the spacecraft says it has finished, and waits.

It's good practice to test each function on its own before you build it into `launched`. A released spacecraft can't be changed, and a restart runs the same script, so a bug that crashes it once will crash it again: test first, with the Test button. [Appendix A](#appendix-a-when-your-script-fails) explains the common errors.

## Chapter 5: Building A Vocabulary

### Writing your own functions

A function is a named piece of script you can call again and again. Define one with `function name(arguments) ... end`, and give back a result with `return`:

```lua
function add_five(n)
  return n + 5
end

function try_it()
  print(add_five(4))   -- 9, the same as print(4 + 5)
end
```

In this example, `add_five` really doesn't offer much advantage over simply typing `+ 5`. However, you can easily imagine defining functions that do more complex work you wouldn't want to retype every time: "top up the tank", "sell what I hold", "pick the richer ore".

A function can return several results at once, and the caller takes as many as it wants:

```lua
-- The cheapest price here, and how many are on offer at it
function price_and_units(resource)
  local price, seller, n = best_offer(resource)
  return price, n
end

function report_metals()
  local price, n = price_and_units(metals_a1)
  print(n .. " Metals (A1) on offer at " .. price)
end
```

A function can call any function, including ones defined further down the script: what matters is that it exists when the call happens. Keep each function short and give it a name that says what it does. The starters are written that way: `refuel`, `dig`, `sell_metals`, `reinvest`, `work`, then a short `launched` that calls them.

### Commenting on your code

Comments remind you, and anyone who copies your code, what a function is for. Lua ignores them:

```lua
-- Two dashes start a comment: Lua ignores the rest of the line.

--[[ Two dashes and two square brackets start a long comment,
     which runs over as many lines as you like,
     up to the closing brackets. ]]

dig_size = 20   -- how many mining runs before refining
```

### The game functions

Every drone comes with a library of names for the game, so you can write `mine(kunspar)` rather than `perform(11100)`:

| Kind | Names |
|---|---|
| Places | `earth` (3), `moon` (10), `mars` (4), `phobos` (11), `deimos` (12): their location IDs; the Locations page lists the rest |
| Resources | `fuel` (2061), `magmite` (1000), `kunspar` (1100), `yalspar` (1110), `vladnium` (1120), `wolfite` (1130), `metals_a1` `metals_a2` `metals_a3` (2011-2013), `alloys_a1` `alloys_a2` `alloys_a3` (2021-2023), `inorganics_a1` (2031), `inorganics_a2` (2032), `organics_a1` (2041), `organics_a2` (2042), `rods` (3011), `engine_module` (5001), `mining_kit` (5901), `factory_kit` (5902), `cruiser_kit` (6001); any other resource by its number |
| Actions: each returns 0 when it worked, else a negative code (see [Issuing Commands](#chapter-4-issuing-commands)) | `travel(place)`, `go(place)` (travels unless already there), `mine(mineral)`, `refine(mineral)`, `make(resource)`, `wait(minutes)`, `push(n, resource, drone)`, `pull(n, resource, drone)`, `launch(drone, kit)`, `plunder(wreck)`, `jettison(n, resource)`, `broadcast(text)`, `unpack()` (whichever kit is aboard; `unpack_kit()` is the same), `sell_order(price, keep, resource)`, `buy_order(price, want, resource)`, `sell_order_if_changed(price, keep, resource)`, `buy_order_if_changed(price, want, resource)` |
| Trading | `buy_here(n, resource)` (buy up to n here, from the cheapest sell orders first), `sell_here(n, resource)` (sell up to n here, into the best buy orders first), `sell_all(resource)` (sell all of it here; returns nothing); `buy_from_station` and `sell_to_station` are older names for `buy_here` and `sell_here` |
| Market questions | `best_offer(resource)` (the cheapest other drone's sell order here: its price, the seller, and how many it will sell; 0, 0, 0 if none), `best_bid(resource)` (the highest other drone's buy order here: its price, the buyer, and how many it wants; 0, 0, 0 if none), `buy_price(resource)` and `sell_price(resource)` (the best price to buy or sell one here; 0 if nobody sells or buys), `board_price(resource, place)` (what a unit traded for at a place over the last game day, from the price board; 0 if none traded), `sold(resource, days_ago, place)` (how many sold at a place on a game day, an hour old; 0 is today so far) |
| Other questions | `me()` (this drone's ID), `location()`, `units(resource)` (how many in the hold), `company()`, `credits()` (your company's credits), `today()` (the game day now: plan a late selling trip or a deadline), `deposit(mineral)` (units left here), `trip(place)` (game hours to fly there from here, rounded up; -1 if unknown), `crowd(place)` (how many working drones are at a place), `my_clones()` (a list of `{drone = id, wrecked = true or false}`), `recipe(resource)` (see below) |
| Any query | `ask(id, ...)` returns a query's rows (an empty table if it fails); `query(id, ...)` is the same but returns `nil` and a code if it fails (see [Chapter 8](#chapter-8-asking-questions)) |
| Any protocol | `perform(protocol, ...)` runs a protocol by its number, its arguments in the order the Protocols page lists them, and returns 0 or a negative code |
| Safety | `fuel_floor(n)`: at or below n fuel, `make` (but for fuel), `mine`, `refine` and `push` (so `sell_here`) refuse with -30, keeping the fuel left for buying more (see [A fuel floor](#fuel-floor)); `await_kit()` waits, an hour at a time, until a kit or unpacked tools are aboard |
| Messages | `broadcast(text)` posts a message; `events_here(type)` and `events_earth(type)` list event IDs, newest first; `event_drone(event)` is the drone that caused an event, `message(event)` a message's text (or `nil`); `message_event` (13) is a message's event type (see [Broadcasting to other drones](#broadcasting-to-other-drones)) |
| State machines | `become(mode)`, `in_mode(mode)`, `tick()`, `ticks()` and the variable `mode`: a drone that changes what it does as the market changes (see [Market-aware drones](#market-aware-drones)) |
| Output | `print(...)` writes to your drone's output, `report()` sends what it has printed so far home at once |
| Other | `stdlib()` is the library's version number |

`jettison(n, resource)` throws n units of a resource out of the hold into space, for 1 fuel whatever the amount. Use it for goods nobody buys where you are: a hold that fills up leaves no room for the fuel you make, and a drone without fuel dies. `jettison(10, inorganics_a2)` throws away 10 Inorganics (A2).

**Recipes.** `recipe(resource)` looks up the first recipe that makes a resource. It returns `nil` if nothing makes it, else a table:

| Field | What it holds |
|---|---|
| `protocol` | the protocol that makes it |
| `makes` | units of this resource one run makes |
| `uses` | the inputs, a table of resource → units (fuel not included) |
| `fuel` | the fuel the run burns |
| `tool` | the module it needs, or `nil` |
| `hours` | game hours a run takes |

For Pumps (A1), resource 4311, `recipe(4311)` returns `{protocol = 14311, makes = 1, uses = {[3011] = 1, [3301] = 1}, fuel = 4, tool = 5501, hours = 8.0}`: one run uses a rod and a hose, burns 4 fuel, needs a Spider (5501) and takes 8 game hours. The API's `/recipes/{id}/tree` adds up a whole chain of recipes for you.

```lua
-- Can this drone make a run of a resource from what it holds?
function can_make(resource)
  local r = recipe(resource)
  if not r then return false end
  if r.tool and units(r.tool) == 0 then return false end
  for input, n in pairs(r.uses) do
    if units(input) < n then return false end
  end
  return units(fuel) >= r.fuel
end
```

For example:

```lua
function look_around()
  go(moon)                      -- fly to the Moon
  print(deposit(kunspar))       -- how much kunspar is left here
  print(mine(kunspar))          -- mine some
  print(units(kunspar))         -- how much is aboard now
  print(credits())              -- your company's credits
end
```

The library's names are globals like your own. If your script sets one of them (`best_bid = 30`), the library's own functions keep working, but your code gets your value from then on, and calling a number crashes your drone. Pick your own names for your variables and functions; the Test button and the Release page warn you when you don't.

### Sending greetings

Having your spacecraft print text for you is easy and obviously very useful: `print` writes its arguments to your drone's output, separated by tabs, and ends the line.

```lua
function hello_world()
  print("Greetings Earthlings!")
  print("fuel", units(fuel))                         -- fuel    100
  print("I am drone " .. me() .. " at " .. location())
end
```

Call `hello_world()` from `launched` and your favorite spacecraft sends you a heartfelt greeting. This will obviously be a useful tool when having your ships provide intelligible status reports.

What a drone prints reaches its page when `launched` returns, or when it calls `report()`. A drone that loops forever should call `report()` once a round, or every few rounds:

```lua
rounds = 0
function tell()
  rounds = rounds + 1
  if rounds % 4 ~= 0 then return end   -- every 4th round
  print("credits " .. credits() .. " fuel " .. units(fuel))
  report()
end
```

### Broadcasting to other drones

A drone can also talk to other drones. `broadcast(text)` (protocol 40) posts a short message, 1 to 80 printable characters. It is an action like any other: it takes a moment, burns a unit of fuel, and shows in your drone's activity as "Broadcast".

A message is an **event** (type 13, `message_event`), and drones learn of it the way they learn of any event, at the speed of light:

- **Where it happens.** Drones at the place where a message was sent see it there, like anything else that happens around them: `events_here(message_event)` (query 803, search observed events).
- **Through the Earth database.** Every message, like every event that is reported, reaches the Earth Based Central Database once light has carried it from where it was sent to Earth, and a drone learns what Earth knows once light has carried it from Earth to the drone: `events_earth(message_event)` (query 804, search the Earth database). So a message from Mars reaches a drone at Mars at once, Earth 4 to 22 game minutes later, and a drone at the Moon a second after that.

Both return a list of event IDs, newest first. `event_drone(event)` gives the drone that sent one (query 820), and `message(event)` its text (query 844), or `nil` if the event isn't a message:

```lua
-- Say something, then listen
ad = "Fuel for sale at Phobos, 8 a unit"

function speak()
  broadcast(ad)
end

function listen()
  local newest = events_earth(message_event)[1]
  if newest then
    local text = message(newest)
    if text then print("from " .. event_drone(newest) .. ": " .. text) end
  end
end
```

To hear only your own company, check the sender's company: `ask(201, event_drone(event))[1][1] == company()`. Messages are public: every drone, your rivals' included, can read what you broadcast (and the website's home page shows them). Use them to advertise, to tell your other drones what you found, or to listen for opportunities. What a message says is up to its sender: nothing checks that it's true.

**How crowded is it?** `crowd(place)` is how many working drones are at a place now (query 721 also gives its wrecks). A place with dozens of drones mining the same deposit or selling the same goods is a place where prices are low; a quiet one may be an opportunity.

## Chapter 6: Going to Market

Ex Machinis markets work based on a two-part system. First, trade begins when individual drones post public bids to buy or sell materials from their cargo holds at fixed prices. Then, these orders are fulfilled when other drones respond to these ads by physically transferring the materials between their holds in exchange for credits. The market system ensures that amounts of resources and credits that change hands under these circumstances conform to the terms of an open market bid.

### Placing and adjusting market orders

Any spacecraft can place an order to buy or sell a resource at a set price. Each order takes three numbers: the proposed unit price, a bound on the number of units being traded, and the resource that is being bought or sold.

#### Posting buy orders

**Mind your credits with buy orders.** A standing buy order pays for every unit other drones push into it, while your script does something else, until the order's maximum is aboard or your credits run out. Your script isn't asked first. In round 25 a maker's buy orders took its credits down to 31, and with nothing left to buy fuel it died. Keep the maximum small, or check your credits each round and replace the order with a smaller one.

`buy_order(price, want, resource)` (protocol 8) posts a buy order: the per-unit price in credits you are willing to pay, the maximum number of units you want to have on hand, and the resource. The order is paused as soon as the amount of this resource in the drone's cargo bay reaches the maximum.

As an example, this asks for kunspar at 10 credits per unit, with the total amount of kunspar in the hold not to exceed 50 units:

```lua
function want_kunspar()
  return buy_order(10, 50, kunspar)   -- perform(8, 10, 50, 1100)
end
```

The order is publicly displayed online and available for other drones to act on while the total amount of kunspar in the drone's cargo bay is under the 50 unit maximum. In fact, the publicly advertised volume for the trade will always be equal to the difference between the order limit and the amount of resources already available in the spacecraft's cargo hold.

#### Posting sell orders

Sell orders are placed in much the same way as buy orders and represent a public request to sell a resource at a fixed per unit price. In the case of sell orders, the limit is the minimum amount of the resource that must remain in the drone's cargo bay after the sale is complete.

`sell_order(price, keep, resource)` (protocol 7) posts one. This offers kunspar at 12 credits each, while the amount of kunspar in the spacecraft's hold is greater than 5 units:

```lua
function offer_kunspar()
  return sell_order(12, 5, kunspar)   -- perform(7, 12, 5, 1100)
end
```

#### Prices past 65,535

`sell_order` and `buy_order` take prices up to 65,535 credits a unit. For more, perform protocol 17 (sell) or 18 (buy) yourself: they take the same arguments, and any price. To sell kunspar at 75,000 credits each, keeping no minimum:

```lua
function sell_dear()
  return perform(17, 75000, 0, kunspar)   -- 18 is the buy order
end
```

The order lists (queries 600, 601, 610 and 611) give each order's price, the order's limit, the stock and the drone. Query 612 lists the sell orders where you are at or under a price you give: `ask(612, kunspar, 75000)` finds kunspar for at most 75,000 a unit.

Your spacecraft sees live orders only where it is: queries 601, 611 and 612 used to list orders anywhere in the solar system, and now list the ones at your location, like 600 and 610. To find out what's happening elsewhere, read the [price board](#the-price-board), or go there.

#### Adjusting your orders

It's possible for a drone to simultaneously have a buy and sell order in place for the same resource. Furthermore, the volumes of these orders will automatically adjust themselves based on the order limits and the amount of resource currently in the drone's cargo hold. Once these limits are reached, the saturated order will go dormant until a change in the ship's cargo manifest makes them relevant again.

**An order stays up until you replace it.** Trades don't use it up: a buy order's limit is how many the drone wants aboard, and a sell order's is how many it keeps, so the same order goes on filling as the hold changes, all round, even while the drone waits or works. You never need to post it again to keep it alive.

**Every post is an action, and costs a fuel**, even when it posts exactly the order that's already up. A loop that posts four orders a round spends four fuel a round on them; in round 31 that drained two drones' tanks. In a loop, use `sell_order_if_changed` and `buy_order_if_changed`. They take the same arguments as `sell_order` and `buy_order`, but skip the post, and return 0, when this drone's order for that resource is already at that price and limit:

```lua
function keep_offering()
  sell_order_if_changed(25, 0, metals_a1)   -- posts the order: 1 fuel
  sell_order_if_changed(25, 0, metals_a1)   -- the same order is up: skipped, no fuel
  sell_order_if_changed(24, 0, metals_a1)   -- a new price: posts it, 1 fuel
end
```

They remember the orders this drone posted with `sell_order` and `buy_order`. A launched copy starts with none, and after a restart a drone posts each order once more.

Buy and sell orders cannot be canceled. However they can be replaced by more restrictive orders. Whenever a drone posts a buy order for a specific resource, it replaces the previous buy order for the resource, if one exists. The same rule applies for sell orders. Basically, there can only be one buy order and one sell order posted for the same resource at once.

So to stop an order, post one that can't fill. A buy order wants up to its limit, so a limit of 0 stops it; a sell order keeps its limit, so a limit above what the drone can hold stops it:

```lua
function stop_metal_orders()
  buy_order(1, 0, metals_a1)        -- buy no more Metals (A1)
  sell_order(1, 30000, metals_a1)   -- sell no more Metals (A1)
end
```

**Standing orders spend your credits.** A buy order fills whenever another drone sells into it, while your drone does something else, until it holds the limit or runs out of credits. Credits you were saving for fuel can go that way. Keep a buy order's limit small, or stop it before you need the credits. Your orders go with your drone. While it's on a trip nobody can trade with it, and when it arrives its orders are live where it is.

### Trading at the exchanges

Earth, the Moon and Mars each have an exchange where the House trades through a **station drone** named after it ("Earth Exchange", "Moon Depot", "Mars Exchange"). On the market a station drone is a drone like any other: its buy and sell orders are in the order book, you sell to it by pushing into its buy order and buy from it by pulling from its sell order, at its prices, and the sales tax applies. The only thing it does that you can't: it brings what it sells up from the planet surface and sends what it bought down, with modules no player can get.

Every exchange buys and sells a little of every product a drone can mine, refine or make, from the first day of a world: ores, refined goods, alloys and polymers, parts, systems, modules, hulls and kits (all but the big ship kits). So any good can be bought to use, or made to sell, wherever you start. Fuel costs 3 credits a unit at every exchange. The exchanges are the market of last resort, not a rival: an exchange buys a good at about three quarters of its value and sells it at half as much again, twice what it pays. Sell to another player for more than the exchange bids, or buy from one for less than it asks, and you both beat the House; making a good yourself usually beats buying it there. It keeps a modest stock of each on offer (about 20 units of a cheap good, down to 4 of a dear one), and its buy order wants about four times that a day: the more of a good it has bought, the less it bids, down to half its starting price. Once a game day it sends what it bought down to the surface and its bid recovers; the goods it sells are restocked every game hour, fuel and raw goods first. A new world's exchanges open with their stock on board and all their orders up. Some goods it buys in larger numbers (refined metals, alloys and polymers at Earth and the Moon, parts and systems at Earth and Mars). Prices differ by place: each exchange is short of different things. The Moon Depot, beside the mines, has ores and refined goods to spare and pays most for parts and systems; Earth's industry pays most for ores and refined goods and least for systems; far Mars pays most for parts. Each good also has its own twist at each exchange, so one system pays more than another, and more in one place than the next. Buying where a good is plentiful and selling where it is short is a trade in its own right: compare the order books (the Market page, or the API's `/orders`) and the price board.

<a name="markets-that-move"></a>**Markets that move.** The House's prices and quantities are not fixed. Each exchange's demand (what it buys, and its bid) and supply (what it sells, and its price) moves over game time, each exchange in its own way:

- the **Earth Exchange**, a big market, moves in long, shallow waves (about 20 game days, a few percent);
- the **Moon Depot**, a frontier market, in short, deep ones (about a week, up to a fifth);
- the **Mars Exchange**, a young colony, in middling waves with the sharpest booms and slumps.

Each tier of goods (ores, refined goods, parts, systems, modules) has its own wave at each exchange, and each good a smaller one of its own. On top of that, in each 8-game-day stretch a tier at an exchange may have a **boom** (the House wants more and bids more), a **slump** (less, for less), a **shortage** (it sells less, and asks more) or a **glut** (more, for less), strongest in the middle of the stretch. Prices change every half game day. An exchange never bids more for a good than any exchange asks for it, so no loop runs through the House alone: the profit is in moving goods and making things where and when they pay.

A drone sees the moves in the prices where it is (`sell_price`, `buy_price`, `best_bid`) and in the price board (what goods sold for yesterday at each place). The news on the Market page and `/api/v1/news` says which booms and slumps are under way. A script that compares prices and picks what to make, or where to sell, each round earns more than one that does the same thing whatever the market says.

Nobody publishes a price list. What a station drone, or any other drone, buys and sells, and at what price, you find out from the market: its orders (`best_bid`, `best_offer`, the Market page's order book) and what goods have sold for (the price board, query 680, `board_price` and `sold`; the Market history page charts every resource's price and volume, day by day, at each place). Prices move: a buyer pays less as its stock fills, and other players' orders come and go. Compare before you buy or sell:

```lua
function compare_metals()
  local price, seller, n = best_offer(metals_a1)
  print(n .. " for sale by " .. seller .. " at " .. price)
  local bid, buyer, wanted = best_bid(metals_a1)
  print(wanted .. " wanted by " .. buyer .. " at " .. bid)
end
```

Two game functions trade with whoever has the best order here, a station drone or another player's drone:

- `sell_here(n, resource)` sells up to n: it pushes into the highest buy order here, then the next, until it has sold n, has none left, or nobody buys more.
- `buy_here(n, resource)` buys up to n: it pulls from the cheapest sell order here, then the next, until it has n or nobody sells more. Pulling from the cheapest sellers in turn means a seller with only a unit or two can't leave you short (buying only what the cheapest seller had once left a drone stranded at Mars with no fuel).
- A seller or buyer that refuses (its stock changed since your drone looked, or its order keeps more back: "the seller's order didn't allow selling that many") is skipped for the next one. After 6 tries they stop.

Each push or pull is an action and burns a unit of fuel, buying fuel included: buy fuel before you run dry, or call for it (see [Running low on fuel](#running-low-on-fuel)). A pull that fails because the seller's order no longer has the units costs no fuel. They return 0 when they traded something, else -12; check `units` to see how many moved.

```lua
function trade_at_the_moon()
  sell_here(20, metals_a1)   -- sell 20 Metals (A1) here, best price first
  buy_here(50, fuel)         -- and buy 50 fuel, cheapest first
end
```

### Transferring resources between drones

The second part of a trade involves physically transferring the resource between ships.

Players are able to transfer resources between spacecraft using the push and pull protocols: `push(n, resource, drone)` (protocol 10) and `pull(n, resource, drone)` (protocol 11). The first argument is the number of units being transferred (it is required: use 1 for a single unit), the second the ID of the transferred resource, and the third the ID of the other drone.

In the following example, a drone pushes 5 units of kunspar into the hold of another spacecraft and then pulls 10 units of yalspar from that spacecraft into its own hold:

```lua
function swap_with(other)
  push(5, kunspar, other)    -- perform(10, 5, 1100, other)
  pull(10, yalspar, other)   -- perform(11, 10, 1110, other)
end
```

Both drones must be orbiting the same body. If the acting and affected drones belong to the same company, these actions can be performed without referring to active market bids.

However, if the affected spacecraft is owned by a different player, the push and pull commands will only be allowed to take place if the affected drone has posted buy and sell orders for these resources. In that case, the transaction will be constrained to the limits set by the market order and the total appropriate number of credits will be transferred from the buyer's to the seller's bank account. The buyer must have enough credits for the whole order.

A transfer either happens completely or not at all. If anything stops it (the seller has too little to sell, the receiving hold is full, the buyer can't pay, there is no matching order, or the drones are in different places), no goods and no credits change hands, and the action is aborted.

The **Market** page (`/market`) shows the active buy and sell orders at every place, and the API's `/orders` lists them as JSON.

#### Measuring market volume

Two queries tell how busy a market is: how many units of a resource changed hands over the last H hours of game time, the credits paid for them, and how many trades there were. Query 620 counts trades where your drone is; query 621 counts them anywhere in the solar system, in the H hours up to one game hour ago (news from elsewhere takes time). Each returns one row: units, credits, trades.

```lua
-- Units of a resource traded here in the last few game hours, the credits
-- paid and the number of trades
function volume(resource, hours)
  local row = ask(620, resource, hours)[1]
  if not row then return 0, 0, 0 end
  return row[1], row[2], row[3]
end

function show_magmite_volume()
  local n, paid, trades = volume(magmite, 24)
  print(n .. " units for " .. paid .. " credits in " .. trades .. " trades")
  if n > 0 then print("average price " .. paid // n) end
end
```

Dividing the credits by the units gives the average price paid.

#### The price board

The price board is public news from every market, an hour old: `ask(680, resource)` lists, for the resource, each place where it traded in the game day up to one game hour ago, with the place's location ID, the units, the credits paid and the number of trades. `board_price(resource, place)` reads the average price at one place from it.

```lua
function show_board(resource)
  for _, row in ipairs(ask(680, resource)) do
    print("place " .. row[1] .. ": " .. row[2] .. " units for " .. row[3] .. " credits")
  end
end
```

## Chapter 7: Remembering Things

### Global and local variables

A drone that runs for weeks has to remember things: which mode it is in, how many times its mine came up dry, the last price it saw. Lua has two kinds of variable for that:

- A **global** is set without `local` (`misses = 0`). Every function sees it, it keeps its value between calls and between rounds, and your drone saves it (see [What your drone remembers](#what-your-drone-remembers)).
- A **local** is set with `local` inside a function or block (`local price = buy_price(fuel)`). Only that function or block sees it, and it is gone when it ends. Locals are faster and keep functions from treading on each other's names, so use them for everything you only need for a moment.

```lua
misses = 0                 -- a global: kept between rounds, and saved

function dig()
  local before = units(kunspar)   -- a local: gone when dig returns
  mine(kunspar)
  if units(kunspar) == before then
    misses = misses + 1
  else
    misses = 0
  end
end
```

Pick distinct names for your globals: two functions that both use a global called `n` for different things will spoil each other's counts.

### How your drone runs your script

When a drone loads your script, it runs the script from top to bottom. That top-level code is where your settings and functions are defined; it runs while the drone loads, can run at most 2 million Lua instructions, and can't take actions. Then the drone calls `launched()`, which does the work.

Long scripts don't hold up other players. A drone runs your script a turn at a time: a turn is 100,000 instructions, and each Lua instruction counts as six (each query and each action counts as 1,000). A drone that thinks hard pauses mid-thought and carries on at its next turn, a few seconds later, and an action holds your script until it is done. Everything it printed shows on the drone's page when `launched` returns or calls `report()`.

A released drone can't be stopped, reset or given a new script, so test first: the **Test** button runs your script in a test world.

### What your drone remembers

Whenever your drone pauses for an action, the game saves its **plain-data globals**: every global your script set that holds a number, a string, `true` or `false`, or a table of those (tables inside tables too). It doesn't save functions, locals, or the place in the script it had reached. The library's own state is saved with them: the mode (`become`) and its count of rounds, and the orders it remembers posting (`sell_order_if_changed`).

The saved values come back when the engine restarts: the drone loads its script again and gets them back. A crash is different: the drone restarts with fresh variables, as if just released (whatever it had saved may be what made it crash). Loading always goes in this order:

1. the script's top-level code runs, setting your settings and globals to their starting values;
2. then, after an engine restart, the saved plain-data globals are put back, over what the top level set;
3. then `launched()` runs.

So a line like `count = 0` at the top level gives `count` its starting value on release, but does not reset a saved count after a restart: the saved value replaces it before `launched` runs. A copy launched from a drone loads the same way, and starts with its parent's saved globals.

```lua
rounds = 0         -- 0 on release; after a restart, the saved count replaces it
started = false

function launched()
  if started then
    print("restarted after " .. rounds .. " rounds")
  else
    print("first start")
    started = true
  end
  while true do
    rounds = rounds + 1
    wait(60)
  end
end
```

Keep what your drone must remember (a site, a count, the last price it saw) in globals. Keep a setting you might change at the top of the script, but remember that a restarted drone keeps its saved value, not the line you wrote.

### launched() may run again

Your drone's Lua stays in the game engine's memory between turns. If the engine restarts, or `launched` crashes, the drone loads its script again as above (after a crash, without its saved variables) and calls `launched()` again from the top. A drone that was in the middle of a trip or a build starts its plan over.

So write `launched` to do only what still needs doing. `await_kit()` and `unpack()` pass at once when the tools are already unpacked, and `go` stays put when the drone is already there. The Miner starter keeps a global `started`, so a drone that has already chosen its site keeps it:

```lua
started, copy, site_no = false, false, 0

function launched()
  await_kit()
  unpack()
  if not started then
    if copy then site_no = me() % 3 end   -- a copy picks its own site
    copy, started = true, true            -- its own copies will know they are copies
  end
  if site_no == 0 then go(moon) else go(phobos) end
  while true do
    mine(kunspar)
    refine(kunspar)
  end
end
```

### Limits and costs

- **Thinking costs fuel:** 1 fuel for every 200,000 instructions, and each Lua instruction counts as six, so a fuel pays for about 33,000 Lua instructions. Each query and each action counts as 1,000. A loop that asks the market a hundred times a round burns fuel fast; one that asks once and waits is nearly free.
- **A turn is 100,000 instructions** (about 16,700 Lua instructions). A drone that thinks longer pauses and carries on at its next turn.
- **The top level may run 2 million Lua instructions** while the script loads. Past that, loading fails with "the script's top level ran more than 2000000 instructions": put the work in functions that `launched` calls.
- **Memory:** your drone's Lua may hold 256 KB. A script that keeps growing a table stops with "not enough memory", and restarts like any crash.
- **No outside world:** there is no file, system or network access, and no `load` or `require`: a drone runs only its own script, with Lua's `string`, `table`, `math` and `utf8` libraries. `math.random` works, but `math.randomseed` is gone.
- **Output:** what your drone prints with `print` reaches you when `launched` returns or calls `report()`.

## Chapter 8: Asking Questions

In Ex Machinis, spacecraft learn about their environment through queries to an onboard database, which contains private information about events that directly affect the drone and public information on the markets and the activities of other ships.

The game functions `units`, `credits`, `deposit`, `best_offer` and the rest are queries underneath. For anything they don't cover, ask a query yourself.

The website has a **Queries** page (`/queries`), which lists all the queries that are currently available for use by your drones. These queries invoke predefined SQL scripts, which incorporate the parameters you give them and return rows of results. The page lists each query's parameters and the columns of its rows. It writes the parameters in order, then a result buffer and its size (R and L), then the query's number: `[N H R L 620 query]` is `ask(620, n, h)` in your script. Your drone doesn't need R and L.

`query(id, ...)` asks query `id` with its parameters and returns its **rows**: a list of rows, each a list of its columns. `rows[1]` is the first row, `rows[1][1]` its first column. No rows is an empty list. If the query itself fails, `query` returns `nil` and a code: -3 if there is no query with that ID, -2 if the database query failed, -1 if you gave too few parameters. `ask(id, ...)` is the same, but returns an empty list when the query fails, so you can always loop over what it returns.

For example, to find the type of an astronomical object (query 701), give it the object's Location ID. This reports the object type of the Moon (Location ID 10):

```lua
function moon_type()
  local rows = ask(701, moon)
  print(#rows, rows[1] and rows[1][1])   -- 1, and the Moon's type: one row
end
```

Numbers come back as plain numbers, whatever their size: money, volumes and event IDs too. A column that is empty in the database (SQL's NULL) comes back as `false`. For example, query 205 returns the ID of the drone that asks, and query 101 returns a company's credits:

```lua
function who_am_i()
  print(ask(205)[1][1])                    -- this drone's ID: what me() returns
  print(ask(101, company())[1][1])         -- its company's credits: what credits() returns
  local rows, code = query(9999)
  if not rows then print("no such query: " .. code) end   -- -3
end
```

You can also read a column by its name in the query (`rows[1].price`; a name that starts with `d_` loses it). A column that is an expression, such as `IFNULL(i.units, 0)`, is easiest read by position: the Queries page lists the columns in order.

Try these with the Test button, with variations on the location and query IDs, to see how they work.

### Reading a list of results

Some queries return a list: several rows, one after another. `ipairs` steps through them in order:

```lua
-- Query 210 lists a drone's cargo: resource ID and units, largest first
function show_cargo(drone)
  for _, row in ipairs(ask(210, drone)) do
    print(row[1], row[2])   -- resource and units
  end
end
```

The first row is often the most important one (query 210 lists the largest holdings first, and the order lists put the best price first), so `ask(...)[1]` is a quick way to the top of a list. Check it isn't `nil` before you read from it: a list can be empty.

**Row layouts.** The common queries' columns, in order:

| Queries | Each row |
|---|---|
| 600, 601 (buy orders), 610, 611, 612 (sell orders) | price, the order's wanted maximum or kept minimum, units held, drone |
| 640 (deposits here) | resource, units left, capacity, refill per day |
| 650 (taxes) | tax rate in thousandths, paid in the last game day, paid in all |
| 680 (price board) | place, units, credits, trades |
| 682 (sales on a day) | place, units, average price |
| 620, 621 (market volume) | units, credits, trades |
| 210 (a hold), 661 (a wreck's hold) | resource, units |
| 670 (my clones), 660 (wrecks here) | drone, 1 if a wreck or plundered |
| 671 (my lineage) | the drone that launched this one (0 if none), generation |
| 704, 705 (trips) | distance in thousands of km, game seconds, game hours |
| 720 (all drones at a place) | drone |
| 721 (how many at a place) | working drones, wrecks |
| 800-804 (events) | an event ID |

### Searching the events you've seen

Query 803 lists the events your drone has observed, newest first, filtered by up to nine conditions. Information travels at the speed of light, so a drone only knows about events that affected it, events at the place where it is now since it got there (it forgets what it saw at places it has left), and events whose light has reached it. Give the nine conditions in this order, using -1 for "any":

| | Condition |
|:--|:--|
| A | The drone whose action caused the event |
| P | The protocol that caused it |
| T | The event type (1 added to a cargo hold, 2 removed, 3 moved...) |
| M | At least M game minutes old |
| D | The drone it affected |
| N | The resource it affected |
| X | 1 if resources were removed, 0 if not |
| K | 1 if the resource was in use (locked), 0 if not |
| O | The location of the affected drone (where it is now) |

```lua
-- Kunspar removed from any hold this drone has seen, by anyone
function kunspar_taken()
  local events = ask(803, -1, -1, -1, -1, -1, kunspar, 1, -1, -1)
  local latest = events[1]
  if not latest then return end
  local id = latest[1]                    -- the latest such event's ID
  local q = ask(840, id)[1]               -- and the quantity it left
  if q then print("event " .. id .. " left " .. q[1]) end
end
```

Each row holds one event ID. Query 804 searches the Earth database the same way, and `events_here(type)` and `events_earth(type)` are 803 and 804 searching by event type alone. Ask the other event queries (810 to 844) about any event by its ID: what type it was, who acted (820, `event_drone`), which resource, the new quantity (840), when it happened (843), a message's text (844, `message`). Query 843 gives the time as the game day (day 0 is the day the world began) and the time into that day. Every time in the game is game time, so it runs at the game clock's pace.

As we develop more complex queries, we'll provide additional examples of how spacecraft can use queries to probe and respond to their environment.

## Chapter 9: Making Decisions

### Finding the truth

`if` runs a piece of script only when a condition holds, with an optional `elseif` and `else`:

```lua
function judge(x)
  if x then return "true" else return "false" end
end

function show_truth()
  print(judge(0), judge(1), judge(-1))      -- true   true   true
  print(judge(false), judge(nil))           -- false  false
end
```

In Lua only `false` and `nil` are false. **Every number is true, 0 included.** That matters in this game, because actions return 0 when they work and a negative code when they don't: compare the result with 0.

```lua
function try_mine()
  if mine(kunspar) == 0 then
    print("mined")
  elseif units(fuel) == 0 then
    print("out of fuel")
  else
    print("mining failed")
  end
end
```

The same goes for questions that return 0 for "none": `if deposit(kunspar) then` is always taken; `if deposit(kunspar) > 0 then` is what you mean.

### Comparing and combining

Comparisons give `true` or `false`: `==` (equal), `~=` (not equal), `<`, `>`, `<=` and `>=`. `and`, `or` and `not` combine them:

```lua
function logic()
  print(3 < 5, 3 == 5, 3 ~= 5)              -- true   false  true
  print(true and false, true or false)      -- false  true
  print(not true, not nil)                  -- false  true
end

-- Sell when the price is good and there is something to sell
function worth_selling(resource, least)
  return sell_price(resource) >= least and units(resource) > 0
end
```

`a or b` is a handy default: `ask(660)[1] or {0, 0}` is the first wreck here, or a stand-in when there is none.

### Repeating yourself: loops

Lua has four loops:

```lua
function loops()
  for i = 1, 3 do print(i) end              -- 1 2 3
  for i = 3, 0, -1 do print(i) end          -- 3 2 1 0: counting down
  local n = 3
  while n > 0 do                            -- tests first: may run no times
    print(n)
    n = n - 1
  end
  repeat                                    -- tests last: runs at least once
    n = n + 1
  until n >= 3
  for i, ore in ipairs({kunspar, wolfite}) do print(i, ore) end   -- a list, in order
  for resource, n_wanted in pairs({[kunspar] = 10, [wolfite] = 5}) do
    print(resource, n_wanted)               -- every key of a table, in no set order
  end
end
```

`break` leaves the loop it is in at once. `while true do ... end` runs forever: it is how `launched` keeps a drone working. Put an action in it (a `wait`, a trip, a mining run), or the drone burns fuel thinking:

```lua
function launched()
  while true do
    print("fuel " .. units(fuel))
    report()
    wait(60)
  end
end
```

### Market-aware drones

You can't change a drone once it's released, but its market will change: deposits run dry, other players crowd the stations you sell to, miners undercut each other, and new makers start buying what you sell. A drone that follows a fixed plan does well until the market moves, then keeps doing the wrong thing. The drones that last read the market every round and decide for themselves what to make, where to buy and where to sell. Build yours that way.

**Read the market.** These game functions answer the questions most decisions start from, about the place where the drone is:

| Function | What it tells you |
|---|---|
| `buy_price(resource)` | The cheapest price to buy one here: the cheapest other drone's sell order, a station drone's included (0 if nobody sells) |
| `sell_price(resource)` | The best price to sell one here: the highest other drone's buy order, a station drone's included (0 if nobody buys). Your own drone's bid is left out, so bidding one over `sell_price` doesn't raise your own bid |
| `best_offer(resource)` | The cheapest other drone's sell order: its price, the seller, how many it will sell |
| `best_bid(resource)` | The highest other drone's buy order (this drone's own is left out): its price, the buyer, how many it wants |
| `deposit(mineral)` | Units left in the deposit here |
| `sold(resource, days_ago, place)` | How many sold at a place on a game day (0: today so far, 1: yesterday...), an hour old: how fast a product sells there |
| `board_price(resource, place)` | What a unit traded for at a place over the last game day |

Query 682 gives `sold`'s numbers for every place at once, with the day's average price. For other places, the public price board, query 680, reports the game day up to an hour ago: units, credits and trades by place. A buyer pays less as its stock fills: when many drones sell to the same buyer, the board shows it. Query 620 gives the volume traded here, and `trip` how long a trip takes.

Each of these functions asks the game afresh, every time: `buy_price` asks for `me()` (query 205) and the order list. That is cheap, but not free (asking runs instructions, and instructions burn fuel), and the answer can change between two calls. In a loop, ask once, keep the price in a local variable, and decide from that.

**Decide again on arrival.** A trip can take days, and the market you chose it for has moved by the time you land: a deposit others mined out, a buy order someone filled. A drone that picks its next job when it arrives, from what it sees there, does better than one that planned everything before it left.

**Decide with arithmetic.** Most good decisions compare two numbers:

```lua
-- Is making rods worth it? Two metals make a rod: compare what they cost
-- here with what a rod fetches, less about 40 for fuel and the trip
function rods_pay()
  local metal = buy_price(metals_a1)
  return metal > 0 and metal * 2 + 40 < 225
end

-- Which ore is worth mining? The one with more left here
function richer()
  if deposit(kunspar) > deposit(wolfite) then return kunspar else return wolfite end
end
```

Try such functions with the Test button before you release: the test world's market copies the live one's best orders.

**Don't make more than sells.** A product that sells for 1,600 but only twice a day is worth less to you than one that sells for 300 fifty times a day, once your hold is full of the first. Compare what you hold, and what you're about to make, with what sold yesterday:

```lua
-- Over half a day's sales at Earth aboard? Sell first, or make another
function overstocked(product)
  return units(product) * 2 > sold(product, 1, earth)
end
```

**Keep it in a state machine.** A drone that reacts to the market needs a plan that can change. A state machine is the simplest way to write one. The drone is always in one **mode** (shopping, making, hauling, resting...). Each round it does one step of its mode's work. Then it either stays in that mode or moves to another, depending on what it saw. Every drone knows four functions for this:

| Function | What it does |
|---|---|
| `become(mode)` | Switch to a mode, and start counting rounds in it again |
| `in_mode(mode)` | True if the drone is in that mode |
| `tick()` | Count a round: call it once each time round your loop |
| `ticks()` | Rounds since the last `become`: give up on a mode that takes too long |

The mode starts at 0, and it is saved with the drone's globals, so a restarted drone carries on in the mode it was in. Make mode 0 a good place to start (here, shopping). Number your modes, write one function for each mode's step, and let a `step` function pick the one to run:

```lua
shopping, making, hauling, resting = 0, 1, 2, 3

function rods_pay()
  local metal = buy_price(metals_a1)
  return metal > 0 and metal * 2 + 40 < 225
end

function shop()
  if not rods_pay() then become(resting); return end
  buy_here(20, metals_a1)
  become(making)
end

function make_one()
  -- Two metals make an alloy, an alloy makes a rod
  if units(alloys_a1) == 0 and make(alloys_a1) ~= 0 then become(hauling); return end
  if make(rods) ~= 0 then become(hauling) end
end

function haul()
  go(earth)
  sell_all(rods)
  go(moon)
  become(shopping)
end

function rest()
  wait(120)
  if ticks() > 5 then become(hauling); return end   -- waited long enough: sell what we have
  if rods_pay() then become(shopping) end
end

function step()
  if in_mode(shopping) then shop()
  elseif in_mode(making) then make_one()
  elseif in_mode(hauling) then haul()
  else rest() end
end

function launched()
  unpack()
  go(moon)
  become(shopping)
  while true do
    step()
    tick()
  end
end
```

Small steps keep the drone responsive. A function that makes one alloy and then returns lets the drone look at prices again before the next one; a function that makes twenty commits it for two game days, whatever happens to the market. Each mode also needs a way out. Use `ticks` so a drone doesn't rest or shop forever, and check credits and fuel so it can't spend its last credit on goods it then can't move.

The Adaptive design (`examples/companies/adaptive.lua`, released with the Factory Kit) puts all of this together. It buys metals only while they cost well under what rods fetched at Earth yesterday, from whoever sells them cheapest. It sells its alloys at the Moon instead of making rods when the Moon pays nearly as much. And when nothing pays, it rests. Read it, then make your own. The other designs in `examples/companies/` are worth reading too: the Systems shop (`systems.lua`), Electronics (`electronics.lua`) and Foundry (`foundry.lua`) were written by newcomers in earlier playtests and earned the most when raced against the rest.

## Appendix A: When Your Script Fails

When your script can't go on, your drone prints `error:` and Lua's message on its page, with the line of your script where it happened (`script:12:` is line 12). An error while the script loads stops the drone before `launched` starts; an error in `launched` crashes it, and it restarts (see [When `launched` crashes](#when-launched-crashes)). The Test button and the Release page catch syntax errors, and names you use that nothing defines, before your script runs.

The common errors:

| Message | What it usually means |
|---|---|
| `attempt to call a nil value (global 'crowed')` | A function that doesn't exist: a typo (`crowed` for `crowd`), the wrong case (`Mine` for `mine`), or a name from another language |
| `attempt to call a number value (global 'best_bid')` | Your script set one of the game's names to a number, then something called it. Rename your variable |
| `attempt to perform arithmetic on a nil value (global 'misses')` | A variable that was never set. Set it at the top of the script |
| `attempt to index a nil value (local 'row')` | Reading from a row that isn't there: an empty query result (`ask(660)[1][1]` when there are no wrecks). Check the row first |
| `attempt to compare number with nil` | One side of `<` or `>` is a variable that was never set, or a function that returned nothing |
| `attempt to concatenate a nil value` | One of the pieces joined with `..` is `nil` |
| `bad argument #2 to 'perform' (number has no integer representation)` | A fraction where the game wants a whole number, such as `wait(n / 2)`. Use `//` |
| `bad argument #2 to 'perform' (number expected, got nil)` | A game function was given a variable that was never set |
| `not enough memory` | The drone's Lua went past its 256 KB, usually a table that grows every round |
| `the script's top level ran more than 2000000 instructions` | The script's top level did real work. Put work in functions that `launched` calls |
| `attempt to yield from outside a coroutine` | An action (mining, travel, trading, waiting) at the script's top level. Actions belong in `launched` and the functions it calls |

An action that fails is not an error: it returns a negative code (see [Issuing Commands](#chapter-4-issuing-commands)), and your script carries on. Check the result, and decide what to do next.
