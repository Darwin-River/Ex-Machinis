# Ownership and open markets (proposal)

**Status:** proposed, not built. The live game still plays by the [Rules of Play](../design-manual.md#The-Rules-of-Play). Tracked by the umbrella issue and its parts listed under [The issues](#the-issues).

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

## 1. Resources by place

- **Each mineral has a home.** Abundance varies by body: rich on a few, absent from others, in place of one `DEFAULT_ABUNDANCE` for every body of a type.
- **Valuable inputs lie far away.** The minerals behind the upper tiers (systems, modules, kit parts) are found only at asteroids, comets or rings. The common ores and metals the starters use stay near Earth, the Moon and Mars, so a new company still pays its way in about 40 game days (#777).
- **The planets don't sell the far goods.** They keep selling fuel, kits and the common goods, and keep buying the far goods near Earth. Supply is far away and demand is at home, and that gap is the market.
- **Fuel only at the exchanges.** Work far away needs a supply line, so fuel depots and far-away fuel brewers become a business.
- **Depletion stays off.** Distance does the job, with fewer rules.
- **Lever:** the far goods' bids must pay for the trip (playbook, lesson 8: the Moon to Mars is about 10 game days each way).

## 2. Private companies and the IPO

This replaces the startup rules: the bank loan and debt, the founding and push fees, maturing and its margin, the credit cap and its sweep, House-only trading (-16), protected wrecks and the five-startup limit.

- **Founding** costs the drone (hull and fuel) and whatever starting cash the founder chooses, from the founder's account. There is no loan, debt or fee.
- **A private company plays by every rule of the game:** it trades with everyone, signs contracts, plunders and salvages, and can be plundered and salvaged.
- **Its code can be changed at any time, for free** (processor tax still applies). A push restarts its drones as now.
- **Its record is public from day one:** trades, earnings, events and drones. When it goes public, investors judge it by that record.
- **At most 2 private companies a player.** A trade that would give a player a third fails.
- **Adding credits:** any shareholder may add credits to the company. It can't be taken back out except as a shareholder (dividends, selling shares, the company closing).
- **The IPO:** the founder offers shares on the stock exchange at a price they choose, but not below cash-out. While an offer stands, the code can't be pushed, so buyers get the code they watched run. **The first share sold, to a player or to the bank, locks the code for good.** The money goes to the founder. After the IPO, trading uses the usual band (0.8 to 1.25 times the bank's price).
- **Grant credits** (the 5,000 at the start and after a bankruptcy) found companies and buy shares at no more than the bank's price, as starting credits do today. With the IPO floor at cash-out, this means grant credits can't be passed to a friend through a mispriced IPO.

## 3. Takeovers: owning 100% unlocks the code

- **A company is private whenever one player owns all its shares,** however they came to own them, and its code can be changed. It counts against that player's 2.
- **Investors can't be harmed:** everyone else sold of their own free will.
- **A takeover makes the company's bonds due at once** (a change-of-control clause), so a raider can't unlock the code and rewrite it to walk away from its debts.
- **It sets a floor under share prices.** A company that trades for less than its fleet, goods and credits are worth gets bought out and reprogrammed or liquidated. This is salvage of a whole company, often a fleet already working far away.
- **Holdouts** (one player keeping a single share) are allowed at first. If they block too many takeovers, add a squeeze-out: a holder of 95% may buy the rest at a set premium.

## 4. The kill switch

- **A company ends only through its own code,** with one new Lua word, `close_company()`, or by going broke (50 game days without credits) or by winding up (60 game days without a sale) as now. Its founder has no Close button: a private company's founder who wants out pushes code that calls it.
- **It works only when the company owes nothing:** no bonds outstanding and no open contracts. Otherwise it fails with a new code ("obligations outstanding"). The ways out:
  - `repay_bonds(n)` redeems its own bonds early at face value (100);
  - a contract is delivered, or cancelled with `cancel_contract(id)`, which forfeits its bond.
- **Every ending is the same ending.** Open orders are withdrawn, then lenders are paid (only when a company goes broke owing), then the credits go to the shareholders by their shares, the bank included. Its drones become wrecks with whatever they still hold, and its code becomes public.
- **The engine never sells for a company.** A smart design notices that it's failing, sells its goods and spare modules, repays its lenders, brings its drones in, and then closes. A careless one leaves its value to salvagers. Investors can read a design's exit routine and pay for one that dies well.
- **A company with no working drones** can't run code, so it ends by itself after 3 game days, in the same way.
- **The 12-hour minimum order life** still holds, and `close_company()` is its one exception, so a company can always die.

## 5. Bankruptcy

- **A player worth under 500 may declare bankruptcy, at most once a real day.** They lose everything (credits, shares, private companies) and start again with 5,000 grant credits. Their history and personal records stay.
- **Their shares go to the bank,** which sells them as it does any it holds. Their private companies become public, bank-held, and open to takeover.
- **It can't print money:** grant credits can't pay an inflated IPO price, and an IPO can't sell below cash-out.

## 6. Anonymous trading and the planets

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

## 7. Contracts and bonds, with grades

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

## 8. Salvage

- **Wrecks keep their hull and equipment** as well as their hold.
- **Plunder is unchanged** (half the hold, once, no tool). **Salvage** is new: a Salvage module, made in the tech tree, recovers a wreck's equipment as used modules and its hull as scrap metal, a little a run.
- **Wrecks decay** (proposed: 2% of what's left a game day), so salvage is a race.
- **There is always a buyer:** the planets buy scrap and used modules at a discount. Used modules work, so they equip a copy cheaply.
- **Wrecks lie where drones died.** With far-away resources, they gather along the long routes.
- **A wreck field view** on the map: wrecks near each place, what they hold, and how fast they decay. A mass ending is a world event.

## 9. Dividends written into the code

- A script declares `dividend_share = 0.3` (default 0.5, between 0 and 1), and may change it as it runs with `set_dividend_share(x)`. Once the code is locked, so is the policy.
- **The policy is public** even when the code isn't: the company page and the stock exchange show the declared share and the share actually paid over 30 game days.
- **Growth companies and income companies** become a choice investors can see. A company that pays out everything has to borrow to grow.

## 10. Who sees a company's code

- **A private company's code:** its owner only.
- **A public company's code:** its owner and any player with **5 shares or more**. A teacher sees their class's code.
- **A dead company's code is public,** so post-mortems and "Copy this code" keep the game's teaching value. The starters and examples stay public.
- **Always public:** behaviour (trades, events, earnings, drones), the dividend policy, and the lineage map's shape, worked out on the server without showing code.
- **Shares become a key to know-how,** and a founder whose design is worth copying gains from the demand for shares.
- This changes rule 7 of the current rules ("All code is public") and needs a note in [`open-source-pathway.md`](../open-source-pathway.md). The game's own code stays MIT-licensed. Only players' in-game scripts are private, and only while their companies live.

## 11. Example scripts and lessons

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

Listed in the umbrella issue.
