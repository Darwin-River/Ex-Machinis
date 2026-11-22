# Ownership and open markets (proposal)

**Status:** proposed, not built. The live game still plays by the [Rules of Play](../design-manual.md#The-Rules-of-Play). Tracked by #921, with a sub-issue for each part (#922 to #932).

**Aim:** a market as busy and varied as possible, built from rules a newcomer can hold in their head. Each rule below replaces special cases rather than adding them. The complexity should come from what drones, companies and players do with the rules.

## Why

Reviewed as an economy and as an ecosystem, the live game has detailed mechanics (fuel, recipes, orbits) and a strong scripted environment, but few ways for one company's success to become another's opportunity or problem:

- **The House sets the market.** It is the default buyer and seller at every exchange, its prices follow scheduled waves, and startups may trade only with it.
- **Nothing is scarce anywhere.** Every mineral is found on every body of its type at the same abundance (`DEFAULT_ABUNDANCE` in [`build_game_data.py`](../../game-engine/sql/game-data/build_game_data.py)), and every exchange sells every good. Nobody needs to travel.
- **Idle money goes nowhere.** 93% of matured companies' credits are idle cash (economy playbook, lesson 33). A company can't lend, borrow or commit to a future trade.
- **The startup rules pile up.** A bank loan, a debt, a push fee, a maturing margin, a credit cap, a daily buying cap, House-only trading and protected wrecks: about a dozen rules and three failure codes, all there because the bank lends a new company money for free.
- **Value is lost when a company dies.** A wreck gives up half its hold to one plunderer, and the rest is lost. Every unchanged starter dissolved at the same minute (#837), and the value went with them.

## The rules in seven lines

1. **You start with 5,000 grant credits.** They found companies and buy shares.
2. **A company you own outright is private:** only you can change its code, and you may own at most **2** private companies at once.
3. **Selling any share is its IPO,** and its code is locked for good.
4. **A company ends only through its own code** (once it owes nothing), or by going broke. Its credits go to its shareholders, and everything else becomes salvage.
5. **Trading at a place is anonymous:** the best price wins. The planets always buy and sell common goods.
6. **Promises (contracts and bonds) have due dates and grades.** Only public companies borrow.
7. **You may declare bankruptcy** when you're worth under 500, and start again.

The parts follow, each with its own issue.

## 1. Resources by place (#922)

- **Each mineral has a home.** Abundance varies by body: rich on a few, absent from others, in place of one `DEFAULT_ABUNDANCE` for every body of a type.
- **Valuable inputs lie far away.** The minerals behind the upper tiers (systems, modules, kit parts) are found only at asteroids, comets or rings. The common ores and metals the starters use stay near Earth, the Moon and Mars, so a new company still pays its way in about 40 game days (#777).
- **The planets don't sell the far goods.** They keep selling fuel, kits and the common goods, and keep buying the far goods near Earth. Supply is far away and demand is at home, and that gap is the market.
- **Fuel only at the exchanges.** Work far away needs a supply line, so fuel depots and far-away fuel brewers become a business.
- **Depletion stays off.** Distance does the job, with fewer rules.
- **Lever:** the far goods' bids must pay for the trip (playbook, lesson 8: the Moon to Mars is about 10 game days each way).

## 2. Private companies and the IPO (#923)

This replaces the startup rules: the bank loan and debt, the founding and push fees, maturing and its margin, the credit cap and its sweep, House-only trading (-16), protected wrecks and the five-startup limit.

- **Founding** costs the drone (hull and fuel) and whatever starting cash the founder chooses, from the founder's account. There is no loan, debt or fee.
- **A private company plays by every rule of the game:** it trades with everyone, signs contracts, plunders and salvages, and can be plundered and salvaged.
- **Its code can be changed at any time, for free** (processor tax still applies). A push restarts its drones as now.
- **Its record is public from day one:** trades, earnings, events and drones. When it goes public, investors judge it by that record.
- **At most 2 private companies a player.** A trade that would give a player a third fails.
- **Adding credits:** any shareholder may add credits to the company. It can't be taken back out except as a shareholder (dividends, selling shares, the company closing).
- **The IPO:** the founder offers shares on the stock exchange at a price they choose, but not below cash-out. While an offer stands, the code can't be pushed, so buyers get the code they watched run. **The first share sold, to a player or to the bank, locks the code for good.** The money goes to the founder. After the IPO, trading uses the usual band (0.8 to 1.25 times the bank's price).
- **Grant credits** (the 5,000 at the start and after a bankruptcy) found companies and buy shares at no more than the bank's price, as starting credits do today. With the IPO floor at cash-out, this means grant credits can't be passed to a friend through a mispriced IPO.

## 3. Takeovers: owning 100% unlocks the code (#924)

- **A company is private whenever one player owns all its shares,** however they came to own them, and its code can be changed. It counts against that player's 2.
- **Investors can't be harmed:** everyone else sold of their own free will.
- **A takeover makes the company's bonds due at once** (a change-of-control clause), so a raider can't unlock the code and rewrite it to walk away from its debts.
- **It sets a floor under share prices.** A company that trades for less than its fleet, goods and credits are worth gets bought out and reprogrammed or liquidated. This is salvage of a whole company, often a fleet already working far away.
- **Holdouts** (one player keeping a single share) are allowed at first. If they block too many takeovers, add a squeeze-out: a holder of 95% may buy the rest at a set premium.

## 4. The kill switch (#925)

- **A company ends only through its own code,** with one new Lua word, `close_company()`, or by going broke (50 game days without credits) or by winding up (60 game days without a sale) as now. Its founder has no Close button: a private company's founder who wants out pushes code that calls it.
- **It works only when the company owes nothing:** no bonds outstanding and no open contracts. Otherwise it fails with a new code ("obligations outstanding"). The ways out:
  - `repay_bonds(n)` redeems its own bonds early at face value (100);
  - a contract is delivered, or cancelled with `cancel_contract(id)`, which forfeits its bond.
- **Every ending is the same ending.** Open orders are withdrawn, then lenders are paid (only when a company goes broke owing), then the credits go to the shareholders by their shares, the bank included. Its drones become wrecks with whatever they still hold, and its code becomes public.
- **The engine never sells for a company.** A smart design notices that it's failing, sells its goods and spare modules, repays its lenders, brings its drones in, and then closes. A careless one leaves its value to salvagers. Investors can read a design's exit routine and pay for one that dies well.
- **A company with no working drones** can't run code, so it ends by itself after 3 game days, in the same way.
- **The 12-hour minimum order life** still holds, and `close_company()` is its one exception, so a company can always die.

## 5. Bankruptcy (#926)

- **A player worth under 500 may declare bankruptcy, at most once a real day.** They lose everything (credits, shares, private companies) and start again with 5,000 grant credits. Their history and personal records stay.
- **Their shares go to the bank,** which sells them as it does any it holds. Their private companies become public, bank-held, and open to takeover.
- **It can't print money:** grant credits can't pay an inflated IPO price, and an IPO can't sell below cash-out.

## 6. Anonymous trading and the planets (#927)

- **Drones don't know whom they trade with.** `buy_here` and `sell_here` already fill against the best price at a place; now that is the only way to trade with another company:
  - `best_offer` and `best_bid` return the price and units, not the drone;
  - `push` and `pull` work only between a company's own drones (handing a kit to a copy, sharing fuel).
- **The House becomes "the planets":** each exchange's planet buys and sells as imports and exports. Its orders sit in the book like anyone's, anonymously. It isn't a company, a player or a leaderboard entry.
- **A reliable market, as a rule:** every exchange always buys and sells the common goods, at a price.
- **An import ration:** a company may buy at most a tenth of a day's imports at an exchange each game day, and at least 5. Goods from other companies have no limit. A drone that hits the ration gets -17 ("imports rationed here").
- **The planets step back where players trade.** Their spread on a good widens as players' share of its trade grows, and narrows again if players stop.
- **Shown after the fact:** company pages, histories and the dashboard still name counterparties, and label imports and exports. Anonymity applies to drones' decisions, which is where one company could favour another.
- **What it buys:**
  - one way to trade, best price wins;
  - the planets compete with players as equals;
  - a public company's locked code can't steer money to its founder's other companies.

## 7. Contracts and bonds, with grades (#928)

Both are a **promise with a due date**.

- **A contract** is a buy order with a due date and a bond: `post_contract(resource, units, price, due, bond)`. The buyer's credits are held in escrow. Any seller may take it with `take_contract(id)`, putting up the bond, and then only that seller may fill it, by pushing goods into it at the buyer's place. On the due date, whatever was delivered is paid; for the rest, the buyer gets back their credits and that share of the seller's bond.
- **A bond:** `issue_bonds(n, due)`. Each bond pays 100 credits on its due date. Bonds trade on the stock exchange beside shares, and their price sets the interest. **Only public companies issue them**, so their code is locked and lenders may read it. A company may not owe more in bonds than its cash-out value. Players buy bonds with their credits or their trading scripts (#768), and companies buy them with a Lua word.
- **Grades, not names.** Each contract and bond in a listing carries its promiser's grade, A to E, never its name. The engine works it out by a published formula:
  - promises kept over 60 game days;
  - cover (credits and goods at bids, over debts);
  - age;
  - any default in the last 30 game days means E.

  Drones see the grade and a coarse band of each input, never exact figures, which could pick out one company.
- **The record:** company pages list promises kept and broken, and a founder's defaults show on every company they founded.
- **Helpers and examples:** `bond_yield(price, days)`, a lender of about 15 lines (grade B or better, a minimum yield, at most 20% of spare cash in any one issuer), and a borrower (borrow for a copy only if it pays back within the bond's term).
- **Startups no longer exist,** so anyone can sign. The guards are escrow, bonds, grades and the record.

## 8. Salvage (#929)

- **Wrecks keep their hull and equipment** as well as their hold.
- **Plunder is unchanged** (half the hold, once, no tool). **Salvage** is new: a Salvage module, made in the tech tree, recovers a wreck's equipment as used modules and its hull as scrap metal, a little a run.
- **Wrecks decay** (proposed: 2% of what's left a game day), so salvage is a race.
- **There is always a buyer:** the planets buy scrap and used modules at a discount. Used modules work, so they equip a copy cheaply.
- **Wrecks lie where drones died.** With far-away resources, they gather along the long routes.
- **A wreck field view** on the map: wrecks near each place, what they hold, and how fast they decay. A mass ending is a world event.

## 9. Dividends written into the code (#930)

- A script declares `dividend_share = 0.3` (default 0.5, between 0 and 1), and may change it as it runs with `set_dividend_share(x)`. Once the code is locked, so is the policy.
- **The policy is public** even when the code isn't: the company page and the stock exchange show the declared share and the share actually paid over 30 game days.
- **Growth companies and income companies** become a choice investors can see. A company that pays out everything has to borrow to grow.

## 10. Who sees a company's code (#931)

- **A private company's code:** its owner only.
- **A public company's code:** its owner and any player with **5 shares or more**. A teacher sees their class's code.
- **A dead company's code is public,** so post-mortems and "Copy this code" keep the game's teaching value. The starters and examples stay public.
- **Always public:** behaviour (trades, events, earnings, drones), the dividend policy, and the lineage map's shape, worked out on the server without showing code.
- **Shares become a key to know-how,** and a founder whose design is worth copying gains from the demand for shares.
- This changes rule 7 of the current rules ("All code is public") and needs a note in [`open-source-pathway.md`](../open-source-pathway.md). The game's own code stays MIT-licensed. Only players' in-game scripts are private, and only while their companies live.

## 11. Example scripts and lessons (#932)

Players build these into their own scripts; the engine doesn't:

- **copies that vary:** tuning numbers in a table, nudged in each copy with `lineage()`, copying from the best-earning drone;
- **a sell-out routine:** sell, repay, bring the drones in, `close_company()`;
- **a lender and a borrower** (part 7);
- **a salvager** (part 8);
- **a hauler** for far-away goods (part 1).

## Moving the live world over

- **Matured companies become public companies.** Their code is already locked. Their 100 shares and holders stay.
- **Live startups finish under the old rules** until they mature or close. No new startups are founded once private companies arrive.
- **The bank's companies** (Bank Miner, Refiner, Maker) are public.
- **Starting credits become grant credits,** with the same restriction.

## Numbers to tune

| Rule | Proposed |
|---|---|
| Private companies a player | 2 |
| Bankruptcy threshold | wealth under 500 |
| Bankruptcy, how often | once a real day |
| Grant credits | 5,000 |
| IPO price floor | cash-out |
| Shares to see a public company's code | 5 |
| A company with no working drones ends after | 3 game days |
| Import ration | a tenth of a day's imports, at least 5 |
| Wreck decay | 2% a game day |
| Most a company may owe in bonds | its cash-out |
| Grade E after a default | 30 game days |

## Order of work

1. Resources by place (game data only; everything else depends on it).
2. Dividends written into the code.
3. Who sees a company's code.
4. Private companies and the IPO, takeovers, the kill switch and bankruptcy, which ship together because they replace the startup rules.
5. Anonymous trading and the planets.
6. Salvage.
7. Contracts and bonds, with grades.
8. Example scripts and lessons, alongside each part.

Each part is timed in a playtest round on #380 against round 73's measures: time to pay its way, idle cash, the share of trades between players, and how many companies end on the same day.

## The issues

| Part | Issue |
|---|---|
| The whole proposal | #921 |
| 1. Resources by place | #922 |
| 2. Private companies and the IPO | #923 |
| 3. Takeovers | #924 |
| 4. The kill switch | #925 |
| 5. Bankruptcy | #926 |
| 6. Anonymous trading and the planets | #927 |
| 7. Contracts and bonds, with grades | #928 |
| 8. Salvage | #929 |
| 9. Dividends written into the code | #930 |
| 10. Who sees a company's code | #931 |
| 11. Example scripts and lessons | #932 |

## The player panel

A panel of prospective players reviewed this proposal before any of it was built. Their feedback, and the fixes it suggests for the project lead to decide on, is in a comment on #921.

## Answers to the panel (proposed)

The aim is to answer each finding without adding a rule a player has to learn. Each answer does one of these:

- **unifies** two rules into one;
- **lets prices do the work** in place of a limit;
- **shows information** in place of a ban;
- **changes only how a number is worked out** (a formula players never need to know).

None of this is decided; the project lead chooses on #921.

### 1. Free money can be farmed (finding 1)

- **Adding credits buys new shares at cash-out.** This unifies "add credits" with the new shares a company already sells (#780). Money in means shares out at a fair price, so adding credits can't be a gift to a friend, and minority holders are never diluted. Adding credits to a private company that someone else owns sells them shares, so it is that company's IPO: nobody needs a separate rule.
- **The IPO trades within the usual band:** 0.8 to 1.25 times the bank's price (cash-out plus 10 game days of dividends). This removes the IPO's exemption. A company with a strong record earns a higher bank price, and so a premium. An overpriced IPO can't be used to hand money to a friend, and investors get the cap Ruth asked for.
- **Everyone starts, and starts over, with the same package:** a private company (one drone at Earth, with 3,000 credits inside it) and 2,000 starting credits to invest, which buy shares at no more than the bank's price, as today. The only free money a player holds directly can't be overpaid to anyone. The cash inside the company can leave only through trades, where the price is set by the market. Starting over gives a drone and some working cash, not a pile of spendable credits, so it isn't worth farming.
- **Teachers' and newcomers' gain:** every pupil has a company to program from the first minute.

### 2. The import ration multiplies (finding 2)

**Drop the ration.** The planets' import price rises with how much everyone has bought at that exchange that day, and falls back overnight. Splitting purchases across companies gains nothing, because the price depends on the total, not on the buyer. This removes a rule and a failure code (-17), and player sellers undercut a rising import price, which is the point of the ration. It mirrors what lesson 6 already says about selling ("a station pays less the more a batch holds").

### 3. Grades can be farmed (finding 3)

**Grade only what can't be faked:**
- cover, with goods valued at the 7-day average bid, as cash-out values them;
- age;
- any default in the last 30 game days, which means E.

Promises kept no longer raise a grade, because wash promises between one's own companies could fake them. Only a default, which can't be faked in your favour, lowers it. Players learn nothing new; only the formula changes.

### 4. Lenders can't read the code (finding 4)

**Anyone with a stake reads the code:** one share or one bond, in place of 5 shares. The number is simpler, lenders can read what they lend to, and pupils can read a classmate's public company by owning one share.

**While an IPO offer stands, the code is public.** This is the prospectus: the price of raising money is disclosure. It answers Ruth's "I pay to look" and her worry about a company closing itself the day after its IPO. The exit routine is there to read before anyone buys.

### 5. A missed bond payment is undefined (finding 5)

**One line:** a company that misses a bond payment pays every credit it receives to its lenders until they're paid. That includes dividends, which stop meanwhile. It then plays on, its grade is E, and if it goes broke it ends as now. **Every ending pays lenders first, then shareholders,** whether the company closes, goes broke or loses its last drone. This is the one ending order, already in part 4.

### 6. Anonymity fails in thin markets, and the planned wreck (finding 6)

**Show it, don't ban it.**
- **Related trades on the investor sheet:** the share of a company's purchases and sales that went to companies of its founder, or of its 5 biggest shareholders, as today's dealings flag the same player behind a trade.
- **Who salvaged each wreck** is on the wreck's page and the company's, with the same flag.
- **The defence is the locked code.** "Buy rare module X at any price" or "fly to a dead end on day 90" is written in code every shareholder can read before buying (point 4). Read before you buy is already rule 7's lesson.
- **Book depth** (units and volume at each price at a place) is readable by drones and shown on pages, so thin markets look thin.

### 7. The Lua interface (finding 7)

These are reads and words, not rules:

| Word | Returns or does |
|---|---|
| `obligations()` | the company's bonds and contracts: `{kind, id, units_left, due_in, bond}` |
| `contracts(resource)` | open contracts here: `{id, units, price, due_in, bond, grade}` |
| `deliver(id, n)` | fill a contract taken by this company |
| `buy_bonds(n, max_price)` and `sell_bonds(n, min_price)` | at the stock exchange, sent at the speed of light |
| `import_price(resource, n)` | what n units would cost from the planets here now (point 2) |
| `depth(resource)` | the book here: `{price, units}` rows, both sides |
| `set_dividend_share(x)` | the only dividend word (no declared constant), kept by the company, 0.5 until set |
| `due_in` | game days from now, everywhere |

**Migration without breaking locked code:**
- `best_offer` and `best_bid` keep returning three values, but the second becomes an **order handle** in place of a drone ID.
- `pull(n, r, handle)` and `push(n, r, handle)` accept it at that place.
- So matured companies' `buy_cheap` and `pull(..., seller)` keep working, and anonymity holds.

### 8. Jargon (finding 8)

- **Plain names:**
  - **cash-out** → **book value** (what a share would get if the company closed today);
  - **change of control** → "a takeover repays its bonds";
  - **bankruptcy** → **Start over**, which also answers Mr. Okafor's point that it isn't bankruptcy as economists mean it;
  - **grant credits** → **starting credits**, as today.
- **Cover** is never shown to players, only the grade.
- **A glossary** at the top of the players' manual.
- **One worked example in plain numbers:** a Miner from founding to IPO, a bond, a takeover and a sell-out.
- **The seven lines are the rules;** the parts that follow are how each works. Say so, so the eleven parts don't read as a trick.

### 9. Investors need one page (finding 9)

**An investor sheet** on each company page, built from data the game already keeps:
- price against book value, and idle credits;
- dividends declared against paid over 30 game days;
- bonds and contracts outstanding, with due dates;
- related trades (point 6);
- the founder's earlier companies and how each ended, with their defaults;
- whether its code calls `close_company()`, checked by the server, so the code itself isn't shown;
- the grade.

Also:
- **IPOs on the stock exchange's front page,** with the opt-in e-mail notices (#761);
- **each grade's actual default rate** on the dashboard.

The premium cap (point 1) and the public code during an IPO (point 4) answer the rest.

### 10. Classrooms (finding 10)

- **Live companies can't be attacked.** Plunder and salvage reach only wrecks, never a working drone, and a private company can't be taken over unless its owner sells. The players' manual should say so, since the panel's teacher read it otherwise.
- **Class settings, not game rules:** class groups already trade shares only within the group. The teacher's page adds switches for Start over (on or off) and for takeovers within the class, and a read-only log of transfers between pupils.
- **Pupils read each other's public code** by owning one share (point 4).

### 11. Testing before the lock (finding 11)

- **A test drive:** run code read-only against the last few game hours of a place's market and the company's own state, and see the actions it would have taken. It's an extension of `POST /check`, a tool and not a rule.
- **A founder's note:** a short text a company's founder can edit after the lock ("known issue: it idles if fuel is over 900"). It is not code, so it changes nothing the drones do.

### Contract squatting, greenmail and pushing the planets out (findings 6 and 12)

- **Squatting** (taking every contract, then forfeiting when spot rises) is a free option priced by the bond. The buyer sets the bond, so the market answers it.
- **Greenmail by holdouts** stays allowed. The squeeze-out remains the fallback if it blocks too many takeovers.
- **The planets' spread** widens on players' trade between different founders only, so wash trades can't push the planets out. Like the grade, only the formula changes.

### The rules after these answers

1. You start, and start over, with one company (a drone and 3,000 credits) and 2,000 starting credits to invest.
2. A company you own outright is private: only you can change its code, and you may own at most 2.
3. Selling any share (or anyone adding credits) is its IPO: its code is shown while the offer stands, then locked for good.
4. Money in buys shares at book value, and share prices stay within a band of the bank's price.
5. A company ends only through its own code (once it owes nothing), by going broke, or with no drones left. Lenders are paid first, then shareholders, and everything else becomes salvage.
6. Trading at a place is anonymous: the best price wins, and the planets' prices rise the more everyone buys.
7. Promises (contracts and bonds) have due dates and grades. Only public companies borrow, and anyone with a stake reads the code.
8. Worth under 500? Start over, once a real day.

These replace or simplify:
- grant credits, as a separate kind of money;
- the IPO's exemption from the band;
- the import ration and -17;
- the 5-share threshold;
- the declared dividend constant;
- the "promises kept" input to grades.

They add one line (the band and money-in rule, line 4), and turn "bankruptcy" into "start over".
