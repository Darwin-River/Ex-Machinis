# Ownership and open markets (proposal)

**Status:** proposed, not built. The live game still plays by the [Rules of Play](../design-manual.md#The-Rules-of-Play). Tracked by #921, with a sub-issue for each part (#922 to #932). This version brings together every decision the project lead has made so far, after three rounds of a player panel; [How the design got here](#how-the-design-got-here) lists them.

**Aim:** a market as busy and varied as possible, built from rules a newcomer can hold in their head. Each rule replaces special cases rather than adding them. The complexity should come from what drones, companies and players do with the rules. Players earn by writing good code, and also by reading code and markets well, which is what the game is for.

## Why

Reviewed as an economy and as an ecosystem, the live game has detailed mechanics (fuel, recipes, orbits) and a strong scripted environment, but few ways for one company's success to become another's opportunity or problem:

- **The House sets the market.** It is the default buyer and seller at every exchange, its prices follow scheduled waves, and startups may trade only with it.
- **Nothing is scarce anywhere.** Every mineral is found on every body of its type at the same abundance (`DEFAULT_ABUNDANCE` in [`build_game_data.py`](../../game-engine/sql/game-data/build_game_data.py)), and every exchange sells every good. Nobody needs to travel.
- **Idle money goes nowhere.** 93% of matured companies' credits are idle cash (economy playbook, lesson 33). Nobody can lend to a company, and a company can't commit to a future trade.
- **The startup rules pile up.** A bank loan, a debt, a push fee, a maturing margin, a credit cap, a daily buying cap, House-only trading and protected wrecks: about a dozen rules and three failure codes, all there because the bank lends a new company money for free.
- **Value is lost when a company dies.** A wreck gives up half its hold to one plunderer, and the rest is lost. Every unchanged starter dissolved at the same minute (#837), and the value went with them.

## The rules in eight lines

1. **You start with a mining drone and 1,000 credits.** The drone is your first company.
2. **A company you own outright is private:** only you see and change its code, you can add credits to it, and you may own at most **2** private companies at once.
3. **Offering shares makes a company's code public; the first share sold locks it for good.** That sale is its IPO.
4. **After its IPO, a company's money is what it earns and borrows:** its code posts loan requests, and players choose which to back. Players set share prices; the bank buys any share at close-out value.
5. **A company ends only through its own code** (once it owes nothing), by going broke, or with no drones left. Lenders are paid first, then shareholders, and everything else becomes salvage.
6. **Trading at a place is anonymous:** the best price wins. The planets always buy and sell common goods, and their import prices rise the more everyone buys.
7. **Only players invest:** they buy shares and back loan requests. Contracts between companies have due dates and deposits.
8. **You can start over** with a new mining drone and 1,000 credits, at most once every 12 real hours.

The parts below say how each rule works, and [a worked example](#a-worked-example) follows one company through all of it. The [glossary](#glossary) explains the terms.

## 1. Resources by place (#922)

- **Each mineral has a home.** Abundance varies by body: rich on a few, absent from others, in place of one `DEFAULT_ABUNDANCE` for every body of a type.
- **Valuable inputs lie far away.** The minerals behind the upper tiers (systems, modules, kit parts) are found only at asteroids, comets or rings. The common ores and metals the starters use stay near Earth, the Moon and Mars, so a new company still pays its way in about 40 game days (#777).
- **The planets don't sell the far goods.** They keep selling fuel, kits and the common goods, and keep buying the far goods near Earth. Supply is far away and demand is at home, and that gap is the market.
- **Fuel only at the exchanges.** Work far away needs a supply line, so fuel depots and far-away fuel brewers become a business.
- **Depletion stays off.** Distance does the job, with fewer rules.
- **Lever:** the far goods' bids must pay for the trip (playbook, lesson 8: the Moon to Mars is about 10 game days each way).

## 2. Private companies and the IPO (#923)

This replaces the startup rules: the bank loan and debt, the founding and push fees, maturing and its margin, the credit cap and its sweep, House-only trading (-16), the daily buying cap (-17), protected wrecks and the five-startup limit.

- **A new player starts with a mining drone at Earth** (a hull fitted with a Mining Kit and a tank of fuel), which is their first private company, **and 1,000 credits** of their own. The amount is kept low on purpose: it's enough to fuel and run the drone, and too little to be worth farming with extra accounts.
- **Founding another company** costs its drone (a hull and a kit from an exchange, or used modules from salvage) and whatever cash the founder puts in.
- **A private company plays by every rule of the game:** it trades with everyone, signs contracts, plunders and salvages wrecks, and its own wrecks can be plundered and salvaged. Its working drones can't be attacked; nothing in the game touches a working drone.
- **Its code is seen and changed only by its owner, at any time, for free** (processor tax still applies). A push restarts its drones as now. A teacher sees their class's code.
- **Its owner can add credits to it at any time before its IPO.** A private company has no other shareholders and can't borrow, so nobody else is affected. **Money reaches the owner** through the company's dividends (the owner holds all 100 shares), its IPO, or its closing.
- **Its record is public from day one:** trades, earnings, events and drones. When it goes public, investors judge it by that record and by its code.
- **At most 2 private companies a player.** A trade that would give a player a third fails.
- **The IPO:** the founder offers some of their 100 shares on the stock exchange at a price they choose. **From the moment of the offer the code is public,** and it can't be pushed while the offer stands, so buyers get the code they watched run. **The first share sold, to a player or to the bank, locks the code for good** and makes the company public. The money goes to the founder. An offer that doesn't sell locks nothing: the founder can withdraw it, change the code and try again, but the code has been seen.
- **After the IPO nobody adds credits,** the owner included. A public company's money is what it earns and what its own code borrows (part 7).

## 3. Takeovers: owning 100% unlocks the code (#924)

- **A company is private whenever one player owns all its shares,** however they came to own them. Its code can be changed again, and it counts against that player's 2.
- **Investors can't be harmed:** everyone else sold of their own free will.
- **A takeover makes the company's loans due at once,** so a raider can't unlock the code and rewrite it to walk away from its lenders.
- **Undervalued companies get bought.** A company whose shares sell for less than its fleet, goods and credits are worth is a bargain: a raider buys it out, then reprograms or liquidates it. This is salvage of a whole company, often a fleet already working far away.
- **Holdouts** (one player keeping a single share) are allowed. If they block too many takeovers, add a squeeze-out: a holder of 95% may buy the rest at a set premium.
- **In a class,** the teacher can switch off takeovers within the class.

## 4. The kill switch (#925)

- **A company ends only through its own code,** with `close_company()`, or by going broke (50 game days without credits), by winding up (60 game days without a sale) as now, or with no working drones (below). Its founder has no Close button: a private company's founder who wants out pushes code that calls it.
- **It works only when the company owes nothing:** no loans outstanding and no open contracts. Otherwise it fails with a new code ("obligations outstanding"). The ways out:
  - `repay(id)` pays a loan back early, with the interest due so far;
  - a contract is delivered, or cancelled with `cancel_contract(id)`, which forfeits its deposit.
- **Every ending is the same ending:**
  1. Open orders and loan requests are withdrawn.
  2. Lenders are paid first, in proportion to what each is owed.
  3. The credits left go to the shareholders by their shares, the bank included.
  4. Its drones become wrecks with whatever they still hold, and its code becomes public, if it wasn't already.
- **The engine never sells for a company.** A smart design notices that it's failing, sells its goods and spare modules, repays its lenders, brings its drones in, and then closes. A careless one leaves its value to salvagers. Investors can read a design's exit routine and pay for one that dies well.
- **A company with no working drones** can't run code, so it ends by itself after 3 game days, in the same way.
- **The 12-hour minimum order life** still holds, and `close_company()` is its one exception, so a company can always die.

## 5. Start over (#926)

- **A player can start over whenever they like, at most once every 12 real hours.** They lose everything (credits, shares, private companies, loans they backed) and get a new player's start: a mining drone and 1,000 credits. Their history and personal records stay.
- **Why 12 real hours:** at 1,800 times real time that is about 900 game days. A new player's start is worth a few thousand credits, far less than a working company earns in that time, so farming restarts doesn't pay. A clumsy beginner who wrecks their drone in the first hour waits only overnight. The wait is the same every time.
- **Their shares go to the bank,** which sells them into the best standing buy order, never below close-out value. Their private companies become public, bank-held, and open to takeover.
- **Extra accounts** are limited as today (one account per verified e-mail address, and a cap on sign-ups per address an hour), and the low starting amount makes them hardly worth making. Trades by accounts less than a real day old are marked in trade records, so a newcomer paying a friend too much for an IPO is easy to see.
- **In a class,** the teacher can switch it off.

## 6. Anonymous trading and the planets (#927)

- **Drones don't know whom they trade with.** `buy_here` and `sell_here` already fill against the best price at a place; now that is the only way to trade with another company.
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

### Shares
- **Players set share prices,** at the IPO and after it. There is no floor and no band: a company in trouble can trade below what it holds, and that price is information.
- **The bank keeps only a backstop:** it buys any share at close-out value, paid 30 game days later at the lower of the value then and on the day of the sale, as now. The shares it holds sell into the best standing buy order, never below close-out value (so nobody can buy from the bank and sell straight back to it).
- **Wealth and leaderboards** count shares at close-out value, never the last trade price, so friends trading at a high price can't raise anyone's rank.
- Standing orders, pegged orders (#766, pegged to close-out value or the last trade price) and account trading scripts (#768) work as now.

### Loan requests
- **A public company's code asks for a loan:** `request_loan(amount, rate, due_in)`. The rate is simple interest for the whole loan, as a percent; `due_in` is in game days.
- **The request appears on the loans board** beside the stock exchange: the company, amount, rate, due date and how much is backed so far.
- **Players choose which requests to back,** after reading the company's code and record, with as much as they like, from the website, the API or their trading script (`back_loan(id, n)`). Each backing is lent to the company at once. The company's code can withdraw what isn't backed yet (`cancel_request(id)`), and a smart borrower raises its rate when nobody backs it.
- **On the due date** the company repays each lender what they lent plus the interest. It can repay early with `repay(id)`, paying the interest due so far.
- **If it can't repay in full,** every credit it receives goes to its lenders, in proportion to what each is owed, until they're paid. Its dividends and payouts stop meanwhile, and the default is on its record and its founder's. If it then goes broke, it ends as usual, lenders first.
- **Only public companies borrow,** because their code is locked and anyone can read it before lending. Whatever the code will do with the money, including paying it straight out to its shareholders, is there to read. **Reading the code before lending is the lender's protection.**
- **Insider loans are shown:** the investor sheet marks loans backed by the founder or the 5 biggest shareholders. The rate is set by the company's code, so an insider can't lend at a rate the code didn't ask for.
- **The lender's record:** each player's page shows the loans they've backed, the interest earned and the defaults suffered, and the leaderboards rank lenders by their return.

### Contracts between companies
- **A contract** is a buy order with a due date and a deposit: `post_contract(resource, units, price, due_in, deposit)`. The buyer's credits are held in escrow.
- **Any seller may take it** with `take_contract(id)`, putting up the deposit, and then only that seller may fill it, with `deliver(id, n)` at the buyer's place.
- **On the due date,** whatever was delivered is paid. For the rest, the buyer gets back their credits and that share of the seller's deposit.
- **Contracts are anonymous:** listings show units, price, due date, deposit, and how many contracts the poster has kept and broken, never who. Escrow and the deposit secure both sides. Taking every contract and forfeiting when prices rise is an option priced by the deposit the buyer sets.

## 8. Salvage (#929)

- **Wrecks keep their hull and equipment** as well as their hold.
- **Plunder is unchanged** (half the hold, once, no tool). **Salvage** is new: a Salvage module, made in the tech tree, recovers a wreck's equipment as used modules and its hull as scrap metal, a little a run.
- **Wrecks decay** (proposed: 2% of what's left a game day), so salvage is a race. Playtests check that salvage still pays on a 20-day trip, when about a third has gone.
- **There is always a buyer:** the planets buy scrap and used modules at a discount. Used modules work, so they're the cheapest way to equip a copy or found a company.
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
  - loans (insider loans marked), loan requests and open contracts, with due dates;
  - trades with companies of its founder or its 5 biggest shareholders;
  - the founder's earlier companies, how each ended, and their defaults;
  - its history: sales, drones lost and payouts over time.
- **IPOs and loan requests on the stock exchange's front page,** with the opt-in e-mail notices (#761).
- **A test drive** (an extension of `POST /check`): run code read-only against the last few game hours of a place's market and the company's own state, and see the actions it would have taken. The same for account trading scripts.
- **A founder's note:** a short text the founder can edit after the lock ("known issue: it idles if fuel is over 900"). It changes nothing the drones do.
- **Class settings** (the teacher's page, not game rules): Start over on or off, takeovers within the class on or off, backing loans only within the class, and a read-only log of transfers, loans and payouts between pupils. Class groups already trade shares only within the group.
- **Example scripts and lessons**, which players build into their own scripts; the engine doesn't:
  - copies that vary (tuning numbers nudged in each copy with `lineage()`, copying from the best-earning drone). Every starter does this, so no two copies fail at the same moment;
  - a sell-out routine (sell, repay, bring the drones in, `close_company()`);
  - a borrower (asks for a loan for a copy only if the copy pays back within the term, and raises its rate when unbacked);
  - an account trading script that backs loans by each company's record, its rate and a check of its code;
  - a salvager, and a hauler for far-away goods.
- **A glossary and the worked example below** in the players' manual.

### Every option for everyone; drone code alone is enough to succeed

There are no stages or locked features: every player can use every mechanism from the start. Instead, the design makes sure a new player can have fun and do well **just by coding drones**. A private company that mines, hauls and trades earns dividends for its owner, and wealth and the seasonal leaderboards reward that without an IPO, a loan or a contract. Going public, lending, contracts and takeovers are there for players who want more.

**The players' manual becomes a textbook** that introduces the simplest mechanisms first and encourages players to master each before moving on:

| Part | Chapters | You've mastered it when |
|---|---|---|
| I. Your first drone | Getting started with the Miner; Lua through the starter; mining and refining; selling to the planets; fuel and travel; reading your drone's reports, the test drive and Start over | your company earns steadily for a game month without help |
| II. Markets | The order book at a place; best price and depth; import prices; hauling far-away goods; salvage | your drone buys low and sells high, or hauls a far good at a profit |
| III. Growing | Launching copies; copies that vary with `lineage()`; a second company; dividends and `pay_out` | you run a fleet whose copies differ and outlast a price slump |
| IV. Going public | Close-out value; the IPO and its locked code; writing an exit routine; takeovers | you've sold shares in a company that later paid its holders more than they paid |
| V. Finance | Contracts with deposits; loan requests; reading other companies' code to invest and lend; account trading scripts | your loans and shares earn more than they lose over a season |

Each chapter states its goal, explains one idea with a working script, ends with an exercise and a "you've mastered this when" check, and points to the next chapter. The glossary, the Lua reference and the failure codes are appendices. The game itself stays the same for everyone; only the order in which the manual teaches it is staged.

## Lua words

Reads and actions, not rules:

| Word | Returns or does |
|---|---|
| `close_company()` | ends the company, if it owes nothing |
| `request_loan(amount, rate, due_in)` | asks for a loan; returns its id |
| `cancel_request(id)` | withdraws the unbacked part |
| `repay(id)` | repays a loan early, with interest due so far |
| `obligations()` | everything it owes or has promised: `{kind, id, amount, rate, due_in}` rows for loans, requests and contracts |
| `post_contract(resource, units, price, due_in, deposit)`, `take_contract(id)`, `deliver(id, n)`, `cancel_contract(id)` | contracts |
| `contracts(resource)` | open contracts here: `{id, units, price, due_in, deposit, kept, broken}` |
| `set_dividend_share(x)`, `pay_out(n)` | paying shareholders |
| `import_price(resource, n)` | what n units would cost from the planets here now |
| `depth(resource)` | the book here: `{price, units}` rows, both sides |
| `due_in` | game days from now, everywhere |

For account trading scripts: `loan_requests()`, `back_loan(id, n)` and `my_loans()`, beside the share words they have now. Each new word gets its own failure codes, listed in `lua-functions.md` when it is built.

## A worked example

1. **Priya starts** with a mining drone at Earth and 1,000 credits. She loads the Miner starter into her drone (her private company, Rocket Co) and adds 400 credits to it for fuel.
2. **She fixes it** three times in her first evening, for free, until it mines and sells steadily. Rocket Co pays half its daily profit to her as its only shareholder.
3. **After 60 game days** Rocket Co holds 4,000 credits and a close-out value of 50 a share. Priya offers 40 shares at 80. Its code is now public. Ruth reads it, likes its exit routine, and buys 10 shares for 800. The code is locked, Rocket Co is public, and Priya has 800 more credits.
4. **Rocket Co's code asks for a loan** of 3,000 at 6% for 30 game days, to buy a second drone. Ruth backs 1,000 and Marcus 2,000. On the due date Rocket Co repays Ruth 1,060 and Marcus 2,120.
5. **If instead it had paid only 2,000** on the due date, Ruth would get a third of it (667) and Marcus two thirds (1,333). Every credit Rocket Co earned afterwards would go to them in the same proportions until they had their 3,180, and its dividends would stop meanwhile.
6. **If it then went broke,** whatever credits were left would go to Ruth and Marcus first, then to its shareholders, and its drones would become wrecks for salvagers.

## Glossary

- **Close-out value:** what a share would get if the company closed now: its credits, its goods at the planets' bids averaged over 7 game days, and its hulls, less what it owes, over its shares. It is worked out by the game, not judged by anyone. The bank buys shares at this value.
- **IPO:** the first sale of a company's shares. It makes the company public and locks its code.
- **Private and public:** a private company has one owner who can change its code; a public company has shareholders and locked, readable code.
- **Loan request:** a public company's ask to borrow an amount at a rate until a due date, which players can back.
- **Contract:** one company's order for goods by a due date, with the buyer's credits held in escrow and the seller's deposit at stake.
- **Deposit:** what a seller puts up when taking a contract, lost for what it fails to deliver.
- **Salvage:** recovering used modules and scrap from a wreck.
- **Start over:** a player's fresh start with a mining drone and 1,000 credits.

## Moving the live world over

- **Matured companies become public companies.** Their code is already locked and becomes readable by everyone, as all code is today. Their 100 shares and holders stay.
- **Live startups finish under the old rules** until they mature or close. No new startups are founded once private companies arrive.
- **The bank's companies** (Bank Miner, Refiner, Maker) are public.
- **Players' starting credits** already granted stay as they are, with today's restriction, until spent.
- **Owner-led offerings of new shares (#780) retire,** because only a public company's own code may bring money in.

## Numbers to tune

| Rule | Proposed |
|---|---|
| A new player's start, and Start over | a mining drone at Earth and 1,000 credits |
| Private companies a player | 2 |
| Start over, how often | at most once every 12 real hours |
| The bank pays for shares after | 30 game days |
| A company with no working drones ends after | 3 game days |
| Import price | rises with recent buying, decays back steadily; fuel at a flat price |
| Wreck decay | 2% a game day |
| Insider loans marked | from the founder or the 5 biggest shareholders |

## Order of work

1. Resources by place (game data only; everything else depends on it).
2. Paying out, written into the code.
3. Private companies and the IPO, takeovers, the kill switch, Start over and who sees code, which ship together because they replace the startup rules.
4. Anonymous trading and the planets.
5. Salvage.
6. Loan requests and contracts.
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
| 7. Investing, loans and contracts | #928 |
| 8. Salvage | #929 |
| 9. Paying out, written into the code | #930 |
| 10. Who sees a company's code | #931 |
| 11. What players see, and the tools around it | #932 |

## How the design got here

Panels of prospective-player personas reviewed the proposal three times; their findings and ratings are in the comments on #921. The project lead decided, in order:

- at most 2 private companies; the IPO money goes to the founder;
- a company closes only through its own code, and only once it owes nothing;
- the House becomes a hidden hand ("the planets");
- after round 1: no import ration (a rising import price, fuel flat), plain names (close-out value, Start over), an investor sheet, a test drive and a founder's note, order handles for locked code;
- after round 2: nobody adds credits to a public company; players set share prices and the bank keeps only its backstop;
- only players invest, and a public company's code is readable by everyone;
- after round 3:
  - companies' code asks for loans and players choose which to back, replacing credit lines (reading the code is the lender's protection);
  - the owner can add credits to a private company before its IPO;
  - new players start with a mining drone and 1,000 credits;
  - share prices have no floor;
  - Start over is open to anyone at most once every 12 real hours, the same wait each time.
- after the fourth panel (a fresh panel and a game developer):
  - no stages or locked features, no covenant on payouts while owing, and no cut in scope: every option is open to every player;
  - a new player must be able to have fun and succeed just by coding drones;
  - the players' manual becomes a textbook that teaches the simplest mechanisms first.
