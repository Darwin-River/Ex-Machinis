# Ownership and open markets (proposal)

**Status:** built. Every part is live and plays by the [Rules of Play](../design-manual.md#The-Rules-of-Play): parts 1 to 10 (#922 to #931; part 5, bankruptcy, #926, turned out not to be needed) and part 11's tools (#932, #993), with the `depth` word (#1009). Separate used modules (part 8) were dropped by the project lead: salvage recovers a wreck's modules as ordinary modules. The pause button and the class clock stay [held for later](#held-for-later). Tracked by #921. This document keeps the design as it was decided; where the built system differs in a detail, the Rules of Play and the players' manual are right. This version brings together every decision the project lead has made, after five rounds of persona-panel review; [How the design got here](#how-the-design-got-here) lists them.

**Aim:** a market as busy and varied as possible, built from rules a newcomer can hold in their head. Each rule replaces special cases rather than adding them. The complexity should come from what drones, companies and players do with the rules.

**The path through the game:** a new player codes drones first, on free grants, against a reliable market. Each new layer opens because the player has earned it, never on a timer:
1. **Coding:** startups on free grants.
2. **The player market:** a startup that matures trades with everyone, and its founder gets its shares.
3. **Investing:** shares pay their holder credits, and credits buy other shares.
4. **Lending:** once a player holds enough credits.

A player who gets in over their head falls back to the simpler game on their own: spend your credits and lending closes; lose your companies and you found startups again, for free.

## Why

Reviewed as an economy and as an ecosystem, the live game has detailed mechanics (fuel, recipes, orbits) and a strong scripted environment, but few ways for one company's success to become another's opportunity or problem:

- **The House sets the market.** It is the default buyer and seller at every exchange, and its prices follow scheduled waves.
- **Nothing is scarce anywhere.** Every mineral is found on every body of its type at the same abundance (`DEFAULT_ABUNDANCE` in [`build_game_data.py`](../../game-engine/sql/game-data/build_game_data.py)), and every exchange sells every good. Nobody needs to travel.
- **Idle money goes nowhere.** 93% of matured companies' credits are idle cash (economy playbook, lesson 33). Nobody can lend to a company, and a company can't commit to a future trade.
- **Value is lost when a company dies.** A wreck gives up half its hold to one plunderer, and the rest is lost. Every unchanged starter dissolved at the same minute (#837), and the value went with them.

The startups that bring new players in work, and this proposal keeps them, with fewer special rules.

## The rules in eight lines

1. **Found startups on free grants.** A startup trades only with the planets, nobody can plunder its wrecks, and closing it cancels its debt.
2. **A startup that earns back its grant plus a margin matures:** it repays the grant, trades with everyone, and you get its 100 shares.
3. **You can change the code of any company you own outright,** and you may own at most **5** outright, startups included.
4. **The first share you sell locks the company's code for good,** unless someone later buys all of it back; you can't buy a company's shares for 7 real days after selling any.
5. **Only companies with locked code can borrow:** their code posts loan requests, and players with at least **5,000 credits** choose which to back.
6. **A company ends only through its own code** (once it owes nothing), by going broke, or with no drones left. Lenders are paid first, then shareholders, and everything else becomes salvage.
7. **Trading at a place is anonymous,** and the planets always buy and sell common goods.
8. **Your credits come from your shares;** with them you can buy shares and back loans.

The parts below say how each rule works, and [a worked example](#a-worked-example) follows one player through all of it. The [glossary](#glossary) explains the terms.

## 1. Resources by place (#922)

- **Each mineral has a home.** Abundance varies by body: rich on a few, absent from others, in place of one `DEFAULT_ABUNDANCE` for every body of a type.
- **Valuable inputs lie far away.** The minerals behind the upper tiers (systems, modules, kit parts) are found only at asteroids, comets or rings. The common ores and metals the starters use stay near Earth, the Moon and Mars, so a startup still matures in about 40 game days (#777).
- **The planets don't sell the far goods.** They keep selling fuel, kits and the common goods, and keep buying the far goods near Earth. Supply is far away and demand is at home, and that gap is the market.
- **Fuel only at the exchanges.** Work far away needs a supply line, so fuel depots and far-away fuel brewers become a business.
- **Depletion stays off.** Distance does the job, with fewer rules.
- **The far map shifts each season.** Every 4 real weeks, the homes of the valuable far-away minerals move: some bodies grow rich, others run poor. The next season's map is published a real week ahead, so players can plan. The common ores near Earth, the Moon and Mars don't move, so starters still mature. Routes, depots and locked designs have to adapt or give way, which keeps the physical economy from being solved once and for all, and feeds salvage and turnarounds.
- **Lever:** the far goods' bids must pay for the trip (playbook, lesson 8: the Moon to Mars is about 10 game days each way).

## 2. Startups, maturing and the IPO (#923)

This keeps the live game's startups and trims them.

### Startups
- **A free grant.** Every startup gets a drone at Earth (valued at 1,000, with 100 fuel) and 3,000 credits from the bank, plus a 200 fee: it owes **4,200**, as today. Nothing comes from the player.
- **Its owner changes its code at any time, for free.** The push fee goes. Processor tax still applies.
- **It trades only with the planets.** It buys from and sells to the planets' orders at a place, and never trades with players' companies (-16), so the grant can't leak to a friend before it has been earned back. It signs no contracts and asks for no loans.
- **It plunders nothing, and nobody can plunder or salvage its wrecks.** A beginner's failures cost them nothing and feed nobody.
- **Closing it cancels its debt:** its credits go back to the bank, and its drones become wrecks with empty holds. Like every company, it closes through its own code (`close_company()`); the Close button on its page pushes that for the owner.
- **Its record and its code** are seen by its owner only (and their teacher, in a class).

### Maturing
- **When** a startup's credits cover its debt and **500** more, its owner may mature it, as today.
- **What happens:** it repays the grant, trades with everyone (players' companies, contracts, salvage), and its owner gets its **100 shares**.
- **Its code stays the owner's to change** while they own all 100 shares. They can keep tinkering with a company that now meets the whole market.

### Owning outright
- **A company is owned outright** when one player holds all its shares, or it's a startup. Its owner can change its code.
- **At most 5 companies owned outright,** startups included. This merges today's five-startup limit with the redesign's private-company limit into one rule. A slot frees when the player sells a share or closes the company; a closed startup's slot reopens a game day later, as today. A trade that would give a player a sixth fails.
- **Public companies have no limit.** A player may found, mature and sell shares in as many companies as they can build; each company's fleet cap (8 working drones) and processor tax still apply.

### The IPO: the first share sold
- **The owner sells shares** on the stock exchange at a price they choose, or to the bank at close-out value.
- **From the moment shares are offered, the code is public,** and it can't be pushed while the offer stands, so buyers get the code they watched run.
- **The first share sold locks the code for good** and makes the company public. The money goes to the owner. An offer that doesn't sell locks nothing: the owner can withdraw it, change the code and try again, but the code has been seen.
- **Why it locks:** buyers get exactly the code they watched run, and nobody can change it after taking their money. The website says so wherever selling shares is offered.
- **No buying back straight away:** a player can't buy shares of a company for 7 real days after selling any of its shares. Without this, an owner could sell one share to the bank, buy it straight back and be owning outright again, so the lock and the limit of 5 would mean nothing. The wait is in real days because at 1,800 times real time even a game month is under half a real hour.
- **Nobody adds credits to a company.** A startup's money is its grant and earnings. A matured company's money is what it earns and, once public, what its code borrows.
- **This replaces:** the credit cap and its sweep, and the daily buying cap (-17); the rising import price (part 6) does its job for everyone. It also replaces the earlier proposal's private companies, starting drone and credits, adding credits before an IPO, and the limit of 2 private companies.

## 3. Takeovers: owning 100% unlocks the code (#924)

- **A public company whose shares one player buys up completely is owned outright again.** Its code can be changed, and it counts against that player's 5.
- **Turnarounds are a business.** Buy a failing company cheaply, rewrite its code, and sell shares again at a profit. The first share sold locks the new code. A fleet already in place, often far away, is worth more than wrecks.
- **Investors can't be harmed:** everyone else sold of their own free will.
- **The founder can't take their own company back within 7 real days of selling shares in it** (part 2), so going public is a real decision.
- **A takeover makes the company's loans due at once,** so a raider can't unlock the code and rewrite it to walk away from its lenders.
- **Holdouts** (one player keeping a single share) are allowed. If they block too many turnarounds, add a squeeze-out: a holder of 95% may buy the rest at a set premium.
- **In a class,** the teacher can switch off takeovers within the class.

## 4. The kill switch (#925)

- **A company ends only through its own code,** with `close_company()`, or by going broke (50 game days without credits), by winding up (60 game days without a sale) as now, or with no working drones (below). **A Close button** on the page of every company owned outright (a startup, or a company with one owner) pushes code that calls `close_company()` for the owner, with one line saying what will happen. The rule is the same; failing stays free in two clicks. A public company has no button: only its own locked code can close it.
- **A startup** always can: its grant is cancelled, and its credits go back to the bank.
- **A matured company can close only when it owes nothing:** no loans outstanding and no open contracts. Otherwise `close_company()` fails with a new code ("obligations outstanding"). The ways out:
  - `repay(id)` pays a loan back early, with the interest due so far;
  - a contract is delivered, or cancelled with `cancel_contract(id)`, which forfeits its deposit.
- **Every ending of a matured company is the same ending:**
  1. Open orders and loan requests are withdrawn.
  2. Lenders are paid first, in proportion to what each is owed.
  3. The credits left go to the shareholders by their shares, the bank included.
  4. Its drones become wrecks with whatever they still hold, and its code becomes public, if it wasn't already.
- **The engine never sells for a company.** A smart design notices that it's failing, sells its goods and spare modules, repays its lenders, brings its drones in, and then closes. A careless one leaves its value to salvagers. Investors can read a design's exit routine and pay for one that dies well.
- **A company with no working drones** can't run code, so it ends by itself after 3 game days, in the same way.
- **The 12-hour minimum order life** still holds, and `close_company()` is its one exception, so a company can always die.

## 5. No bankruptcy, no Start over (#926, not needed)

Failing costs a new player nothing: startups are free, and closing one cancels its debt. A player never has their own money at risk until they've earned it from shares. A player who loses everything they've earned founds startups again, as on their first day. The earlier proposal's Start over, its wait and its starting package are not needed.

## 6. Anonymous trading and the planets (#927)

- **Drones don't know whom they trade with.** `buy_here` and `sell_here` already fill against the best price at a place; now that is the only way to trade with another company. For a startup, they fill only against the planets' orders.
  - `best_offer` and `best_bid` return a price, units and an **order handle** in place of a drone ID. `pull(n, r, handle)` and `push(n, r, handle)` accept the handle at that place, so locked code written for `pull(..., seller)` keeps working. A handle is opaque, belongs to one order, and is never a drone ID. The same goes for every query that lists orders (`sell_orders`, `buy_orders`, `orders_everywhere`) and for trade events.
  - `push` and `pull` with a drone ID work only between a company's own drones (handing a kit to a copy, sharing fuel).
- **The House becomes "the planets":** each exchange's planet buys and sells as imports and exports. Its orders sit in the book like anyone's, anonymously. It isn't a company, a player or a leaderboard entry.
- **A reliable market, as a rule:** every exchange always buys and sells the common goods, at a price.
- **The import price** of a good at an exchange rises with how much everyone has bought there recently and decays back steadily; there is no overnight reset to race for. Splitting purchases across companies gains nothing. Player sellers undercut a rising import price, so trade between players grows. **Fuel stays at a flat list price,** so the newcomers' safety net can't be spiked. `import_price(resource, n)` shows the curve.
- **The planets step back where players trade.** Their spread on a good widens as players' share of its trade grows (counting only trades between companies of different founders, so wash trades can't push the planets out), and narrows again if players stop. They return faster than they retreat, so a market that players abandon recovers quickly.
- **Shown after the fact:** company pages, histories and the dashboard still name counterparties, label imports and exports, and flag trades between companies with the same player behind them. Anonymity applies to drones' decisions, which is where one company could favour another.
- **Book depth** (units at each price, both sides) is readable by drones and shown on pages, so thin markets look thin.

## 7. Investing, loans and contracts (#928)

**Players are the only investors.** Companies make things and trade goods; their only financial acts are asking for loans, repaying them and paying out, written in their code. Players buy shares and back loans, and earn by judging which code and which markets will pay. Companies never buy shares or lend: today's rule that companies don't trade shares (-18) stays.

**A player's credits come from their shares:** dividends, payouts, selling shares, and a company's closing. There are no starting credits. A new player's first credits come from maturing a startup.

### Shares
- **Players set share prices.** There is no floor and no band: a company in trouble can trade below what it holds, and that price is information.
- **The bank keeps only a backstop:** it buys any share at close-out value, paid 30 game days later at the lower of the value then and on the day of the sale, as now. The shares it holds sell into the best standing buy order, never below close-out value (so nobody can buy from the bank and sell straight back to it).
- **Wealth and leaderboards** count shares at close-out value, never the last trade price, so friends trading at a high price can't raise anyone's rank.
- Standing orders, pegged orders (#766, pegged to close-out value or the last trade price) and account trading scripts (#768) work as now.

### Loan requests
- **A public company's code asks for a loan:** `request_loan(amount, rate, due_in)`. The rate is simple interest for the whole loan, as a percent; `due_in` is in game days. The loans board also shows each rate per game year, so loans of different lengths compare.
- **Only companies with locked code borrow:** lenders can read code that won't change. A company its owner still owns outright, or a startup, asks for no loans.
- **Players with at least 5,000 credits** may back loan requests, from the website, the API or their trading script (`back_loan(id, n)`). Below that, a player can't back new loans; loans they've already backed run to their due date. Lending opens as a player gets on, and closes again if they spend down, so a player who gets in over their head drops back to the simpler game by itself.
- **Each backing is lent to the company at once.** The company's code can withdraw what isn't backed yet (`cancel_request(id)`), and a smart borrower raises its rate when nobody backs it.
- **On the due date** the company repays each lender what they lent plus the interest. It can repay early with `repay(id)`, paying the interest due so far.
- **If it can't repay in full,** every credit it receives goes to its lenders, in proportion to what each is owed, until they're paid. Its dividends and payouts stop meanwhile, and the default is on its record and its founder's. If it then goes broke, it ends as usual, lenders first.
- **Reading the code is the lender's protection.** Whatever the code will do with the money, including paying it straight out to its shareholders, is there to read.
- **Insider loans are shown:** the investor sheet marks loans backed by the founder or the 5 biggest shareholders. The rate is set by the company's code, so an insider can't lend at a rate the code didn't ask for.
- **The lender's record:** each player's page shows the loans they've backed, the interest earned and the defaults suffered, and the leaderboards rank lenders by their return.

### Contracts between companies
- **A contract** is a buy order with a due date and a deposit: `post_contract(resource, units, price, due_in, deposit)`. The buyer's credits are held in escrow. Matured companies only; startups trade only with the planets.
- **Any seller may take it** with `take_contract(id)`, putting up the deposit, and then only that seller may fill it, with `deliver(id, n)` at the buyer's place.
- **On the due date,** whatever was delivered is paid. For the rest, the buyer gets back their credits and that share of the seller's deposit.
- **Contracts are anonymous:** listings show units, price, due date, deposit, and how many contracts the poster has kept and broken, never who. Escrow and the deposit secure both sides.

## 8. Salvage (#929)

- **Wrecks keep their hull and equipment** as well as their hold. A startup's wrecks are the exception: nobody can plunder or salvage them.
- **Plunder is unchanged** (half the hold, once, no tool). **Salvage** is new: a Salvage module, made in the tech tree, recovers a wreck's equipment as used modules and its hull as scrap metal, a little a run.
- **Wrecks decay** (proposed: 2% of what's left a game day), so salvage is a race. Playtests check that salvage still pays on a 20-day trip, when about a third has gone.
- **There is always a buyer:** the planets buy scrap and used modules at a discount. Used modules work, so they're the cheapest way to equip a copy.
- **Wrecks lie where drones died.** With far-away resources, they gather along the long routes.
- **A wreck field view** on the map: wrecks near each place, what they hold, how fast they decay, and who salvaged each (flagged when the same player is behind the wreck and the salvager). A mass ending is a world event.

## 9. Paying out, written into the code (#930)

- **`set_dividend_share(x)`** sets the share of each game day's profit the company pays to its shareholders (0 to 1; 0.5 until set). The company keeps the setting. Once the code is locked, so is the policy.
- **`pay_out(n)`** pays any spare credits to the shareholders at any time, on top of dividends. Companies can't invest their idle cash, so this is how it returns to players, who reinvest it.
- **The policy is public:** the company page and the stock exchange show the dividend share and what was actually paid over 30 game days.
- **Growth companies and income companies** become a choice investors can see. A company that pays out everything has to borrow to grow.

## 10. Who sees a company's code (#931)

- **A company owned outright** (a startup, or a matured company with one owner): its owner only, and their teacher, in a class.
- **A public company's code, and a dead company's code:** everyone. Investors and lenders study it before buying or lending, and studying code becomes a way to earn.
- **From the moment shares are offered,** the code is public, even if the offer is withdrawn unsold.
- **Always public:** behaviour (trades, events, earnings, drones), the payout policy, and the lineage map.
- **The founder is paid for the design when they sell shares.** After that, a design anyone can read can be copied, and copies compete with it; that is the game's open-code lesson.
- This keeps the spirit of today's rule 7 ("All code is public") for every company that has gone public or died. [`open-source-pathway.md`](../open-source-pathway.md) gets a note: the game's own code stays MIT-licensed, and only a script owned outright is private, and only while it is.

## 11. What players see, and the tools around it (#932)

- **The investor sheet** on each company page, built from data the game already keeps:
  - price against close-out value, and idle credits;
  - dividends and payouts, declared against paid, over 30 game days;
  - loans (insider loans marked), loan requests and open contracts, with due dates;
  - trades with companies of its founder or its 5 biggest shareholders;
  - the founder's earlier companies, how each ended, and their defaults;
  - its history: sales, drones lost and payouts over time.
- **IPOs and loan requests on the stock exchange's front page,** with the opt-in e-mail notices (#761).
- **A test drive** (an extension of `POST /check`): run code read-only against the last few game hours of a place's market and the company's own state, and see the actions it would have taken. The same for account trading scripts.
- **A founder's note:** a short text the founder can edit after the lock ("known issue: it idles if fuel is over 900"). It changes nothing the drones do.
- **Class settings** (the teacher's page, not game rules): takeovers within the class on or off, backing loans only within the class, and a read-only log of transfers, loans and payouts between pupils. Class groups already trade shares only within the group.
- **Example scripts and lessons**, which players build into their own scripts; the engine doesn't:
  - copies that vary (tuning numbers nudged in each copy with `lineage()`, copying from the best-earning drone). Every starter does this, so no two copies fail at the same moment;
  - a sell-out routine (sell, repay, bring the drones in, `close_company()`);
  - a borrower (asks for a loan for a copy only if the copy pays back within the term, and raises its rate when unbacked);
  - an account trading script that backs loans by each company's record, its rate and a check of its code;
  - a salvager, and a hauler for far-away goods.
- **A glossary and the worked example below** in the players' manual.

### Getting new players to a first success

These are screens and starter scripts, not rules:
- **Starters that mature untouched.** Each starter matures on its own if a new player changes nothing, and has plain-English comments on the three numbers worth changing ("sell when the price is above this").
- **A guided first mission:** "found a startup, change one number, mature it", with a target time to beat and the exact line to edit.
- **Times in real minutes:** a startup's page says when it should mature at the current pace, in real minutes, not only game days.
- **"While you were away":** a short summary on return of what each company did, earned and lost, building on "Since you last looked" (#771).
- **The rules revealed in layers:** a new player sees the basics (rules 1, 2 and 7, plus fuel and travel, which are what wreck first drones). The rest appear in the strip under the menu as each layer is earned ("Next: sell a share to go public"), and the way to 5,000 credits shows as a progress bar, not a locked door. Nothing is switched off; the help shows what matters next.

### Every option for everyone who earns it; drone code alone is enough to succeed

Nothing is switched on by a timer or a teacher. Each layer opens because the player has earned it: maturing opens the player market, shares bring credits, and 5,000 credits open lending. A new player can have fun and do well **just by coding drones**. Startups that mature pay their founder dividends, and wealth and the seasonal leaderboards reward that without a share sale, a loan or a contract.

**The players' manual becomes a textbook** that follows the same path, simplest first, and encourages players to master each part before moving on:

| Part | Chapters | You've mastered it when |
|---|---|---|
| I. Your first startup | Getting started with the Miner; Lua through the starter; mining and refining; selling to the planets; fuel and travel; reading your drone's reports and the test drive | your startup earns back its grant and matures |
| II. Markets | Trading with everyone at a place; best price and depth; import prices; contracts; hauling far-away goods; salvage | your company buys low and sells high, or hauls a far good at a profit |
| III. Growing | Launching copies; copies that vary with `lineage()`; more companies; dividends and `pay_out` | you run a fleet whose copies differ and outlast a price slump |
| IV. Going public | Close-out value; selling shares and locking the code; writing an exit routine; turnarounds | you've sold shares in a company that later paid its holders more than they paid |
| V. Finance | Reading other companies' code; buying shares; backing loan requests; account trading scripts | your loans and shares earn more than they lose over a season |

Each chapter states its goal, explains one idea with a working script, ends with an exercise and a "you've mastered this when" check, and points to the next chapter. The glossary, the Lua reference and the failure codes are appendices.

### Held for later

- **A pause button for companies owned outright:** the owner puts the company to sleep, so it burns no fuel and runs up no costs until woken, for players and classes who are away for days. Held because it adds to the interface; to be reconsidered once the rest is built and played.
- **A class clock** (a class's companies running only during lesson hours), for the same reason.

## Lua words

Reads and actions, not rules:

| Word | Returns or does |
|---|---|
| `close_company()` | ends the company: always for a startup, otherwise only if it owes nothing |
| `request_loan(amount, rate, due_in)` | asks for a loan (public companies only); returns its id |
| `cancel_request(id)` | withdraws the unbacked part |
| `repay(id)` | repays a loan early, with interest due so far |
| `obligations()` | everything it owes or has promised: `{kind, id, amount, rate, due_in}` rows for loans, requests and contracts |
| `post_contract(resource, units, price, due_in, deposit)`, `take_contract(id)`, `deliver(id, n)`, `cancel_contract(id)` | contracts |
| `contracts(resource)` | open contracts here: `{id, units, price, due_in, deposit, kept, broken}` |
| `set_dividend_share(x)`, `pay_out(n)` | paying shareholders |
| `import_price(resource, n)` | what n units would cost from the planets here now |
| `depth(resource)` | the book here: `{price, units}` rows, both sides |
| `startup_status()` | as today: the debt, credits and whether it can mature |
| `due_in` | game days from now, everywhere |

For account trading scripts: `loan_requests()`, `back_loan(id, n)` and `my_loans()`, beside the share words they have now. Each new word gets its own failure codes, listed in `lua-functions.md` when it is built.

## A worked example

1. **Priya founds a startup,** Rocket Co, on a free grant: a drone at Earth and 3,000 credits, owing 4,200. She loads the Miner starter and fixes it three times in her first evening, for free. It sells its metals to the planets.
2. **After 40 game days** Rocket Co holds 4,700 credits, its debt plus 500. Priya matures it: it repays the 4,200, trades with everyone from now on, and she gets its 100 shares. She keeps changing its code; it's still hers alone.
3. **Its dividends** (half its daily profit, until her code says otherwise) bring Priya her first credits. Over the next 60 game days she founds two more startups and tunes Rocket Co to haul a far-away good.
4. **She sells 40 shares at 80,** against a close-out value of 50. The moment she offers them, Rocket Co's code is public. Ruth reads it, likes its exit routine, and buys 10 shares for 800. The code is locked, Rocket Co is public, and Priya has 800 more credits.
5. **Rocket Co's code asks for a loan** of 3,000 at 6% for 30 game days, to buy a second drone. Ruth and Marcus each hold over 5,000 credits, so they can back it: Ruth backs 1,000 and Marcus 2,000. On the due date Rocket Co repays Ruth 1,060 and Marcus 2,120.
6. **If instead it had paid only 2,000** on the due date, Ruth would get a third of it (667) and Marcus two thirds (1,333). Every credit Rocket Co earned afterwards would go to them in the same proportions until they had their 3,180, and its dividends would stop meanwhile.
7. **If it then went broke,** whatever credits were left would go to Ruth and Marcus first, then to its shareholders, and its drones would become wrecks for salvagers. A cheaper fate: Marcus buys all 100 shares cheaply, which makes the loans due at once, repays them, rewrites the code and sells shares again.

## Glossary

- **Startup:** a company on a free grant from the bank. It trades only with the planets, and closing it cancels its debt.
- **Maturing:** a startup repaying its grant once it can; it then trades with everyone, and its founder gets its 100 shares.
- **Owned outright:** a startup, or a company whose shares one player holds all of. Its owner can change its code.
- **Close-out value:** what a share would get if the company closed now: its credits, its goods at the planets' bids averaged over 7 game days, and its hulls, less what it owes, over its shares. It is worked out by the game, not judged by anyone. The bank buys shares at this value.
- **IPO:** the first sale of a company's shares. It makes the company public and locks its code.
- **Public:** a company whose shares more than one holder owns; its code is locked and anyone can read it.
- **Loan request:** a public company's ask to borrow an amount at a rate until a due date, which players with at least 5,000 credits can back.
- **Contract:** one company's order for goods by a due date, with the buyer's credits held in escrow and the seller's deposit at stake.
- **Deposit:** what a seller puts up when taking a contract, lost for what it fails to deliver.
- **Salvage:** recovering used modules and scrap from a wreck.
- **Turnaround:** buying all of a failing public company's shares, rewriting its code, and selling shares again.

## Moving the live world over

- **Live startups carry on** under the trimmed startup rules: no push fee, no credit cap, no daily buying cap.
- **Matured companies whose shares one player still holds all of** are owned outright, and their code becomes changeable again. Every other matured company is public; its code is already locked and stays readable by everyone, as all code is today.
- **The bank's companies** (Bank Miner, Refiner, Maker) are public.
- **Players' starting credits** already granted stay as they are, with today's restriction, until spent. New players get none, and neither do new class accounts (whose pupils get 5,000 at once today); their first credits come from maturing.
- **Owner-led offerings of new shares (#780) retire,** because only a public company's own code may bring money in.

## Numbers to tune

| Rule | Proposed |
|---|---|
| A startup's grant | a drone (valued at 1,000) and 3,000 credits, plus a 200 fee: 4,200 owed, as today |
| Credits to mature, above the debt | 500, as today |
| Companies owned outright | 5, startups included |
| A closed startup's slot reopens after | 1 game day, as today |
| Credits needed to back new loans | 5,000 |
| No buying a company's shares after selling any | 7 real days |
| The far map shifts | every 4 real weeks, published a real week ahead |
| The bank pays for shares after | 30 game days |
| A company with no working drones ends after | 3 game days |
| Import price | rises with recent buying, decays back steadily; fuel at a flat price |
| Wreck decay | 2% a game day |
| Insider loans marked | from the founder or the 5 biggest shareholders |

## Order of work

1. Resources by place (game data only; everything else depends on it).
2. Paying out, written into the code.
3. Startups trimmed, maturing without the lock, the IPO lock at the first share sold, takeovers, the kill switch and who sees code, which ship together.
4. Anonymous trading and the planets.
5. Salvage.
6. Loan requests and contracts, after the credit check (#937).
7. The investor sheet, the test drive, example scripts and the manual as a textbook, alongside each part.

Each part is timed in a playtest round on #380 against round 73's measures: time to mature, idle cash, the share of trades between players, and how many companies end on the same day.

## The issues

| Part | Issue |
|---|---|
| The whole proposal | #921 |
| 1. Resources by place | #922 |
| 2. Startups, maturing and the IPO | #923 |
| 3. Takeovers | #924 |
| 4. The kill switch | #925 |
| 5. Start over (not needed) | #926 |
| 6. Anonymous trading and the planets | #927 |
| 7. Investing, loans and contracts | #928 |
| 8. Salvage | #929 |
| 9. Paying out, written into the code | #930 |
| 10. Who sees a company's code | #931 |
| 11. What players see, and the tools around it | #932 |
| Safety and operations | #934, #935, #936, #937 |

## How the design got here

Panels of prospective-player personas reviewed the proposal five times; their findings and ratings are in the comments on #921. The project lead decided, in order:

- at most a few companies owned outright; the money from selling shares goes to the founder;
- a company closes only through its own code, and only once it owes nothing;
- the House becomes a hidden hand ("the planets");
- after round 1: no import ration (a rising import price, fuel flat), plain names (close-out value), an investor sheet, a test drive and a founder's note, order handles for locked code;
- after round 2: nobody adds credits to a public company; players set share prices and the bank keeps only its backstop;
- only players invest, and a public company's code is readable by everyone;
- after round 3: companies' code asks for loans and players choose which to back (reading the code is the lender's protection); no share-price floor;
- after round 4: every option is open to every player who earns it; a new player must be able to succeed just by coding drones; the players' manual becomes a textbook;
- then, in place of private companies, starting credits and Start over:
  - **the live game's startups stay,** trimmed: free grants, trading only with the planets, maturing for shares;
  - **the code locks at the first share sold,** not at maturing, so owners keep tinkering and turnarounds pay;
  - **one limit of 5 companies owned outright;**
  - **no bankruptcy;**
  - **5,000 credits to back loans,** so the game goes from simple to hard and falls back to simple for a player who gets in over their head.
- after round 5:
  - **interface fixes for a first success:** a Close button that pushes `close_company()`, starters that mature untouched, a guided first mission, times in real minutes, "while you were away", and the rules revealed in layers;
  - **no buying back for 7 real days after selling,** so the lock can't be rotated;
  - **the far map shifts each season,** announced a week ahead;
  - **no limit on public companies.**
- **no starting credits for class accounts** either; a pause button and a class clock are held for later.
