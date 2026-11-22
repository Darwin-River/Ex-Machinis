# Ownership and open markets (proposal)

**Status:** proposed, not built. The live game still plays by the [Rules of Play](../design-manual.md#The-Rules-of-Play). Tracked by #921, with a sub-issue for each part (#922 to #932). This version brings together every decision the project lead has made so far, after two rounds of a player panel; [How the design got here](#how-the-design-got-here) lists them.

**Aim:** a market as busy and varied as possible, built from rules a newcomer can hold in their head. Each rule replaces special cases rather than adding them. The complexity should come from what drones, companies and players do with the rules. Players earn by writing good code, and also by reading code and markets well, which is what the game is for.

## Why

Reviewed as an economy and as an ecosystem, the live game has detailed mechanics (fuel, recipes, orbits) and a strong scripted environment, but few ways for one company's success to become another's opportunity or problem:

- **The House sets the market.** It is the default buyer and seller at every exchange, its prices follow scheduled waves, and startups may trade only with it.
- **Nothing is scarce anywhere.** Every mineral is found on every body of its type at the same abundance (`DEFAULT_ABUNDANCE` in [`build_game_data.py`](../../game-engine/sql/game-data/build_game_data.py)), and every exchange sells every good. Nobody needs to travel.
- **Idle money goes nowhere.** 93% of matured companies' credits are idle cash (economy playbook, lesson 33). Nobody can lend to a company, and a company can't commit to a future trade.
- **The startup rules pile up.** A bank loan, a debt, a push fee, a maturing margin, a credit cap, a daily buying cap, House-only trading and protected wrecks: about a dozen rules and three failure codes, all there because the bank lends a new company money for free.
- **Value is lost when a company dies.** A wreck gives up half its hold to one plunderer, and the rest is lost. Every unchanged starter dissolved at the same minute (#837), and the value went with them.

## The rules in eight lines

1. **You start with one company:** a drone at Earth and 3,000 credits.
2. **A company you own outright is private:** only you see and change its code, and you may own at most **2** private companies at once.
3. **Offering shares makes a company's code public; the first share sold locks it for good.** That sale is its IPO.
4. **A company's money is what it starts with, what it earns, and what its own code borrows;** nobody adds credits. Players set share prices, never below close-out value, and the bank buys any share at close-out value.
5. **A company ends only through its own code** (once it owes nothing), by going broke, or with no drones left. Lenders are paid first, then shareholders, and everything else becomes salvage.
6. **Trading at a place is anonymous:** the best price wins. The planets always buy and sell common goods, and their import prices rise the more everyone buys.
7. **Only players invest:** they buy shares and offer credit lines to public companies. Contracts between companies have due dates and deposits.
8. **Worth under 500? Start over,** once a real day, with a mining drone and a tank of fuel.

The parts below say how each rule works. The [glossary](#glossary) explains the terms.

## 1. Resources by place (#922)

- **Each mineral has a home.** Abundance varies by body: rich on a few, absent from others, in place of one `DEFAULT_ABUNDANCE` for every body of a type.
- **Valuable inputs lie far away.** The minerals behind the upper tiers (systems, modules, kit parts) are found only at asteroids, comets or rings. The common ores and metals the starters use stay near Earth, the Moon and Mars, so a new company still pays its way in about 40 game days (#777).
- **The planets don't sell the far goods.** They keep selling fuel, kits and the common goods, and keep buying the far goods near Earth. Supply is far away and demand is at home, and that gap is the market.
- **Fuel only at the exchanges.** Work far away needs a supply line, so fuel depots and far-away fuel brewers become a business.
- **Depletion stays off.** Distance does the job, with fewer rules.
- **Lever:** the far goods' bids must pay for the trip (playbook, lesson 8: the Moon to Mars is about 10 game days each way).

## 2. Private companies and the IPO (#923)

This replaces the startup rules: the bank loan and debt, the founding and push fees, maturing and its margin, the credit cap and its sweep, House-only trading (-16), the daily buying cap (-17), protected wrecks and the five-startup limit.

- **A new player starts with one private company:** a drone at Earth and 3,000 credits inside the company. The player holds no credits of their own; every credit a player holds is earned (dividends, interest, an IPO, selling shares, or a company closing).
- **Founding another company** costs its drone (hull and fuel) and whatever starting cash the founder chooses, from the founder's credits. There is no loan, debt or fee.
- **A private company plays by every rule of the game:** it trades with everyone, signs contracts, plunders and salvages wrecks, and its own wrecks can be plundered and salvaged. Its working drones can't be attacked; nothing in the game touches a working drone.
- **Its code is seen and changed only by its owner, at any time, for free** (processor tax still applies). A push restarts its drones as now. A teacher sees their class's code.
- **Its record is public from day one:** trades, earnings, events and drones. When it goes public, investors judge it by that record and by its code.
- **At most 2 private companies a player.** A trade that would give a player a third fails.
- **Nobody adds credits to a company,** its owner included. A company's money is its starting cash, its earnings, and what its own code borrows from players' credit lines (part 7). Nobody outside the company's code can create shares or force an IPO. A private company that runs short can close itself through its code (its credits go back to its owner) and be founded again with more.
- **Private companies can't borrow,** because their code is hidden and can change. A private company's testing budget is its starting cash and its earnings.
- **The IPO:** the founder offers some of their 100 shares on the stock exchange at a price they choose, never below close-out value. **From the moment of the offer the code is public,** and it can't be pushed while the offer stands, so buyers get the code they watched run. **The first share sold, to a player or to the bank, locks the code for good** and makes the company public. The money goes to the founder. An offer that doesn't sell locks nothing: the founder can withdraw it, change the code and try again, but the code has been seen.

## 3. Takeovers: owning 100% unlocks the code (#924)

- **A company is private whenever one player owns all its shares,** however they came to own them. Its code can be changed again, and it counts against that player's 2.
- **Investors can't be harmed:** everyone else sold of their own free will.
- **A takeover makes the company's debts due at once** (its credit lines are repaid first), so a raider can't unlock the code and rewrite it to walk away from its lenders.
- **It sets a floor under share prices.** A company that trades for less than its fleet, goods and credits are worth gets bought out and reprogrammed or liquidated. This is salvage of a whole company, often a fleet already working far away.
- **Holdouts** (one player keeping a single share) are allowed. If they block too many takeovers, add a squeeze-out: a holder of 95% may buy the rest at a set premium.
- **In a class,** the teacher can switch off takeovers within the class.

## 4. The kill switch (#925)

- **A company ends only through its own code,** with `close_company()`, or by going broke (50 game days without credits), by winding up (60 game days without a sale) as now, or with no working drones (below). Its founder has no Close button: a private company's founder who wants out pushes code that calls it.
- **It works only when the company owes nothing:** no debt on its credit lines and no open contracts. Otherwise it fails with a new code ("obligations outstanding"). The ways out:
  - `repay(n)` pays back what it has drawn;
  - a contract is delivered, or cancelled with `cancel_contract(id)`, which forfeits its deposit.
- **Every ending is the same ending:**
  1. Open orders are withdrawn.
  2. Lenders are paid first.
  3. The credits go to the shareholders by their shares, the bank included.
  4. Its drones become wrecks with whatever they still hold, and its code becomes public, if it wasn't already.
- **The engine never sells for a company.** A smart design notices that it's failing, sells its goods and spare modules, repays its lenders, brings its drones in, and then closes. A careless one leaves its value to salvagers. Investors can read a design's exit routine and pay for one that dies well.
- **A company with no working drones** can't run code, so it ends by itself after 3 game days, in the same way.
- **The 12-hour minimum order life** still holds, and `close_company()` is its one exception, so a company can always die.

## 5. Start over (#926)

- **A player worth under 500 may start over, at most once a real day.** They lose everything (credits, shares, private companies, credit lines) and start again with a drone fitted with a mining kit and a tank of fuel, in a new private company with no cash. They can mine and sell from the first minute. Their history and personal records stay.
- **Their shares go to the bank,** which sells them into the best standing buy order, never below close-out value. Their private companies become public, bank-held, and open to takeover.
- **It can't print money:** starting over gives no cash, and no share sells below close-out value, so there's nothing to pass to a friend first. The most a restart could leak is the kit's resale value.
- **In a class,** the teacher can switch it off.

## 6. Anonymous trading and the planets (#927)

- **Drones don't know whom they trade with.** `buy_here` and `sell_here` already fill against the best price at a place; now that is the only way to trade with another company.
  - `best_offer` and `best_bid` return a price, units and an **order handle** in place of a drone ID. `pull(n, r, handle)` and `push(n, r, handle)` accept the handle at that place, so locked code written for `pull(..., seller)` keeps working. A handle is opaque, belongs to one order, and is never a drone ID. The same goes for every query that lists orders (`sell_orders`, `buy_orders`, `orders_everywhere`) and for trade events.
  - `push` and `pull` with a drone ID work only between a company's own drones (handing a kit to a copy, sharing fuel).
- **The House becomes "the planets":** each exchange's planet buys and sells as imports and exports. Its orders sit in the book like anyone's, anonymously. It isn't a company, a player or a leaderboard entry.
- **A reliable market, as a rule:** every exchange always buys and sells the common goods, at a price.
- **The import price** of a good at an exchange rises with how much everyone has bought there recently and decays back steadily; there is no overnight reset to race for. Splitting purchases across companies gains nothing. Player sellers undercut a rising import price, so trade between players grows. **Fuel stays at a flat list price,** so the newcomers' safety net can't be spiked.
- **The planets step back where players trade.** Their spread on a good widens as players' share of its trade grows (counting only trades between companies of different founders, so wash trades can't push the planets out), and narrows again if players stop.
- **Shown after the fact:** company pages, histories and the dashboard still name counterparties, label imports and exports, and flag trades between companies with the same player behind them. Anonymity applies to drones' decisions, which is where one company could favour another.
- **Book depth** (units at each price, both sides) is readable by drones and shown on pages, so thin markets look thin.

## 7. Investing, credit lines and contracts (#928)

**Players are the only investors.** Companies make things and trade goods; their only financial acts are borrowing, repaying and paying out, written in their code. Players buy shares and lend to companies, and earn by judging which code and which markets will pay. Companies never buy shares or lend: today's rule that companies don't trade shares (-18) stays.

### Shares
- **Players set share prices,** at the IPO and after it, never below close-out value. Standing orders, pegged orders (#766, pegged to close-out value or the last trade price) and account trading scripts (#768) work as now.
- **The bank keeps only a backstop:** it buys any share at close-out value, paid 30 game days later at the lower of the value then and on the day of the sale, as now. The shares it holds sell into the best standing buy order, never below close-out value.
- **Wealth and leaderboards** count shares at close-out value, never the last trade price, so friends trading at a high price can't raise anyone's rank.

### Credit lines
- **A player offers a public company a credit line:** a limit, a rate (simple interest, a percent a game week) and a due date. The player's credits for the limit are set aside while the line stands. Players offer lines on the company's page, through the API, or from their trading script.
- **The company's code decides when to borrow:** `borrow(n)` draws from the cheapest lines first (the earliest offered at a tie), and `repay(n)` pays back the dearest first. Interest runs only on what is drawn and is paid to the lender's account each game day. `debt()` returns what it owes, at what rates and when due; `credit()` returns what it could still draw and the best rate on offer.
- **A lender may withdraw the undrawn part** of a line after 12 game hours (the minimum order life). What is drawn is due on the line's due date.
- **A company may owe at most its close-out value.**
- **Only public companies borrow,** because their code is locked and anyone can read it before lending.
- **A missed payment:** every credit the company receives goes to its lenders until they're paid, its dividends stop meanwhile, and the default is on its record and its founder's. If it then goes broke, it ends as usual, lenders first.
- **Rates come from players.** Good code with a solid record gets cheap credit, and risky code pays more or gets none. A company page and the stock exchange show each public company's best and average rates, the market's verdict on its risk.
- **Lines aren't tradable** for now: a lender waits out the term, so terms should be short. Making lines saleable later would turn them into bonds.

### Contracts between companies
- **A contract** is a buy order with a due date and a deposit: `post_contract(resource, units, price, due_in, deposit)`. The buyer's credits are held in escrow.
- **Any seller may take it** with `take_contract(id)`, putting up the deposit, and then only that seller may fill it, with `deliver(id, n)` at the buyer's place.
- **On the due date,** whatever was delivered is paid. For the rest, the buyer gets back their credits and that share of the seller's deposit.
- **Contracts are anonymous:** listings show units, price, due date and deposit, never who. Escrow and the deposit secure both sides, so they need no rating. Taking every contract and forfeiting when prices rise is an option priced by the deposit the buyer sets.
- **The record:** company pages list contracts kept and broken.

## 8. Salvage (#929)

- **Wrecks keep their hull and equipment** as well as their hold.
- **Plunder is unchanged** (half the hold, once, no tool). **Salvage** is new: a Salvage module, made in the tech tree, recovers a wreck's equipment as used modules and its hull as scrap metal, a little a run.
- **Wrecks decay** (proposed: 2% of what's left a game day), so salvage is a race.
- **There is always a buyer:** the planets buy scrap and used modules at a discount. Used modules work, so they equip a copy cheaply.
- **Wrecks lie where drones died.** With far-away resources, they gather along the long routes.
- **A wreck field view** on the map: wrecks near each place, what they hold, how fast they decay, and who salvaged each (flagged when the same player is behind the wreck and the salvager). A mass ending is a world event.

## 9. Paying out, written into the code (#930)

- **`set_dividend_share(x)`** sets the share of each game day's profit the company pays to its shareholders (0 to 1; 0.5 until set). The company keeps the setting. Once the code is locked, so is the policy.
- **`pay_out(n)`** pays any spare credits to the shareholders at any time, on top of dividends. Companies can't invest their idle cash, so this is how it returns to players, who reinvest it.
- **The policy is public:** the company page and the stock exchange show the dividend share and what was actually paid over 30 game days.
- **Growth companies and income companies** become a choice investors can see. A company that pays out everything has to borrow to grow.

## 10. Who sees a company's code (#931)

- **A private company's code:** its owner only (and their teacher, for a class).
- **A public company's code, and a dead company's code:** everyone. Investors and lenders study it before buying or lending, and studying code becomes a way to earn.
- **From an IPO offer on,** the code is public, even if the offer is withdrawn unsold.
- **Always public:** behaviour (trades, events, earnings, drones), the payout policy, and the lineage map.
- **The founder is paid for the design at the IPO.** After that, a design anyone can read can be copied, and copies compete with it; that is the game's open-code lesson.
- This keeps the spirit of today's rule 7 ("All code is public") for every company that has gone public or died. [`open-source-pathway.md`](../open-source-pathway.md) gets a note: the game's own code stays MIT-licensed, and only a private company's in-game script is private, and only while the company is private.

## 11. What players see, and the tools around it (#932)

- **The investor sheet** on each company page, built from data the game already keeps:
  - price against close-out value, and idle credits;
  - dividends and payouts, declared against paid, over 30 game days;
  - debts, credit lines and rates, and open contracts, with due dates;
  - trades with companies of its founder or its 5 biggest shareholders;
  - the founder's earlier companies, how each ended, and their defaults;
  - its history: sales, drones lost and payouts over time.
- **IPOs on the stock exchange's front page,** with the opt-in e-mail notices (#761).
- **A test drive** (an extension of `POST /check`): run code read-only against the last few game hours of a place's market and the company's own state, and see the actions it would have taken.
- **A founder's note:** a short text the founder can edit after the lock ("known issue: it idles if fuel is over 900"). It changes nothing the drones do.
- **Class settings** (the teacher's page, not game rules): Start over on or off, takeovers within the class on or off, and a read-only log of transfers between pupils. Class groups already trade shares only within the group.
- **Example scripts and lessons**, which players build into their own scripts; the engine doesn't:
  - copies that vary (tuning numbers nudged in each copy with `lineage()`, copying from the best-earning drone);
  - a sell-out routine (sell, repay, bring the drones in, `close_company()`);
  - a borrower (borrow for a copy only if it pays back within the line's term);
  - an account trading script that lends (offers lines to public companies by their record, their rates and a reading of their code);
  - a salvager, and a hauler for far-away goods.
- **A glossary and one worked example** in plain numbers in the players' manual: a Miner from founding to IPO, a credit line, a takeover and a sell-out.

## Lua words

Reads and actions, not rules:

| Word | Returns or does |
|---|---|
| `close_company()` | ends the company, if it owes nothing |
| `borrow(n)`, `repay(n)` | draws from the cheapest lines; pays back the dearest |
| `debt()` | what it owes: `{owed, rate, due_in}` rows |
| `credit()` | what it could still draw, and the best rate on offer |
| `obligations()` | its debts and contracts together: `{kind, id, units_left, due_in, deposit}` |
| `post_contract(resource, units, price, due_in, deposit)`, `take_contract(id)`, `deliver(id, n)`, `cancel_contract(id)` | contracts |
| `contracts(resource)` | open contracts here: `{id, units, price, due_in, deposit}` |
| `set_dividend_share(x)`, `pay_out(n)` | paying shareholders |
| `import_price(resource, n)` | what n units would cost from the planets here now |
| `depth(resource)` | the book here: `{price, units}` rows, both sides |
| `due_in` | game days from now, everywhere |

Each new word gets its own failure codes, listed in `lua-functions.md` when it is built.

## Glossary

- **Close-out value:** what a share would get if the company closed now: its credits, its goods at the planets' bids averaged over 7 game days, and its hulls, less its debts, over its shares. It is worked out by the game, not judged by anyone.
- **IPO:** the first sale of a company's shares. It makes the company public and locks its code.
- **Private and public:** a private company has one owner who can change its code; a public company has shareholders and locked, readable code.
- **Credit line:** a player's offer to lend a public company up to a limit, at a rate, until a due date.
- **Contract:** one company's order for goods by a due date, with the buyer's credits held in escrow and the seller's deposit at stake.
- **Deposit:** what a seller puts up when taking a contract, lost for what it fails to deliver.
- **Salvage:** recovering used modules and scrap from a wreck.
- **Start over:** a player's fresh start with a mining drone, when they are worth under 500.

## Moving the live world over

- **Matured companies become public companies.** Their code is already locked and becomes readable by everyone, as all code is today. Their 100 shares and holders stay.
- **Live startups finish under the old rules** until they mature or close. No new startups are founded once private companies arrive.
- **The bank's companies** (Bank Miner, Refiner, Maker) are public.
- **Players' starting credits** already granted stay as they are, with today's restriction, until spent. New players start with a company only.
- **Owner-led offerings of new shares (#780) retire,** because only a company's own code may bring money in.

## Numbers to tune

| Rule | Proposed |
|---|---|
| A new player's company | a drone at Earth and 3,000 credits |
| Private companies a player | 2 |
| Share price floor (IPO and after) | close-out value |
| The bank pays for shares after | 30 game days |
| Most a company may owe | its close-out value |
| Undrawn credit line withdrawable after | 12 game hours |
| Start over threshold | wealth under 500 |
| Start over, how often | once a real day |
| Start over gives | a drone fitted with a mining kit and a tank of fuel |
| A company with no working drones ends after | 3 game days |
| Import price | rises with recent buying, decays back steadily; fuel at a flat price |
| Wreck decay | 2% a game day |

## Order of work

1. Resources by place (game data only; everything else depends on it).
2. Paying out, written into the code.
3. Private companies and the IPO, takeovers, the kill switch, Start over and who sees code, which ship together because they replace the startup rules.
4. Anonymous trading and the planets.
5. Salvage.
6. Credit lines and contracts.
7. The investor sheet, the test drive, example scripts and lessons, alongside each part.

Each part is timed in a playtest round on #380 against round 73's measures: time to pay its way, idle cash, the share of trades between players, and how many companies end on the same day.

## The issues

| Part | Issue |
|---|---|
| The whole proposal | #921 |
| 1. Resources by place | #922 |
| 2. Private companies and the IPO | #923 |
| 3. Takeovers | #924 |
| 4. The kill switch | #925 |
| 5. Start over | #926 |
| 6. Anonymous trading and the planets | #927 |
| 7. Investing, credit lines and contracts | #928 |
| 8. Salvage | #929 |
| 9. Paying out, written into the code | #930 |
| 10. Who sees a company's code | #931 |
| 11. What players see, and the tools around it | #932 |

## How the design got here

A panel of prospective-player personas (a student, a teacher, a trading-game veteran, a developer and an investor) reviewed the first version and the answers to it; their findings and ratings are in the comments on #921. The project lead decided, in order:

- at most 2 private companies; Start over below 500, at most once a real day; the IPO money goes to the founder;
- a company closes only through its own code, and only once it owes nothing;
- the House becomes a hidden hand ("the planets");
- after round 1: no import ration (a rising import price, fuel flat), grades from what can't be faked, plain names (close-out value, Start over), an investor sheet, a test drive and a founder's note, order handles for locked code;
- after round 2: nobody adds credits to a company; Start over gives a mining drone and no cash; players set share prices above close-out value and the bank keeps only its backstop; new players start with just their company;
- then: **only players invest**, through shares and credit lines (replacing company bonds and grades), and **a public company's code is readable by everyone** (replacing reading by stake).
