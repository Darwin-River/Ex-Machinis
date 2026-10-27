# Public Forge economy playbook

How to seed a Public Forge world so that its economy thrives, learned by running a world, releasing company designs into it and measuring the result (#378, #380). Use it to seed a live server.

- **Designs** are FORTH scripts released like any player's: the starters in `examples/starters/` and the company designs in `examples/companies/`.
- **Numbers** come from a local test world at 600 game seconds a real second (one game day in 2.4 real minutes), with the engines' clock floor on. Until round 12 the clock slowed only when the engines ran 60 real seconds behind; at 600 that let drones wait hours of game time for their turn, so output per game day is understated (see lesson 13). From round 13 it slows at 10 seconds behind.

## Best performers

Ranked by average gain: what a company made beyond its starter package (credits plus goods at House prices, less 1,000 credits, 100 fuel and the kit), the amount it is taxed on. A design earns a place here only after at least 20 game days in a mixed world.

| Design | File | Where | Avg gain (round 8, fresh world) | Gaining | Notes |
|---|---|---|---|---|---|
| Smith v5 | `examples/companies/smith.fth` | Moon, Phobos, Deimos | about 105 a game day (round 16) | 9 of 9 | Spreads like the Chemist; Mars-side copies sell rods at the Mars Exchange, a short hop. The best earner per day. |
| Machinist v3 | `examples/companies/machinist.fth` | Moon, Earth | about 84 a game day (round 16) | 6 of 6 | Gear boxes (450) from metals bought from miners; brews until its tank is full. |
| Smith v3 | `examples/companies/smith.fth` | Moon, Earth | +1,513 by day 19 (about 80 a day) | 6 of 6 | Mines yalspar and vladnium, turns its metals into rods and sells them at the Earth Exchange 4 an hour. Needs rods at 90 (parts lever) and an Earth budget to match. |
| Miner (Moon copies) | `examples/starters/miner.fth` | Moon | +2,496 by day 19 | all | Kunspar to metals; offers them to other drones, the rest to the Moon Depot. Moon kunspar supports about 12. |
| Chemist v3 (Moon copies) | `examples/companies/chemist.fth` | Moon | +1,771 by day 19 | all | Yalspar and vladnium to metals, polymers and inorganics. Moon yalspar supports about 14 (Smiths share it). |
| Chemist v3 (Mars-side copies) | `examples/companies/chemist.fth` | Phobos, Deimos | +553 by day 19 | all | Same, selling at the Mars Exchange; loses its first 10 game days to the trip. |
| Refiner | `examples/starters/refiner.fth` | Moon, Phobos, Deimos | +662 by day 19 | all | Idles once its tank is full: little upside, but sells fuel to other drones. |
| Miner (Mars-side copies) | `examples/starters/miner.fth` | Phobos, Deimos | +309 by day 19 | all | Slow start (the trip), then steady. |
| Trader | `examples/starters/trader.fth` | Moon to Mars | 0 (none gaining in round 5) | none | Survives, but clears about 5 a unit after a 20-day round trip. Not worth seeding. |

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
| Made goods' prices | `station_goods` (`made_value`) | alloys 30-50, polymers 30, parts 75-105, systems 450: an alloy sold for less than its metals | inputs + fuel + a wage per game hour (20 alloys, 25 parts, 30 systems): alloys 70-100, polymers 70, parts 205-385, systems 790-1,400 | Each step up the chain pays more per hour than mining (17). Round 23 measures it. |

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

**What they liked best** (both rounds): finding a niche by working it out (wolfite nobody mined, parts nobody made, salvage nobody took), the first full loop paying out, undercutting other sellers by a credit, and the Test button.

## Round log

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

### Baseline (before #380)

From the four 300-company runs in #378, each with miners, refiners and traders in equal numbers:

| Run | Change | Result |
|---|---|---|
| 1 | Tax on gains; traders buy from miners | Traders never left the Moon: their 48-hour wait kept resetting. |
| 2 | Traders count looks | Traders sold at Mars, but at about 37 after buying at 34. 4 Mars-side miners ran dry. |
| 3 | Traders sell in portions; miners fly to buy fuel | Traders made a margin of about 7, but 47 of 68 Mars-side miners ran dry. |
| 4 | Miners brew at 40; Mars Exchange budget 15,000 | Every company survived 38 days; every group but the traders gained. |
