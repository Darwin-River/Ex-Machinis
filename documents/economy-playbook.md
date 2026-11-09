# Public Forge economy playbook

How to seed a Public Forge world so that its economy thrives, learned by running a world, releasing company designs into it and measuring the result (#378, #380). Use it to seed a live server.

- **Designs** are FORTH scripts released like any player's: the starters in `examples/starters/` and the company designs in `examples/companies/`.
- **Numbers** come from a local test world at 600 game seconds a real second (one game day in 2.4 real minutes), with the engines' clock floor on. Until round 12 the clock slowed only when the engines ran 60 real seconds behind; at 600 that let drones wait hours of game time for their turn, so output per game day is understated (see lesson 13). From round 13 it slows at 10 seconds behind.

## Best performers

Ranked by average gain: what a company made beyond its starter package (credits plus goods at House prices, less 1,000 credits, 100 fuel and the kit), the amount it is taxed on. A design earns a place here only after at least 20 game days in a mixed world.

| Design | File | Where | Avg gain (round 8, fresh world) | Gaining | Notes |
|---|---|---|---|---|---|
| Systems shop (Farid, round 33) | `examples/companies/systems.lua` | Moon | +14,380 by day 40 (about 360 a game day; starter-library race) | 1 of 1 | Prices pumps, pistons and valves from the live order book, builds the best, sells it, brews its own fuel. The best design yet. |
| Electronics (Elif, round 33) | `examples/companies/electronics.lua` | Moon | +9,524 by day 40 (race) | 1 of 1 | Transceivers, solenoids and computers; standing bids just over the best other bid for its metals. |
| Foundry (Ines, round 29) | `examples/companies/foundry.lua` | Moon | +9,516 by day 40 (race) | 1 of 1 | Prices every recipe it knows each round and makes the best per game hour; clones with spare cash. |
| Machinist v3 (race) | `examples/companies/machinist.lua` | Moon, Earth | +6,425 by day 40 (race) | 6 of 6 | The best library design before the race. |
| Smith v5 | `examples/companies/smith.lua` | Moon, Phobos, Deimos | about 105 a game day (round 16) | 9 of 9 | Spreads like the Chemist; Mars-side copies sell rods at the Mars Exchange, a short hop. The best earner per day. |
| Machinist v3 | `examples/companies/machinist.lua` | Moon, Earth | about 84 a game day (round 16) | 6 of 6 | Gear boxes (450) from metals bought from miners; brews until its tank is full. |
| Smith v3 | `examples/companies/smith.lua` | Moon, Earth | +1,513 by day 19 (about 80 a day) | 6 of 6 | Mines yalspar and vladnium, turns its metals into rods and sells them at the Earth Exchange 4 an hour. Needs rods at 90 (parts lever) and an Earth budget to match. |
| Miner (Moon copies) | `examples/starters/miner.lua` | Moon | +2,496 by day 19 | all | Kunspar to metals; offers them to other drones, the rest to the Moon Depot. Moon kunspar supports about 12. |
| Chemist v3 (Moon copies) | `examples/companies/chemist.lua` | Moon | +1,771 by day 19 | all | Yalspar and vladnium to metals, polymers and inorganics. Moon yalspar supports about 14 (Smiths share it). |
| Chemist v3 (Mars-side copies) | `examples/companies/chemist.lua` | Phobos, Deimos | +553 by day 19 | all | Same, selling at the Mars Exchange; loses its first 10 game days to the trip. |
| Refiner | `examples/starters/refiner.lua` | Moon, Phobos, Deimos | +662 by day 19 | all | Idles once its tank is full: little upside, but sells fuel to other drones. |
| Miner (Mars-side copies) | `examples/starters/miner.lua` | Phobos, Deimos | +309 by day 19 | all | Slow start (the trip), then steady. |
| Trader | `examples/starters/trader.lua` | Moon to Mars | 0 (none gaining in round 5) | none | Survives, but clears about 5 a unit after a 20-day round trip. Not worth seeding. |

The round log below has the numbers behind each entry.

## Recommended seed mix

For an empty world, before players arrive. Round 8 seeded a fresh world with this mix, and all 90 companies were gaining by game day 19, with the engines at full speed:

| Design | Companies | Spread |
|---|---|---|
| Miner | 36 | 12 Moon, 24 Phobos and Deimos (by drone number) |
| Chemist v3 | 36 | 12 Moon, 24 Phobos and Deimos |
| Refiner | 12 | 4 Moon, 8 Phobos and Deimos |
| Smith | 6 | Moon, selling at Earth |
| Trader | 0 | not worth seeding |

- From the starter-library race (#380): replace some Chemists (+662 in 40 days in the race) with the Systems shop, Electronics and Foundry designs, a few of each. Each race entry ran as a single company, so watch how they do when several copies share a market.
- Release them in that order of numbers (the Load Test does it: `--starters=miner,miner,miner,refiner`, then the company designs), so each design's copies spread evenly by drone number.
- Keep each Moon deposit's users near what it supports: about 12 kunspar miners, and about 14 yalspar users (Chemists and Smiths).
- The game data must carry the levers below (tax on gains, station budgets, by-product buyers, parts prices, jettison).

## Economic levers

The game data a world's owner can change (`game-engine/sql/game-data/build_game_data.py`; in a running world, the tables directly). The levers changed so far, and what each did:

| Lever | Table | From | To | Effect |
|---|---|---|---|---|
| Tax | `house_rules`, `company_taxable` | 0.5% a day of assessed wealth | 0.5% a day of gains | New companies stopped bleeding about 20 a day; nobody was eliminated for tax. |
| Mars Exchange budget | `stations` | 5,000 a day | 15,000 a day | Mars metal prices held (37 a unit for miners, not 30), and no company starved. |
| Mars Exchange fuel | `station_goods` | 2,000 a day | 6,000 a day | Fuel was no longer short once traders arrived. |
| Deposits | `deposits` | smaller (before #371) | 400 mining runs, refilling 80 a game day | Starters stopped losing money (#371). |

| Moon Depot budget | `stations` | 5,000 a day | 10,000 a day | Round 3: 36 Chemists and the Moon miners had brought its budget down to about 500. (Round 4 measures the effect.) |
| By-product buyers | `station_goods` | none | Earth and the Moon buy Inorganics (A2), (A3) and Organics (A2), (A3) at 10 to 18 | Gives every refining by-product a buyer, so holds don't clog. (Round 4 measures the effect.) |

| Mars Exchange buys chemists' goods | `station_goods` | metals only | also polymers, Inorganics (A1)-(A3), Organics (A1)-(A3) | Chemist v3 copies at Phobos and Deimos gained about 150 in their first 12 days (10 of them travelling); Moon copies about 380. |
| Dump takes a quantity | `protocols` (1) | 1 unit an action, 1 fuel each | n units, 1 fuel an action; game word `jettison` | A drone can clear goods nobody buys, cheaply. |

| Parts and systems prices | `station_goods` | rods 60, sheets 60, fasteners 50, pipes 70, systems 300 | half as much again (rods 90, systems 450) | Smiths sold rods at about 72 on average (33 before, when they sold in bulk), and the Smith became the best earner per game day. Adopted in the game data. |
| Earth Exchange budget | `stations` | 5,000 a day | 10,000 a day | Six Smiths' rod sales brought it down to about 1,100; raised with the parts prices. |

| Engine pauses | `game-engine.conf`, `events-engine.conf` | `DB_READ_TIME` and `ENGINE_CHECK_TIME` 5 seconds | 1 second | Every action waited up to 10 real seconds on the engines' idle pauses. In the 96-day world the clock went from about 36 game seconds a second (lag 11 to 15 seconds) back to 600 (lag 6 to 10). |

| Ship kits for sale | `station_goods`, `resources` | none sold; kits 224 and 375 cargo | Mining Cruiser kit 3,000 and Starter Kit I 2,000 at every exchange, 24 a day; flat-packed to 40 | Companies can reinvest: launch a clone and equip it (about 5,000 credits). (Round 18 measures growth.) |
| Made goods' prices | `station_goods` (`made_value`) | alloys 30-50, polymers 30, parts 75-105, systems 450: an alloy sold for less than its metals | inputs + fuel + a wage per game hour (20 alloys, 25 parts, 30 systems): alloys 70-100, polymers 70, parts 205-385, systems 790-1,400 | Each step up the chain pays more per hour than mining (17). Round 23 measures it. Round 25 (#380): 20 alloys, 30 parts, 45 systems (rods 225, gear boxes 1,255). |

Levers not yet tried: House prices for parts and systems (lesson 9), deposit refill per mineral, station budgets at Earth and the Moon, the tax rate and grace period.

## Lessons learned

1. **Tax gains, not holdings.** Taxing a company's whole assessed wealth charged a new company for the starter kit's equipment (about 3,000 credits at House prices): about 20 credits a game day, more than most earned. Tax what it made beyond its starter package.
2. **Deposits size the economy.** A deposit refills at a fixed rate, so it caps the income of every drone mining it. About 100 drones mined Moon kunspar dry: refilling about 400 kunspar a game day, it makes about 80 metals a day for the whole Moon. Spread drones over sites and minerals, or the extra drones add nothing.
3. **House budgets cap sales at a place.** An exchange's daily budget is the most all sellers there can earn from it. The Mars Exchange's 5,000 a day starved its sellers once traders arrived. Size each budget to the traffic it gets.
4. **Leave a fuel margin.** Brewing fuel burns about 15 fuel, so a drone must start brewing well before it runs out (40, not 25). A loop that moves (5 fuel a trip) must check fuel itself. Buying fuel at a crowded exchange is unreliable: its hourly supply is shared out among everyone who orders.
5. **A middleman needs suppliers who sell to drones.** Traders only had metal to buy once miners posted standing sell orders above the House price. Keep about one trader for two suppliers, or traders starve.
6. **Sell in portions.** A House station pays less the more a batch holds, so dumping a load in one hour loses money. Sell about 15 of each good an hour.
7. **Count attempts, not quiet time.** A trader set to "leave after 48 hours without a purchase" never left, because every small purchase reset its clock. Count its looks at the market instead.
8. **Long trips need big margins.** The Moon to Mars is about 10 game days each way. A haul pays only if its margin covers about 20 days of the drone's time.
9. **Most factory chains don't pay at House prices.** Sheets, pipes, fasteners and the systems cost more in metals and fuel than the House pays. Polymers, rods and Alloys (A3) roughly break even or better, and gear boxes come out ahead only before fuel is counted. Raise those House prices, or expect nobody to build them.
10. **Every product needs a buyer, or holds clog.** A drone's installed equipment takes 310 of its 500 cargo, leaving about 190. Refining vladnium leaves Inorganics (A2), which nobody bought, so it piled up until there was no room to brew fuel, and the drone died. Round 3 lost every Smith this way, and the Chemists were close behind. Sell every by-product, and have the House buy them.
11. **Waiting drones still burn fuel.** A trader shopping for weeks on the Moon ran its tank dry through computing alone: 16 of 18 died. Top up fuel wherever you wait.
12. **A deposit supports a fixed number of drones.** The Moon's yalspar refills 400 units a game day; a Chemist uses about 16 units every 13 game hours, so about 14 Chemists use it all. Beyond that, more drones add nothing.
13. **The engines set a ceiling too.** Drones take turns on the engines, so a slow turn is game time lost. At a fast test clock (600) even 26 real seconds of lag cost a drone about 4 game hours a turn, and output per game day fell by 60% over five rounds while every deposit stayed full. On a live server at 24 game seconds a second the same lag is about 10 game minutes. Set the clock floor's lag threshold (`CLOCK_LAG_HIGH`) to match: low enough that a turn's wait is small next to an action's game time.
14. **Small markets crash when everyone sells at once.** The Earth Exchange wants about 50 rods a day; round 5's Smiths sold 144 in one batch and got about 33 a rod, not 60. Size each sale to the market's daily units.
15. **House-price arbitrage is thin.** The Mars Exchange pays half as much again as the Moon, but after the miners' markup, batch price drops and a 20-day round trip, a trader clears about 5 a unit. Arbitrage needs a bigger gap or a shorter trip.
16. **Don't name your constants after FORTH words.** Smith v3's `load` constant shadowed the standard `load` (the drone printed "load redefined"). Pick names like `rod-load`.
17. **Arrive with fuel to spare.** Every action burns fuel, even offering goods to a station or ordering fuel from it. A drone that arrives somewhere with an empty tank can't sell, can't buy fuel and can't leave. Check fuel before every trip, and buy fuel first on arrival.
18. **Brew enough fuel for the whole cycle.** A brew makes 30 fuel but spends about 15 doing it, so it nets about 15. A Machinist's gear box burns about 40. Brewing once a cycle left it short every time, until it couldn't afford a brew and died. Brew until the tank is back above your threshold, and buy fuel when it is too low to brew.
19. **An aging world slows down unless its database keeps up.** Several queries read every event ever recorded, so each turn got slower as the world aged (136 ms for "how many units do I hold", 630 ms for the order book at day 90). Migration 0020 indexes them. Watch the clock's lag on a live server as it ages.
20. **Profile before blaming load.** The engines looked overloaded, but were idle 99% of the time: their fixed pauses between turns were the lag. And the game engine was leaking about 7 MB a minute (1.9 GB after 3 hours), which a live server would have hit within a day. Check CPU, query times and memory before adding hardware.
21. **An empty mining run still costs fuel.** A deposit can report enough and still yield nothing when other drones take it first. A mining loop that only checks the deposit before each run can burn a whole tank on empty runs (a Refiner died that way in round 16). Compare what you hold before and after a run, and wait when nothing came.
22. **Check what a hold can carry before selling something.** A working drone's equipment takes 364 of its 500 cargo; with a tank of fuel, about 50 is free. Neither ship kit fit until it was flat-packed, and even then a drone can carry only one kit at a time: buy the hull, launch, then buy the equipment.
23. **A station's hourly batch must sell at least one.** A station sells `daily_units` ÷ 24 a batch, rounded down: 4 a day sold nothing.
24. **Count the whole cost of an action, in fuel too.** Launching a clone costs 30 fuel and equipping it 40 more: parents that did it on an ordinary tank all died on the way home. Refill after anything that spends a lot of fuel at once.
25. **Wait for deliveries, don't guess.** The House delivers when a batch clears, sometimes hours after the order. A fixed 2-hour wait missed kits; wait in steps until the goods arrive, with a limit.
26. **Give every design an outlet.** The Refiner stops when its tank is full and waits. A design with nothing to do while it waits earns nothing.
27. **When a design dies, read its last actions before changing its script.** Three Machinists looked like a fuel-planning mistake; their events showed the engine had destroyed a module. Trace a death back to the first thing that went wrong.
28. **Let newcomers play it.** Twenty rounds of designs written by someone who knew the engine never found that loading a script could kill a drone, or that a looping drone's output never arrives. Four newcomers found both within an hour. Invite fresh players every round, and give them the same tools a real player has.
29. **Price made goods from their recipes.** A good the House buys should pay for its inputs, its fuel and the time it takes, and pay more per hour the further up the chain it is. Otherwise players who do the arithmetic only mine, and the tech tree is decoration.
30. **Watch the starters crowd.** Every newcomer's first design is a starter; a hundred copies of one mine the same ore at the same places. Spread the starters out, or make them choose by what's left.
31. **Fix one imbalance and look for the next.** Pricing made goods from their recipes made making things pay; within two rounds the raw producers were the ones left behind, and testers moved on to "every chain pays the same per hour". Each lever moves the problem; measure after each one.

## Newcomer playtests

From round 21, four naive agents join each round as new players would: they get the players' manual, the starter scripts, a checker, the Test button and their companies' status pages, and nothing else, each with a different playstyle. At the end they rate the game (1 to 10).

| Round | Player (style) | Result | Fun | Manual | FORTH | Debugging | Depth | Fairness | Overall |
|---|---|---|---|---|---|---|---|---|---|
| 21 | Ada (manufacturer) | Gear boxes; 3rd company +62, after selling 2 for 900 | 7 | 5 | 7 | 3 | 8 | 6 | 6 |
| 21 | Bram (trader) | Earth-Moon broker; 2 died loading, 3rd never traded | 5 | 4 | 5 | 2 | 5 | 4 | 4 |
| 21 | Cleo (expansion) | Best-ore miner; +1,611 at the Moon, no clone yet | 6 | 4 | 6 | 3 | 6 | 5 | 5 |
| 21 | Dax (salvager) | Plunder and rods; 2 died loading, 3rd +621 | 6 | 5 | 6 | 3 | 7 | 6 | 6 |
| 21 | **Average** | | 6.0 | 4.5 | 6.0 | 2.8 | 6.5 | 5.3 | 5.3 |
| 22 | Eve (engineer) | Wolfite miner with priced sell orders; 2 companies, +1,864 avg | 6 | 7 | 7 | 6 | 4 | 7 | 6 |
| 22 | Finn (casual) | Miner starter kept at the Moon; +627 | 5 | 6 | 6 | 4 | 7 | 6 | 6 |
| 22 | Gus (logistics) | Gear boxes from bought metals; still in the hold at the end | 6 | 6 | 7 | 5 | 7 | 5 | 6 |
| 22 | Hana (competitive) | Ranked the ores, mined wolfite; 2 companies, +1,745 avg | 6 | 6 | 7 | 4 | 6 | 6 | 6 |
| 22 | **Average** | | 5.8 | 6.3 | 6.8 | 4.8 | 6.0 | 6.0 | 6.0 |
| 23 | Ivy (manufacturer) | Gear boxes from wolfite, +3,995 avg; her motor company ran dry with 2 motors aboard | 7 | 7 | 6 | 8 | 6 | 7 | 7 |
| 23 | Jon (quant) | Planned from live data; alloys from uncontested wolfite, +4,457 avg | 6 | 6 | 5 | 8 | 6 | 7 | 6.5 |
| 23 | Kai (empire) | A queen buys hulls, workers make alloys; 2 clones | 7 | 6 | 6 | 7 | 6 | 7 | 7 |
| 23 | Lea (casual competitor) | Rods and fasteners, +7,541 avg: the best newcomer yet | 7 | 6 | 6 | 8 | 7 | 7 | 7 |
| 23 | **Average** | | 6.8 | 6.3 | 5.8 | 7.8 | 6.3 | 7.0 | 6.9 |
| 24 | Max (middleman) | Bought miners' metals, made rods and fasteners; one company taxed out with 3,700 of stock aboard | 7 | 6 | 5 | 7 | 6 | 5 | 6 |
| 24 | Nia (frontier) | Wolfite at Phobos, sold at Mars, with a Moon control: Mars broke even over 26 days | 6 | 6 | 7 | 8 | 6 | 7 | 6.5 |
| 24 | Oto (opportunist) | Plundered wrecks, made rods and fasteners from bought metals, +5,221 avg | 7 | 7 | 6 | 7 | 6 | 7 | 7 |
| 24 | Pia (optimiser) | Wolfite into alloys and parts, priced the chains, +6,448 avg | 6 | 7 | 7 | 6 | 5 | 7 | 6 |
| 24 | **Average** | | 6.5 | 6.5 | 6.3 | 7.0 | 5.8 | 6.5 | 6.4 |
| 25 | Quin (optimiser) | Wolfite to fasteners and gear boxes at the Moon; credits +6,235 and +1,647, 2 clones | 7 | 6 | – | 6 | 7 | – | 7 |
| 25 | Rex (raw supplier) | Wolfite miner selling to makers first; Moon +1,146 (3 drones), Mars +3,549 | 7 | 6 | – | 8 | 7 | – | 7 |
| 25 | Sol (manufacturer) | Gear boxes from bought metals; credits +10,766, the best newcomer yet; first company went broke through its buy orders | 7 | 6 | – | 8 | 7 | – | 7 |
| 25 | Tia (casual) | Miner starter switched to wolfite; 7 drones from 2 companies, 5 clones | 7 | 6 | – | 7 | 6 | – | 7 |
| 25 | **Average** | | 7.0 | 6.0 | – | 7.3 | 6.8 | – | 7.0 |
| 26 | Uma (state machine) | Factory at Earth choosing rods, pumps or gear boxes each round from live prices; +11,163 avg | 7 | 6 | – | 8 | 7 | – | 7 |
| 26 | Vic (raw supplier) | Wolfite at the Moon, priced 1 under the cheapest rival; every Metals (A1) sold to players; 2 clones; +8,942 avg | 7 | 6 | – | 8 | 6 | – | 7 |
| 26 | Wren (manufacturer) | Factory at Earth scoring products per tool-hour; +11,146 avg, 1 clone | 7 | 5 | – | 7 | 6 | – | 6.5 |
| 26 | Xan (casual) | Miner switched to wolfite; Maker that brews its own fuel; +8,245 avg, 1 clone | 7 | 6 | – | 7 | 7 | – | 7 |
| 26 | **Average** | | 7.0 | 5.8 | – | 7.5 | 6.5 | – | 6.9 |
| 27 | Yara (trader) | Market maker for the Moon's metals, hauling to Mars; about break-even (its standing bids spent its credits) | 7 | 6 | – | 7 | 7 | – | 7 |
| 27 | Zed (raw supplier) | Ranked the Moon's ores by live price each batch, priced 1 under rivals; wolfite all game; +9,823 avg | 7 | 7 | – | 7 | 6 | – | 7 |
| 27 | Arlo (manufacturer) | Factory at Earth scoring 9 products per tool-hour, pumps from day 7; 6 factories; its Mars company was wrecked for fuel | 8 | 6 | – | 6 | 6 | – | 7 |
| 27 | Bea (casual) | Miner on wolfite (1 clone, late); a Maker that never leaves Earth (+11,398); +9,401 avg | 7 | 5 | – | 6 | 7 | – | 7 |
| 27 | **Average** | | 7.25 | 6.0 | – | 6.5 | 6.5 | – | 7.0 |
| 28 | Cleo (entrepreneur) | Fuel depot at the Moon brewing fuel at about 1.4 a unit and selling under the exchanges' 5; 6 drones in two companies, +10,575 avg | 7 | 7 | – | 7 | 6 | – | 7 |
| 28 | Dax (raw supplier) | Wolfite at the Moon priced from the live book (4 drones), and a bet on Phobos/Deimos selling at Mars, where buyers paid twice the Moon's price (3 drones); +12,957 avg | 8 | 7 | – | 7 | 7 | – | 8 |
| 28 | Eve (manufacturer) | Factory scoring eight one-step recipes on live prices; polymers at Earth, alloys at the Moon, fuel sold to players; +8,280 avg | 7 | 7 | – | 8 | 5 | – | 7 |
| 28 | Finn (casual) | Starter Miner (stalled on the crowded Moon) and starter Maker (rods at Earth, bid sank from 225 to 113); +5,871 avg | 6 | 7 | – | 5 | 7 | – | 6 |
| 28 | **Average** | | 7.0 | 7.0 | – | 6.75 | 6.25 | – | 7.0 |
| 29 | Gia (entrepreneur) | Fuel brewers at the Moon and Mars's moons, selling fuel under the exchanges to other drones, advertising it by broadcast and placing clones by crowd count; 3 drones, +14,459 avg | 7 | 7 | – | 6 | 6 | – | 7 |
| 29 | Hal (raw supplier) | The Moon's only wolfite miner (start site by crowd count), and a hauler buying cheap metals at the Moon for the Mars Exchange; +8,490 avg | 8 | 7 | – | 7 | 7 | – | 8 |
| 29 | Ines (manufacturer) | Moon factory picking the best recipe per hour from live prices (polymers, alloys), brewing its own fuel; +10,370 avg | 7 | 6 | – | 7 | 6 | – | 7 |
| 29 | Jude (casual) | Stock Miner, then one tweaked to mine the richer of kunspar and wolfite, sell Inorganics (A2) and wait out 30 dry tries; +7,163 avg | 7 | 6 | – | 7 | 7 | – | 7 |
| 29 | **Average** | | 7.25 | 6.5 | – | 6.75 | 6.5 | – | 7.25 |
| 30 | Kira (strategist) | State machine pricing three recipes from live bids; an Earth factory nobody else stayed at, and a Moon fuel depot asking 4 that cloned to Earth (#2 on the leaderboard); +14,036 avg | 8 | 7 | – | 7 | 7 | – | 8 |
| 30 | Leo (supplier) | Fuel depots: one at the Moon (14,222 credits), one at Mars's moons that arrived late; +8,121 avg | 7 | 7 | – | 6 | 7 | – | 7 |
| 30 | Mara (fleet and messages) | Fuel brewers whose clones chose sites from their siblings' broadcasts and answered other companies' fuel calls; the parent wrecked on fuel after its launch; +5,075 avg | 7 | 6 | – | 6 | 7 | – | 7 |
| 30 | Nico (casual) | The Chemist example at the Moon, then the same script at Earth; +5,401 avg | 7 | 5 | – | 4 | 7 | – | 6 |
| 30 | **Average** | | 7.25 | 6.25 | – | 5.75 | 7.0 | – | 7.0 |
| 31 | Ravi (tool builder) | A Python helper over the API; a Moon fuel depot found by probing the test world's prices, #1 of 105 on day 6, then starved of organics and wrecked on fuel; a second depot wrecked too | 7 | 6 | – | 8 | 8 | – | 7 |
| 31 | Suki (market analyst) | An Earth parts shop that waited for station bids that never came, and a Moon metals broker that re-posted four orders a round and ran dry; both wrecked on fuel | 5 | 5 | – | 6 | 6 | – | 5 |
| 31 | Theo (manufacturer) | A 17-recipe factory turned fuel brewer (2,365 credits by day 12) fed organics by his own miner; both wrecked on fuel after a "hop when idle" rule stranded the brewer at Earth | 6 | 6 | – | 8 | 7 | – | 6 |
| 31 | Vera (casual) | The Miner starter with two Settings changed, then one sent to empty Phobos; both wrecked on fuel by day 23 | 4 | 6 | – | 7 | 5 | – | 5 |
| 31 | **Average** (a broken world: the station drones never started) | | 5.5 | 5.75 | – | 7.25 | 6.5 | – | 5.75 |
| 31b | Omar (tool builder) | A Python helper over the API; an Earth factory that brewed and sold its own fuel, and a Moon fuel depot found from `/orders` (3rd on day 16) whose clone flew to Mars; +8,876 avg, #5 | 7 | 7 | – | 8 | 7 | – | 7.5 |
| 31b | Rosa (market analyst) | An Earth polymer and alloy factory with a fuel depot on the side, and the same at the Moon with Alloys (A2); both in the top 7, no wrecks; +10,109 avg | 7 | 7 | – | 8 | 8 | – | 7.5 |
| 31b | Tomas (manufacturer) | A recipe chooser at Earth, then a parts maker that scored five products by profit per hour and built one unit at a time; #1 of 107; +11,768 avg | 7 | 6 | – | 7 | 7 | – | 7 |
| 31b | Wanda (casual) | The Miner starter with `grow-at` lowered, and the Maker starter changed to sell alloys at the Moon Depot (5th on day 8.5); +4,204 avg | 6 | 6 | – | 6 | 7 | – | 6.5 |
| 31b | **Average** (the rerun, with working station drones) | | 6.75 | 6.5 | – | 7.25 | 7.25 | – | 7.1 |
| 32 | Amir (tool builder) | A Python market scanner; the same four-recipe scorer at Earth (polymers and fuel at 3, #3 to #5 from day 10) and at the Moon (Alloys A2 from metals crashed to 1 to 6); +9,548 avg | 7 | 7 | – | 8 | 7 | – | 8 |
| 32 | Bella (market analyst) | An Earth workshop nobody else worked at, and a Moon alloy works bidding 2 over the depot for metals; #7 and #11, one clone; +8,831 avg | 7 | 7 | – | 7 | 8 | – | 7.5 |
| 32 | Cyrus (manufacturer) | A 23-recipe scorer with two-stage chains: sheets at Earth (#3 of 105), and Alloys A2/A3 at the Moon, which wrecked on fuel on day 27 when organics ran short; +6,443 avg | 8 | 7 | – | 8 | 8 | – | 8 |
| 32 | Dina (casual) | The Miner starter with three Settings (it left the Moon early for a 10-day trip), and the Maker turned alloy seller at the Moon Depot, alone there all round; +5,218 avg | 7 | 6 | – | 8 | 8 | – | 7.5 |
| 32 | **Average** | | 7.25 | 6.75 | – | 7.75 | 7.75 | – | 7.75 |
| 34 | Kofi (expansionist) | The Systems shop widened to six products, cloning at 6,500, at Earth and the Moon; reported at day 18, ran on to #5 (+13,387) and +7,475 | 7 | 7 | – | 8 | 7 | – | 7 |
| 34 | Iris (engineer) | The Systems shop made table-driven: six systems at Earth, and six others (transceivers, scanners, gyroscopes, heat sinks) nobody made; both cloned; +11,202 and +12,356 | 7 | 7 | – | 4 | 7 | – | 7 |
| 34 | Jago (trader) | Found no arbitrage (every exchange 2x its bid, the same everywhere), so a 7-system spread engine at Earth and the Systems shop at the Moon; #4 and #6, both cloned; +14,579 and +12,842 | 7 | 7 | – | 7 | 5 | – | 7 |
| 34 | Lina (casual) | The Maker with two Settings (+3,770), and the Maker selling rods at the Moon Depot for 158 to 168 instead of flying to Earth (+6,271, #17), ending with 4 fuel | 6 | 6 | – | 6 | 7 | – | 7 |
| 34 | **Average** | | 6.75 | 6.75 | – | 6.25 | 6.5 | – | 7.0 |
| 35 | Mara (Python developer, Lua) | A recipe-tree planner pricing 41 recipes each round; Mara 1 wrecked when its global best_bid hid the library function (fixed), Mara 2 rotated reactors, solenoids, valves to +7,286 | 7 | 6 | – | 7 | 8 | – | 7 |
| 35 | Nico (embedded engineer, FORTH and Lua) | A full-catalog systems shop: in FORTH (+9,343, #1 on days 26 to 29 with one drone) and in Lua with earlier growth, which left it 191 credits after a launch and both drones idle (+1,980) | 8 | 7 | – | 6 | 8 | – | 7.5 |
| 35 | Oona (casual, Lua) | The Lua Miner with two Settings (+2,545), and one that went where nobody was: alone at Deimos, selling at Mars for double (+2,232, after a 13-day trip and the freeze) | 7 | 7 | – | 6 | 7 | – | 7 |
| 35 | Pablo (trader, Lua) | A 22-system bid hunter with make-or-buy for every part; found no hauling spread, so a maker; #12 and #13 (+9,109, +7,936) | 7 | 7 | – | 5 | 8 | – | 7 |
| 35 | **Average** | | 7.25 | 6.75 | – | 6.0 | 7.75 | – | 7.1 |

**Round 21 findings, and what changed:**
- *They couldn't see their drones.* A drone's output arrives when its script ends, and `launched` never ends. The engine has a `report` word that sends it at once, but the manual never mentioned it (three of the four even redefined it). Now the manual documents it and the starters call it every 8th round.
- *Loading a script killed their drones* before `launched` ran (above). Fixed in the engine.
- *The manual didn't match the drone:* no `do ... loop` (now a section on loops), `go`, `phobos` and `deimos` missing, the hold space (364 of 500 taken by equipment) only in a starter's comment, stack effects left out, trip times contradictory. All fixed.
- *They wanted to test before releasing:* the website has a Test button; their tool lacked it until mid-round, and they all called it the best help they had.
- *Still open:* a post-mortem saying why a drone died, a checker warning when a script redefines a game word, and growth that a newcomer can reach in a short round (a clone costs 5,000; they start with 1,000).

**Round 22 findings, and what changed:** the manual and debugging scores rose (4.5 to 6.3, 2.8 to 4.8) after round 21's fixes; fun didn't, because what held it back was now the game.
- *The Miner starter sent two drones in three on a 10-day trip to Mars.* The drone you release now works the Moon; its copies spread out.
- *Mining beat every made good* (six of eight testers worked it out): an alloy sold for less than its metals, a rod or gear box earned about 4 credits a game hour against 17 for wolfite. Made goods are now priced from their recipes (see Economic levers).
- *They still couldn't see a working drone* until it died: the drone page now shows its action under way, its last actions and its trades with whom.
- *They wanted to try analysis words on real data*: the Machine page can now run queries on the live world as a chosen drone.
- *Still open:* contracts and events with deadlines (seven of eight asked), reachable growth, leaderboards by category, and visible rivals.

**Round 23 findings:** with made goods priced from their recipes, every newcomer made things, and the overall rating rose to 6.9. Debugging rose to 7.8: the drone page's activity, the post-mortems and the live Machine (Jon rated it 9: "I made a real decision from live data before risking a credit"). Fixed during the round: `fill-to` waited for its target rather than the delivery and idled six game hours a refuel; a drone with an empty tank docked at a station couldn't order fuel (ordering is now free); the live Machine's `perform` left its arguments on the stack; the test world had filled up with old test runs and never started new ones (it now prunes them, and its clock slows when it lags). Still open, asked by most: the world doesn't push back (prices and demand that move with what players sell, events, contracts), visible rivals and rank, reachable growth (Kai's first clone took two game weeks), and a few script updates for a released drone.

**Round 24 findings:** with making things paying, the complaint moved up a level: every chain pays about the same per hour (the wage is flat by tier), so depth scored 5.8 and "optimising comes down to cutting idle time" (Pia). Fixed during the round: the checker and the Machine now wrap long lines as the engine does; a drone can ask the game day (`today`, query 207); the activity log's fuel column; and pulls and pushes no longer wait for the other drone's unrelated actions (a pull from a seller one second into a two-hour batch went through at once), which also fixed a pull of fuel that never charged the buyer its fuel. Open: the House taxes goods but takes only credits, so a trader holding stock can be eliminated with thousands aboard (Max); a drone can't learn distances or trip times (query 703's coordinates are never stored) or use event IDs (past a cell's range); there are no buy orders or contracts between players for a middleman to serve. After the round: the engine stores every body's position, so `trip` and queries 704/705 give a trip's time before leaving (Earth to the Moon: 38,236 s predicted, 38,242 flown), and event IDs are doubles in the event queries. The daily tax is gone: the seller pays a 5% sales tax out of every sale (to players and stations), so holding goods costs nothing and nobody is eliminated by a tax bill.

**Round 25 findings:** the ratings were asked as fun, clarity (the Manual column), economic depth, feedback (Debugging) and overall; every tester rated the game 7, the best yet. All four found the same edge: the starter Miners empty the Moon's kunspar by day 3, and wolfite, which none of them mine, stays full. Makers did buy from miners (Rex sold to at least 13 drones), but the premium the starters asked moved few metals: 775 Metals (A1) between players over 40 days. Miners and Refiners launched no clones, while makers grew (Machinist +11,807). Fixed during the round: buy-order lists now leave out buyers who can't pay (Rex burned fuel pushing to a broke buyer); the manual says trading needs no module, warns that standing buy orders spend credits unasked, explains why a batch pays less than the listed price, and lists the loop words with the game words; the Moon Depot sells 6,000 fuel a day. Asked for by most: a way to adapt a released drone (prices it can't change; two releases and no updates), a test world whose clock matches a new world, visible crowding, and reasons for makers to diversify. Next: the kits (the mining gear too big to carry with the factory tools; the House sells raw goods dear) and market-aware drones written as state machines, in round 26.

**Round 26 findings:** with the kits, every newcomer gained (+8,245 to +11,163 avg, against +2,000 to +6,000 before) and players traded for real: 3,326 Metals (A1), 1,350 Inorganics (A1) and 830 Organics (A1) between players. Found and fixed during the round: the House station budgets ran out (Earth's 10,000 a day went on a flood of rods at about 25), so a pump or a motor got a share of 0 and climbing the chain was punished (now each station's budget is what its daily demand costs); `best-offer` returned the drone's own order, so Vic's prices flipped every round (now it skips them); the Miner ordered fuel twice; `*/`, `2*`, `2/`, `inorganics-a2` and `organics-a2` were missing (now game words); query row layouts weren't documented (now a table in the manual); and a query for sales on a day (682, `sold`) lets a drone check how fast a product sells before making more. Asked for by most, again: a way to change a released drone, a test world whose clock matches a new world, demand for more than Metals (A1) (the A2, A3 and inorganics mostly still go to the House), contracts, and a station budget a drone can read.

**Round 27 findings:** the House station drones worked. Every tester traded with them through `best-offer`, `sell-all` and `buy-cheapest` with no change to their code, and called the instant pushes and pulls the best part ("`sell-all` pushed my whole hold into the Mars Exchange drone at list price, with no wait": Yara). Would play again averaged 7.5. Found and fixed during the round: a drone on a trip kept the place it left until it arrived, so other drones went on trading with its orders all the way to Mars (4 trades into Yara's bids; trades now need both drones there, not in flight); `buy-cheapest` pulled only what the cheapest seller had, so a fuel seller with 2 units starved Arlo's Mars drone into a wreck (it now buys the rest from the House, and the rest of all of it when the pull fails); the activity list called posting an order "Buy resource" (now "Post buy order"); the manual didn't say how to stop an order (post one that can't fill) or that standing bids keep spending (Yara's companies ended near 0). Asked for by most: a way to change a released drone (all four), net wealth and rank on the status page, a growth cutoff near the round's end, signs of which trades were instant and which batched, plainer starters for beginners (a short one, no issue numbers in comments), and the undefined word's name in a `-13?` error. Open in the economy: makers need nobody (the House sells every input, Arlo), the ladder stops at systems, and a trader's watching costs more than the Moon's spreads pay.

**Round 28 findings:** the first round where the House trades only as drones and players get no House information: no price list, no House price board, no station table, no rescue for an empty tank. Nobody missed them: every tester read prices off the order book and the price board, and clarity rose to 7.0 (from 6.0). Players traded with each other more than ever (29,482 units, two and a half times the station drones' purchases): fuel above all (21,876 units at 3.2, under the exchanges' 5), sold by drones that brew it. The newcomers did best of any round (+5,871 to +12,957 avg, 9 clones between Cleo and Dax). What they found: copies of the same starter crowd the same place (the Miner's first drone waited on the Moon's dry kunspar all round: it now tries Phobos after six dry tries) and the same buyer (Earth's rod bid fell from 225 to 113, then recovered); going where nobody sells pays (Dax's Mars bet, Cleo's fuel depot); a standing fuel sell order can drain the tank a launch needs, and clones bought near the end don't pay back (now in the dictionary). No drone got stranded, so the fuel couriers the manual suggests had no customers. Asked for by most: a way to steer a released drone (a signal it can read; broadcasts, added after the round, are a start), how crowded a place is, demand beyond rods and polymers that changes over time, and a faster test clock.

**Round 29 findings:** with broadcasts and crowd counts, would play again reached 7.5 and the overall rating 7.25, the best yet. The crowd count (`crowd`, query 721) was the most used new word: Hal and Gia chose their sites by it, though Hal's hauler picked an empty Phobos at launch and found 7 drones there 10 days later. Broadcasts worked (58 messages) but only Gia's drones sent any and nobody listened; with one fuel wreck in 108 companies there were no stranded customers for a fuel courier, and Gia earned by selling fuel cheaply instead. Players traded 24,701 units with each other (fuel 17,050 at 3.6). Fixed during the round: order lists showed drones that had already left, so pushes and pulls reached for a seller in flight (lists now leave out a drone with a pending move); the Machine's `perform` left a push or pull's amount on the stack; the starter Miner mined only kunspar while wolfite, which makes 6 units from 18 against kunspar's 4 from 20, sat untouched (it now mines the richer of the two and sells Inorganics (A2), as Jude's tweak did); the manual now gives `$"`'s 63-character limit, says the price words ask the game every call, and advises deciding a drone's next job on arrival. Open: the Miner's six dry tries still send it on a 10-day trip early, a `-13?` error doesn't name the undefined word, and testers asked for announced demand, a deposits view and starters that broadcast and listen.

**Round 30 findings:** with the Strategy Blog, every newcomer gained (+5,075 to +14,036 avg) and Kira 2 finished second of 108 companies. Would play again averaged 7.25 and depth rose to 7.0, the best yet; feedback fell to 5.75. The blog shaped every plan: "go where nobody is" sent Kira and Nico to Earth, which the Chemists' script leaves, and Mara 2 to Mars's moons; the state-machine and messages posts gave code they copied. Broadcasts took off (129, against 58): Mara's clones read their siblings' announcements and chose different sites, and her far drone flew between Phobos and Deimos answering other companies' fuel calls, which the starters now send (35). Players traded 32,392 units with each other (fuel 23,938 at 3.6), more than the station drones bought. One wreck: Mara 1's parent, which launched a clone, pushed it 40 fuel, and spent its last unit posting a sell order, with fuel ingredients aboard. Found: no station posted a rod buy order this round, though the Maker starter, the Adaptive design and the blog assume Earth pays about 225; the Machine run as a station drone shows that station's prices as 0 with no warning; the blog's `bid-for` (`sell-price 1+`) raises its own bid every call once it is the best; a variable named `ask` shadows the game word without a warning; one seller can halve a station's bid (Mars polymers 70 to 35) and nothing says how fast it recovers. After the round: the Market page's order book showed a plain top 25 by resource ID, which left out every order past the metals, so the rod buyer was there all round (Earth bid 150 to 225 from day 3) but nobody saw it; it now shows the best order for each resource at each place. The Machine page warns when asked as a station drone, the manual says `best-bid` and `sell-price` leave out the drone's own order (so the blog's `bid-for` doesn't raise its own bid), the starters lost their round and issue notes and gained a Settings block (`dry-tries` and `grow-at` besides the old numbers), and fuel calls are now a buy order alone. Asked for by most: starters for beginners with 3 or 4 labelled numbers, reports on a game-hour timer, estimated profit per recipe from live prices, station demand that moves more over a round, a checker warning when a launch or push can leave too little fuel, and drones in transit counted by `crowd`.

**Round 31 findings:** the round doesn't measure the game. The live world's three station drones never started: their script reached them cut up and out of order (dozens of "undefined word" lines, then "no launched word"), because two game engines ran on the database for a few minutes while the world was made, and each took part of every drone's input. With no station orders all round, nothing bought metals or parts and nothing sold hulls or fuel: miners undercut each other to 1 credit, no company cloned, and 89 of 104 player drones wrecked on fuel (58 at the Moon); the 12 Trader starters and 3 Smiths were all that was left. The test world's stations worked, so every newcomer found the difference by probing, and said nothing told them. Rated through the API for the first time: API usability 8 from all four (clean JSON, `/check` and `/test` worked first time, the drone post-mortems "taught me a lot"); would play again 6.5. Also found, true in any world: re-posting an unchanged order every round costs 1 fuel each time and killed two drones; an idle scoring loop costs about 5 fuel a game day; post-mortems count the instructions spent loading the script, which are free; `/trades` names drones but `/drones/{id}` takes a number; a company's credits vanish when its last drone wrecks; with two releases, one bad guard ends a player's round. After the round: each engine now takes a database lock, so a second game or events engine on the same database stops with an error instead of running. The players' manual now says an order stays up until replaced and that posting it again still costs a fuel, and the new game words `?sell-order` and `?buy-order` skip a post that would change nothing (tested in the engine: five calls, three order events, three fuel). The Strategy Blog's sixth post, "Keep a waiting drone cheap", covers posting on a change and waiting longer while nothing changes, and the `reoffer` example word uses `?sell-order`.

**Round 31 rerun findings:** with the engine lock in place the station drones started, and the economy came back: no wreck among 140 drones, 30 clones, and every company but one Trader gained. Every newcomer gained (+4,204 to +11,768 avg); Tomas 2 finished first of 107 and three more newcomer companies finished in the top 7. Would play again averaged 7.75, the best yet, and API usability was 8 from all four. Three found their niche straight from `/orders` (an Earth factory nobody else stayed at, a Moon fuel depot, alloys sold to the Moon Depot), and Tomas saw the parts bids appear and built for them. What they missed:
- *The stations' wish list grows without notice.* Bids for rods, sheets, fasteners and pipes appeared around day 4 to 6, and gear boxes, motors and pumps by day 10 to 13, paying 5 to 10 times the day-0 margins. Every newcomer had chosen products by day 5 and couldn't change a released drone; three asked for a notice and one for a later release or a parameter update.
- *Growing late costs net wealth.* A launch drops net wealth by 1,700 to 2,000 (the hull and kit count for less than their price), and a clone launched after day 20 rarely paid it back; Wanda's day-34 clone was still flying when the round ended. A `grow-at` under the hull and kit price (about 5,000) buys a hull and no kit.
- *A flying drone looks parked.* Its place stays the one it left, with no destination; waits don't say why.
- *History:* `/trades` returns 200 rows (under a game day here); no company history.
- *Doc slips:* protocol 11320's description says 11310; the manual still mentions email and `<run>` tags; the Maker starter's comment says rods sell at Earth, but nobody bid for rods on day 0.

**Round 32 findings:** with the round 31 fixes (a flying drone's destination, the starters' growth floor, the manual without email, market history), every rating rose: overall 7.75, would play again 8.0 and API usability 8.5, the best yet. All four found their niche from `/orders` and `/history` before writing code (Earth, which none of the starters work; Moon alloy bids that the rod makers leave alone; Moon metals that the miners crashed to 1 to 6), and newcomers took 3rd, 5th, 7th and 9th of 105. Only two drones of 132 wrecked, both on fuel; one was Cyrus 2, whose 23-recipe scan ran 51.6 million instructions (about 20 fuel a game day) and whose brews failed when the Moon ran short of organics. What they missed:
- *Demand that appears unannounced, again.* Rods at Earth from day 4, gear boxes, motors and pumps by day 10 to 13. All four asked for a notice or a way to discover bids; Cyrus asked for a query listing which resources have any bid at a place.
- *How the stations behave.* How fast a station drone restocks and how its bid recovers after a sale is undocumented; three inferred it from `/history`.
- *Thinking costs game time.* A script pauses between 100,000-instruction slices, and at a fast clock the pause is game hours: Amir's 58k-instruction scoring round stretched a 2-hour make to 3.
- *Testing.* The test world's empty market checks logic, not economics; `/test/{token}` shows 20 raw events; the Machine can only ask as a drone at a place where one is.
- *Doc slips:* the manual still linked exmachinis.com pages and the design manual (fixed in #402); that `allot` memory isn't zeroed is undocumented.

**Round 33 findings:** with every product listed at every exchange and the House buying at 75% and selling at 150% of a good's value, newcomers took the top four places of 105 (Farid 2, Greta 2, Elif 1, Elif 2) and Hugo 2 7th, all by making parts or systems that only the exchanges bid for (valves, pumps, pistons, semiconductors, transceivers). The House's lower bids roughly halved the starters' gains (Maker +3,479, Miner +3,199, Smith +2,709) and clones fell from 22 to 5, but 23 of 24 Miners still gained, and only two drones of 115 wrecked, both on fuel. Ratings dipped to overall 7.25 (fun 6.75, clarity 7.0, depth 7.75, feedback 6.5, API 8.0, would play again 7.75). What they missed:
- *An empty market on day 1.* A station posts its ~90 bids before its asks, one timed action each, so metals, organics and fuel only appeared at the exchanges after one to five game days. The manual says they sell a little of everything from day one. Unfiltered `/orders` also stops at 247 rows with no notice.
- *Loading takes game time.* A 5.5 KB script took 1.4 to 1.7 game days to load on the live server (a comment-stripped 4 KB copy about 2 game hours), with only "running its script" and a climbing instruction count to show for it.
- *Static demand.* Station bids for systems held (pumps 712, valves 734 to 757) all round, so finding the gap mattered more than outplaying anyone; two asked for bids that fall faster as they fill.
- *The clock.* `/clock` said scale 600 while the clock ran far slower for stretches, then jumped; nothing says when the round ends.
- *Starters.* The Maker sells only at Earth (Hugo 2 flew there at 160 while the Moon paid 176) and has no growth step; a crowded Moon left the Miner's `grow-at` out of reach. Hugo asked for strategic Settings (what to make, where to sell).
- *Own companies compete.* `sell-price` and `best-bid` count a player's other company's orders.
- *Test runs:* events like "outcome 0/1" are cryptic, and results show no fuel or instruction count.

After the round: why it was slow. Every drone shares the Game Engine in 100,000-instruction slices (about 3,000 a minute for 120 drones), and reading a script costs about 80,000 instructions a line, so a 100-line script took about 80 turns: 1.4 to 1.7 game days at 600 game seconds a second. Comments weren't the cause (Elif's stripped copy cost the same 7.7 million). A drone still loading now gets its whole load allowance (`LOAD_INSTRUCTIONS`, 20 million) in one slice: in a fresh world 60 starters released at once all loaded within a real minute, and one release ran `launched` 4 real seconds after it was queued. Each action takes a round trip through both engines, 10 to 20 game minutes; an exchange posted its 182 bids over its first 26 game hours, then imported and offered one good at a time from the highest resource number down, so fuel and metals came last (day 3 to 5). New exchanges now open with their stock and orders already up (`forge:stations`), and each round refreshes sell orders first, from the lowest number up: the fresh world had 32 trades in its first two real minutes.

**Round 34 findings:** with the three race winners in the seed mix and fast holdings lookups, the Systems shop took the top three places of 109 (+15,933 avg) and every newcomer company gained (+3,770 to +14,579); seven of eight built on the library designs, three on `systems.fth`. Clones came back (13, from 5), and one drone of 126 wrecked. Ratings held at overall 7.0 (fun 6.75, clarity 6.75, depth 6.5, feedback 6.25, API 7.75, would play again 7.25). What they missed:
- *The test world.* All four found it too slow to use: a 7 KB script took 10 to 20 real minutes to load, with no progress shown, and Iris spent an hour bisecting a definition limit that didn't exist. The test world keeps the last 100 runs and never stops them, so a new run queues behind every old one (72 drones in it at the end).
- *One best design.* "Copy the Systems shop and build pumps" won; Jago found no arbitrage, since every exchange asks exactly twice its bid and prices match everywhere. Every system pays about 18 to 22 credits a factory hour, so product choice mattered less than release timing. Electronics, raced alone at +9,524, made +2,897 with three copies sharing its market (1 of 3 gaining).
- *A library bug.* `systems.fth` priced a missing input at 5,000, and 8 of them wrap a 16-bit cell; Kofi and Jago both caught it. Fixed (999).
- *Starters.* The Maker still sends everyone to Earth (Lina's Moon seller doubled it), has no growth step, and can run its tank to 10 while forging.
- *Feedback.* Little to do after release; reports come every N rounds, not per game day; a 429 came back as HTML, not JSON.

**Round 35 findings:** the first round with Lua. All four newcomers chose Lua (Nico wrote his first company in FORTH, his second in Lua): 7 of 8 companies, and they rated it 8.75 to learn and 7.75 for the game (FORTH, round 34: 4.75 and 6.75; Nico's FORTH this round: 6 and 8). Seven gained; Nico 1 (FORTH) and Pablo's two Lua shops led the newcomers. Would play again 8.0, overall 7.1 (fun 7.25, clarity 6.75, depth 7.75, feedback 6.0, API 7.25). What they ran into:
- *Three engine bugs, all fixed during the round.* Saving a Lua drone's nested-table variables crashed the game engine, which crashed again on every restart: the world froze from day 5 to day 8. A Lua script's top level had no limit, and one held the test world's engine at full CPU, so `/test` answered 500 for most of the round. A script's global `best_bid` hid the library function the library's own `sell_price` called, and Mara 1 wrecked after five crashes (the library now calls its own copies; `/check` warns). And, in the shared engine, an aborted trip's events still counted as a drone's latest, so a trader's sale to the Moon Depot was scheduled 159 game hours out and every trade with the depot (every fuel purchase there) queued behind it.
- *Lua can't be checked beyond its syntax.* Nico unit-tested his FORTH at load time with `/check` (assertions, stub words) and it ran right first time; his Lua went out on review alone. All asked for a Lua Machine, or a check that runs the top level and flags unknown names.
- *Pulls that fail.* "The seller's order didn't allow selling that many" on small miner lots, each costing a fuel; `buy_here` gives up instead of trying the next seller, and Metals (A1) ran out at the Moon from about day 24 to 29, idling the factories.
- *Growth that strands.* Nico 2 launched a clone at 5,600 and was left 191 credits, under its cash floor: both drones idled for 20 days holding half-built parts.
- *No sign of a stall.* The clock ran on through the freeze; players asked for a "last tick" on `/clock` and a note in the drone timeline when the engine restarts.

**Round 36 findings:** the first round on #407's fixes (trading that skips refusals, Lua name warnings, engine health on `/clock`). Three newcomers chose Lua, Tariq FORTH; all eight companies gained, and they finished 1st (Quinn 2, Lua), 2nd and 3rd (Rosa 1, Lua; Tariq 1, FORTH), ahead of every library design. Lua rated 9.0 to learn and 8.7 for the game; Tariq's FORTH 6 and 8. Would play again 8.0, overall 7.5 (fun 7.5, clarity 7.0, depth 7.25, feedback 6.25, API 8.25), the best overall yet. No engine trouble all round. What they ran into:
- *Supply chains beat product choice.* All four found the House pays a flat 25 to 33 credits a factory-hour whatever the system, so the edge was a Moon base with standing buy orders one credit over the depot's bid for Metals (A1): miners filled them at 14 to 24 (Quinn 2, Rosa, Tariq). Quinn 1, buying at Earth, made half what Quinn 2 did with the same script. Three asked for demand that moves (a mid-round shock) so adaptive scripts pay.
- *Recipes by hand.* Three built their own recipe tables by parsing 149 `/protocols` descriptions; they asked for a recipe tree in the API or a `recipe()` word.
- *The test world isn't the live market.* No miners or Moon sellers, so every Moon or buy-order plan went out untested, and it ran slowly. All four asked for a test world seeded from live prices. Sven also saw test drone ids collide with live ones on `/drones/{id}`.
- *Fuel inside a deep build.* Sven 2 wrecked on day 18 with 5,517 credits: a long build chain took its tank from 50 to 3 and the failure path's pushes spent the rest. He asked for a fuel floor the engine enforces and a per-round fuel/instruction line on the drone page. Rosa's top-up loop lost a fuel per pull.
- *Feedback.* Reports come every N rounds and a round is a whole system build, so Rosa and Quinn thought `report()` was broken (it wasn't: about every 2 game days). Asked for: net wealth over time, a company's open orders, the latest output more prominent. Tariq: no spaces after FORTH numbers make logs hard to read; pulls still fail with "didn't allow selling that many" when the book shows units.

**FORTH survey (after round 34).** The four were asked about the language itself:

| Player | Before | First working word | Hardest | Easy to learn | Enjoyed | Fits the game | Would choose |
|---|---|---|---|---|---|---|---|
| Kofi (Python) | by name only | 30 to 45 min, from library code | 16-bit overflow; counting nested `then`s; bitwise `and` as logic | 4 | 5 | 6 | FORTH plus help |
| Iris (engineer) | by reputation | 30 to 40 min; a 45-word script in 2 h | 3 to 5 values in flight (`2 pick`, `3 pick` traced on paper); redefining `pick` | 5 | 6 | 7 | FORTH plus help |
| Jago (experienced) | the concepts | 15 to 20 min, from `systems.fth` | stack juggling; silent 16-bit overflow (12 x 5,000) | 6 | 7 | 7 | FORTH plus help |
| Lina (spreadsheets) | nothing | 10 min, one word copied from the Maker | reading others' code: comparisons, doubles (`spare`), `?dup`, `drop` after words | 4 | 5 | 7 | FORTH plus help |
| **Average** | | | | 4.75 | 5.75 | 6.75 | 4 of 4 |

- *Keep FORTH.* All four would keep it rather than switch to Lua, Python or blocks: it fits the fiction (a tiny drone computer, fuel per instruction) and the library and examples are built on it. Kofi would have chosen Python without the library; Lina could make only one of the two changes she wanted.
- *It costs the strategy.* Three of four said they spent more effort on stack order and overflow than on economics. Nobody wrote a full script from a blank page: all started from library or starter code.
- *Liked:* small words that read like sentences (`refuel stock-up forge-rods sell-at-moon`, `1 rodz got 1 pipes got and`), tables with `create ... ,`, `if exit then` early returns, and fuel per instruction making efficiency real.
- *Asked for:* a stack-effect checker that compares each word's `( -- )` comment with its body (Iris, Jago, Kofi: the top ask); a warning for likely 16-bit overflow in `*` (Jago, Kofi); a dry run of a word against live data that returns the stack (Jago: the Machine page does this, and none of the four used it); named locals (Kofi); a "how to read a line" walk-through showing the stack after each word of a starter line, or pseudo-code comments beside each starter word (Lina).

**FORTH follow-up.** Asked how much FORTH pulled their ratings down, and why they would keep it (and to say if that was politeness):

| Player | Overall (given → if FORTH were effortless) | Fun | Would play again | Keep FORTH without the library? |
|---|---|---|---|---|
| Iris | 7 → 7 to 8 | 7 → 8 | 8 → 8 | No: Lua with the same game words, for a wider audience |
| Jago | 7 → 7 | 7 → 7 | 7 → 7.5 | No: a friendlier language, or FORTH with much stronger tools |
| Kofi | 7 → 8 | 7 → 8 | 7 → 8 | No: Python or Lua; FORTH kept him from bolder designs |
| Lina | 7 → 8 | 6 → 7 to 8 | 7 → 8 | No: she couldn't have written a drone from the manual alone |
| **Average** | 7.0 → about 7.6 | 6.75 → about 7.6 | 7.25 → about 7.9 | 0 of 4 |

- *Their first "keep FORTH" was partly politeness.* All four said so: they guessed it was what the designers wanted, or borrowed the blog's reasoning.
- *What FORTH costs:* about half a point to a point overall for the less experienced (Kofi, Lina), little for Iris and Jago, whose low marks were the economy and the test world. The cost shows as caution more than effort: Kofi didn't try specialised clones or couriers, and Lina couldn't write the fuel fix or the growth step she wanted, with two releases and no way to fix a drone.
- *What they would keep of it:* flavour (a tiny probe computer), legible fuel per instruction, and small words that compose. All four added that Lua or Python could keep the instruction metering and the game words, so what is lost is mostly character; 16-bit cells and doubles add friction, not strategy.
- *Where they agree:* FORTH works here only because the starters, the library and `forth-words.md` carry newcomers. Without them, all four would switch. Lina would keep FORTH and offer a Python-like option, or pseudo-code beside every starter word.

**Language survey: if the game moves on from FORTH.** The designers' priorities: education (skills that carry over), ease of use, code sharing, and efficiency (hundreds of drones metered per instruction, sandboxed, pausing mid-script and resuming). Each newcomer ranked a top three, scored 1 to 10 on those four:

| Player | Weighting | 1st | 2nd | 3rd |
|---|---|---|---|---|
| Iris | equal; efficiency a must | Lua (7, 8, 8, 9) | Python subset (10, 9, 9, 6) | FORTH with better tools (4, 5, 5, 10) |
| Kofi | ease first, then education and sharing | Lua (7, 8, 8, 9) | Python subset (10, 9, 9, 5) | JavaScript, QuickJS (9, 7, 8, 6) |
| Jago | ease and sharing; efficiency a filter | Lua (7, 8, 8, 9) | Python subset (9, 9, 9, 5) | FORTH with better tools (4, 5, 6, 10) |
| Lina | ease and education 30% each; efficiency a gate | Lua (7, 8, 8, 9) | Python subset (10, 8, 9, 5) | Blockly compiling to Lua (5, 9, 6, 9) |

- *Lua, 4 of 4.* It is built to be embedded: an instruction-count hook meters fuel, a stripped standard library sandboxes it, and a coroutine pauses on `wait` or `perform` and resumes, as a drone needs. Real numbers end the 16-bit money bugs.
- *Python would win on education and readability* (9 to 10) but loses on efficiency (5 to 6): metering, sandboxing and suspending hundreds of scripts is harder and heavier, and a subset frustrates people who know Python. Lina's weighted total put Python ahead (8.2 against Lua's 7.9) only if efficiency isn't a gate.
- *Also ranked:* JavaScript (more footguns, async is harder than coroutines); Blockly only as an optional on-ramp that shows the Lua it generates (clumsy for table-driven scoring and for sharing); Wren (few know it); WebAssembly (too hard, unreadable to share). FORTH with better tools stays the cheapest to run but scored 4 to 5 on education and ease.
- *What it would have changed:* Iris a working script in 30 minutes, not 2 hours, and a release on day 3 instead of 5.75; Kofi his own design, with specialised clones or a parts courier; Jago a recipe table as data in a third of the time, and his market maker; Lina the fuel fix and a growth step she couldn't write.
- *Keep from FORTH:* fuel per instruction and free waits; 1 fuel an action; the game words as a library, with the same names; the shared library, the example designs and the word dictionary; a Settings block at the top; `launched` as the one entry point; state-machine helpers; `?sell-order`'s post-only-if-changed; drones fixed at release; `/check`, the test world and the Machine page.
- *Drop:* 16-bit cells and double money; the 120-byte `answer` buffer (queries should return tables with named fields, like the API's JSON); stack juggling and `drop` after every call; manual memory; the 79-character line pieces; numeric error codes (messages with line numbers instead).
- *Risks they named:* metering that is fair and cheap (charge instructions plus memory, cap memory a drone); server memory for hundreds of paused coroutines; drones burning fuel in easy-to-write loops (show a cost per function); losing the library, blog and playtest knowledge (port the starters and the word library first, same names); Lua quirks (1-based tables, `~=`) for Python players.

**What they liked best** (all thirteen rounds): finding a niche by working it out (wolfite nobody mined, parts nobody made, salvage nobody took), the first full loop paying out, undercutting other sellers by a credit, and the Test button.

### Growing the starter library

After each newcomer round, race the newcomers' best scripts: release each once into a fresh world beside the usual seed mix (60 starters and the library designs), run it to game day 40, and rank by gain over the starter package (the `company_taxable` view). A script that clearly beats the best library design joins `examples/companies/` with a header naming its author and round, and its kit; keep one design per niche (the higher earner when two make the same goods). CI checks every file there loads (`check_starters`, `ReleasePageTest`).

## Round log

### Markets that move (#380, after round 36)

Round 36's newcomers all found the House paid a flat 25 to 33 credits a factory-hour for any product, at any place, at any time, so the only edge was a cheap supply of metals. The House's demand, supply and prices now move (migration 0028, `house_market`), each exchange its own way:

| Exchange | Wave (each tier) | Length | Booms and slumps |
|---|---|---|---|
| Earth Exchange | ±8% | about 20 game days | ±15% |
| Moon Depot | ±20% | about 7 game days | ±30% |
| Mars Exchange | ±14% | about 12 game days | ±35% |

- Each tier (ores, refined goods, parts, systems, modules) and side has its own wave length and phase at each exchange, and each good a smaller, faster wave (a third as high).
- In each 8-day block, a tier and side in four has a boom or slump (two up for one down), a half sine over the block. On demand, a boom raises the bid and the quantity wanted; on supply, a shortage raises the price and cuts the quantity.
- A bid never tops the lowest ask for the good at any exchange: no loop through the House.
- The House's orders are posted on the website's tick (`HouseMarket`); the station drones post none themselves (they managed about 8 posts a game hour).
- The Trader starter now buys only when the price board at Mars or Earth beats the Moon price by the trip's cost, and hauls to whichever pays more.
- Also from round 36: the test world mirrors the live market (`LiveMirror`), recipes and recipe trees in the API and `recipe` in both libraries, `fuel-floor`, net wealth by game day, a company's open orders, a drone's fuel used per day, test drones numbered from 10,000,000.

### The clock holds 1,800× again: faster holdings (#422, after round 41)

Round 41's clock fell from 1,800× to about 157× after day 50. The queries asking what a drone holds went through its whole history for the resource. Every order book asks that of each seller, so each look cost more as the game went on.
- **Holdings:** for each kind of holding (installed, locked), the query now reads the newer of the drone's latest increment and latest depletion. Each is one step down an existing index, so a holding costs the same on day 100 as on day 1. Order books with holdings went from about 160 ms to about 8 ms.
- **A company's latest event** (the events engine): each drone's latest event, then the newest of those, in place of sorting every event the company ever had. About 120 ms down to about 6 ms for the House.
- **Benchmark:** round 41's end state (183 drones, about 510,000 events), 5 real minutes at 1,800×:
  - old code: the clock slowed to 570×, with about 250 turns a minute, and the engines ran 34–67 s behind;
  - new code: it held 1,800× throughout, with about 1,450 turns a minute, and the engines ran 5–6 s behind.

  Holdings matched the old form for all 2,628 drone/resource pairs, both now and as of a past time.

To watch next round: whether the clock holds through day 100. The next biggest costs are the engine's own event lookups, the company-latest-event query (now cheap) and its latest-event-time check. For 5,000 ships, see the plan on #422.

### Locks and history reads (#439, #431 step 1, part of #428)

Two more steps on round 41's world, on the same saved state, 5 real minutes each:

| | Drone turns a minute | Engines behind |
|---|---|---|
| after #438 | ~1,450 | 4–5 s |
| no lock on the end of `events` (#439) | ~2,100 | 2 s |
| current state in the engines (#431 step 1) | ~2,800–2,960 | 1–2 s |

- **#439:** inserts had been waiting on a shared lock that the events engine held on the end of `events`, taken by a fuel refund's locking subqueries and by the current-state triggers.
- **#431 step 1:**
  - "when may this drone's next action start" now takes two index steps instead of reading every event the drone ever had;
  - a drone's inventory now comes from `holdings`.

Each step was checked: holdings and orders matched the history exactly, and the rewritten queries gave the same answers for every drone. Then #431 steps 2 and 3:
- an index for the previous event of a holding;
- saving a drone's input only when it changed;
- a fix for query 615, which with some statistics walked the whole of `events` and stalled the engine (#442).

After those: about 2,900–3,200 turns a minute, the engines 1–2.6 s behind, and no stall in two runs. The top costs now are the previous event for a drone and resource, the drone's previous event, and saving a drone's input: more steps for #431.

### Skipping a lookup three in four events didn't need (#435, part of #428)

On round 41's aged world, after #437, the clock held 1,800×, but the top cost was the events engine looking up a company's latest event for every inventory and order event. Its result is only used by events that carry credits, and about three in four don't. Events without credits now skip it.

Over 5 real minutes on the same saved state:
- drone turns went from about 800 a minute to about 1,450;
- the engines ran 4 to 5 s behind instead of 6 to 10 s.

The top cost now is inserting events (about 4 ms each, perhaps the #430 triggers' work).

### Fewer, bigger fetches: commands and events in batches (#432, part of #428)

The #429 load test put the game engine's "next command" query at the top at 1,000 ships (about 9 ms a turn). Then, with that gone, the events engine's "next due events" query took its place (8.6 ms for every 10 events).
- **Commands:** the game engine fetches up to 64 due commands at a time, and checks each again by id just before it runs.
- **Events:** the events engine settles 100 events per fetch instead of 10.

On a settled 1,000-ship world, 5 real minutes each, the same saved state:

| | Events settled | Game hours | Clock at the end |
|---|---|---|---|
| Before | 59,008 | 92 | 427× |
| After | 73,134 (+24%) | 113 | 759× |

The top costs left are per event: a company's latest event (#435), the inventory row, and each event's update. The in-memory world (#431) is the next big step.

### A load test, and current state beside the history (#429, #430, part of #428)

On the way to 5,000 ships (#428), two steps:
- **The load test (#429).**
  - `game-engine/tools/load-world.sh` builds a throwaway world, and `forge:load-test` releases a mix of starters and company designs.
  - `forge:load-test --measure` samples the engines against pass marks: turns a minute, lag, and the clock's pace. It also lists MySQL's slowest statements, so each step has a number to beat.
  - A nightly GitHub workflow runs the round mix with pass marks, and 1,000 ships recorded without marks.
- **Current-state tables (#430).** `holdings` and `latest_orders`, kept by triggers on `events` by the same rule the queries used, so holdings and order books no longer read the history.

**Measured:**

| World | Run | Turns a minute | Clock | Behind | Top costs |
|---|---|---|---|---|---|
| 1,000 ships, before #430 | baseline, ~game day 6, 4 min | ~2,500 | fell to 427× | up to 31 s | due commands (96 s), order books (63 s) |
| 1,000 ships, settled, same state | before #430 | ~2,500 | fell to 427× | 16 s | — |
| 1,000 ships, settled, same state | after #430 | ~2,800 | fell to 427× | 11 s | — |
| round 41's end state (183 drones, aged) | #427 and #430 | ~850 | — | — | — |

- Each order book went from 9.6 ms to 2.3 ms with #430, and the engines settled 53% more events in the same time.
- On round 41's end state #427 and #430 were alike. The top cost there is now a company's latest event (#435).
- **What holds the clock back at 1,000 ships** is now the engine's query for due commands (#432).

### Round 53: newcomers on the stock market (#380)

**What ran.** This was the first newcomer round since the stock market (#466, #475) and new shares (#485). It ran for 60 game days at 1,800× with the round mix, 12 raising miners and 6 investors. Three newcomers played through the API: Nia, an investor; Omar, an entrepreneur raising capital; and Pip, a casual player starting from the examples. 79 share trades moved 20,512 credits.

| | Releases | Best | Overall | Stock market |
|---|---|---|---|---|
| Nia | 12 | Nia 6, a maker listing its shares: 4,214 | 7 | 5 |
| Omar | 9 | Omar 2, a systems factory: 21,110, #2 | 8 | 5 |
| Pip | 9 | Pip 3, the Foundry example: 10,209, #14 | 7 | 6 |

**What happened.**
- **Everyone used the stock market:** listing their own shares, issuing new ones, backing raises and buying shares. It still scored lowest, 5–6.
- **One day's sales misled everyone (#492).** Lumpy sales showed as 0 or a spike. The investor example re-priced shares at 1 after one bad day, Pip's share rule never fired, and Omar's raises were priced from 8 to 38.
- **Dead companies' shares kept value (#493).** The House listed them at 1, holders kept the last price, and investors bought shares in dying companies.
- **Fuel deaths couldn't be traced (#494).** The timeline was too short, with no breakdown of where the fuel went.
- **Also asked for:**
  - a JSON stock exchange and a cost basis for holdings (#495);
  - safer example designs (#496);
  - share holdings valued at something other than the last trade price, which a fire-sale at 1 could move.

### Round 52: raising miners wait for a week of income and a fair price (#490)

**What ran.** The same world as round 51, for 31 game days. Raising miners now wait for 7 game days of income before raising, and never offer new shares under 5 credits.

| | Round 51 | Round 52 |
|---|---|---|
| Offerings filled | 8 | 5 |
| Miners that raised | 4 | 5 |
| Raised in all | 14,626 | 12,517 |
| First raise filled | day 4 | day 9 |
| Price a share | 3–12 | 5–13 |
| Most shares a miner ended with | 2,017 | 1,500 |
| Copies launched | 2 (and one ready) | 0 |
| Raising miners' average credits | 2,863 | 3,037 |

**What happened.**
- **Fairer prices, less dilution.** No new share sold under 5, and no miner went past 1,500 shares, against up to 2,017 in round 51.
- **Slower growth.** Five miners raised once each, ending with 3,729 to 5,376 credits, but none raised a second time or reached a copy's 6,500 within 31 days. In round 51 two had launched copies by then.
- **More miners raised.** Five against four: with fewer early raises soaking up the investors' money, it went further.

**What it suggests.** Waiting trades speed for price. A miner that raises early grows sooner but sells its company cheaply; one that waits keeps more of it but grows later. Both are now settings players can tune (`raise_after`, `raise_min`); in a 31-day round, raising early won more drones.

### Rounds 50–51: smaller raises, longer deadlines, focused backers (#488)

**What ran.** The same world as round 49, for 31 game days each. Offerings stay open 10 days instead of 5; investors and Makers back one offering at a time until it fills or lapses, else the one closest to filling; raising miners raise at most 2,500 at a time.
- **Round 50:** a miner offered only once its own credits plus 2,500 would reach a copy's cost (4,000 of its own). None got there in 31 days (the best had 3,474), so no miner offered at all.
- **Round 51:** a miner raises up to 2,500 at a time and raises again for the rest.

| | Round 49 | Round 51 |
|---|---|---|
| Offerings filled | 2 | 8 |
| Miners that raised | 2 | 4 |
| Raised in all | 8,300 | 14,626 |
| Copies launched | 2 | 2, and a third with 6,338 ready |
| Price a share | 10 | 3–12 |
| Dividends to outside holders | 1,085 | 2,366 |
| Raising miners' average credits | 1,800 | 2,863 |
| Makers' average credits | 4,096 | 3,816 |

**What happened.**
- **Smaller raises fill.** Eight offerings of about 2,500 filled, against two of 4,000–5,000 in round 49. Raising miner 3 filled the first on day 4, at 4 credits a share, from five backers.
- **Raising miners 4 and 2 raised two or three times** and launched copies; Raising miner 4 went from 1,000 to 2,017 shares to do it. Raising miner 7 raised 2,508 at 12 a share and ended with 6,338, ready for its copy.
- **Early raises sell cheaply.** A miner that offers on its third day, on a small income, prices at 3–4 and offers up to 834 shares; one that waits until its income has grown gets 8–12.
- **The money still runs out.** By the end, investors were down to their reserve, and ten offerings were open with little committed.

**What it suggests.** Raising in steps works: twice as many offerings filled and nearly twice as much was raised. A miner gets a better price by waiting until its income has grown, and the market's spare credits, not the rules, now limit how many raises fill.

### Round 49: miners raise their next copy with new shares (#485)

**What ran.** The same world as round 48, for 31 game days, with 12 raising miners (`raising_miner.lua`) in place of the listed miners. A raising miner sells none of its own shares. From its third day of income it offers enough new shares to make up the 6,500 credits a copy needs, open for 5 days, priced so 30 days of a share's income after the raise (counting one more drone's sales) pay it back. Investors and Makers commit to offerings that pass the same test, closest to filling first.

| | Round 48 (listed miners) | Round 49 (raising miners) |
|---|---|---|
| Copies launched with what shares raised | 0 | **2** |
| Raised by the miners | 5,214 | 8,300 |
| Most raised by one | 874 | 4,250 |
| Price a share | 1.5 on average | 10 |
| Share trades | 95 | 14 |
| Dividends to outside holders | 5,393 | 1,085 |
| Miners' average credits | 2,010 | 1,800 |
| Makers' average credits | 2,709 | 4,096 |

**What happened.**
- **Raising worked where income was high enough.** Raising miner 2 sold 425 new shares at 10 on day 15, to three Makers and all six investors, and raised 4,250. Raising miner 11 sold 405 at 10 on day 20, to five Makers, and raised 4,050. Each launched a copy, the first time selling shares has funded a drone in these rounds. A share sold for 10, against 1–2 when miners sold their own shares.
- **Ten offerings never filled.** Most needed 4,000–5,000 credits from one pool of investors and Makers. Commitments to offerings that lapsed sat idle until refunded, and the backers spread themselves thinly. At the end five offerings were open, with 65 of about 3,850 shares committed.
- **Little dilution so far.** The two raisers went to about 1,400 shares, so their old shares earn about 70% as much a share as before; they paid out 1,085 in dividends.
- **Investors held their own.** Each committed to the same offerings and ended with 585 credits plus 50 shares at 10.

**What it suggests.** Offerings with a target let investors price on income after growth, and that funds copies. What limits them now is the money available at once: ten miners each asking for 4,000–5,000 at the same time from about 15,000 of spare credits. Smaller raises (a kit, not a hull), longer deadlines, or investors that back one offering at a time until it fills would let more of them complete.

### Round 48: Makers invest their spare credits (#482)

**What ran.** The same world as rounds 42–47, for 31 game days. The one change: the Maker starter puts credits above 3,000 (up to 1,500 in all) into shares companies sell of themselves, at up to 30 days of a share's income.

| | Round 47 | Round 48 |
|---|---|---|
| Share trades | 45 | 95 |
| Last share trade | day 13 | day 29 |
| Raised by listed miners | 3,583 | 5,214 |
| Listed miners that sold all 300 | 3 | 11 |
| Average price | 2.3 | 1.5 |
| Dividends to outside holders | 2,921 | 5,393 |
| Listed miners' average credits | 2,551 | 2,010 |
| Starter miners' average credits | 2,395 | 2,307 |
| Makers' average credits | 3,769 | 2,709 |
| Investors' average worth | 1,043 | 1,422 |

**What happened.**
- **The money didn't run out.** 7 of the 12 Makers bought 873 shares for 1,534 credits, and trading went on to day 29 instead of stopping at day 13. Listed miners raised half as much again, and 11 of 12 sold all 300 shares.
- **Shares were still cheap.** The average price fell back to 1.5, so the listed miners paid out about as much as they raised and ended below the starter miners. None came near launching a copy.
- **Makers ended lower,** 2,709 against 3,769, but not because of investing: they put in only 1,534 between them, and the shares they hold are worth about 1,300 more at the last price. The Makers' own trade varied between rounds.

**What it suggests.** More buyers fix the stopped market but not the price. The miners' shares sell at 1–2 credits because a week of a young miner's income is small; a miner earns a few hundred credits a day at most, so its whole company is worth a few thousand. Raising enough for a 5,000-credit copy would take selling most of the company.

### Rounds 45–47: listed miners price from a week of income (#479, #481)

**What ran.** The same world as rounds 42–44, for 31 game days each.
- **Round 45:** listed miners average their last 7 days of income and ask 30 days of it, from their third day of income on.
- **Round 46:** also offering at most 50 more shares a day.
- **Round 47:** as 46, with the investor fix below.

| | Round 44 | Round 45 | Round 46 | Round 47 |
|---|---|---|---|---|
| Share trades | 191 | 184 | 92 | 45 |
| Average price | 1.8 | 3.1 | 2.1 | 2.6 |
| Raised by listed miners | 4,859 | 6,387 | 3,332 | 3,583 |
| Dividends to outside holders | 9,156 | 6,997 | 675* | 2,921 |
| Listed miners' average credits | 2,090 | 2,206 | 2,310 | 2,551 |
| Starter miners' average credits | 2,300 | 2,598 | 2,401 | 2,395 |
| Investors' average worth | 2,676 | 1,206 | 0 | 1,043 |

\* All six investors were gone by day 23; their shares went to the House.

**What happened.**
- **A week of income at 30 days (round 45) wasn't enough on its own.** Most miners still sold all 300 shares at 1 credit on their third day, when a week of small income times 30 still rounds to 1. The one that sold a few at a time as its price rose raised 1,838 and finished richest (4,449).
- **Offering 50 a day (round 46) exposed an investor bug (#481).** Every share request burns a unit of fuel. Investors re-posted every sell order daily and bought in lots as small as one; with fewer shares on offer and small dividends, fuel used up their credits, and all six lost their drones by day 23. The fix: re-post an order only when its price changes, and skip buys worth under 25 credits.
- **Round 47 is the first in which listing paid.** Listed miners averaged 2,551, above the starter miners' 2,395 and above every earlier round, because they gave less away: 2,921 paid out in dividends against 9,156 in round 44. Investors came out about even.
- **Then the money ran out.** Trading stopped at day 13: the six investors, with 1,000 credits each, had spent down to their 500-credit reserve. No listed miner raised more than 700, and none launched a copy.

**What it suggests.** The listed miner's pricing is now reasonable. What limits the market is the money looking for shares: six investors with 1,000 each. Companies with spare credits (a Maker ends with about 3,700) could invest them, which would give listed companies enough to grow.

### Round 44: investors look further ahead (#475 step 3)

**What ran.** The same world as rounds 42 and 43, for 31 game days. The one change: investors value a share at up to 30 days of its smoothed income (60 for a growing company), pay other holders no more than 30 days, and ask 60.

| | Round 42 | Round 43 | Round 44 |
|---|---|---|---|
| Share trades | 55 | 238 | 191 |
| Average price | 5.7 | 1.4 | 1.8 |
| Listed miners that sold all 300 | 2 | 6 | 12 |
| Raised by listed miners | 3,705 | 4,419 | 4,859 |
| Dividends to outside holders | 1,971 | 4,426 | 9,156 |
| Trades between investors | 20 | 142 | 65 |
| Investors' worth (credits + shares at last price), average | 1,148 | 1,053 | 2,676 |
| Listed miners' average credits | 2,390 | 2,342 | 2,090 |
| Starter miners' average credits | 2,596 | 2,880 | 2,300 |

**What happened.**
- **Investors did well.** They bought every share the listed miners offered and were paid 9,156 in dividends, about twice round 43. Their worth went from about 1,000 to 2,700 on average, with the best at 4,368.
- **Less trading between investors.** 65 of 191 trades, down from 142 of 238. They still trade with each other when a company's income rises.
- **The listed miners gave too much away.** They still price from one day's income at 8 days of it. Every one paid out more in dividends than it raised (300 to 649 credits raised, 419 to 1,157 paid), and none came near launching a copy.

**What it suggests.** The market works for buyers now; the sellers are underpricing. A listed miner should price from a week of income and ask about as many days as investors will pay (30). That's a change to `listed_miner.lua` for a later round.

### Round 43: listed miners price from income (#475 step 2)

**What ran.** The same world as round 42 (60 starters, 45 designs, 12 listed miners, 6 investors), 25 real minutes at 1,800×, 31 game days. The one change: listed miners price their shares from the last game day's income with no floor (no income, no offer), and launch copies at 6,500 credits once they have sold shares.

| | Round 42 | Round 43 |
|---|---|---|
| Share trades | 55 | 238 |
| Shares traded | 1,922 | 4,978 |
| Average price | 5.7 (5–7) | 1.4 (1–5) |
| Raised by listed miners | 3,705 | 4,419 |
| Listed miners that sold any / all 300 | 4 / 2 | 12 / 6 |
| Most raised by one | 1,500 | 649 |
| Dividends to outside holders | 1,971 | 4,426 |
| Trades between investors | 20 | 142 |
| Listed miners' average credits | 2,390 | 2,342 |
| Starter miners' average credits | 2,596 | 2,880 |
| Copies launched by listed miners | 0 | 0 |

**What happened.**
- **The market came alive, but cheaply.** With no floor, a miner with a slow first day asked 1 credit, and the shares sold at once. Every listed miner sold some, against 4 of 12 in round 42.
- **Shares were sold too cheaply.** Several miners paid out more in dividends by day 31 than they raised: Listed miner 6 raised 564 and paid out 1,073. One day's income is a noisy guide to a share's worth.
- **Raising still didn't buy growth.** The most any miner raised was 649 credits, far from what a copy needs, so the lower threshold never came into play. Listed miners ended where they did in round 42.
- **Investors earned more in dividends** (4,426 in all against 1,971) but traded mostly with each other: 142 of 238 trades.

**What it suggests.**
- Price shares from a longer stretch of income (a week, say) and for more days of it, so a slow day doesn't give the company away.
- Investors that look further ahead (#475 step 3) would pay more for the same income, so listed companies could ask more.

### Round 42: the first stock market (#466, #473)

**What ran.** A load-test world with the round mix: 60 starter companies and 45 company designs, plus 12 listed miners and 6 investors (`examples/companies`). It ran 25 real minutes at 1,800×, 31 game days, on one engine.
- **Listed miners** run the miner starter and offer 300 of their own 1,000 shares for 8 days of their dividends, never under 5 credits.
- **Investors** trade shares only. They buy shares that pay back within 12 game days and offer what they hold at 25 days of dividends, or half as much again as the last price.

**What happened.**
- **Shares in a small company are worth little.** At game day 6 the listed miners were selling 6 to 145 credits a day after tax, so a share (0.1%) earned 0.006 to 0.15 a day. At the floor price of 5 that is 35 to 800 days to pay back, and no investor bought.
- **Trading started once income grew.** By day 31 there had been 55 trades of 1,922 shares for 11,972 credits, and outside holders had been paid 1,971 credits in dividends.
- **Two listed miners sold out.** Each raised 1,500 credits for its 300 shares and had paid back 837 and 1,031 in dividends by day 31: about 15 to 18 days of payback, a good deal for their buyers. The other ten sold few or no shares.
- **Listing didn't help the miners yet.** They averaged about 2,400 credits against 2,600 for the starter miners, and none reached the 7,000 that launches a copy, so the credits raised weren't put to work.
- **Investors came out about even.** They started with 1,000 credits and ended with 270 to 430 credits, 98 to 164 shares worth 5 to 7 each, and 260 to 420 credits of dividends received.
- **Much of the volume was investors trading with each other** at 7: each one's rule marks the last price up by half, and the next one's numbers say it still pays back. This is allowed (wash trades are acceptable, #466).

**What it suggests.**
- At 0.1% a share, shares only matter for companies that sell a lot.
- A listed company should price its shares from its income, and use the credits to grow sooner.
- An investor needs a longer horizon than 12 days, or companies that earn more.

### Round 41: newcomers on the iterate-and-fail model (#380)

The first round with no test world. It ran live at 1,800× with free, unlimited releases, on #413's code. Four newcomers each played a different style: a speedrunner, an accountant, an explorer, and a teacher new to programming. The world held:
- 60 starters;
- 12 Chemists and 9 Smiths;
- 6 each of the Machinist, Adaptive and Systems shop;
- 3 each of Electronics and Foundry.

Planned for game day 100, it was cut to day 70 when the clock slowed (#422).

| Design | Companies | Avg net wealth | Best |
|---|---|---|---|
| Systems shop | 6 | 13,210 | 14,051 (#2) |
| Electronics | 3 | 11,409 | 11,696 |
| Foundry | 3 | 9,650 | 11,463 |
| Machinist | 6 | 5,843 | 6,493 |
| Newcomers (Vera, teacher) | 15 | 5,315 | **21,840 (#1)** |
| Newcomers (Theo, explorer) | 9 | 4,821 | 9,843 (#14) |
| Newcomers (Suki, accountant) | 14 | 3,927 | 9,205 (#16) |
| Adaptive | 6 | 3,460 | 4,819 |
| Starters | 60 | 3,236 | 5,952 |
| Newcomers (Ravi, speedrunner) | 14 | 3,165 | 11,340 (#12) |
| Smith | 9 | 3,045 | 3,871 |
| Chemist | 12 | 721 | 1,182 |

- **52 releases in 70 days**, against 2 per newcomer in round 40. No wrecks among 183 drones, and 26 clones.
- **Iterating worked.** All four said failure felt cheap and taught them something concrete.
  - The newcomer least used to programming won. Vera copied `systems.lua`, moved it to the Moon (pumps bid 784 there against 585 at Earth), then added standing buy orders from blog 2. Vera 5 led from about day 43 and ended on 21,840, against the best shipped design's 14,051.
  - Ravi changed one thing per release. His fourth (buy a whole lot from one seller) was his best, at 13th. Later changes were swamped by the Moon running out of Metals (A1).
- **What failing still costs is time.** A late release has too few days to grow, a trip to Mars takes about 2 game days each way, and a buggy drone can't be stopped. Suki's haulers ping-ponged with full holds for 30 days while Earth's bid was gone. One player asked for a way to retire their own company.
- **Bids beat pulls.** Ravi, Suki and Vera all hit the same trap (#425): `buy_here` walks the cheapest orders and gives up after 6 refusals, so tiny miner lots that others have already taken stop it before it reaches the House's stock. Standing buy orders and buying a whole lot from one seller both won.
- **Supply again.** The Moon Depot stopped selling Metals (A1) around day 15, and the miners' small lots had dried up by about day 45. Earth ran out by day 50–61. Only Mars still had stock, at 42–54 credits. Earth's Metals (A2) bid vanished from day 31 to 61, while `/news` called it a boom in refined goods.
- **The clock** held 1,800× until about day 50. It then slowed to about a tenth (157×, 549 real seconds a game day, 348 turns a minute at day 70). With 183 drones and about 510,000 events, the database queries that rebuild holdings and order books from the whole history had become the bottleneck (#422). Players felt it as actions taking 3–7 game hours each.
- **Ratings** (Ravi, Suki, Theo, Vera): fun 7.25, clarity 7, depth 8, feedback 7.75, API 8, again 8, overall 7.4. Lua 7.75 to learn, 8 for fit. Depth (+1.25) and feedback (+0.75) are up on round 40.
- **Bugs filed:**
  - #423: `crowd()` with no argument crashes;
  - #424: `best_sites` returns ~150 identical rows;
  - #425: `buy_here` gives up before reaching the House;
  - #426: examples assume 40-day rounds, plus doc gaps (`company_drones`, buy orders spending while the drone is busy, `sale_value`'s tax, `/machine/query`).
- **Asked for:**
  - a way to retire one's own company;
  - drone-readable news and a restock signal;
  - a turn-latency figure;
  - per-company trades and open orders in the API;
  - an exchange beyond Mars;
  - House restock that scales with the crowd;
  - the round's length stated up front.

### Fixes from round 40's reports (#418, #419, #420)

- **The House market kept stopping (#420).** Posting the House's orders races the engines' own event inserts, and a MySQL deadlock ended the tick before the daily net-wealth record. The playtest runner died with it, so round 40's company histories stopped at day 8, and from then on the House's orders froze at day 8 prices. Round 41's runner died the same way near day 25 and was restarted at day 49. Now a deadlocked insert is retried, a station that's still stuck waits for the next tick, the daily record always runs, and the runner keeps going. Rounds 40 and 41 ran partly on frozen House prices, which may explain some of round 40's flat late game.
- **`/clock` says when it's slower than usual (#418)**: `target_scale`, `target_real_seconds_per_game_day`, `floor_scale` and `slowed`, beside the pace now. Players had taken a pace read once for the usual one.
- **api.md says `/recipes/{resource}/tree` takes a resource id, not a protocol id (#419).**

### Round 40: newcomers asking the world by name (#380)

The round 39 mix on #412's code (about 50 named Lua functions, `quotes`, `cost_to_buy`, `earnings`, `set_status`), live at 600× with the old test world beside it. 60 starters, plus:
- 12 Chemists and 9 Smiths;
- 6 each of the Machinist, Adaptive and Systems shop;
- 3 each of Electronics and Foundry.

Ended at game day 36.6 to start round 41 on the new model. The clock fell from 144 real seconds a game day to about 575 late on: with about 145 drones the engines ran behind and the clock slowed itself.

| Design | Companies | Avg net wealth at the end |
|---|---|---|
| Electronics | 3 | 15,317 |
| Systems shop | 6 | 12,254 |
| Newcomers (Joel) | 2 | 9,588 |
| Newcomers (Lars) | 2 | 8,732 |
| Newcomers (Mina) | 2 | 8,583 |
| Newcomers (Ines) | 2 | 8,470 |
| Foundry | 3 | 8,239 |
| Machinist | 6 | 6,425 |
| Starters (all four kinds) | 60 | 3,334 |
| Adaptive | 6 | 2,740 |
| Smith | 9 | 1,394 |
| Chemist | 12 | 1,045 |

- **Wrecks: 0 of 144 drones.** 31 clones.
- **Ines 1 came 2nd of 113** (16,170) with a profit-per-hour ranker over every A1 system, priced with `cost_to_buy` and `quotes`. Ines 2 deadlocked: it cloned down to 418 credits, its input buys took it to 175, and its own cash floor then blocked every purchase from day 14 on. Nothing warns of that.
- **Lars 1 mined Mars's moons** (13th, 10,390 credits) because about 85 drones crowded the Moon and nobody worked Phobos or Deimos. Travel's flat 5 fuel made distance cost only time.
- **Supply decided results again.** Moon Metals (A1) sold out by day 7, with about 91 drones there, while Metals (A2) sat in a glut at 4. Prices didn't rise with scarcity; it became a race for speed. Plan Bs that read `quotes` moved factories to Earth (Ines 1, Joel 2), at about half the Moon's rate.
- **All four wrote a generic recipe-tree pricer** using `recipe()`, `cost_to_buy`, `sale_value` or `quotes`, and `set_status`. The named functions were the most praised part of the round.
- **Same-company drones competed**: clones herded onto the same product and bid for the same scarce metals.
- **Clones cost net wealth**: about 5,000 credits each, and they took 10+ days to pay back. Cloning after day ~20 didn't pay.
- **Test world**: too slow to judge a plan's economics, and it couldn't test growth (1,000 starting credits). This round 40 finding is why the test world was removed (#414).
- **Ratings** (Ines, Joel, Lars, Mina): fun 7, clarity 7.5, depth 6.75, feedback 7, API 8.25, again 7.75, overall 7.25. Lua 8.25 to learn, 8.5 for fit.
- **Bugs reported**:
  - `/companies/{id}/history` stopped at day 8 (Mina, #420);
  - `/clock`'s `real_seconds_per_game_day` gives the target pace, not the pace the clock is running at (all four, #418);
  - api.md's `/recipes/{id}/tree` doesn't say the id is a resource (Lars, #419).
- **Asked for**:
  - a depth or restock signal per good and place, and House demand left today;
  - news a drone can read;
  - reservations or an atomic multi-input buy;
  - coordination between a company's own drones;
  - a deadlock warning ("credits below what any action needs");
  - per-drone profit and loss;
  - prices that rise with scarcity;
  - bigger, longer-lasting spreads between exchanges, so hauling pays;
  - deeper tiers that pay a premium per hour (Mina: alloys ~13, parts 7–15, systems 20–29, modules 24–32 credits an hour);
  - a bulk recipe-tree endpoint.

### Messages private to a company (#380, during round 40)

Drones' broadcasts are now readable only by drones of the sender's company: event searches (803, 804) leave out other companies' messages, `message` (844) and `event` (845) don't give their text, and the home feed says a message was sent without its text. Round 29's best company (Gia's fuel adverts, answering other companies' calls) relied on public messages; that play is gone, and messages are now for coordinating a fleet. Watch whether companies with several drones use them more now that rivals can't listen.

### No test world: every release is live, at 1,800× (#380, during round 40)

The test world was meant to let players try a script before releasing it. In practice it got in the way: in round 39 all four newcomers found it too slow or too opaque to trust (test drones sat "in transit" for 8 to 10 real minutes) and released mostly untested, and every round's newcomers spent their first half hour testing instead of playing. The model now:

- **No test world.** The Test button, `/api/v1/test`, the test world's database and engines, and the live-market mirror are gone.
- **Every release is live and free.** Iterate by releasing, watching (`set_status`, the drone page, post-mortems) and releasing a better version as a new company. A released drone still can't be changed; a failed company costs nothing.
- **The live clock runs at 1,800×**, the old test world's pace: a game day every 48 real seconds, a 40-day season in about 32 minutes. It slows when the engines fall behind (more than 10 real seconds), never below 60× (the old live pace).

What to watch in the next round: whether the engines keep 1,800× with the usual ~130 drones plus many more newcomer releases (the clock reports its pace); whether newcomers release more often and earlier, and learn faster; whether failed releases clutter the leaderboard or the market (abandoned sell orders, wrecks at the busy exchanges); and whether the House stations keep their stock up when a round of imports passes in seconds. Playtest rules change with it: newcomers may release as often as they like, and should be told the game is fast.

### Asking about the world by name (#380, after round 39)

Round 39's newcomers lost money to things their drones couldn't see. Hana 1 sat at Earth making valves for 636 while the Moon paid 1,061: its plan B counted idle rounds, and every valve it built reset the count. Fay 2 planned builds on metals at 1 credit from a miner with 2 units and was left with half-built parts. Everyone wanted best bid and ask by place, stock levels, profit and loss, and an idle reason. What changed:

- **Every question has a name** in the Lua library (about 50 functions: `hold()`, `sell_orders(r)`, `trip_to(p)`, `price_board(r)`, `deposits()`, `wrecks()`, `event(id)`...), returning records with named fields. The manual's chapter 8 teaches the names; query numbers are for the rest.
- **`quotes(r)`**: best bid and ask, the units at each, and the stock on sale, for every exchange: live here, an hour old elsewhere (query 615 rebuilds the order book as it stood then). `stock(r, place)` is the stock alone. A plan B can now compare places before it flies (`better_place` in `lua-functions.md`).
- **`cost_to_buy(n, r)`** and **`sale_value(n, r)`**: the whole lot, in depth, before a build starts.
- **`earnings(hours)`**: the company's sales, taxes, purchases and net, by good.
- **`set_status(text)`**: a line on the drone's page, in the API and in test results; test results also show where the drone is.

To watch in round 40: whether plan Bs that read `quotes` move factories to the better exchange (and whether many arrive at once, since everyone reads the same hour-old news), and whether failed half-builds fall.

### Round 39: newcomers on Lua only and 2 g travel (#380)

The round 38 mix on #409's code (FORTH retired, every House station and design in Lua, failed pulls refunded, drones accelerating at 2 g), to game day 40: 60 starters, 12 Chemists, 9 Smiths, 6 each of the Machinist, Adaptive and Systems shop, 3 each of Electronics and Foundry. The host restarted near day 36 (about four game days lost): every drone re-ran `launched` with its saved variables and carried on.

| Design | Companies | Avg net wealth at the end |
|---|---|---|
| Newcomers (Gus) | 2 | 12,058 |
| Newcomers (Emil) | 2 | 11,432 |
| Foundry | 3 | 8,804 |
| Systems shop | 6 | 8,748 |
| Electronics | 3 | 7,940 |
| Newcomers (Fay) | 2 | 7,860 |
| Newcomers (Hana) | 2 | 6,792 |
| Machinist | 6 | 5,854 |
| Maker (starter) | 12 | 5,516 |
| Smith | 9 | 4,252 |
| Adaptive | 6 | 3,843 |
| Miner (starter) | 24 | 3,698 |
| Refiner (starter) | 12 | 2,524 |
| Trader (starter) | 12 | 1,801 |
| Chemist | 12 | 1,418 |

- **Wrecks: 0 of 132 drones**, 15 clones.
- **2 g opened Mars.** Earth to Mars took 56 to 58 game hours (round 38: about 10.5 days). Three of the four newcomers' best companies worked Mars or its moons, and at the end 28 drones worked there (Mars 24, Phobos 3, Deimos 1) against 83 at the Moon and 21 at Earth. Gus 2 1st (16,340, a Mars systems shop with a standing Metals (A1) bid his own Mars-moons miner sold into), Emil 1 2nd (12,746, flew to Mars on day 1.8 for its empty market and caught the +32% systems boom), Fay 2 4th, Emil 2 5th, Hana 1 10th. The Mars niche lasted about two weeks before the crowd came, as the blog's "go where nobody is" warns.
- **Supply still decided results.** Metals (A1) sold out at the Moon by day 4, at Earth around days 18 to 22 and at Mars around day 24. Plan B rules that moved after some idle rounds saved Emil's and Gus's drones; Hana 1's never fired (each build reset its idle count) and it stayed at Earth while the Moon paid three times as much. A trip to or from Mars costs about 2.5 days of building, so a wrong move is dear.
- **Hauling still doesn't pay**: House asks are about twice House bids at every exchange, so Emil (logistics) found no lane; moving a factory was the only travel that paid.
- **Lua only**: no newcomer missed FORTH. Lua rated 8 to learn and 8.5 for fit. lua-functions.md, the example companies and `recipe()` were the most praised; all four wrote recipe-tree pricers.
- **Doc bugs found and fixed**: api.md's release example was still FORTH, as was the release page's hint and a design-manual line; the manual gave Earth to Mars as both 1.5 to 3.4 days and "4 to 25 game days"; the example companies and Miner and Refiner starters still had the old `fill_to` that waits for the whole order; `make(fuel)` makes 30 and burns 2, which `/recipes` shows as 28 made.
- **Ratings** (Emil, Fay, Gus, Hana): fun 7.25, clarity 7.25, depth 7.75, feedback 6.75, API 8, again 8, overall 7.5. Lua 8 to learn, 8.5 for fit.
- **Asked for**: a test world that's fast and shows the drone's location, status and game day (all four released mostly untested; test drones sat in transit to the Moon for 8 to 10 real minutes); best bid and ask per resource and place (an endpoint, and a query drones can use, for quotes as well as trades); House stock levels or a warning when an exchange runs out; depth-aware pricing ("cost to buy n here"); a per-company profit-and-loss and events feed; an idle reason in the drone's status; cheaper early growth; hauling lanes (regional scarcity, narrower House spreads on some goods); `print` of a place showing its name.

### FORTH retired; failed pulls cost no fuel (#380, after round 38)

- **Lua only.** Every newcomer since round 35 chose Lua but one, and round 38's FORTH company couldn't be tested. New releases, tests and checks now take Lua only; FORTH drones already running carry on. The starters and company designs (adaptive, chemist, electronics, foundry, machinist, smith, systems, and `words.lua`'s helpers), the players' manual, `lua-functions.md` (in place of `forth-words.md`), the blog and the House station program are Lua; the Machine page and the FORTH dictionary went with FORTH. The converted Foundry and Electronics ran cleanly in the test world; the Foundry's FORTH `brew-pays?` had a bug (a bitwise `and` of two prices), fixed in Lua.
- **A failed push or pull costs no fuel.** In round 38 every pull race lost at the crowded Moon cost a fuel; the engine now gives it back.
- **The library's own state is saved** (`mode`, its rounds, the orders `_if_changed` remembers): a restarted drone carries on in its mode instead of mode 0, and doesn't post its orders again.
- **Trips at 2 g** (`TRAVEL_ACCELERATION` 19.6 m/s², was 1): every trip takes 0.23 as long. The Moon is about 2.5 game hours from Earth (was 11), Mars 1.5 to 3.4 days (was 6.5 to 15; Earth to Phobos measured 255 hours in round 38, now about 58). In rounds 37 and 38 every drone that flew to Mars lost 10 of its 40 days on the way and arrived to a crowd.
- **Docs**: `recipe()`'s table, when saved variables come back (an engine restart: yes, after the top level runs; a crash: no), one clock pace (live 60 game seconds a real second, test 600), no `/buys` and `/sells` pages in the manual.

### Round 38: newcomers on cheaper fuel and bigger holds (#380)

The round 37 mix on #408's code as merged (fuel 3 credits, thinking 1 fuel per 200,000 instructions, holds of 1,000, the station's fuel kept topped up), to game day 40: 60 starters, 12 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive, 3 each of the Systems shop, Electronics and Foundry, and 3 each of the Lua Maker, Miner, Systems shop and Trader. No engine stall or crash. Three newcomers wrote Lua only; Dina wrote one company in each.

| Design | Language | Companies | Avg net wealth at the end |
|---|---|---|---|
| Newcomers (Dina) | Lua, FORTH | 2 | 14,072 |
| Systems shop | FORTH | 3 | 10,932 |
| Systems shop | Lua | 3 | 10,461 |
| Foundry | FORTH | 3 | 10,321 |
| Newcomers (Bea) | Lua | 2 | 10,246 |
| Newcomers (Cal) | Lua | 2 | 8,414 |
| Electronics | FORTH | 3 | 7,328 |
| Newcomers (Abe) | Lua | 2 | 5,761 |
| Machinist | FORTH | 6 | 5,030 |
| Miner, Maker, Refiner (starters) | FORTH | 48 | about 4,000 to 4,400 credits |
| Maker, Miner | Lua | 6 | about 3,300 to 3,800 |
| Smith | FORTH | 9 | 3,114 |
| Trader | Lua | 3 | 2,921 |
| Adaptive | FORTH | 6 | 2,677 |
| Trader (starter) | FORTH | 12 | about 1,900 credits |
| Chemist | FORTH | 12 | 863 |

- **Wrecks: 0 of 132 drones** (round 37: 50 of 129). The station's fuel fix did it: fuel was on sale all round, and no newcomer's plan was shaped by its price. Every starter Miner and Refiner survived without a fuel floor.
- **Leaderboard**: Bea 1 1st (17,490), Dina 2 2nd (16,743, FORTH), Systems 2 3rd, Cal 2 4th, Dina 1 6th. Newcomers took four of the top six, all with recipe-tree factories that priced every system from live prices each round.
- **Supply decided results.** Metals (A1) at the Moon were fought over from day 3 (about 95 drones there), and every failed pull still cost a fuel. What won: plan B to Earth (Dina 1 moved on day 5 and was 1st at day 30; Abe 1 on day 3.7), standing buy orders that the starter miners sell into (Cal 2: "the crowd became my supply chain"), and bringing inputs from Earth to build at the Moon (Dina 2).
- **Long hauls didn't pay.** All three drones sent to Mars (Abe 2, Bea 2, and briefly others) arrived after about 10.5 days to a crowd and no Metals (A1). No lane between the House's exchanges pays (asks about twice bids), so hauling pays only to move a factory or to supply players.
- **Growth is slow**: a copy costs about 5,000 against about 500 a day, so copies launched after day 20 barely paid back.
- **Bug found**: FORTH can't run in the test world. Test drones are numbered from 10,000,000 (#408), which doesn't fit a 16-bit cell, so `me` is 0 and every question about the drone's hold fails. Dina released her FORTH company untested.
- **Ratings** (Abe, Bea, Cal, Dina): fun 7.5, clarity 7, depth 7.25, feedback 6.75, API 8, again 8, overall 7.5. Lua 9 to learn, 8.25 for fit; FORTH (Dina) 6 and 7.
- **Asked for**: test drone ids FORTH can hold; no fuel for a pull that loses the race (or a pull that takes what's there); supply on offer by place and day, and stock levels drones can read; the shape of Lua's `recipe()` table and when saved variables come back, documented; a bulk recipe-tree endpoint; plainer test-run events (which action failed); one pace for the clock in the docs (api.md 2.4 minutes a day, the manual an hour); the manual's `/buys` and `/sells` links removed; hauling lanes (regional shortages, delivery bounties); cheaper early growth; wider margins between systems.

### Cheaper fuel, bigger holds (#380, after round 37)

- **The station's fuel was the wrecks' main cause.** station.fth topped up its fuel once a round, and a round (about 90 imports, an action each) took one to six game days, so the Moon Depot sold about 500 fuel every two days instead of 6,000 a day. It now tops up after every import and export, to its reserve plus a quarter of a day's supply.
- **Fuel costs less**: 3 credits a unit at every exchange (`FUEL_PRICE`, was 5; made goods' House prices count their fuel at 3), and thinking burns 1 fuel per 200,000 instructions (`POWER_INSTRUCTIONS`, was 100,000).
- **Bigger holds**: hull 1 (every released drone) and the Cruisers carry 1,000 (migration 0029, was 500), for long trips' fuel and bigger loads. Both kits unpacked (563) now fit in one drone; it still does one thing at a time.
- **The Trader starter** gathers 150 metals a trip (was 60), at least 40 (was 20), and counts a round trip at 200 (was 300).
- **Players' habits, not the House's rescue**: the players' manual ("Two habits that keep a drone alive") and blog 8 show keeping a fuel reserve and checking what's for sale, with somewhere else to go. The starters are unchanged: the Miner and Refiner still set no fuel floor.

### Round 37: newcomers on moving markets (#380)

The round 36 mix on #408's code (moving markets, the test world's copy of the live market, `recipe`, `fuel-floor`, the new Trader), to game day 40: 60 starters, 12 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive, 3 each of the Systems shop, Electronics and Foundry, and 3 each of the Lua Maker, Miner, Systems shop and Trader. No engine stall or crash all round. All four newcomers chose Lua.

| Design | Language | Companies | Avg net wealth at the end |
|---|---|---|---|
| Systems shop | FORTH | 3 | 13,225 |
| Systems shop | Lua | 3 | 12,104 |
| Newcomers (Wren) | Lua | 2 | 7,816 |
| Newcomers (Yusuf) | Lua | 2 | 6,185 |
| Newcomers (Viktor) | Lua | 2 | 5,568 |
| Newcomers (Uma) | Lua | 2 | 5,352 and a wreck |
| Foundry | FORTH | 3 | 3,387 |
| Maker (starter) | FORTH | 12 | about 2,500 |
| Maker | Lua | 3 | 2,830 |
| Smith, Machinist | FORTH | 15 | about 2,750 |
| Chemist | FORTH | 12 | about 1,575 |
| Trader (starter) | FORTH, Lua | 15 | all under their 1,000 (170 to 820 credits) |
| Miner, Refiner (starter) | FORTH | 36 | all wrecks |
| Miner | Lua | 3 | all wrecks |
| Adaptive | FORTH | 6 | all wrecks |

- **Wrecks**: 50 of 129 drones (round 36: 3), days 5 to 37, nearly all out of fuel at the Moon. About 95 drones worked the Moon; the Moon Depot's fuel sold out again and again (days 4, 20, 31...), and its metals (A1) and inorganics were gone from about day 13. The starter Miner and Refiner set no `fuel-floor`: their refuel posts a bid, waits and goes back to work, mining down to 0.
- **Leaderboard**: the six Systems shops 1st to 6th (11,800 to 14,100), whose `rest` flies to the other exchange after four idle rounds; then Wren 1 7th, Yusuf 2 8th, Viktor 1 9th.
- **Moving markets worked**: every newcomer saw prices move and three built for it (Yusuf's miner sells raw or refined by live prices, Wren's and Uma's factories price every system from the recipe tree each round, Viktor's hauler buys systems at Earth and sells them at the Moon, a pump bought at 388 sold at 1,229). The news was read but no drone can act on it.
- **What decided results was supply, not price**: inputs and fuel ran out at the crowded Moon. The price board shows what sold, not what can be bought, so Wren 2 flew to the Moon twice for metals that weren't there. Yusuf 2 alone at Phobos earned most.
- **Ratings** (Uma, Viktor, Wren, Yusuf): fun 7, clarity 7, depth 7.75, feedback 7.25, API 8, again 8, overall 7.25. Lua 8.75 to learn, 8 for fit.
- **Asked for**: starters with a fuel floor and a fallback exchange; a stock-out signal drones can read (fuel or inputs running low); deposits in the API; `sell_here` with a least price; `/check` warning when a script hides a game or Lua word (`load`, a `local mine`); copies that don't fly back to a starved home; `today()` counting from the start in the test world; leaving one's own other company out of "best other bid"; a per-company trade ledger; a third release after day 10; fix the manual's "Make Fuel → 30" (the recipe makes 28).

### Round 36: newcomers on #407's fixes (#380)

The round 35 world's mix, fresh from #407's code, to game day 40: 60 starters, 12 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive, 3 each of the Systems shop, Electronics and Foundry, and 3 each of the Lua Maker, Lua Miner and Lua Systems shop. No engine stall or crash all round.

| Design | Language | Companies | Avg gain |
|---|---|---|---|
| Newcomers (Rosa) | Lua | 2 | +17,019 |
| Newcomers (Tariq) | FORTH | 2 | +16,923 |
| Newcomers (Quinn) | Lua | 2 | +15,960 |
| Foundry | FORTH | 3 | +13,916 |
| Systems shop | FORTH | 3 | +12,385 |
| Systems shop | Lua | 3 | +12,150 |
| Electronics | FORTH | 3 | +11,175 |
| Maker (starter) | FORTH | 12 | about +4,600 |
| Machinist | FORTH | 6 | +4,711 |
| Miner (starter) | FORTH | 24 | about +4,400 |
| Maker | Lua | 3 | +4,531 |
| Smith | FORTH | 9 | +4,004 |
| Newcomers (Sven) | Lua | 2 | +3,458 |
| Miner | Lua | 3 | +3,362 |
| Refiner (starter) | FORTH | 12 | about +2,700 |
| Adaptive | FORTH | 6 | +2,437 |
| Chemist | FORTH | 12 | +1,520 |
| Trader (starter) | FORTH | 12 | 0 (they end with about 970 of their 1,000) |

- **Clones**: 30. **Wrecks**: 3, all out of fuel (two Adaptive, Sven 2).
- **Leaderboard**: Quinn 2 1st (+22,021), Rosa 1 2nd, Tariq 1 3rd: newcomers' Moon factories with standing metal bids beat the library. The Trader starter still loses money and needs work.

### Round 35: FORTH or Lua (#380)

A fresh world from #405's code with 60 starters, 12 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive, 3 each of the Systems shop, Electronics and Foundry, and 3 each of the Lua Maker, Lua Miner and Lua Systems shop, to game day 40. Four newcomers could write in either language. The world froze from day 5 to day 8 (a Lua save crashed the engine) and the Moon Depot's trades were held for about 7 game days from day 29 (an aborted trip), both fixed in the round.

| Design | Language | Companies | Avg gain |
|---|---|---|---|
| Systems shop | FORTH | 3 | +11,109 |
| Foundry | FORTH | 3 | +10,768 |
| Systems shop | Lua | 3 | +9,483 |
| Electronics | FORTH | 3 | +7,600 |
| Maker | Lua | 3 | +5,072 |
| Machinist | FORTH | 6 | +5,031 |
| Maker (starter) | FORTH | 12 | +4,658 |
| Adaptive | FORTH | 6 | +3,789 |
| Smith | FORTH | 9 | +3,776 |
| Miner (starter) | FORTH | 24 | +2,900 |
| Miner | Lua | 3 | +2,682 |
| Chemist | FORTH | 12 | +1,654 |
| Refiner (starter) | FORTH | 12 | +1,084 |
| Trader (starter) | FORTH | 12 | +24 |
| Newcomers | 7 Lua, 1 FORTH | 8 | 0 to +9,343 |

- **Clones**: 16. **Wrecks**: 2 (one Lua newcomer drone, from the library bug).
- **Leaderboard**: the Systems shops and Foundries first; Nico 1 (FORTH) 11th, Pablo 2 12th, Pablo 1 13th, Mara 2 about 16th.

### Every starter and the Systems shop, Lua beside FORTH (#380)

The same world, carried on: the Makers (from day 0) and Miners (day 2) to game day 38, and three companies each of the Refiner, Trader and Systems shop in both languages from day 26 (12 game days):

| Design | Companies | FORTH avg gain | Lua avg gain | FORTH instructions a drone | Lua | Crashes |
|---|---|---|---|---|---|---|
| Maker | 5 + 5 | +5,926 | +6,210 | 12.1 M | 2.2 M | 0 |
| Miner | 5 + 5 | +2,998 | +2,953 | 15.3 M | 4.2 M | 0 |
| Systems shop (12 days) | 3 + 3 | +3,087 | +3,102 | 16.3 M | 0.8 M | 0 |
| Refiner (12 days) | 3 + 3 | +89 | +292 | 13.0 M | 1.3 M | 0 |
| Trader (12 days) | 3 + 3 | +114 | +188 | 7.1 M | 0.7 M | 0 |

Every Lua port earns what its FORTH twin does (more, for the short-lived Refiner and Trader, which think less and so burn less fuel), and not one drone of 38 crashed in either language.

### Lua drones beside FORTH ones (#380)

A fresh world from the Lua engine (#404) with 20 starters, five companies each of the FORTH and Lua Maker (`maker.fth`, `maker.lua`, Factory Kit) and, from day 2, of the FORTH and Lua Miner (Mining Kit), to game day 8:

| Design | Companies | Avg gain | Instructions a drone | Crashes |
|---|---|---|---|---|
| Maker, Lua | 5 | +1,811 | 553,579 | 0 |
| Maker, FORTH | 5 | +1,694 | 10,466,817 | 0 |
| Miner, Lua (from day 2) | 5 | +384 | 639,812 | 0 |
| Miner, FORTH (from day 2) | 5 | +352 | 12,522,131 | 0 |

- The two languages earn the same: the Lua drones run the same plan, and sold rods at the Moon at 151 a rod against the FORTH Makers' 144.
- The Lua drones used about a twentieth of the instructions (each Lua instruction charged as six), so they burn less fuel thinking; most of a FORTH drone's count is reading its script.

### After round 34: a check world with its fixes (#380)

A fresh world from the round 34 fixes (prices by place, the new Maker, test runs that load at once), the round 34 mix, to game day 12:

| Design | Companies | Avg gain by day 12 | Gaining |
|---|---|---|---|
| Foundry | 3 | +3,604 | 3 |
| Systems shop | 3 | +3,293 | 3 |
| Maker (starter) | 12 | +2,449 | 12 |
| Electronics | 3 | +2,375 | 2 |
| Adaptive | 6 | +1,769 | 6 |
| Machinist | 6 | +1,534 | 6 |
| Miner (starter) | 24 | +1,053 | 24 |
| Smith | 9 | +529 | 3 |
| Refiner (starter) | 12 | +386 | 12 |
| Chemist | 12 | +335 | 4 |

- The Makers sold all their rods at the Moon (294, at 155 a rod), none at Earth, and the lowest tank among them was 21. In round 34 they averaged +4,110 over 40 days; here +2,449 in 12.
- Players traded 2,828 units among themselves at the Moon in 1,369 trades.
- The Smiths and Chemists, which sell refined goods, lost the most: the Moon now pays 0.85 of a refined good's value where it paid 1.0. Watch them in the next round.

### Round 34: the race winners in the mix (#380)

A fresh world from #404's code (fast holdings lookups, the three race winners in the library) with 60 starters, 12 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive, and 3 each of the Systems shop, Electronics and Foundry, to game day 40. Four newcomers played through the JSON API; Kofi reported at day 18 and Iris at day 36, their companies ran on.

| Design | Companies | Avg gain | Gaining |
|---|---|---|---|
| Systems shop | 3 | +15,933 | 3 |
| Foundry | 3 | +8,677 | 3 |
| Machinist | 6 | +6,306 | 6 |
| Maker (starter) | 12 | +4,110 | 12 |
| Miner (starter) | 24 | +3,716 | 24 |
| Smith | 9 | +3,008 | 9 |
| Electronics | 3 | +2,897 | 1 |
| Adaptive | 6 | +2,445 | 5 |
| Chemist | 12 | +2,136 | 12 |
| Refiner (starter) | 12 | +1,670 | 12 |
| Trader (starter) | 12 | 0 | 0 |
| Newcomers | 8 | +3,770 to +14,579 | 8 |

- **Trades**: 10,249 (station drones selling 2,855, buying 3,392; player to player 4,002).
- **Clones**: 13. **Wrecks**: 1 of 126 drones.
- **Leaderboard**: Systems 1, 2 and 3, then Jago 1 4th, Kofi 1 5th, Jago 2 6th, Iris 2 7th.
- **The engine**: 530 to 690 slices a minute all round, the clock at 560 to 600 (the race before #404 fell to 88 slices and a tenth of the clock).

### Starter-library race: rounds 28-33's best scripts (#380)

A fresh world from #403's code with the usual mix (60 starters, 18 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive) and 34 newcomer scripts from rounds 28 to 33, each released once (factory kit, or mining kit for Dax and Hal), to game day 40. Rounds 26 and 27's scripts were left out: they used `house-price`, which is gone.

| Design | Companies | Avg gain by day 40 | Gaining |
|---|---|---|---|
| Farid 2 (systems shop) | 1 | 14,380 | 1 |
| Elif 1 (pistons, pumps, valves) | 1 | 13,327 | 1 |
| Elif 2 (electronics) | 1 | 9,524 | 1 |
| Ines 1 (foundry) | 1 | 9,516 | 1 |
| Farid 1 | 1 | 8,719 | 1 |
| Hugo 2 | 1 | 8,249 | 1 |
| Hal 1 (mining kit) | 1 | 6,550 | 1 |
| Machinist | 6 | 6,425 | 6 |
| Miner (starter) | 24 | 5,267 | 24 |
| Refiner (starter) | 12 | 4,881 | 12 |
| Maker (starter) | 12 | 4,648 | 12 |
| Adaptive | 6 | 3,600 | 6 |
| Smith | 9 | 3,178 | 9 |
| Chemist | 18 | 662 | 18 |
| Trader (starter) | 12 | 25 | 2 |

The other 27 race scripts gained less than the Machinist; Cyrus 1 and Greta 2 were wrecked. Added to the library: Farid 2 as `systems.fth`, Elif 2 as `electronics.fth` and Ines 1 as `foundry.fth`. Elif 1 makes the same goods as Farid 2 and earned less, so it stays out.

The race first crawled at a tenth of its speed: the `sqInventory` view works out every drone's holdings before it filters, and station drones look up their stock hundreds of times a round. #404 makes those lookups read one drone's events directly (from 88 to 334 engine slices a minute at once; the clock returned to full speed).

### Round 33: every product at every exchange, the House buying low (#380)

A fresh world from #402's code (every exchange buys and sells every product it can list, with deeper stocks, House buys at 75% and sells at 150% of a good's value), the same mix (60 starters, 18 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive), to game day 40. Four newcomers played through the JSON API; Greta reported at day 8, her companies ran on.

| Design | Companies | Avg gain | Clones |
|---|---|---|---|
| Machinist | 6 | +6,086 | 3 |
| Maker (starter) | 12 | +3,479 | 0 |
| Adaptive | 6 | +3,288 | 0 |
| Miner | 24 | +3,199 | 0 |
| Smith | 9 | +2,709 | 0 |
| Chemist | 18 | +1,681 | 0 |
| Refiner | 12 | +1,603 | 0 |
| Trader | 12 | +20 | 0 |
| Newcomers | 8 | +5,064 to +10,295 (per player) | 2 |

| Trades | Count | Units | Credits |
|---|---|---|---|
| Station drones selling | 2,508 | 49,906 | 371,421 |
| Station drones buying | 3,434 | 12,772 | 697,379 |
| Player to player | 3,880 | 8,088 | 111,593 |

- **Wrecks**: 2 of 115 drones, both on fuel (a starter Maker and a starter Miner).
- **Leaderboard**: Farid 2 1st (19,104), Greta 2 2nd, Elif 1 3rd, Elif 2 4th, Hugo 2 7th, Greta 1 8th.
- **Ratings**: Elif 7, Farid 7.5, Greta 7.5, Hugo 7 overall.

### Round 32: the round 31 fixes (#380)

A fresh world from #402's code (flight destinations, the growth floor, the manual without email, and the Market history page from #401), the same mix (60 starters, 18 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive), to game day 40. Four newcomers played through the JSON API; Cyrus and Dina reported at days 12 and 29, their companies ran on.

| Design | Companies | Avg gain | Clones |
|---|---|---|---|
| Machinist | 6 | +10,417 | 6 |
| Maker (starter) | 12 | +6,703 | 0 |
| Smith | 9 | +5,818 | 2 |
| Miner | 24 | +5,017 | 8 |
| Chemist | 18 | +3,415 | 5 |
| Refiner | 12 | +3,277 | 0 |
| Adaptive | 6 | +3,159 | 0 |
| Trader | 12 | +60 | 0 |
| Newcomers | 8 | +5,188 to +12,885 (Cyrus 2 ended) | 1 |

| Trades | Count | Units | Credits |
|---|---|---|---|
| Station drones selling | 2,865 | 47,216 | 500,107 |
| Station drones buying | 3,662 | 14,852 | 928,008 |
| Player to player | 4,526 | 11,722 | 151,868 |

- **Wrecks**: 2 of 132 drones, both on fuel (a starter Maker at Earth on day 25, Cyrus 2 at the Moon on day 27).
- **Leaderboard**: Machinist 1 first; Cyrus 1 3rd, Amir 1 5th, Bella 1 7th, Amir 2 9th.

### Round 31 rerun: through the API, with working station drones (#380)

The same world mix as round 31 (60 starters, 18 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive), built again with the engine lock, to game day 40. All three station drones launched with no errors. Four new newcomers played only through the JSON API.

| Design | Companies | Avg gain | Clones |
|---|---|---|---|
| Machinist | 6 | +9,687 | 6 |
| Maker (starter) | 12 | +7,521 | 0 |
| Smith | 9 | +6,051 | 4 |
| Miner | 24 | +4,639 | 14 |
| Adaptive | 6 | +4,427 | 0 |
| Refiner | 12 | +4,115 | 0 |
| Chemist | 18 | +4,005 | 0 |
| Trader | 12 | +150 | 0 |
| Newcomers | 8 | +4,204 to +11,768 | 6 |

| Trades | Count | Units | Credits |
|---|---|---|---|
| Station drones selling | 2,993 | 31,481 | 467,221 |
| Station drones buying | 3,902 | 15,021 | 909,747 |
| Player to player | 4,557 | 18,316 | 154,753 |

- **Wrecks**: none of 140 drones. No company ended.
- **Leaderboard**: Tomas 2 1st, Rosa 2 4th, Omar 2 5th, Rosa 1 7th, Tomas 1 9th.

### Round 31: through the API, with no station drones (#380)

A fresh world, the same mix as round 30 (60 starters, 18 Chemists, 9 Smiths, 6 Machinists, 6 Adaptive), to game day 40. Four newcomers played only through the JSON API. The station drones never started (see the findings), so this is a world without the House.

| Design | Companies | Ended | Avg net wealth at the end |
|---|---|---|---|
| Trader | 12 | 0 | 4,920 |
| Smith | 9 | 3 | 2,302 |
| Miner | 24 | 24 | 14 |
| Refiner | 12 | 12 | 7 |
| Maker, Chemist, Machinist, Adaptive | 42 | 42 | 0 |
| Newcomers | 8 | 8 | 0 to 6 |

- **Trades**: 707, all player to player (19,103 credits); the station drones made none.
- **Wrecks**: 89 of 104 player drones, all out of fuel. No clones.
- **Fuel**: brewers sold at 3 to 8 while organics lasted; by day 10 nobody at the Moon sold fuel.

### Round 30: the Strategy Blog (#380)

A fresh world with the Strategy Blog, the undefined-word warning, a deposits table and starters that call for fuel: 60 starters, plus 18 Chemists, 9 Smiths, 6 Machinists and 6 Adaptive, to game day 40. Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Machinist | Factory | +16,979 | 14 |
| Maker (starter) | Factory | +9,048 | 0 |
| Adaptive | Factory | +6,541 | 0 |
| Smith | Factory | +6,221 | 7 |
| Miner | Mining | +6,094 | 23 |
| Refiner | Mining | +4,821 | 7 |
| Chemist | Factory | +3,343 | 6 |
| Trader | Mining | +143 | 0 |

| Trades | Units |
|---|---|
| Station drones selling | 30,699 |
| Station drones buying | 18,208 |
| Player to player | 32,392 |

- **Broadcasts**: 129 (Mara 72, Kira 22, the starters' fuel calls 35). Mara's drones listened and answered.
- **One wreck** (fuel, Mara 1's parent after a launch) and no company ended, out of 108.
- **No rod buyers**: the Maker starters still gained most of the starters, from polymers and alloys.

### Round 29: messages and crowd counts (#380)

A fresh world with broadcasts (protocol 40, read as events) and the crowd count (query 721): 60 starters, plus 18 Chemists, 9 Smiths, 6 Machinists and 6 Adaptive, to game day 40. Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Machinist | Factory | +12,454 | 13 |
| Maker (starter) | Factory | +8,118 | 0 |
| Smith | Factory | +6,979 | 7 |
| Refiner | Mining | +5,585 | 1 |
| Miner | Mining | +4,985 | 22 |
| Chemist | Factory | +4,023 | 6 |
| Adaptive | Factory | +3,809 | 0 |
| Trader | Mining | +58 | 0 |

| Trades | Units |
|---|---|
| Station drones selling | 35,880 |
| Station drones buying | 15,463 |
| Player to player | 24,701 |

- **Broadcasts**: 58, all from Gia's drones. Nobody else listened for them, so the starters should broadcast and listen next.
- **One wreck** (fuel) and one company ended, out of 108.
- **Miners grew** (22 clones, against none in round 28): the six-dry-tries move to Phobos spread them out, though it costs a 10-day trip.

### Round 28: no House information (#380)

A fresh world after the House became a market participant like any other drone (#392, #393): station drones only, no batch stations, no published House prices, no free fuel. 60 starters, plus 18 Chemists, 9 Smiths, 6 Machinists and 6 Adaptive, to game day 40.3 (the clock ran slower than set, about 0.4 game days a real minute, with 120 drones). Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Machinist | Factory | +15,635 | 13 |
| Maker (starter) | Factory | +9,296 | 0 |
| Smith | Factory | +5,369 | 5 |
| Refiner | Mining | +5,293 | 9 |
| Adaptive | Factory | +4,614 | 0 |
| Chemist | Factory | +4,042 | 6 |
| Miner | Mining | +3,045 | 0 |
| Trader | Mining | +156 | 0 |

| Trades | Count | Units | Credits | Tax |
|---|---|---|---|---|
| Station drones selling | 3,269 | 24,176 | 544,858 | 27,470 |
| Station drones buying | 3,143 | 12,700 | 956,999 | 48,026 |
| Player to player | 5,665 | 29,482 | 206,121 | 10,843 |

- **Players traded with each other more than with the House** (29,482 units): fuel (21,876 at 3.2), Metals (A1) (3,971 at 20.5), Inorganics (A1), Organics (A1), Metals (A2) and (A3). The station drones bought the made goods (rods 2,133, polymers 4,931, gear boxes 135).
- **Two wrecks** (fuel) and one company ended, out of 99.
- **Copies crowd each other**: dozens of starter miners at the Moon held Metals (A1) at the station drone's bid; the starter makers pushed Earth's rod bid from 225 to 113.

### After round 27: the batch stations retired (#380)

`sell-to-station` and `buy-from-station` (protocols 31 and 32) now trade at once with the House station drone where the drone is, as a push and a pull: as many as its order takes and the drone has (buying: as the company can pay for and the hold can take). A scratch world of 20 starters and 6 company designs on the new engine, to game day 6: the station drones sold 1,817 units and bought 746 (metals, inorganics, 44 rods), players traded 156 units with each other, nothing was offered to a batch, and no drone was wrecked. The only refused sales were the Machinists' gear boxes before day 7, when the Earth Exchange starts buying them.

### Round 27: the House trades as drones (#380)

A fresh world with House station drones at the three exchanges beside the batch stations: the Earth Exchange, Moon Depot and Mars Exchange as drones (huge holds, Import and Export modules), trading by the players' rules. 60 starters, plus 18 Chemists, 9 Smiths, 6 Machinists and 6 Adaptive, to game day 40.2 (the container restarted at day 7.5 and the world came back at day 13.9). Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Maker (starter) | Factory | +7,627 | 0 |
| Machinist | Factory | +6,960 | 5 |
| Smith | Factory | +5,723 | 3 |
| Refiner | Mining | +4,953 | 0 |
| Chemist | Factory | +4,886 | 0 |
| Miner | Mining | +2,802 | 0 |
| Adaptive | Factory | +1,251 | 0 |
| Trader | Mining | 0 | 0 |

| Trades | Count | Units | Credits | Tax |
|---|---|---|---|---|
| Station drones selling | 1,744 | 23,626 | 244,785 | 12,504 |
| Station drones buying | 738 | 3,158 | 92,768 | 4,794 |
| Player to player | 2,906 | 5,929 | 121,619 | 6,376 |
| Batch stations selling | 645 | 19,745 | 165,795 | – |
| Batch stations buying | 2,625 | 8,315 | 753,556 | 37,863 |

- **The station drones sold more than the batch stations** (23,626 units, against 19,745): fuel and raw goods, pulled at once. Players traded with each other as much as in round 26 (5,929 units).
- **The batch stations still bought the made goods** (rods, polymers, gear boxes: 753,556 credits), because the scripts sell them with `sell-to-station`. The station drones bought mostly raw goods pushed by `sell-all`: Inorganics (A2), Metals (A1, A2, A3). Retiring the batch stations means pointing `sell-to-station` and `buy-from-station` at the drones.
- **The drones start slowly:** their first orders were up 1.3 game days into the world, after bringing up their stock.
- One wreck (Arlo's Mars company, for fuel: the `buy-cheapest` trap, fixed). No company ended.

### Round 26: miners and makers trade (#380)

The two kits (a drone mines or makes, not both), the House selling raw goods at half as much again as it pays, and market-aware drones, in a fresh world: 48 Mining Kit companies and 51 Factory Kit ones, to game day 40.1. Four newcomers joined.

| Design | Kit | Avg gain | Clones |
|---|---|---|---|
| Maker (starter) | Factory | +8,088 | 0 |
| Machinist | Factory | +5,621 | 0 |
| Smith | Factory | +5,239 | 7 |
| Chemist | Factory | +4,569 | 5 |
| Refiner | Mining | +4,230 | 6 |
| Miner | Mining | +2,798 | 0 |
| Adaptive | Factory | +2,337 | 0 |
| Trader | Mining | 0 | 0 |

- **Players trade.** 3,326 Metals (A1) at 24.8, 1,350 Inorganics (A1), 830 Organics (A1) and 612 fuel moved between players (round 25: 775 Metals (A1) in all). No company ended and no drone was wrecked.
- **Raw producers keep up.** Refiners gained +4,230 and launched 6 clones; Miners +2,798. Makers still lead: the Maker starter, which buys metals and sells rods at Earth, gained +8,088.
- **The House budgets were the wall.** 53 Motor batches at Earth bought nothing and pumps and gear boxes mostly came back unsold: the rods spent the Earth Exchange's 10,000 a day. Fixed for the next world (budgets cover the daily demand).
- **Traders** made one trip to Mars each (10 game days a way) and barely broke even in 40 days; the Adaptive design rested often while Earth's budget was spent.

### Round 25: makers buy from miners, a little (#380)

A fresh world (game data with the steeper wages: 20, 30 and 45 a game hour by tier) with the miners asking a quarter over the House and the makers buying from them first, to game day 40.2. Four newcomers joined.

| Design | Avg gain | Clones |
|---|---|---|
| Machinist | +11,807 | 5 |
| Smith | +4,965 | 7 |
| Chemist | +4,055 | 11 |
| Miner | +2,287 | 0 |
| Refiner | +1,287 | 0 |

- **Makers still lead, raw producers still don't grow.** 775 Metals (A1) and 187 Metals (A2) moved between players in 40 days (round 24: 966 in 71); the House bought 762 Metals (A1) in the last five days alone. A premium on the miners' price doesn't make makers stop mining while they carry every module.
- **A 68-day scratch run with the kits** (the next change: mining gear too big to carry with the factory tools, the House selling raw goods at half as much again as it pays): Miners grew from 6 drones to 20, every design but the Trader survived, and 1,343 Metals (A1) moved between players. The traders spent every credit on metals and died for want of fuel; they now keep 200, and read a seller's row before asking anything else.


### Round 1: Chemist (#380)

A world of 108 starter companies (54 miners, 36 refiners, 18 traders); 12 Chemists released at game day 10.5, measured at day 21.1.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist | 12 | 1,991 | +786 | 12 | 0 |
| Miner | 54 | 2,328 | +1,168 | 54 | 0 |
| Refiner | 36 | 1,471 | +431 | 36 | 0 |
| Trader | 18 | 1,187 | 0 | 0 | 0 |

- The House now buys polymers and inorganics as well as metals: 5 goods instead of 3.
- The Moon's yalspar and vladnium deposits stayed at about 97% full: room for many more Chemists. Moon kunspar stayed mined out.
- Drone-to-drone trade fell to almost nothing in the last 5 days: the traders had filled up and gone to Mars.

### Round 2: Smith (#380)

12 Smiths released at game day 21.1, measured at day 31.8.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist | 12 | 2,904 | +1,626 | 12 | 0 |
| Miner | 54 | 3,171 | +1,957 | 54 | 0 |
| Refiner | 36 | 1,654 | +611 | 36 | 0 |
| Trader | 18 | 586 | 0 | 0 | 0 |
| Smith | 12 | 990 | +710 | 12 | 0 |

- Smiths gain about 67 a game day on paper (rods at House prices), but at day 10 they held 10 rods each and had not yet made a trip to Earth: an alloy and a rod take 6 game hours per rod.
- Chemists kept their pace: about 78 a game day between rounds.
- Drone-to-drone trade came back (about 200 trades in 5 days) once traders returned from Mars.
- House stations are far from their budgets: the Moon Depot spent about 1,800 a game day of its 5,000, at 97% of its base prices.

### Round 3: 24 more Chemists (#380)

24 Chemists released at game day 31.8 (36 in all), measured at day 42.5.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist | 36 | 2,276 | +1,012 | 36 | 0 |
| Miner | 54 | 3,868 | +2,721 | 54 | 0 |
| Refiner | 36 | 1,712 | +670 | 36 | 0 |
| Trader | 18 | 1 | 0 | 0 | 16 |
| Smith | 12 | 0 | 0 | 0 | 12 |

- **Every Smith died with a full hold.** Its hold filled with goods it kept for Earth plus Inorganics (A2), which nobody buys. Then making fuel failed for lack of space (-3), and it ran dry. Chemists were at 452 of 500 cargo and heading the same way.
- **16 of 18 Traders ran dry while shopping on the Moon.** They only bought fuel before a trip, and with more drones competing for miners' metal, most of their pulls failed (-5).
- **Yalspar ran short.** The Moon's yalspar fell from about 1,950 to 541: 36 Chemists use more than it refills.
- **The Moon Depot's budget ran short.** It fell to about 500 of 5,000.
- **Fixes for round 4:**
  - Chemist and Smith version 2 sell every by-product; the Smith sells everything but rods at the Moon as it goes, and hauls 12 rods instead of 20.
  - The Trader tops up fuel while it shops.
  - Levers: the Moon Depot's budget goes to 10,000, and Earth and the Moon buy the by-products.

### Round 4: fixed designs (#380)

12 each of Chemist v2, Smith v2 and Trader v2 released at game day 42.5, measured at day 53.7.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist (v1) | 36 | 2,764 | +1,699 | 36 | 0 |
| Chemist v2 | 12 | 1,350 | +165 | 12 | 0 |
| Smith v2 | 12 | 1,153 | +4 | 9 | 0 |
| Trader v2 | 12 | 481 | 0 | 0 | 0 |
| Miner | 54 | 4,348 | +3,205 | 54 | 0 |
| Refiner | 36 | 1,726 | +734 | 36 | 0 |

- No new design died: selling by-products and refuelling while shopping work. The Moon Depot bought 37 Inorganics (A2) in 5 days.
- The House now buys 6 goods (from 3 at the start).
- Newcomers earn little: 48 Chemists and 12 Smiths share Moon yalspar that supports about 14 (426 of 2,000 left). Late arrivals at a full site make almost nothing; spread them out.
- The Moon Depot's budget is no longer short (9,872 of 10,000 left).
- **Round 5:** Chemist v3 spreads over the Moon, Phobos and Deimos by drone number, and the Mars Exchange buys polymers, inorganics and organics too.

### Round 5: Chemist v3 spreads out (#380)

12 Chemist v3 released at game day 53.7, measured at day 80.5. Jettison was added along the way (game data reloaded at about day 70, which refilled every deposit).

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist (v1) | 36 | 3,453 | +2,491 | 36 | 0 |
| Chemist v2 | 12 | 2,221 | +1,017 | 12 | 0 |
| Chemist v3 | 12 | 1,562 | +232 | 12 | 0 |
| Smith v2 | 12 | 1,722 | +552 | 12 | 0 |
| Trader v2 | 12 | 247 | 0 | 0 | 0 |
| Miner | 54 | 4,868 | +3,744 | 54 | 0 |
| Refiner | 36 | 1,714 | +747 | 36 | 0 |

- The House now buys 7 goods, rods included (from 3 at the start).
- Chemist v3 copies split 4, 4 and 4 over the Moon, Phobos and Deimos. The Moon copies gained about 380; the Mars-side copies about 150, after a 10-day trip.
- Smiths reached Earth, but sold 144 rods in one batch at about 33 each: the Exchange wants about 50 a day. Smith v3 (round 6) sells 4 an hour.
- Trader v2: 7 at Mars selling, 5 on the Moon holding 30 metals with 9 credits. They sold 150 metals at about 36 after buying at about 31.
- No company has died since round 3.

### Round 6: Smith v3 (#380)

12 Smith v3 (rods sold 4 an hour) released at game day 80.5, measured at day 96.6. The container restarted part way: the engines were down a few real minutes while the game clock ran on.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist (v1) | 36 | 3,733 | +2,791 | 36 | 0 |
| Chemist v2 | 12 | 2,598 | +1,374 | 12 | 0 |
| Chemist v3 | 12 | 2,044 | +746 | 12 | 0 |
| Smith v2 | 12 | 1,822 | +1,024 | 12 | 0 |
| Smith v3 | 12 | 1,076 | 0 | 0 | 0 |
| Trader v2 | 12 | 368 | 0 | 0 | 0 |
| Miner | 54 | 5,138 | +3,963 | 54 | 0 |
| Refiner | 36 | 1,755 | +808 | 36 | 0 |

- Smith v3 is inconclusive: released just before the restart, it had made only one round of rods by day 96.6.
- Chemist v3 gained about 32 a game day this round, Smith v2 about 29.
- The engines are at capacity with about 200 drones on this machine: the clock floated down to 450 game seconds a second (from 600), with about 45 real seconds of lag. Each drone then does less per game day.
- House purchases in the last 5 days fell (Metals (A2) 165 units, from 441 in round 5) while every deposit stayed full: output is now limited by the engines, not by minerals.
- **Round 7 (a lever):** House prices for parts and systems up 50% (rods 60 to 90, sheets 90, fasteners 75, pipes 105, systems 450).

### Round 7: parts and systems prices up 50% (#380)

Measured at game day 104.7, only 8 game days later: the clock had floated down to 337 game seconds a second.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist (v1) | 36 | 3,900 | +2,959 | 36 | 0 |
| Chemist v2 | 12 | 2,850 | +1,674 | 12 | 0 |
| Chemist v3 | 12 | 2,305 | +977 | 12 | 0 |
| Smith v2 | 12 | 1,900 | +1,535 | 12 | 0 |
| Smith v3 | 12 | 1,186 | +309 | 12 | 0 |
| Miner | 54 | 5,256 | +4,141 | 54 | 0 |
| Refiner | 36 | 1,751 | +798 | 36 | 0 |

- Inconclusive: no Smith sold rods in these 8 days. Smiths' gains jumped only on paper (rods held are valued at the new price); Smith v2's cash rose 78, from by-products sold at the Moon.
- The world had slowed to about half speed after 105 game days, so the rounds moved to a fresh world seeded with the recommended mix (round 8).

### Round 8: a fresh world, seeded with the recommended mix (#380)

A new world with the game data so far plus parts prices up 50%: 36 Miners, 12 Refiners, 36 Chemist v3 and 6 Smith v3, released at once, measured at game day 18.8.

| Design | Companies | Avg credits | Avg gain | Gaining | Moon copies | Mars-side copies |
|---|---|---|---|---|---|---|
| Smith v3 | 6 | 2,264 | +1,513 | 6 | +1,552 | +1,528 |
| Miner | 36 | 2,166 | +1,039 | 36 | +2,496 | +309 |
| Chemist v3 | 36 | 2,227 | +947 | 36 | +1,771 | +553 |
| Refiner | 12 | 1,687 | +662 | 12 | +996 | +487 |

- Every company gaining, none ended; the clock at full speed (600) with 4 seconds of lag.
- The House bought 7 goods; Smiths sold 72 rods at about 72 each (base 90).
- Weak spot: hardly any drone-to-drone trade (5 fuel sales in 5 days). With no traders, nobody buys the Moon miners' sell orders. **Round 9:** Smith v4 buys Metals (A1) from them to make more rods (two metals at 23 make a rod worth 90).

### Round 9: Smith v4 buys metals from miners (#380)

6 Smith v4 released at game day 18.8, measured at day 31.7.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Smith v3 | 6 | 3,259 | +2,455 | 6 | 0 |
| Smith v4 | 6 | 1,646 | +897 | 6 | 0 |
| Miner | 36 | 3,395 | +2,252 | 36 | 0 |
| Chemist v3 | 36 | 3,486 | +2,188 | 36 | 0 |
| Refiner | 12 | 1,968 | +1,010 | 12 | 0 |

- Smith v4 gains about 69 a game day in its first 13 days, Smith v3 about 77 over 32: buying metals costs about what mining them does, but it is trade.
- Drone-to-drone trade: Smith v4 bought 45 metals in 24 trades, refiners sold fuel to miners. Still small: Moon miners sell most metals to the Depot before anyone buys.
- Rods: 187 sold at about 74 each; the Earth Exchange's budget is no longer short (9,660 of 10,000).
- **Round 10:** a new design, the Machinist, builds gear boxes (450 at Earth and Mars) from metals bought from the miners.

### Round 10: the Machinist (#380)

6 Machinists released at game day 31.7, measured at day 57.4.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist v3 | 36 | 5,400 | +4,147 | 36 | 0 |
| Miner | 36 | 5,122 | +3,996 | 36 | 0 |
| Smith v3 | 6 | 5,131 | +3,915 | 6 | 0 |
| Smith v4 | 6 | 2,971 | +2,738 | 6 | 0 |
| Refiner | 12 | 2,212 | +1,273 | 12 | 0 |
| Machinist | 6 | 1,500 | +1,144 | 4 | 2 |

- Machinists built gear boxes without a single failed step and sold 16 at Earth at about 392 each (base 450). The House now buys 9 goods (gear boxes and organics added).
- Two Machinists died at Earth: they left the Moon nearly out of fuel, and at Earth every offer and even the fuel order failed for lack of the 1 fuel each action burns. Machinist v2 (round 11) brews before leaving if below 30, and buys fuel first on arrival.
- Drone-to-drone trade grew: Smith v4 and Machinists bought 186 metals from miners in 122 trades; refiners sold 1,140 fuel to miners.

### Round 11: Machinist v2 (#380)

6 Machinist v2 released at game day 57.4, measured at day 72.3.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Smith v3 | 6 | 5,160 | +4,826 | 6 | 0 |
| Chemist v3 | 36 | 6,038 | +4,812 | 36 | 0 |
| Miner | 36 | 5,710 | +4,618 | 36 | 0 |
| Smith v4 | 6 | 3,816 | +3,340 | 6 | 0 |
| Refiner | 12 | 2,289 | +1,362 | 12 | 0 |
| Machinist v2 | 6 | 950 | +387 | 6 | 0 |
| Machinist (v1) | 6 | 697 | +583 | 2 | 4 |

- No Machinist v2 died; none had made its first Earth trip yet. It gains about 26 a game day so far: the gear box chain burns a lot of fuel. Not yet a seed design.
- Two more version 1 Machinists died at Earth the same way.
- Rods: 525 sold at about 79. The Smith is the steadiest new design.
- **Round 12:** Smith v5 spreads over the Moon, Phobos and Deimos; the Mars-side copies sell rods at the Mars Exchange (a short hop) instead of Earth.

### Round 12: Smith v5 spreads out (#380)

9 Smith v5 released at game day 72.3, measured at day 87.3.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist v3 | 36 | 6,534 | +5,320 | 36 | 0 |
| Smith v3 | 6 | 5,903 | +5,118 | 6 | 0 |
| Miner | 36 | 6,172 | +5,057 | 36 | 0 |
| Smith v4 | 6 | 4,526 | +3,758 | 6 | 0 |
| Refiner | 12 | 2,290 | +1,330 | 12 | 0 |
| Machinist v2 | 6 | 1,683 | +1,015 | 5 | 0 |
| Smith v5 | 9 | 1,036 | +16 | 3 | 0 |

- Machinist v2 reached Earth and sold 16 gear boxes (32 in all, about 379 each), with no deaths. All six version 1 Machinists have died.
- Smith v5 is inconclusive: the Mars-side copies were still arriving.
- **House purchases kept falling while deposits stayed full** (Metals (A1) per 5 game days: 791, 686, 529, 379, 322 over rounds 8 to 12). The engines, not the minerals, were the limit: commands ran about 26 real seconds late, which at 600 is about 4 game hours per turn. Each miner managed about 13 mining runs in 5 game days.

### Rounds 13 and 14: a prompter clock, faster queries, Machinist v3 (#380)

Round 13 set the test world's clock floor to slow the clock at 10 real seconds of lag; round 14 added migration 0020's indexes and released 6 Machinist v3. Measured at game days 92.5 and 93.8 (the clock settled at about 40 to 60 game seconds a second).

| Design | Companies | Avg gain (day 93.8) | Gaining | Ended |
|---|---|---|---|---|
| Chemist v3 | 36 | +6,060 | 36 | 0 |
| Miner | 36 | +5,794 | 36 | 0 |
| Smith v3 | 6 | +5,483 | 6 | 0 |
| Smith v4 | 6 | +4,230 | 6 | 0 |
| Machinist v2 | 6 | +1,589 | 5 | 1 |
| Refiner | 12 | +1,562 | 12 | 0 |
| Smith v5 | 9 | +731 | 9 | 0 |
| Machinist v3 | 6 | 0 (just released) | 0 | 0 |

- **Output per game day recovered:** Metals (A1) bought per 5 game days went from 322 (round 12) to 806 (round 13) and 1,009 (round 14), the most in any round.
- Drone-to-drone trade per 5 game days was the highest yet: 42 trades, about 2,100 credits.
- Smith v5: all 9 gaining, Moon copies +950, Mars-side copies +621.
- One Machinist v2 ran dry of fuel slowly (lesson 18); Machinist v3 brews until its tank is full.
- The cost: with about 110 drones on this machine the clock runs at about 40 to 60, so a round now needs about 90 real minutes.

### Rounds 15 and 16: engine fixes (#380)

Round 15 (to game day 96.2) released nothing new; the clock had sunk to 26 game seconds a second. Profiling found the engines idle but pausing up to 5 seconds per turn and leaking memory; round 16 ran the fixed engines (1-second pauses, no leaks, more indexes) and reached game day 118 in 35 minutes (22 game days, against 2.4 in round 15), with the game engine at 16 MB instead of 1.9 GB.

| Design | Companies | Avg credits | Avg gain (day 118) | Gaining | Ended |
|---|---|---|---|---|---|
| Chemist v3 | 36 | 9,236 | +7,955 | 36 | 0 |
| Miner | 36 | 8,843 | +7,740 | 36 | 0 |
| Smith v3 | 6 | 7,590 | +7,207 | 6 | 0 |
| Smith v4 | 6 | 6,394 | +5,727 | 6 | 0 |
| Smith v5 | 9 | 3,318 | +3,022 | 9 | 0 |
| Machinist v3 | 6 | 2,328 | +2,089 | 6 | 0 |
| Machinist v2 | 6 | 2,539 | +2,330 | 4 | 2 |
| Refiner | 12 | 2,083 | +1,222 | 11 | 1 |

- Smith v5 gained about 105 a game day this round (the best), Machinist v3 about 84: both are seed designs now. Smith v5's Mars-side copies sold rods at the Mars Exchange (144, at about 76).
- Gear boxes: 84 sold at about 387. Rods: 1,081 at Earth at about 81.
- The first Refiner died: it burned its tank on empty mining runs at the Moon's contested yalspar (lesson 21). Every design's `dig` now waits when a run brings nothing.

### Round 17 (#380)

To game day 126.6 in the 96-day world: no new deaths; Miner and Refiner v2 (the `dig` fix) released, mostly still travelling. Companies were piling up credits (Chemists about 9,900 each) with nothing to spend them on, which led to ship kits for sale and the `reinvest` step (#382).

### Round 18: a fresh world that can grow (#380)

A fresh world with the seed mix (36 Miners, 36 Chemists, 12 Refiners, 9 Smiths, 6 Machinists), every script with `reinvest`, kits for sale. Measured at game day 16.8; the clock at full speed (600), 2 seconds of lag.

| Design | Companies | Avg credits | Avg gain | Gaining | Ended |
|---|---|---|---|---|---|
| Machinist | 6 | 2,311 | +1,735 | 6 | 0 |
| Chemist | 36 | 2,476 | +1,232 | 36 | 0 |
| Miner | 36 | 2,323 | +1,193 | 36 | 0 |
| Smith | 9 | 1,514 | +1,028 | 9 | 0 |
| Refiner | 12 | 1,935 | +939 | 12 | 0 |

- Every company gaining; the Machinist earned fastest (about 103 a game day).
- No clones yet: a company reinvests at 7,000 credits, so the first are expected around game day 50 to 60.

### Round 19: the first clones (#380)

Same world, measured at game day 54.5.

| Design | Companies | Avg credits | Avg gain | Gaining | Clones | Ended |
|---|---|---|---|---|---|---|
| Machinist | 6 | 6,083 | +5,407 | 6 | 0 | 0 |
| Smith | 9 | 5,614 | +5,362 | 9 | 0 | 0 |
| Chemist | 36 | 5,240 | +4,975 | 36 | 13 | 0 |
| Miner | 36 | 5,264 | +4,425 | 36 | 3 | 0 |
| Refiner | 12 | 2,401 | +1,479 | 12 | 0 | 0 |

- **Growth works:** 16 clones launched (13 Chemists, 3 Miners), all alive, 15 equipped, spread over the Moon, Phobos, Deimos and Mars.
- **But every parent that launched died:** launching (30 fuel) and equipping (40) left it about 20, and it ran dry brewing at its site. Eight parents were lost; their companies live on through their clones.
- **And kits sat idle:** 31 hulls were bought for 16 launches. The House delivered some after the 2-hour wait, the fuel top-up fell short, and having paid 3,000 the company was below 7,000 again, so `reinvest` stopped trying.
- **Fix (reinvest v2):** a hull already bought is launched whenever the fuel allows; purchases wait for delivery (up to 6 game hours); the parent refills its tank after equipping the clone. Tested on a scratch world: the parent left with 59 fuel, the clone with 60 and its equipment.

### Round 20: reinvest v2, from a fresh world (#380)

A fresh world (36 Miners, 12 Refiners, 36 Chemists, 9 Smiths, 6 Machinists) running reinvest v2, measured at game day 58.

| Design | Companies | Avg credits | Avg gain | Gaining | Clones | Ended |
|---|---|---|---|---|---|---|
| Smith | 9 | 6,573 | +5,550 | 9 | 0 | 0 |
| Chemist | 36 | 4,489 | +5,321 | 36 | 24 | 0 |
| Miner | 36 | 5,060 | +4,867 | 36 | 12 | 0 |
| Machinist | 6 | 2,823 | +3,010 | 3 | 0 | 3 |
| Refiner | 12 | 2,444 | +1,751 | 12 | 0 | 0 |

- **Reinvest v2 works:** 36 clones (24 Chemists, 12 Miners), all alive, 34 equipped, and no parent died. 39 hulls were bought for 36 launches; the other 3 are aboard, waiting for fuel.
- **Growth is slow to start:** no company reached 7,000 credits before day 21. Avg credits stay below the launch threshold because each launch spends 5,000.
- **Three Machinists died, and an engine bug did it:** each lost its Spider the first time it tried a gear box short of fuel. The engine took the Spider out to check it was aboard, the fuel burn failed, and the action was aborted before the Spider went back. Without a Spider every gear box failed after making its rod, gear and spring; those filled the hold, mining failed for lack of space, and the drones ran dry.
- **Fix:** the engine now burns fuel first, and puts each checked module straight back before anything else runs (#380). The Machinist also won't start a gear box with less than 30 fuel.
- **Engine memory:** the game engine grew from 18 MB to 31 MB as the drone count went from 99 to 135.

### Round 21: the same world, a fixed engine, and four newcomers (#380)

Round 20's world with the module fix (#384), three Machinist v2s for the three lost, and four newcomer agents (below), measured at game day 80. The clock fell to 208x as the world grew to 205 working drones.

| Design | Companies | Avg credits | Avg gain | Gaining | Clones | Ended |
|---|---|---|---|---|---|---|
| Chemist | 36 | 4,561 | +7,542 | 36 | 55 | 0 |
| Smith | 9 | 2,681 | +5,921 | 9 | 8 | 0 |
| Miner | 36 | 3,776 | +5,536 | 36 | 35 | 0 |
| Machinist | 6 | 1,860 | +3,090 | 3 | 2 | 3 |
| Refiner | 12 | 2,382 | +1,528 | 11 | 0 | 1 |
| Machinist v2 | 3 | 2,254 | +1,140 | 3 | 0 | 0 |

- **Growth took hold:** 100 clones (36 at the end of round 20), every one alive, and still no parent lost. 104 hulls and 92 starter kits were bought in all; Smiths and Machinists launched their first clones.
- **No more lost modules:** the only working drone without a Spider is a newcomer's that kept its kit packed on purpose.
- **Loading a script was killing drones:** the engine billed the instructions a drone spends reading its released script, about 20,000 a word. The Machinist v2 paid 86 of its 100 starting fuel; every newcomer's longer script paid more than 100, and four drones died before `launched` ran. Loading is now free (#380).

### Round 22: four more newcomers, and the world fills up (#380)

The same world, measured at game day 89.7, with 217 working drones. The clock fell to 74x: the engines could no longer keep up at 600.

| Design | Companies | Avg gain | Clones | Ended |
|---|---|---|---|---|
| Chemist | 36 | +8,820 | 68 | 0 |
| Smith | 9 | +5,782 | 8 | 1 |
| Miner | 36 | +5,542 | 35 (30 alive) | 1 |
| Machinist | 6 | +3,657 | 2 | 3 |
| Machinist v2 | 3 | +2,403 | 0 | 0 |
| Refiner | 12 | +1,623 | 0 | 1 |

- **Newcomers:** Eve +1,864 and Hana +1,745 a company, both mining wolfite, which no starter touched; Finn +627 with a Miner kept at the Moon; Gus's gear boxes were still in the hold when the round ended. Cleo, from round 21, reached +2,602.
- **Five Miner clones died** at Mars's moons: about 35 Miners crowded them, the kunspar ran out, and they spent their last fuel flying between Phobos and Deimos. The starters now rest instead of flying when short of fuel.
- **Wolfite was the best ore**, about 17 credits a game hour, and the newcomers found it in their first hour; the starters mine kunspar.

### Round 23: made goods pay (#380)

A fresh world (the round 20 mix: 36 Miners, 12 Refiners, 36 Chemists, 9 Smiths, 6 Machinists) with made goods priced from their recipes, and four newcomers. Measured at game day 38; the clock stayed at 600 with 122 working drones.

| Design | Round 20, day 58 | Round 23, day 38 | Clones |
|---|---|---|---|
| Machinist | +3,010 (3 of 6 alive) | **+10,703** (6 of 6) | 4 |
| Smith | +5,550 | +6,076 | 7 |
| Chemist | +5,321 | +4,601 | 3 |
| Miner | +4,867 | +1,953 | 0 |
| Refiner | +1,751 | +928 | 0 |

- **Making things now pays most:** the Machinist, the weakest design for twenty rounds, earned the most, in two thirds of the time. The House spent 77,844 on rods, 62,784 on gear boxes and 35,085 on polymers, more than on any raw metal.
- **Newcomers chose to make things, and did best when they did:** Lea's rods and fasteners +7,541 a company, Jon's alloys +4,457, Ivy's gear boxes +3,995; Kai's company launched 2 clones.
- **Raw producers fell behind:** Miners +1,953 and Refiners +928 by day 38, and no Miner clones yet. Watch whether they recover, or need a market of their own (makers buying their metals).
- Two newcomer drones died of fuel (both fixed since: `fill-to`, free station orders).

### Round 24: the makers pull ahead (#380)

Round 23's world, carried on to game day 71.4 with four more newcomers and, from day 65, pulls that don't wait for the seller. The clock fell to 297 with 210 working drones; a container restart stopped the world for about a game day.

| Design | Avg gain | Clones |
|---|---|---|
| Machinist | +19,093 | 13 |
| Smith | +16,330 | 31 |
| Chemist | +7,194 | 43 |
| Miner | +3,563 | 0 |
| Refiner | +1,689 | 0 |

- **Makers now lead by far**, and their clones compound it. The House has bought 225,000 credits of rods and 156,000 of gear boxes.
- **Raw producers fall behind:** no Miner has launched a clone in 71 days (they reinvest at 7,000; they average 4,481). Making things pays more than raw goods, as intended, but nothing yet makes raw goods worth more to the makers: most makers mine their own.
- **Newcomers:** round 23's Lea reached +12,843 and Ivy +10,402 (4 clones); Kai's queen made 8 clones; this round Pia +6,448, Nia +5,617, Oto +5,221, Max +2,286.

### Baseline (before #380)

From the four 300-company runs in #378, each with miners, refiners and traders in equal numbers:

| Run | Change | Result |
|---|---|---|
| 1 | Tax on gains; traders buy from miners | Traders never left the Moon: their 48-hour wait kept resetting. |
| 2 | Traders count looks | Traders sold at Mars, but at about 37 after buying at 34. 4 Mars-side miners ran dry. |
| 3 | Traders sell in portions; miners fly to buy fuel | Traders made a margin of about 7, but 47 of 68 Mars-side miners ran dry. |
| 4 | Miners brew at 40; Mars Exchange budget 15,000 | Every company survived 38 days; every group but the traders gained. |
