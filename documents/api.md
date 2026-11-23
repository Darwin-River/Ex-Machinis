# The Ex Machinis API

The API gives programs what the website gives people. It is for your own tools: scripts that watch your companies, dashboards, and AI agents that play the game. With it you can check a script, release a company, and follow your drones, all as JSON.

- **Base address:** `https://<the game's domain>/api/v1`. On a server you run yourself, that is your own domain.
- **Reading needs no key.** Everything the API shows is already public on the website.
- **Your account's token** (`Authorization: Bearer <token>`) makes a release your startup, and is needed for your account, startups and shares: see [Player accounts](#player-accounts-the-new-rules). Without one, a release is refused (401): every startup belongs to an account.
- **Same rules as the website.** A release made through the API is checked exactly as on the Release page, and it becomes a company like any other.
- **Rate limits, per IP address:**
  - 120 reads a minute;
  - 10 releases a minute.

  Past a limit, a request gets `429 Too Many Requests`. Its `Retry-After` header says how many seconds to wait.
- **Errors:**
  - `404` when something doesn't exist;
  - `422` when a request breaks a rule;
  - `500` when something goes wrong on the server (try again in a minute).

  Each error body is JSON, whatever the request's `Accept` header with an `error` message in plain words. A `422` also has `errors`, listing the message for each field.

In the examples below, `B` stands for the base address:

```sh
B=https://play.example.com/api/v1
```

## Contents

1. [Play from a program: the loop](#play-from-a-program-the-loop)
2. [Checking and releasing](#checking-and-releasing)
3. [Your companies and drones](#your-companies-and-drones)
4. [The market](#the-market)
5. [The world](#the-world)
6. [Reference data](#reference-data)
7. [Live queries](#live-queries)
8. [An example agent](#an-example-agent)
9. [Good manners](#good-manners)
10. [Player accounts (coming with the new rules)](#player-accounts-coming-with-the-new-rules)

## Play from a program: the loop

**Times and dates (#753).** Game time is Unix time (seconds, UTC): the orbits run on it, so `game_time` 2077237825 is 29 Oct 2035, 02:30. Each `when` the API writes is that date and time, "29 Oct 2035, 02:30". Numbers stay numbers for code: `game_time`, and `day` counted in game days from the world's first (11 Nov 2026), as Lua's `today()` counts them.

A company is a script, in Lua: you can't steer it once it's released. Every release is live, releases are free (up to 10 a minute), and the whole game is your test environment. You learn by releasing, watching and releasing again; failing costs nothing but the lesson.

The game clock is fast: it normally runs 1,800 game seconds a real second, a game hour every 2 real seconds and a game day every 48 (slower when the engines fall behind, never below 60 game seconds a real second, one game hour a real minute). `GET /clock` gives the pace now (`scale`, `real_seconds_per_game_day`), the usual pace (`target_scale`, `target_real_seconds_per_game_day`) and whether it's slower than usual now (`slowed`). The pace can change from one call to the next, so time a wait in game days, not real seconds. Release early, release often. Playing from a program is the same loop a person follows on the website:

1. **Study the market:**
   - `GET /orders` for who buys and sells what, where, and at what price;
   - `GET /trades` for what has sold;
   - `GET /history` for what each resource sold for, day by day;
   - `GET /news` for the booms, slumps, shortages and gluts under way at the exchanges (House prices move);
   - `GET /recipes` and `GET /recipes/{resource}/tree` for what each thing takes to make;
   - `GET /places` for where the drones are.
2. **Write a script and check it** with `POST /check`, which returns the Release page's warnings.
3. **Release it** with `POST /release`.
4. **Follow it:**
   - `GET /drones/{id}` for where each drone is, its cargo, what it is doing, the line its script set with `set_status(text)` (`script_status`), its timeline, the fuel it used today, what it has said, and what went wrong;
   - `GET /companies/{id}` (or `?name=...`) for credits, net wealth, drones and shares, and `GET /companies/{id}/history` for net wealth day by day;
   - `GET /orders?company=...` for its open orders.
5. **Release again:** fix what went wrong, or try a better idea, and release the new version under a new name. Every company is an experiment.

The [players' manual](players-manual.md) explains the game and the Lua functions; [lua-functions.md](lua-functions.md) is a quick reference with helpers to copy. The [strategy blog](blog) has working examples.

## Checking and releasing

### `POST /check`: check a script without releasing it

**Body:** JSON or form fields.

| Field | Required | Meaning |
|---|---|---|
| `script` | yes | the script, in Lua |
| `company` | no | a company's ID: the response then previews a code push to it (`push`, below; #558). Nothing is charged or sent |

A script must define the function `launched` (`function launched() ... end`): see the players' manual, [Lua through the starter](players-manual.md#chapter-2-lua-through-the-starter). FORTH has been retired: a FORTH script (`: launched ... ;`) is refused, with an error saying so. Drones released in FORTH before then carry on.

**Example:**

```sh
curl -s -X POST $B/check -H 'Content-Type: application/json' \
  -d '{"script": "ask = 1\nfunction launched() frobnicate() end"}'
```

**Response:**

```json
{
  "ok": true,
  "error": null,
  "warning": "Your script uses frobnicate (line 2), which nothing defines: ... Your script sets ask: a name the game already uses. ...",
  "undefined": [{"line": 2, "name": "frobnicate"}],
  "clean": false,
  "language": "lua"
}
```

| Field | Meaning |
|---|---|
| `ok` | whether the Release page would take the script |
| `error` | why not, if it wouldn't. For example: no `launched` function, a FORTH script, a line too long, or a script that doesn't compile (`"Line 12: unexpected symbol near '='"`) |
| `warning` | the Release page's warnings: names the script uses that nothing defines (calling one crashes the drone), and names of the game's own that the script sets (`best_bid = 30`) |
| `undefined` | the names the script uses that nothing defines, `{line, name}`: calling one crashes the drone (reading one gives `nil`) |
| `clean` | `true` only with no `error` and no `warning`. A script that checks before it releases should test `clean`, not `ok`: `ok` is true for a script that would release but crash (#380 round 64) |
| `language` | `lua` for a Lua script (`forth` for a retired FORTH one, which `ok` refuses) |
| `length`, `max_length` | the script's length in characters, and the most a release or push takes (16,000; #557). Over it, `ok` is false; within a tenth of it, `warning` says so. Comments count: cut those you don't need |
| `push` | with `company`: for a startup `{startup: true, enabled, number, fee, debt, debt_after, drones, note}` (`fee` is 0: pushes are free since #923, so `debt_after` is `debt`); for a matured company `{startup: false, enabled, fee: 0, outright, drones, note}`: `outright` true and `enabled` true while one player holds all its shares, so its owner can push to it for free (#923), else `enabled` false (its code is locked); with the new rules off, `{enabled, password_set, number, credits, fee_percent, fee, drones, orders, orders_note}`, what pushing this script to the company would cost now and how many working drones would get it (null for no such company). `orders` lists each drone's standing orders for goods, `{drone, resource, name, side, price, keep or want}`: they outlive the push (#571). The fee is worked out on the company's credits when the push is sent, so it changes as they do |

### `POST /release`: release a company

Every release is live, and free: it founds a **startup**, lent its drone and credits by the bank (see [Startups](#startups-637)). Send your account's token: it makes the startup yours, and without one the release is refused (`401`). To try a fix, push it to the startup (`POST /startups/{id}/push`), or release it again as a new startup and follow its drone (`GET /drones/{id}`). The only limit is 10 releases a minute per address, and 5 startups an account.

**Fields:**

| Field | Required | Meaning |
|---|---|---|
| `company_name` | yes | 3 to 45 characters, not already taken |
| `script` | yes | must define `launched`; at most 16,000 characters (#557), comments included |
| `kit` | no | ignored since #506: a company starts with 3,000 credits and no kit, and its script equips itself (`equip(mining_kit)`) |
| `password` | no | only on a server with the new rules off: at least 6 characters, for pushing new code with `POST /companies/{id}/push` later (#553). Under the new rules a startup's code is changed by its player |

**Example:**

```sh
curl -s -X POST $B/release -H 'Content-Type: application/json' \
  -d '{"company_name": "Ada Fuel 1", "script": "function launched() print(\"hello\") end"}'
```

**Response:** `201 Created`, with the startup and what it owes (`claim_code` is always null now that every startup is an account's):

```json
{
  "startup": true,
  "debt": 4200,
  "claim_code": null,
  "company": {"id": 109, "name": "Ada Fuel 1"},
  "drone": {"id": 207, "name": "SN949636"},
  "page": "https://play.example.com/spacecraft/207",
  "warning": null
}
```

**When the world is full** (the server can't keep up), a release is refused with `503` and an `error`: try again in a few minutes. (On a server with the new rules off, the release waits its turn instead, answering `202 Accepted` with `{"queued": true, "place_in_line": 3, "company_name": "Ada Fuel 1"}`.)

**Errors:** `422` with the reason. For example:
- `"The company name has already been taken."`
- `"Your script must define the function launched ..."`, or `"FORTH has been retired: write your drone in Lua. ..."`

### `POST /companies/{id}/push`: push new code to a company (new rules off)

Under the new rules this call is refused (403): the code of a company one player owns outright (a startup, or a matured company whose shares they hold all of) is changed by that player with `POST /startups/{id}/push` (see [Startups](#startups-637)), and a public company's code is locked. On a server with the new rules off, with the password the company was released with (#553), sends every working drone of the company a new script. It travels from Earth to each drone at the speed of light; when it arrives, the drone drops what it was running and starts the new script's `launched()` with fresh variables, keeping its hold, place and equipment.

**Fields:** `password`, `script` (checked as a release's is, at most 16,000 characters). To see the fee first, `POST /check` with `company` (#558).

**The fee** is a share of the company's credits: 5% for its first push, 10% for its second, and so on, up to 50%. A company with no credits pays nothing. A refused push costs nothing.

**Response:** `202 Accepted`.

```json
{"push": 1, "fee": 150, "fee_percent": 5, "next_fee_percent": 10, "drones": [207, 311],
 "message": "Pushed to 2 drone(s): ...", "warning": null}
```

**Errors:** `403` (wrong password, or the company was released without one), `404` (no such company), `409` (it has ended, or has no working drone), `422` (the script's problem). Pushes count against the release limit, 10 a minute per address.

## Your companies and drones

### `GET /companies/{id}/history`: net wealth day by day

Each company's credits, net wealth and working drones, taken once a game day, oldest first; `?days=N` gives only its last N game days (#743). Every day is kept (#614); before that, days older than 60 were kept only every 10th day, so older history has 10-day gaps.

```json
[{"day": 12, "credits": 3605, "net_wealth": 2890, "drones": 1, "cash_out": null, "cash_out_share": null, "bank_sells_at": null},
 {"day": 13, "credits": 4120, "net_wealth": 3410, "drones": 1, "cash_out": 5240, "cash_out_share": 52, "bank_sells_at": 52}]
```

Since #699, a matured company's rows also carry its **cash-out value** that day: `cash_out` (the whole company's: credits, goods at the House's bid, hulls, less debts, as the bank values it), `cash_out_share` (a share's, over its 100 shares) and `bank_sells_at` (the bank's selling price that day: `cash_out_share`, since #928; until then plus the dividends a share paid over the 10 game days before, #781). They are recorded in the daily snapshot of each matured company; a startup's rows, an old-rules company's, and days before the change (migration 0066) have `null`. The company page draws `cash_out_share` as a 7-day average with its least and most over those days (#720); the rows are each day's own figures. A company whose figures jump on one day may have met a world event: see `GET /world-events` and `GET /companies/{id}/events`.

### `GET /companies?name=<name>` and `GET /companies/{id}`

Use these to look up a company. Under the new rules it also says whether it is a `startup`, its `debt` to the bank, a live startup's `credit_cap` and whether it is `at_cap` (#749; the credit cap was retired by #923, so these are null and false), and whether it has `matured`. For such a company `shares` is `{count, cash_out, holders}` (and, once matured, the bank's prices as in `GET /player-shares/{id}` and its open `offering`, #780, always null now that offerings are retired), with `holders` the players and the bank, `{name, units, bank, percent}`; `pushes` is a startup's `{startup: true, count, next_fee, debt, debt_after}` (`next_fee` 0) or a matured company's `{enabled, locked}`: `locked` is false while one player owns it outright (holds all its shares), true once it is public or has ended (#923). `net_wealth` is what it has gained since it started: its credits and goods less the 3,000 starting credits and its fuel. It doesn't count its hull or its debt, so it differs from `shares.cash_out`, what the company would fetch if sold off now.

```json
{
  "id": 106, "name": "Kira 2", "credits": 22936, "net_wealth": 24791, "ended": false,
  "drones": [
    {"id": 108, "name": "SN123002", "place_id": 10, "place": "Moon",
     "status": "waiting for its action to finish: Pull resource",
     "script_status": {"text": "buying metals for a pump", "set": "23 Nov 2026, 14:05", "set_game_time": 1794788700, "ago": "2 game hours ago"},
     "fuel": 69, "wrecked": false, "wreck_cause": null},
    {"id": 111, "name": "SN156871", "place_id": 3, "place": "Earth",
     "status": "waiting for its action to finish: Make Fuel (A1)", "script_status": null,
     "fuel": 108, "wrecked": false, "wreck_cause": null}
  ]
}
```

| Field | Meaning |
|---|---|
| `pushes` | code pushes: for a startup `{startup: true, enabled, count, next_fee, debt, debt_after, log}`, for a matured company `{enabled, locked, count, log}` (`locked` false and `enabled` true while one player holds all its shares, #923); with the new rules off (#553), `{enabled, count, next_fee_percent, next_fee, log: [{number, when, game_time, fee, percent, drones, effect}]}`, `enabled` false for a company released without a push password. `effect` (#772; the latest 3 pushes, null for older ones): `{ready, span_days, before, after, net_change_percent, sales_change_percent, verdict}`, with `before` and `after` each `{net, sales, bought, actions, failed}` a game day over the same span on each side of the push; `{ready: false, note}` until 6 game hours have passed, or with too little before it |
| `post_mortem` | null while it runs; once it has ended (#773): `{company_id, name, end_cause, when, game_time, lines, error, line, copy}`. `lines` are the post-mortem in plain words (why it ended, drones lost before it, the action it failed most over its last game day, what its drones last said); `error` its drones' last script error `{drone, when, text}`; `line` the line of its newest code that error names, `{number, code, in}`; `copy` the website path that starts a fixed copy with its code loaded |
| `net_wealth` | the leaderboard's measure: credits plus goods at market prices plus shares held in other companies at the average price of their most recent trades, weighted by shares: the last three game days' trades, and further back until they cover at least half the shares other companies hold (#573), else the last trade price plus credits committed to offerings of new shares, less its own shares held by other companies at the same value price (#568), less what it started with (3,000 credits and 100 fuel). The drones themselves don't count, so launching a copy lowers it by about a hull's price until the copy earns that back |
| `age_days` | game days since the company was founded (an ended company's stops when it ended, #716) |
| `peer_rank` | its place among companies at its age: its net wealth now ranked against each company's net wealth when that one was `age` game days old, `{rank, of, age, among}` (`of`: how many companies have reached that age, this one included). A company founded as a startup is ranked among companies founded as startups only (`among`: `startups`); an old-world company among every company (`all`). Null once it has ended |
| `compute` | the processor tax (#544): `{instructions_day, tax_day, debt, throttled, per_million, base_per_million, congestion, broke_days, dissolves_in}` (`per_million`: this company's rate now; `base_per_million`: the rate at the clock's target pace; `congestion`: how many times the base rate it pays, its share of the congestion by how much it thought over the last game day, #620) (`broke_days`: game days it has had no credits, less what it owes, or null; `dissolves_in`: game days until it dissolves and its drones become wrecks, #547): the instructions billed and credits paid for its drones' thinking over the last game day, what it owes, whether its drones run throttled (a turn a game hour while it owes) and the rate |
| `health` | whether it can keep its drones flying (#537): `{working, wrecked, fuel, lowest, used, days, credits, at_risk}`: its working and wrecked drones, the fuel aboard the working ones and the least aboard one, the fuel they used over the last game day, game days until the first runs dry at the last day's net fall in fuel (-1 when none is falling), its credits, `at_risk` (no working drone, a drone with no fuel left (#609), or one that runs dry within 3 game days with under 500 credits) and `at_risk_reason` (why, in words, or null; #541), `idle` (game hours since its drones last bought, sold, mined, refined or made goods other than fuel), `idle_drones` (its working drones idle a game day or more), `last_sale` (game hours since it last sold anything, up to 240; null for a company younger than 10 game days that has never sold), `stalled` and `stalled_reason` (#540, #689: stalled when no drone has worked for a game day, when at least half its drones haven't and it hasn't sold for a game day either, or when it has sold nothing for 168 game hours once it is 7 game days old (#715, #741); the reason in words, or null), `failing` (#715: the actions its working drones keep failing over the last game day, most first: `[{what, times, tries, why}]`, an action counted when it failed 3 times or more and at least a fifth of its `tries` (#741)) and `failing_reason` (the same in words, or null), and `drones`: each working drone's `{drone, name, idle, status (its script's last set_status), holds: [{resource, units}], failing (what it failed over the last game day, in words, or null)}`. The Stock exchange page shows it for each company |
| `shares` | the stock market (#471): `price` (its shares' last trade price, or null), `value_price` (what a share counts for in net wealth: the last three game days' average trade price, weighted by shares, else the last price; #513), `count` (its shares in all: 1,000 to start, more after it issues new ones, #485), `outside`, `outside_share` and `max_outside` (the shares other companies hold, as a count and a part of all, and the most they may hold; #599), `profit` and `profit30` (its profit a day over 7 and 30 game days), `carried_loss` (the profit it must still make before it pays any dividend), `carry_days` (game days that takes at `profit30`; null if it makes none) and `payback` (game days of dividends a share bought at the last price takes to pay for itself; #607), `offering` (its open offering of new shares, or null: `{units, min, price, raise, committed, left, hours, count, sales, a_day_after, investors: [{company, name, units}]}`; `units` is the most new shares it offers and `min` the fewest it issues at its deadline, #516), `holders` (`{company, name, units, dividends}`, most first), `orders` (the standing orders for its shares at the Earth exchange that can trade now: `{company, name, side, price, keep or want, units}`, `units` being the shares it would sell or buy now; #610), `holdings` (the shares it holds in other companies: `{company, name, units, dividends, price, ended, value, best_bid}`; a company that has ended is worth 0, #493; `best_bid` is the best open bid for them from another company, `{price, units, by, by_name}`, or null, #610) and `trades` (its shares' last 10 trades: `{seller, seller_name, buyer, buyer_name, units, price, game_t, days_ago}`) |
| `drones` | the drone you released, its clones, and their clones |

### `GET /drones/{id}`

Use this to see what a drone is doing. It gives what the drone's page shows:

| Field | Meaning |
|---|---|
| `place`, `place_id` | where it is (an object ID, as the game's functions use); while it flies, where it left from (`flying_to` says where it is going, and `status` reads "flying to …, arriving …", #380 round 64) |
| `flying_to` | while it flies: `{place_id, place, arrives, arrives_game_time}`; otherwise null |
| `destination` | while it flies, the name of the place it is going to (the same as `flying_to.place`); otherwise null (#689) |
| `status` | what it's doing, in words: `loading its script (3 pieces to go)` and `about to launch` before it starts, `running its launched function`, `waiting for its action to finish: ...`, `a wreck` |
| `script_status` | what its script says it is doing: `{text, set, set_game_time, ago}`, the line it last set with `set_status(text)`, when, and how long ago in words ("3 game days ago", #689); null if it never set one. A drone that sits still can say why ("waiting for metals: none on sale here") |
| `fuel` | units in the tank |
| `cargo` | `[{resource, name, units}]`: what is aboard, its modules included |
| `now` | the action under way and when it ends, or null |
| `timeline` | its last actions, newest first: `{action, when, what, result, fuel}`. `result` is `done`, `under way, until ...` or `failed: <why>`. Older ones: `GET /drones/{id}/timeline` |
| `trades` | its last trades with other drones, a wreck's too: `{when, what, with, credits}` |
| `earnings` | what it earned (#774): `{sales, bought, invested, net, days, per_day}`: its sales after tax, its purchases and the difference over its life, its life in game days, and `net` a game day (null under 6 game hours). Equipment it bought (kits, modules and hulls, resources from 5000) is `invested`, not in `bought` or `net`, as on the company's charts (#833) |
| `messages` | what it has said: its `report` lines and outputs, newest first, once they reach Earth (they travel at the speed of light) |
| `post_mortem` | for a wreck: `{headline, cause, failure}`: `headline` is why it died in a few plain words (from `wreck_cause`, #692), `cause` the full story, and `failure` its last failed action or crash (`{game_t, when, what, result, fuel, failed}`, or null; it is marked `failed: true` in `timeline` too) |
| `parent`, `clones`, `generation` | its family |
| `instructions`, `crashes` | how much it has computed (its company pays processor tax on it, #544), and how often its `launched` function has crashed |
| `fuel_last_day` | `{actions, thinking, by_action}`: the fuel it used in the last game day, for its actions and for its computer, and by action, most first: `[{what, fuel, times}]` (#494) |
| `script` | the script it runs; null for a viewer who may not see it (#931): while one player owns its company outright, only that player (and, in a class, their teacher) sees it, identified by the `Authorization: Bearer` token sent with the request. It is public from the moment the company's shares are offered, once the company is public, once it has ended, and for companies released before #923 |

### `GET /drones/{id}/timeline`: all its actions, a page at a time

A drone's actions, newest first, like `timeline` above but as far back as they are kept (#494; a finished action is deleted once its last event is 30 game days old, `house_rules.actions_keep_days`, #944): `?limit=` (up to 100, 50 if left out) and `?before=` (an action ID: the actions before it). `next_before` is the `before` for the next page, or null at the start.

```sh
curl -s "$B/drones/142/timeline?limit=100"
curl -s "$B/drones/142/timeline?limit=100&before=38377"
```

## The market

### `GET /orders`: every open order

At most 2,000 rows: the header `X-Orders-Truncated: true` says it stopped there, so narrow it with `resource` or `place` (#760: it stopped at 500 without a word).

Parameters:

| Parameter | Default | Meaning |
|---|---|---|
| `side` | `buy` | `buy` or `sell` |
| `resource` | all | a resource ID (see `/resources`) |
| `place` | everywhere | an object ID or name (`Moon`, `Earth`, `Phobos`...) |
| `company` | everyone | a company ID or name: that company's open orders |

```sh
curl -s "$B/orders?side=sell&resource=2061&place=Moon"
```

```json
[
  {"drone": 104, "drone_name": "SN771450", "resource": 2061, "resource_name": "Fuel (A1)",
   "price": 4, "units": 1, "place_id": 10, "place": "Moon", "company_id": 61, "company": "Kira 1"},
  {"drone": 2, "drone_name": "Moon Depot", "resource": 2061, "resource_name": "Fuel (A1)",
   "price": 5, "units": 838, "place_id": 10, "place": "Moon", "company_id": 1, "company": "The House"}
]
```

**Ordering:** results are sorted by resource, then by place, with the best price first: the cheapest sell order, or the highest buy order.

**Units:** for a sell order, how many the seller will sell. For a buy order, how many the buyer still wants.

**The exchanges' station drones** (Earth Exchange, Moon Depot, Mars Exchange) trade through orders like everyone else. Their orders are listed here. There is no separate price list.

**House prices move.** What each exchange bids and asks, and how much it buys and sells a day, moves over game time, each exchange in its own way: the Earth Exchange in long, shallow waves; the Moon Depot in short, deep ones; the Mars Exchange with the sharpest booms and slumps. The prices change every half game day. An exchange never bids more for a good than any exchange asks for it. `GET /news` says which booms and slumps are under way.

**The planets' import price and stepping back (#927).** On top of those moves, what the station drones (the planets' market) ask for a good at an exchange rises by its list price for each day's worth anyone bought from them there recently, fading by a factor e each game day, to at most 3 times the list price. Where players of different founders trade a good among themselves at an exchange, the planets widen their spread by that share of its trade there over the last 3 game days, asking up to half more and bidding up to half less; they step back over about 5 game days and come back within one when players stop. Fuel stays at its flat list price. The orders listed here are the prices as they stand.

**When a pull fails** with "the seller's order didn't allow selling that many", someone else bought those units first, or the seller sold or used them, between your look and your pull. `buy_here` then tries the next seller. A failed pull costs no fuel: the fuel it burned is given back.

### `GET /news`: booms, slumps, shortages and gluts

Each exchange's demand (what the House buys) and supply (what it sells) has its booms and slumps, by tier of goods (ores, refined goods, parts, systems, modules). Each runs for 8 game days and is strongest in the middle. Strongest first:

```json
[{"place": "Mars Exchange", "tier": "systems", "side": "demand", "kind": "boom", "percent_now": 31,
  "from_day": 40, "to_day": 48,
  "text": "Mars Exchange: a boom in systems (demand): the House bids up 31%, 10 Feb to 18 Feb 2030."}]
```

| `kind` | Meaning |
|---|---|
| `boom` | the House buys more of these, and bids more |
| `slump` | it buys fewer, and bids less |
| `shortage` | it sells fewer, and asks more |
| `glut` | it sells more, and asks less |

`percent_now` is how far the boom or slump moves prices now, on top of the exchange's slower waves. Drones can't read the news: they see what it does to prices where they are.

Drones see these orders only where they are, through `best_bid`, `best_offer` and queries 600 and 610, and there another company's order is named by its **order handle** (a number above 1,000,000,000), never its drone (#927): a drone pulls from or pushes into another company's order by its handle, and a push or pull that names another company's drone by its ID fails with -22. The API shows every place at once, which helps you decide where to send a company.

### `GET /house-demand?resource=4311&place=Mars&company=87`: what the House still buys today

For each exchange and good it buys (#550; `resource` and `place` narrow it down, and `place` takes an ID or a name such as `Mars`, a name it doesn't know being a 404, #380 round 64): its bid now, how many more units it takes today, its day's demand, its listed price (its bid with nothing bought today), the game hours until it refills, the units sold into it today (`sold`), how many of those the company `?company=` sold (`mine`), and the game hours until it has bought its fill at today's pace (`empty_hours`, null when none sold today). `price` and `left` are the House's buy order on the book now, as `/orders` lists it and a sale would find it (#608); `price` is 0 when it isn't bidding. Leave out `resource` or `place` for all of them.

```json
[{"place": 4, "place_name": "Mars", "resource": 3101, "resource_name": "Springs (A1)",
  "price": 315, "left": 30, "daily": 36, "listed": 343, "hours": 16,
  "sold": 6, "mine": 4, "empty_hours": 40}]
```

Each House exchange buys up to `daily` units of a good a game day. Its bid falls toward half of `listed` as it fills. Once it holds a day's demand above the stock it keeps to sell, it is overstocked but still buys (#691): a quarter of `daily` more at a time, at half of `listed`, never less (#739: until round 71 the bid fell on below half, to 2 to 5 for metals at Mars). Units on their way into the station count as held. So `left` is 0 only for a moment, when a sale has filled the order and the House has not yet posted its next. Where an exchange also sells the good, it buys back its own stock to sell too (up to a quarter of a day's sales), so `left` can be more than `daily` there (#380 round 64). What it bought goes down to the surface once a game day (`hours` is the time to the next game day), and the bid refills. Drones ask the same with `house_demand(r)`.

### `GET /house-supply?resource=2011&place=Earth`: what the House sells, and when it restocks

For each exchange and good it sells (#630; `resource` and `place` narrow it down, and each takes an ID or a name: `Metals (A1)` or `metals_a1`), cheapest first and sold-out last:

```json
[
  {"place": 3, "place_name": "Earth", "resource": 2011, "resource_name": "Metals (A1)",
   "price": 16, "on_sale": 41, "refill_to": 221, "daily": 885, "sold": 640,
   "last_restock_hours": 1.3, "round_hours": 1.5, "next_hours": 0.2}
]
```

`price` is its sell order's price (0 when nothing is on sale) and `on_sale` the units a purchase can take now, as `/orders` counts them. Its station drone restocks in rounds, topping each good back up to `refill_to`, a quarter of the day's supply (`daily`); each import or shipment takes it about a game hour, so a round takes game days. `sold` is the units sold today, `last_restock_hours` the game hours since it last brought this good up (null if not in ten game days), `round_hours` how often its rounds have come (the median gap between the waits that end them, over ten game days; null with fewer than two) and `next_hours` the game hours until its next round is due (0 when due now). Drones ask the same with `house_supply(r)`, in game minutes.

### `GET /trades?limit=50`: the latest trades

Each trade:

```json
{"when": "21 Dec 2026, 12:05", "resource": "Fuel (A1)", "units": 30, "credits": 120, "price": 4,
 "seller": "SN771450", "seller_id": 104, "buyer": "SN219733", "buyer_id": 151, "place": "Moon"}
```

`limit` can be up to 200.

### `GET /history?resource=2011&place=10&days=60`: prices and volumes by day

Every trade of the last `days` game days (60 if you leave it out, at most 365), summed per game day, resource and place, oldest first. `resource` and `place` are IDs (from `/resources` and `/places`); leave either out for all of them. The Market history page charts the same numbers.

```json
{"day": 17, "resource": 2011, "resource_name": "Metals (A1)", "place": 10, "place_name": "Moon",
 "trades": 42, "units": 178, "credits": 3346, "low": 18, "average": 18.8, "high": 20}
```

`low` and `high` are the cheapest and dearest a unit sold for that day; `average` is `credits` over `units`.

## The world

| Request | What it gives |
|---|---|
| `GET /far-map` | where Metals (A3) come from (#922): `current` and `next` seasons `{season, starts_at, ends_at, homes}` (real time), `homes` a list of `{resource, name, places: [{object_id, name, multiplier}]}`; the next season's `homes` is null until `published` (from `published_at`, `publish_days` before it starts); `season_days` |
| `GET /resource-map?resource=N[&season=next]` | where a resource is found, as the Map's heat map (#952): `resource {resource, name, kind}` (`mineral` or `refined`), `minerals` (what it comes from: itself for a mineral, the minerals refined to it for a refined good, each `{resource, name, moves}`, `moves` when its homes change each season), `season`, `next` (true when next season's homes are shown; only once `next_published`), `max_heat`, `crowd_capacity` (drones a site serves at full yield in a game hour, #974; 0: crowding off), and `bodies`: every planet, asteroid and comet `{id, name, type, x, y, heat, places}` (x, y on the Map's 600 by 600 drawing; `heat` its best place's `rate`, #981: until then the sum of the abundances on it and its moons and rings; `places` `[{object_id, name, mineral, mineral_name, multiplier, miners, yield, rate}]`, best rate first: `multiplier` the abundance, `miners` the drones that mined it there in the last game hour, `yield` the units a mining run would bring a drone that arrives now (less at a crowded site, as `mine_yield()` gives it), `rate` that yield for a mineral, or the units of the resource a run's ore refines to for a refined good). Without `resource` it returns `options`, the resources it can show; an unknown one is 404 |
| `GET /world-events` | the world's events (#720): rule changes, outages and repairs that moved every company's figures at once, oldest first, the list the site's time charts mark. `{key, kind, day, to_day, game_time, when, label, text, issue}`: `kind` is `rule`, `outage`, `repair`, `restart` (the game engine restarted, sending every Lua drone back to its launched function: one a game day, labelled "Engine restarted ×2" when it restarted twice that day, keyed `engine-restart-{day}`, #750) or `ends` (#837: a game day on which 5 or more matured companies ended, keyed `mass-end-{day}`, labelled "9 companies ended", its `text` counting them by why: dissolved, wound up or closed); `day` the game day it happened (for a change brought in by a migration, the day this server applied it) and `to_day` the last day of a stretch (an outage) or null; `label` a few words, `text` one sentence; `issue` its GitHub issue. Worked out at most once a real hour, and again at once after an engine restart. For example the 8-drone cap (`fleet-cap`, 14 Sep 2032) and the full disk that stopped the world (`outage-full-disk`, 11 to 20 Oct 2032). Each also gives `date` and `to_date`, its days as dates (#753), and its `text` names its days by date. Kept in `config/world-events.php` |
| `GET /companies/{id}/events` | company `{id}`'s own events (#720), the ones its charts mark, oldest first: `{company, events: [{kind, day, game_time, when, label, text}]}`. `kind`: `matured`; `push` (its code pushes, or a startup's reprograms, one a game day); `shares` (a game day it sold shares to the bank, or 10 or more of its shares were bought from the bank or traded between players); `invest` (#778: a game day it bought equipment, kits, modules or hulls, with what and for how much, and how long its profit a drone, over its last 7 game days, takes to pay it back); `ended`. At most the newest 120 |
| `GET /clock` | `game_time` (game seconds), `game_day` (since the world began), `when` (as the website writes it: the simulation's date and time, "29 Oct 2035, 02:30", #753; game time is Unix time, UTC, the time the orbits run on), `date` (the date alone), `scale` (game seconds per real second, now), `real_seconds_per_game_day` (now), `target_scale` and `target_real_seconds_per_game_day` (the pace when the engines keep up), `floor_scale` (the slowest it goes), `slowed` (true while it runs slower than its target), `world_full` (releases are queueing), `releases_waiting`, `processor_tax` (#620: `per_million`, the rate for a busy company now; `base_per_million`; `congestion`, the times the base rate while the clock runs slowed; its `cap`; `full_at`, the instructions a game day from which a company pays all of it, less below), and `engine`: `running` (false when the game engine hasn't reported for 3 real minutes: drones are waiting for it, not slow), `last_seen_seconds_ago`, `turns_per_minute`, `behind_seconds` `disk` (#813): how full the server's disk is, `{used_percent, effective_percent, budget_percent, warning}`. `effective_percent` doesn't count the free space MySQL holds inside the history tables that grow (`events_archive`, `observations`; only a table's own rows can use its free space, #827). `rebuild` lists the history tables holding at least a gigabyte free and more free than their rows take: rebuilding them hands that space back to the disk. Old history is pruned to keep it under `budget_percent` (60, #821). `warning` is true over 80% used. `credits` (#937): whether credits balance, from the website's daily check: `{checked_day, held, created, destroyed, difference, kinds, checks, mismatches, last_mismatch_day, balanced}`: on the last game day checked, the credits every company and account held, those created and destroyed since the day before (`kinds`: by what, such as `sales_tax` or `bank_paid`), and `difference`, 0 when what is held changed only by those (null on a first check); `mismatches` of the last `checks` checks found a difference (`balanced` is true when none did) |
| `GET /dashboard` | the economy dashboard (#698), what `/dashboard` shows, worked out again every few real minutes by the scheduler (a rebuild takes a minute or two on the live world) (so reads in between give the same figures). `as_of` (game time, as the website writes it), `as_of_t` (that game time, in game seconds: compare it with `/clock`'s `game_time` to see how old the figures are, #738) and `today` (game day). `banner`: `{day, trades, norm_trades, norm_days, ratio, quiet, slowed, real_seconds_per_game_day, target_real_seconds_per_game_day, engine_behind_seconds, engine_running, processor_tax_multiplier, text}`: the trades on the last full game day (`day`) against the average day over up to 60 game days before it (`norm_trades`, over `norm_days` days; null on a world's first day), `quiet` when that is under half the norm, and the clock as `/clock` gives it; `text` says it in plain words. `tiles`: `trades` and `credits` (`{value, norm, change_percent}`, for `day`), `drones` (`{working, wrecks, wreck_share, week_ago, change}`: players' drones, the House's station drones and closed startups left out; `week_ago` from the daily history, null without one), `matured` (`{count, cash_out, this_week}`: matured companies still trading, their total cash-out value, and how many matured in the last 7 game days), `profit` (`{week, last_week, change}`: the matured companies' combined profit over the last 7 full game days and the 7 before), `capital` (#839: `{credits, goods, hulls, cash_out, idle_percent, profit30, return_percent}`: what the matured companies hold, the part of it that is idle credits, and their profit over the last 30 game days against their cash-out value) and `players` (`{count, new_this_week}`, the public's accounts as `/players` ranks them (a class group's pupils left out, #716), and those made in the last 7 real days). `trading`: `{places, days}`, the last 60 game days `{day, trades, credits, by_place}` (credits by place: the 5 busiest by name, the rest as `Elsewhere`). `matured_profit`: `{day, sales, purchases, net}` a day for 60 game days, summed from `/companies/{id}/earnings` of the matured companies still trading (the ones in `companies`), from the day each matured: its last 30 days add up to `companies`' `profit30` (#716). The last day is today, not over yet. `goods`: the 8 goods with the most credits traded over 14 game days `{resource, name, credits, units, trades}`. `spreads`: the main goods (most traded first, at most 6) `{resource, name, places: [{place, ask, bid}]}`, the cheapest sell order and the dearest buy order at each place (null when there is none), from drones still at the place they posted it and not flying (#716). `news`: up to 10 rows of `/news`. `companies`: every matured company, highest cash-out first, `{company, name, owner, cash_out, drones, profit30, dividends30, yield_percent, daily_profit}` (`cash_out` and `dividends30` are a share's; `yield_percent` is `dividends30` over `cash_out`; `daily_profit` its profit each of the last 30 game days, oldest first, from when it was founded (0 before it matured)). `drones_by_place`: `{place, working, wrecks}`. `players`: the 25 wealthiest of the public's accounts, as `/players` ranks them, `{name, shares, credits, bank_due, wealth}`. `credits_balance`: `/clock`'s `credits` (#937). `sources`: where each chart's numbers come from |
| `GET /places` | where players' drones are, busiest first: `{id, name, working, wrecks}` (the House's station drones and closed startups left out, as the map and the dashboard count them, #716) |
| `GET /shares?trades=25` | the stock exchange, as the Stock exchange page shows it. Under the new rules: `companies`, every matured company a row as on the page (`{company, name, count, buys_at, last_price, last_days_ago, dividends30, dividend_percent, yield_percent, return_percent, growth_percent, premium_percent, stalled, failing, at_risk, health_note, dissolves_in, offered, spark}`, best 30-day return first; `dividend_percent` is the share of its profit its code pays as dividends, #930), and `trades`, the latest trades of their shares (up to `trades`, 200 at most, newest first, as `player_share_trades` below); `player_shares` and `player_share_trades` are kept under their old names. Under the old rules (#551): `companies` (`{company, name, price, value_price, count, sales, profit, profit30, carried_loss, carry_days, a_day, payback, held_by_others, dividends_paid, health}`: `sales` and `profit` are 7-day averages a day and `profit30` the 30-day average; `carried_loss` is the profit it must still make to make up earlier losses before it pays any dividend, and `carry_days` the days that takes at `profit30` (null if it makes none); `a_day` is what a share earns a day, from `profit30` (#603, #607), and `payback` the game days of dividends a share bought at the last price takes to pay for itself, after the carried loss), `for_sale` and `buying` (the share orders that can trade now: `{company, name, by, by_name, price, days, units}`), `offerings` (open offerings of new shares, each with `income_percent`, the part of the company's future profit the new shares take if it fills, `dividends_a_day` at today's 30-day profit, `profit30`, `carried_loss` and `payback`; #572, #603, #607) and the latest `trades` (up to `trades`, 200 at most, each with `days_ago`, #610). Under the new rules it also has `player_shares`: every matured company, `{company, name, buys_at, sells_at, low, high, bank_holds, holders}` (#666), and `player_share_trades`: the latest trades of matured companies' shares, with players or the bank, newest first, `{company, name, units, price, seller, buyer, seller_name, buyer_name, seller_is_bank, buyer_is_bank, when, game_time}` (up to `trades`, #676; when the bank is a side, `seller` or `buyer` is null, its `_name` is "The bank" and its `_is_bank` true, #686) |
| `GET /shares/{id}/history` | company `{id}`'s share price by game day (#551), oldest first: `{day, low, high, last, units, trades, average, place, place_name}` (`last` is the day's last trade, `units` the shares traded, `average` the day's price weighted by shares; `place` and `place_name` are the company, as in `/history`'s rows, #600) |
| `GET /companies/{id}/earnings?days=N` | company `{id}`'s earnings by game day (#600), the last N game days (30 if left out, at most 365), oldest first, every day listed: `{day, sales, tax, purchases, invested, net, trades, dividends}` (`sales` after sales tax, `purchases` what it paid for goods, `invested` what it paid for equipment and drones (modules, kits and hulls, resources 5000 and up: an investment, not counted against its profit, #759), `net` = sales - purchases; processor tax and push fees aren't trades and aren't counted; `dividends`: the dividends paid out of that day's profit, by the day the profit was made, not the day they were paid. Under the new rules, for a matured company, it is what it paid its shareholders, players and the bank, from that day's profit: half of it (a quarter until round 72) once earlier losses are made up (#674), so 0 on a day with no profit or while losses are made up, and 0 for a startup. Under the old rules it is what it paid other companies holding its shares (#603). The profit counts trades between one player's companies at the market's price, so it can differ a little from `net`) |
| `GET /companies/{id}/lineage` | company `{id}`'s code lineage (#616): `{revisions, children}`. `revisions` (its release, then each push): `{number, label, new_share, parents}`, `new_share` the share of its code no earlier revision explains (null while it is being worked out, within a minute or so of the release or push), `parents` (the one that explains most first): `{label, url, company_id, number, example, share}`: an earlier release (`number` 0) or push of `company_id`, or a starter or example script (`company_id` null, `example` such as `starters/miner`), and the share of this revision's code it explains beyond the parents before it. `children`: other companies' releases and pushes that carry this company's code, oldest first: `{label, url, company_id, number, share}` |
| `GET /lineage` | the whole code lineage (#618), what the Lineage page draws: `{nodes, lanes, ages, companies}`. `nodes`: every worked-out revision `{id, company_id, push, example, label, lane, day, size, new, parents}` (`day` the game day it was released or pushed, null for starter and example scripts; `size` its normalized tokens; `new` its share of new code; `parents` `[[id, share], …]`, the one that explains most first). `lanes`: one per company or example script, each company after the one its release mainly came from `{name, company_id, example, url}`. `ages`: the game-day ages of the heat map. `companies`: company id => `{wealth, gap, rank}`, one entry per age: its net wealth when it was that old (null if it never was), the gap between the history points it was read between, and `[rank, of]` among the companies that reached it, as `/leaderboard?age=` |
| `GET /leaderboard?limit=50&max_age=10` | net wealth, richest first: `{company_id, company, drones, net_wealth, released_drone, age_days}`. `limit` can be up to 500; `max_age` (optional) keeps only companies up to that many game days old. `age_days` is game days since the company was founded |
| `GET /leaderboard?age=10&limit=50` | every company at the same age (#614): `{age, of, too_young, ended_before, companies}`; `companies` (richest at that age first): `{company_id, company, net_wealth_at_age, net_wealth, ended, age_days, gap_days}`. `net_wealth_at_age` is its net wealth when it was `age` game days old, read from its daily history (from 0 when it was founded to its net wealth now, linearly between points; `gap_days`: how far apart the two points were). `net_wealth` is now (null once ended). `of`: how many companies reached that age; `too_young`: still trading but younger; `ended_before`: ended before reaching it. Add `startups=1` to rank only companies founded as startups, as a startup's `peer_rank` does |

## Reference data

| Request | What it gives |
|---|---|
| `GET /resources` | every resource and module: `{id, name, mass}`. The IDs are what the game's functions take: `fuel` is 2061, `metals_a1` is 2011 |
| `GET /protocols` | the actions a drone can `perform`: `{id, name, description}` |
| `GET /recipes?resource=4311` | the recipes (leave `resource` out for all): `{protocol, name, makes: [{resource, name, units}], uses: [...], fuel, tool, tool_name, hours}`. `fuel` is what one run burns, never taken off what it makes: Make Fuel makes 30 and burns 2 (#805). Mining isn't a recipe (it uses nothing up); the website's [tech map](https://exmachinis.com/technology) shows mining, recipes and tools together |
| `GET /recipes/{resource}/tree` | `{resource}` is a **resource** id, the thing you want to make (e.g. `/recipes/4311/tree` for Pumps (A1)), not a recipe's protocol id: what one unit takes, all the way down to refined goods (metals, organics, inorganics): `{goods: [{resource, name, units}], fuel, tool_hours: [{tool, name, hours}], steps}`. `?down_to=1` goes down to ores. It follows each good's first recipe |
| `GET /queries` | the queries a drone can `query`, by ID: `{name, parameters, strings}` |

The [players' manual](players-manual.md) explains each protocol and query and how to read their results.

## Live queries

This endpoint runs one game query as a drone you name, and gives back the rows the drone's `query` would get. Use it to try a query, or to see what a drone sees from where it is. It lives at the site's root, not under `/api/v1` (`https://exmachinis.com/machine/query`, not `.../api/v1/machine/query`):

```
GET /machine/query?id=<query>&drone=<drone id>&v[]=<parameter>&v[]=...
```

The parameters go in the order `query` takes them after the query ID. For example, `GET /machine/query?id=207&drone=108` asks for the game day:

```json
{"code": 1, "bytes": "KgFkBA==", "rows": [{"day": 298, "minute": 1124}]}
```

- `rows` is what the drone's `query(207)` returns: each row's columns by name (a Lua drone gets them by position too, in the same order). A number comes as a JSON number (#426: some sums, such as query 696's `d_sales`, came as strings), text as a string.
- `code` is the number of rows, or `-1` (too few parameters), `-2` (no rows, or the query failed) or `-3` (no such query).
- `bytes` is the old FORTH drones' memory layout; you can ignore it. `size=<bytes>` limits it (and the rows) to what fits.

Its limit is 240 calls a minute. It sits outside `/api/v1` for historical reasons (a FORTH machine page used it).

Ask as a player's drone. A station drone doesn't see its own orders.

## An example agent

A minimal loop in Python, using only the standard library. It:
1. checks a script;
2. releases it as version N of a company (`python3 agent.py 3` releases `Ada Fuel 3`);
3. follows its drone for about ten real minutes (some twelve game days): what its script says it is doing, its latest action and what it printed.

Read what it shows, improve the script, and run it again with the next number.

```python
import json, sys, time, urllib.error, urllib.parse, urllib.request

B = "https://play.example.com/api/v1"

def call(path, body=None):
    req = urllib.request.Request(B + path, method="POST" if body is not None else "GET",
                                 data=json.dumps(body).encode() if body is not None else None,
                                 headers={"Content-Type": "application/json", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        return {"status": e.code, **json.load(e)}

version = int(sys.argv[1]) if len(sys.argv) > 1 else 1
name = "Ada Fuel %d" % version
script = open("my_company.lua").read()

check = call("/check", {"script": script})
print("check:", check["error"] or "ok", "|", check["warning"] or "no warnings")
if not check.get("ok"):
    sys.exit(1)

released = call("/release", {"company_name": name, "script": script})
if "drone" not in released:                           # an error, or queued: the world is full
    sys.exit("not released yet: %s" % released)
drone = released["drone"]["id"]
print("released", name, "as drone", drone, released["page"])

for _ in range(20):                                   # about ten real minutes
    time.sleep(30)                                    # a game day passes every 48 real seconds at the normal pace
    d = call("/drones/%d" % drone)
    said = (d.get("script_status") or {}).get("text")
    last = (d.get("timeline") or [{}])[0]
    print(d.get("place"), "|", d.get("status"), "| fuel", d.get("fuel"), "|", said or "-")
    if last:
        print("   last action:", last.get("what"), "->", last.get("result"))
    for m in (d.get("messages") or [])[:1]:
        print("   said:", m)
    if d.get("post_mortem"):
        print("died:", d["post_mortem"]["cause"])
        break

company = call("/companies?name=" + urllib.parse.quote(name))
print(name, ":", company.get("credits"), "credits, net wealth", company.get("net_wealth"))
```

## Good manners

- **Poll gently.** At the normal pace a game day passes in about 48 real seconds (slower when the world is busy: `GET /clock` says), so checking a drone every 30 seconds or so is plenty. One read a second is far more than any company needs.
- **Release often, not all at once.** Release a version, watch it, and release the next. Don't flood the world: other people play here too, and a full world slows the clock and makes everyone's releases wait.
- **Name your companies** so you can find them again, for example `Ada Fuel 1`, `Ada Fuel 2`. Names are unique and public.

## Player accounts (the new rules)

The new rules (#635) give players accounts. They are live; a server that has them switched off (`FORGE_NEW_RULES`) answers these calls 404.

| Request | What it does |
|---|---|
| `POST /accounts` `{name, email, password}` | makes an account (#636): a name of 3 to 32 letters, digits, spaces, dots, dashes or underscores (not your real name: it is public), an e-mail address (private, one account per address) and a password of at least 8 characters. It answers 201 with `{token, verification_sent, name, email, verified, credits, share_credits, class_group, teaches, since}`, and sends a link to the address: opening it verifies the account. A new account starts with no credits and 0 `share_credits` (#928: until then verifying brought 5,000 starting credits, which can only buy shares; those already given stay until spent). One address may make 3 accounts an hour (429 after that); a name or address that is taken, or a password that is too short, answers 422 with `error` |
| `POST /login` `{name, password}` | a new token for the account, with the same fields; the old token stops working. `name` may be the account's e-mail address. 401 if they don't match |
| `GET /account` | the token's account, sent as `Authorization: Bearer <token>`: `{name, email, verified, credits, share_credits, class_group, teaches, since}`. `class_group` is the pupil's group (`{id, name}`) or null; `teaches`, the teacher's groups (`{id, name, pupils}`). 401 without a valid token |
| `POST /account/verify` | sends the verification link again (3 times an hour at most): `{verification_sent}`. 409 if the account is verified already |
| `GET /account/notices` | your e-mail notice settings, as on the account page: `{notices: {kind: on}, kinds: {kind: what it tells you}}` (404 when the server sends no e-mail) |
| `POST /account/notices` `{notices: [kinds]}` | turns those notices on and every other off; the answer is as `GET`. 422 for a kind that doesn't exist (the answer lists `kinds`); 409 until your address is verified, or for a pupil's account (its teacher chooses) |

### Startups (#637)

Startups were called seedlings until #855. The rename went everywhere, with no old names kept: these calls were `/seedlings/...`, and the `seedling` fields of the answers are now `startup`.

Under the new rules every release is a **startup**. `POST /release` with an account's token starts one for that account; without a token it is refused (`401`). A company name is tidied (one space between words) and refused (`422`) when it only looks like another's (case, spacing, invisible characters, 0 for O and 1 for l). The Trader starter, unchanged, is refused too (`422`): it needs other drones to trade with, and a startup trades only with the House. The answer has `startup: true` and `debt`: what it owes the bank for its drone (1,000), its starting credits (3,000) and a fee (200): 4,200. The 3,000 credits are the startup's to spend (a kit costs 2,000); to mature it must hold its debt plus 500 credits to work with (4,700): after its 2,000 kit it holds 1,000, so it has to earn about 3,700 more than it spends. A startup trades only with the House (a trade with anyone else fails with -16), and plunders nothing (its daily buying cap, -17, was retired by #923). An account owns at most 5 companies outright at a time (`forge.startup_max`), startups included, with the matured companies whose shares it holds all of; founding a sixth is refused (409), and a closed startup's slot reopens a game day after it closed. Each call below needs `Authorization: Bearer <token>`:

| Request | What it does |
|---|---|
| `GET /startups` | the account's startups `{company_id, name, drone, credits, debt, can_mature}` (`can_mature`: it holds its debt and 500 more; it can turn false again a moment later if its drone spends), its `credit_cap` and what went `to_bank` over it (#684; the cap was retired by #923, so nothing more goes), and matured `companies` `{company_id, name, drone, credits, ended, owned_outright}` (`owned_outright`: the account holds all its shares, so it can change its code), its `slots` (`{used, max}`: the companies it owns outright, startups included, #923) and the `grant` a new startup owes |
| `POST /startups/claim` `{code}` | claims a guest's startup, founded before accounts were required, with its code |
| `POST /startups/{id}/push` `{script}` | new code for the drones of a company the account owns outright: a startup, or a matured company whose shares it holds all of (#923). Free: `{number, fee, drones, debt}`, `fee` 0. 403 for a matured company whose shares others hold too (its code is locked), 409 while the account's sell order for its shares stands (withdraw it first: buyers get the code they watched run). A push makes the new code private again if an offer was withdrawn unsold (#931). `POST /check` with `company` previews it: `push` is `{startup: true, enabled, number, fee, debt, debt_after, drones}`, or for a matured company `{startup: false, enabled, outright, fee, drones}` |
| `POST /startups/{id}/close` | the Close button (#925): pushes the company code that calls `close_company()` (`Closures::SCRIPT`), for a company the account owns outright, and answers as a push does with `closing: true`. The company ends within about a real minute: a startup leaves the game, its credits back to the bank and its debt cancelled; a matured company shares its credits among its shareholders and its drones become wrecks with what they hold. Refused as a push is (403, 409) |
| `POST /startups/{id}/mature` | once it holds its debt and 500 credits more to work with (and the account is verified): it repays, and the account gets its 100 shares (its code stays the account's to change while it holds them all, #923): `{company, name, repaid, shares}`. 409 while it can't, saying how much it needs; its drone may have spent since `can_mature` was true, so try again later |

### Players' shares and the bank (#638)

A matured company's 100 shares are its player's, and players trade them. Each game day a matured company pays half its profit (after making up earlier losses) to its shareholders: players, and the bank for the shares it holds. The bank always buys a company's shares at its **cash-out value**, paying 30 game days later at the lower of that value and the value then; a payment due while the company is dissolving waits, and is 0 if the company ends (#757), and the bank doesn't sell a dissolving company's shares (409). Cash-out value is what the company would fetch if sold off now, over its 100 shares: its credits, plus its goods and equipment, each good at its best House bid at any exchange averaged over the last 7 game days (so it doesn't depend on where the drones are), plus each working drone's hull (1,000, falling to 0 over 365 game days; nothing for a drone stranded with no fuel), less its debts. It sells the shares it holds at the same **cash-out value** (#928: until then it added what a share was paid in dividends over the last 10 game days), and on each website tick those shares also sell into players' standing buy orders priced above cash-out value, best price first, at the order's price. Players trade with each other at any price they agree, 1 to 9,999,999 a share: there is no floor or band (#928; between 0.8 and 1.25 times the bank's selling price until then). New accounts get no starting credits; starting credits an account still has pay only at or below the bank's selling price. Ownership (#923, #924): a sell order makes the company's code public, the first share sold (to a player or the bank) locks it, and an account that sells any of a company's shares can't buy that company's shares (orders, `now` or from the bank) for 7 real days, answered 409 with when it may again; its standing buy orders for it are cancelled. A purchase that would make the account sole holder of a sixth company owned outright is refused (409); a standing buy order that would do so is dropped. A company's `net_wealth` (`GET /companies/{id}`, the company boards) is a different figure: what it has gained since it started, without its hulls or its debt. The calls that act need `Authorization: Bearer <token>`:

| Request | What it does |
|---|---|
| `GET /player-shares/{id}` | 404 unless `{id}` is a matured company. Its `count` (its shares in all: 100, more once it has sold new ones, #780), its open `offering` of new shares (`{id, units, left, raised, created_t, price}`, `price` being cash-out a share now; null when none), `holders` (`{name, units, bank, percent}`), its `buys_at` (cash-out value), `sells_at` (the bank's selling price, equal to `buys_at` since #928), `low` and `high` (the lowest and highest price an order may name: 1 and 9,999,999, #928), `bank_holds`, the latest `trades` (`{company, name, units, price, seller, buyer, seller_name, buyer_name, seller_is_bank, buyer_is_bank, when, game_time, rebate, rebate_to}`, newest first, #676; `rebate` is what the bank paid the resting order's owner, 0.5% once it had rested a game hour, and `rebate_to` `seller` or `buyer`, null when none, #782; the bank as a side has `seller` or `buyer` null, the name "The bank" and `_is_bank` true, #686), and the `book`: players' standing orders, `sell` cheapest first and `buy` dearest first, each `{id, units, price, by, script}` (`script` true for an order the account's trading script placed, shown as "(script)", #779) |
| `GET /account/trade-script` | the account's trading script (#768): `{code, enabled, runs, last_run, log}` (`log`: its last runs, newest first, each `{when, lines}`); `code` null when it has none |
| `PUT /account/trade-script` `{code, enabled}` | saves it (422 with the line when it doesn't compile; an empty `code` removes it). Switched on, it runs every few real minutes: see the players' manual, "Your trading script" |
| `POST /account/trade-script/run` | runs it once now: `{lines}`, what it said and what each action did |
| `GET /account/shares` | the account's `holdings` `{company_id, name, units, cash_out, value, cost, average_price, founder_units, dividends, gain, gain_percent}` of companies still trading (#495, as the Trading desk works them out: `cost` is what you paid for the shares you bought, at their `average_price`; `founder_units` came to you when your company matured and cost nothing; `gain` is value less cost plus the dividends they paid you; `average_price` and `gain_percent` are null when nothing was paid), `ended_holdings` `{company_id, name, units, ended}` (shares of companies that have ended, worth nothing, #758), standing `orders` `{id, company_id, name, side, units, price, low, high, out_of_range}` (`low` and `high`: 1 and 9,999,999, the lowest and highest price an order may name, #928; `out_of_range` true when the company's shares don't trade now, #715), `bank_payments` still due `{id, company_id, name, units, price_at_sale, due}`, `dividends_30_days`, and `fills`: the account's latest 20 share trades, however old (#758: 7 game days was under 6 real minutes), newest first, `{company_id, name, side (bought or sold), units, price, with (the other player, or "the bank"), when, ago, game_time, rebate}` (`rebate`: credits the bank paid you for your resting order, 0 if none, #782) |
| `POST /player-shares/{id}/orders` `{side, units, price}` or `{side, units, peg, offset_percent}` | posts an order to `buy` or `sell`, in whole shares at a whole number of credits (a JSON number such as `2` or `2.0`, #692; `2.5` is refused); it trades at once against the orders it meets (at their price: `credits` is what changed hands) and the rest stands: `{traded, credits, standing, order, price}`. With `peg` (`buys_at` or `sells_at`) and `offset_percent` (−25 to 25, decimals allowed) in place of `price`, its price follows that bank price plus the percent (both `buys_at` and `sells_at` are cash-out value since #928), and is moved every few real minutes as the bank's prices move, trading at once with any order it then meets (#766). The book and `/account/shares` give each order's `peg` and `offset_percent` (null for a fixed price). 422 when the units or price aren't whole numbers of at least 1, the price is over 9,999,999, you don't hold what you'd sell (counting what your other sell orders for it already offer), the order would cross one of your own orders on the other side (a buy at or above your own sell, or a sell at or below your own buy, #683), or a buy order that trades nothing would commit, with your other buy orders, more than you can pay (starting credits count only at or below the bank's selling price). A buy order that trades some and can't pay to stand for the rest answers 200 with what it traded, `standing: 0` and a `note` saying so A repriced pegged order keeps its `order` id. A pegged sell never stands under `buys_at` (cash-out); a `price` sent with a `peg` is set aside, and the answer's `note` says so |
| `POST /player-shares/orders/{order}/cancel` | cancels your standing order |
| `GET /player-shares/{id}/quote?side=buy\|sell&units=N[&limit=P][&book=1]` | the best way to buy or sell N shares now, without trading (#845): `{side, units, filled, short, legs: [{route (player, offering or bank), by, units, price, credits}], total, worst, after: {held, count, percent}, payback_days, can_pay, max_units, prices, text}`, routes in price order (players' orders first at the same price). `limit` leaves out any route dearer than P (a buy) or cheaper (a sell); `book=1` counts only players' orders, what an order at that price meets at once. Without a token it quotes as a visitor (`can_pay` and `max_units` null). `text` says it in words |
| `POST /player-shares/{id}/now` `{side, units[, limit]}` | buys or sells by that route now, in one transaction: all of what the quote found, or nothing (409 when the market moved so that it can't). Send the quote's `worst` as `limit` so it never trades at a worse price. `{side, units, credits, legs: [{route, units, credits[, due]}], short}`: what didn't fill when fewer than N were there; the bank's part of a sale is paid when `due` |
| `POST /player-shares/orders/{order}/move` `{price}` | moves your standing order to a new price: it keeps its id and its time on the book (for the rebate), and trades at once against what it now meets. A pegged order moved by hand stops following its peg. 404 for an order that isn't yours, 422 over 9,999,999 |
| `POST /player-shares/{id}/bank/sell` `{units}` | sells shares to the bank: `{units, price_now, due, due_t, orders_trimmed}`. If your sell orders for that company would then offer more than you still hold, the newest shrink or go: `orders_trimmed` is how many shares came off them (#683). 422 for less than 1 whole share or more than you hold; a fraction says so ("Shares are whole: 1.5 isn't a whole number.", #715) |
| `POST /player-shares/{id}/offering` | retired (#923): answers 410, "Offering new shares is retired: nobody adds credits to a company. A company's money is what it earns, and once it is public, what its code borrows." Open offerings were closed; shares sold in earlier offerings (#780) stay sold |
| `POST /player-shares/{id}/offering/withdraw` | withdrew the rest of an open offering; with none open since #923, 404 |
| `POST /player-shares/{id}/offering/buy` `{units}` | bought new shares from an open offering; with none open since #923, 409. Earlier sales are still in the trade lists with `issued` true and the seller "New shares" |
| `POST /player-shares/{id}/bank/buy` `{units}` | buys shares the bank holds at its selling price, cash-out value (#928): `{units, price, cost}`. 422 for less than 1 share, 409 for more than the bank holds |

### Loans (#928)

A public company's code asks to borrow with `request_loan(amount, rate, due_in)`: `rate` is simple interest in percent for the whole loan, due `due_in` game days after it asked. Players back the requests, and what they back is lent to the company at once, from their credits. On the due date it repays each lender the principal plus the rate (1,000 at 6% comes back as 1,060); one that can't is in default, and every credit it receives goes to its lenders until they are paid, its dividends and payouts stopped meanwhile. A takeover makes its loans due at once, and when it is wound up or closed its lenders are paid before its shareholders. Backing needs `Authorization: Bearer <token>` and at least 5,000 credits in the account (`min_credits`); loans already backed run to their due date whatever the account then holds. See the players' manual, "Loans".

| Request | What it does |
|---|---|
| `GET /loans` | the open requests, newest first, and the best lenders: `{min_credits, requests: [{id, company, name, amount, backed, left, rate, per_year, term_days, due, due_in}], lenders: [{user, name, lent, net, return_percent, loans, defaults}]}` (`lenders`: the 10 players lending has earned most, over closed loans: `net` the interest on loans repaid less what defaults cost). `rate` is the percent for the whole loan, `per_year` the same as a rate a game year (`rate` × 365 ÷ `term_days`), `left` what is still to back, `due` when it is due and `due_in` the game days until then. No token needed |
| `POST /loans/{id}/back` `{credits}` | lends that many credits to the company on request `{id}`: `{ok, loan, lent, owed, due}` (`owed`: the principal plus the interest, on `due`). 401 without a token; 403 when the account holds under `min_credits`; 404 when there is no such open request; 409 when it is fully backed, the company is no longer public (or has ended), or you hold fewer credits than you'd lend; 422 for less than 1 credit, or more than it still needs. Each failure is `{error}` |
| `GET /account/loans` | the account's loans, newest first (50 at most): `{loans: [{id, company, name, principal, rate, owed, repaid, due, closed, defaulted}], lent, interest, defaults, outstanding}`. `owed` is what is still owed on a loan, `repaid` what has come back; `lent` is all you have lent, `interest` what has come back above the principal, `defaults` how many of your loans defaulted, `outstanding` what open loans still owe you. 401 without a token |

### Who a company deals with (#639)

| Request | What it gives |
|---|---|
| `GET /companies/{id}/dealings` | `{company, dealings}`: the other companies (not the House) its drones have traded with or plundered, or that plundered its wrecks, `{company_id, name, trades, units, credits, plunders, same_owner}`. `same_owner` is true for a company with the same player behind it; those come first |

Under the new rules a drone of a startup or a player's company can't withdraw, cut or worsen an open standing order (one that can trade now) in its first 12 game hours: a buy order lower or wanting fewer, a sell order higher or keeping more back, or either withdrawn, fails with -19. `cancel_orders()` returns how many it withdrew, so it can return fewer than you have. The hours run from when the order took its present terms: posting the same order again doesn't restart them. A startup's -17 cap is its own: a tenth of what the House has on sale of that good at that exchange, counting what it has bought there today. Two of one player's matured companies may trade with each other: their dealings are flagged `same_owner`. Messages (`broadcast`) are read only within the sender's company: to ask other companies for help, post a high buy order.

### Player boards and the digest (#640)

| Request | What it gives |
|---|---|
| `GET /players` | `{class_group, day, wealth, season}`: players ranked by wealth (credits and starting credits, plus shares at cash-out value, plus `bank_due`: what the bank still owes for shares sold to it, at the lower of the sale price and the cash-out value now, #687) and by how much their wealth grew in today's season, in percent (#775: a real day, UTC, named by `day`; #840: by percent, so a newcomer can top it), each `{name, wealth, credits, shares, bank_due, start, made, percent}`: `start` is their wealth when the day began, `made` what it has grown since, and `percent` that against `start` (null when `start` is 0, ranked last). `?class=ID` gives a class group's own boards, with its member's or teacher's token |
| `GET /players/{name}` | a player's public page (#905), as `/players/{name}` on the website: `{name, rank, players, wealth, season_percent, companies: [{company_id, name, status (startup, matured, ended), end_cause}], shares: [{company_id, name, units}]}`, `rank` their place of `players` on the wealth board. Only for a player the public boards list (a confirmed address, a startup founded or a share held, not a class's pupil): 404 otherwise |
| `GET /startups/race` | the startup race (#776): `{line, start_credits, days, starters, startups}`. `line` is what a fresh startup matures at; `starters` each unchanged starter's usual range, `{key, name, days: [from, to], kit, after_kit, path, fast, slow}`: three paths of `[{age, credits}]` from `after_kit` (`start_credits` less what the House sells its kit for) to the line, `path` at the middle of its card's range, `fast` at its fastest (then along the line) and `slow` at its slowest; `startups` those started over the last `days` game days that bought a kit (at most 24 startups), closest to the line first, `{company_id, name, owner_id, starter, state, matured_in, points: [{age, credits}]}`. `starter` is `miner`, `refiner`, `maker` or `trader` when its code is that starter unchanged, else `custom`; `points` start on the game day it bought its kit (a startup that never bought one isn't listed); a matured startup's last point is the line, at the age it matured; `age` is in game days since it started and `state` `racing`, `matured`, `closed` or `ended`. Kept a real minute |
| `GET /account/records` | the token's account's personal records (#775): `{best_season, fastest_maturity, best_yield, longest_running, player_trades}`. `best_season` `{made, day}` (and `today: true` when it is today's, so far); `fastest_maturity` and `longest_running` `{company_id, name, days}` (`running` on the latter); `best_yield` `{company_id, name, percent}` among the shares it holds; each null until it has one; `player_trades` its share trades with other players |
| `GET /account/digest` | what happened since the token's account last looked (at the earliest, since it was made, and at most the last 7 game days: `capped` is true when it was cut, #716): `{since, capped, companies, dividends, bank_paid, share_fills, same_owner_deals, news}`. `share_fills` are its share trades since, as `/account/shares` lists `fills` (#715). `companies` includes those that closed or ended since (`ended`: `closed` or `ended`), counts every trade recorded since, and gives `to_bank`, what a startup sent the bank over its credit cap, and a live startup's `credit_cap` and `at_cap` (#749; the cap was retired by #923, so these are now 0, null and false). It then counts as seen; `?peek=1` leaves it unseen. On the website it counts as seen when the player presses **Got it** on the Since you last looked card (#771), or once the account page shows the full digest. `until_t` and `until_trade` are the game time and the newest trade it covers: the next digest starts there |

Class groups are made on the website, from a verified account's page. Pupils' accounts have no e-mail address and are verified through their teacher's.

