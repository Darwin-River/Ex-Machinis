# The Ex Machinis API

The API gives programs what the website gives people. It is for your own tools: scripts that watch your companies, dashboards, and AI agents that play the game. With it you can check a script, release a company, and follow your drones, all as JSON.

- **Base address:** `https://<the game's domain>/api/v1`. On a server you run yourself, that is your own domain.
- **No account and no key**, just as on the website. Everything the API shows is already public on the website.
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

## Play from a program: the loop

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

A script must define the function `launched` (`function launched() ... end`): see the players' manual, [Speaking Lua](players-manual.md#chapter-10-writing-your-drone-in-lua). FORTH has been retired: a FORTH script (`: launched ... ;`) is refused, with an error saying so. Drones released in FORTH before then carry on.

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
  "language": "lua"
}
```

| Field | Meaning |
|---|---|
| `ok` | whether the Release page would take the script |
| `error` | why not, if it wouldn't. For example: no `launched` function, a FORTH script, a line too long, or a script that doesn't compile (`"Line 12: unexpected symbol near '='"`) |
| `warning` | the Release page's warnings: names the script uses that nothing defines (calling one crashes the drone), and names of the game's own that the script sets (`best_bid = 30`) |
| `language` | `lua` for a Lua script (`forth` for a retired FORTH one, which `ok` refuses) |

### `POST /release`: release a company

Every release is live, and free: the only limit is 10 a minute per address. A released drone can't be changed, so to try a fix, release it again as a new company and follow its drone (`GET /drones/{id}`).

**Fields:**

| Field | Required | Meaning |
|---|---|---|
| `company_name` | yes | 3 to 45 characters, not already taken |
| `script` | yes | must define `launched` |
| `kit` | no | ignored since #506: a company starts with 3,000 credits and no kit, and its script equips itself (`equip(mining_kit)`) |

**Example:**

```sh
curl -s -X POST $B/release -H 'Content-Type: application/json' \
  -d '{"company_name": "Ada Fuel 1", "script": "function launched() print(\"hello\") end"}'
```

**Response:** `201 Created`.

```json
{
  "queued": false,
  "company": {"id": 109, "name": "Ada Fuel 1"},
  "drone": {"id": 207, "name": "SN949636"},
  "page": "https://play.example.com/spacecraft/207",
  "warning": null
}
```

**When the world is full** (the server can't keep up), the release waits its turn and launches when there is room. The response is then `202 Accepted`:

```json
{"queued": true, "place_in_line": 3, "company_name": "Ada Fuel 1"}
```

Look the company up by name later.

**Errors:** `422` with the reason. For example:
- `"The company name has already been taken."`
- `"Your script must define the function launched ..."`, or `"FORTH has been retired: write your drone in Lua. ..."`

## Your companies and drones

### `GET /companies/{id}/history`: net wealth day by day

Each company's credits, net wealth and working drones, taken once a game day, oldest first. The last 60 game days are kept day by day; older ones every 10th day.

```json
[{"day": 12, "credits": 3605, "net_wealth": 2890, "drones": 1},
 {"day": 13, "credits": 4120, "net_wealth": 3410, "drones": 1}]
```

### `GET /companies?name=<name>` and `GET /companies/{id}`

Use these to look up a company.

```json
{
  "id": 106, "name": "Kira 2", "credits": 22936, "net_wealth": 24791, "ended": false,
  "drones": [
    {"id": 108, "name": "SN123002", "place_id": 10, "place": "Moon",
     "status": "waiting for its action to finish: Pull resource",
     "script_status": {"text": "buying metals for a pump", "set": "day 12, 14:05", "set_game_time": 1794788700},
     "fuel": 69, "wrecked": false, "wreck_cause": null},
    {"id": 111, "name": "SN156871", "place_id": 3, "place": "Earth",
     "status": "waiting for its action to finish: Make Fuel (A1)", "script_status": null,
     "fuel": 108, "wrecked": false, "wreck_cause": null}
  ]
}
```

| Field | Meaning |
|---|---|
| `net_wealth` | the leaderboard's measure: credits plus goods at market prices plus shares held in other companies at their last trade price plus credits committed to offerings of new shares, less what it started with (3,000 credits and 100 fuel). The drones themselves don't count, so launching a copy lowers it by about a hull's price until the copy earns that back |
| `shares` | the stock market (#471): `price` (its shares' last trade price, or null), `count` (its shares in all: 1,000 to start, more after it issues new ones, #485), `offering` (its open offering of new shares, or null: `{units, price, raise, committed, left, hours, count, sales, a_day_after, investors: [{company, name, units}]}`), `holders` (`{company, name, units, dividends}`, most first), `orders` (the standing orders for its shares at the Earth exchange: `{company, name, side, price, keep or want}`), `holdings` (the shares it holds in other companies: `{company, name, units, dividends, price, ended, value}`; a company that has ended is worth 0, #493) and `trades` (its shares' last 10 trades: `{seller, seller_name, buyer, buyer_name, units, price, game_t}`) |
| `drones` | the drone you released, its clones, and their clones |

### `GET /drones/{id}`

Use this to see what a drone is doing. It gives what the drone's page shows:

| Field | Meaning |
|---|---|
| `place`, `place_id` | where it is (an object ID, as the game's functions use); while it flies, where it left from |
| `flying_to` | while it flies: `{place_id, place, arrives, arrives_game_time}`; otherwise null |
| `status` | what it's doing, in words: `loading its script (3 pieces to go)` and `about to launch` before it starts, `running its launched function`, `waiting for its action to finish: ...`, `a wreck` |
| `script_status` | what its script says it is doing: `{text, set, set_game_time}`, the line it last set with `set_status(text)` and when; null if it never set one. A drone that sits still can say why ("waiting for metals: none on sale here") |
| `fuel` | units in the tank |
| `cargo` | `[{resource, name, units}]`: what is aboard, its modules included |
| `now` | the action under way and when it ends, or null |
| `timeline` | its last actions, newest first: `{action, when, what, result, fuel}`. `result` is `done`, `under way, until ...` or `failed: <why>`. Older ones: `GET /drones/{id}/timeline` |
| `trades` | its last trades with other drones, a wreck's too: `{when, what, with, credits}` |
| `messages` | what it has said: its `report` lines and outputs, newest first, once they reach Earth (they travel at the speed of light) |
| `post_mortem` | for a wreck: `{cause}`, why it died, in plain words |
| `parent`, `clones`, `generation` | its family |
| `instructions`, `crashes` | how much it has computed (1 fuel per 200,000 instructions), and how often its `launched` function has crashed |
| `fuel_last_day` | `{actions, thinking, by_action}`: the fuel it used in the last game day, for its actions and for its computer, and by action, most first: `[{what, fuel, times}]` (#494) |
| `script` | the script it runs |

### `GET /drones/{id}/timeline`: all its actions, a page at a time

A drone's actions, newest first, like `timeline` above but as far back as you like (#494): `?limit=` (up to 100, 50 if left out) and `?before=` (an action ID: the actions before it). `next_before` is the `before` for the next page, or null at the start.

```sh
curl -s "$B/drones/142/timeline?limit=100"
curl -s "$B/drones/142/timeline?limit=100&before=38377"
```

## The market

### `GET /orders`: every open order

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

**When a pull fails** with "the seller's order didn't allow selling that many", someone else bought those units first, or the seller sold or used them, between your look and your pull. `buy_here` then tries the next seller. A failed pull costs no fuel: the fuel it burned is given back.

### `GET /news`: booms, slumps, shortages and gluts

Each exchange's demand (what the House buys) and supply (what it sells) has its booms and slumps, by tier of goods (ores, refined goods, parts, systems, modules). Each runs for 8 game days and is strongest in the middle. Strongest first:

```json
[{"place": "Mars Exchange", "tier": "systems", "side": "demand", "kind": "boom", "percent_now": 31,
  "from_day": 40, "to_day": 48,
  "text": "Mars Exchange: a boom in systems (demand): the House bids up 31%, days 40 to 48."}]
```

| `kind` | Meaning |
|---|---|
| `boom` | the House buys more of these, and bids more |
| `slump` | it buys fewer, and bids less |
| `shortage` | it sells fewer, and asks more |
| `glut` | it sells more, and asks less |

`percent_now` is how far the boom or slump moves prices now, on top of the exchange's slower waves. Drones can't read the news: they see what it does to prices where they are.

Drones see these orders only where they are, through `best_bid`, `best_offer` and queries 600 and 610. The API shows every place at once, which helps you decide where to send a company.

### `GET /trades?limit=50`: the latest trades

Each trade:

```json
{"when": "day 40, 12:05", "resource": "Fuel (A1)", "units": 30, "credits": 120, "price": 4,
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
| `GET /clock` | `game_time` (game seconds), `game_day` (since the world began), `when` (as the website writes it), `scale` (game seconds per real second, now), `real_seconds_per_game_day` (now), `target_scale` and `target_real_seconds_per_game_day` (the pace when the engines keep up), `floor_scale` (the slowest it goes), `slowed` (true while it runs slower than its target), `world_full` (releases are queueing), `releases_waiting`, and `engine`: `running` (false when the game engine hasn't reported for 3 real minutes: drones are waiting for it, not slow), `last_seen_seconds_ago`, `turns_per_minute`, `behind_seconds` |
| `GET /places` | where drones are, busiest first: `{id, name, working, wrecks}` |
| `GET /leaderboard?limit=50` | net wealth, richest first: `{company_id, company, drones, net_wealth, released_drone}`. `limit` can be up to 500 |

## Reference data

| Request | What it gives |
|---|---|
| `GET /resources` | every resource and module: `{id, name, mass}`. The IDs are what the game's functions take: `fuel` is 2061, `metals_a1` is 2011 |
| `GET /protocols` | the actions a drone can `perform`: `{id, name, description}` |
| `GET /recipes?resource=4311` | the recipes (leave `resource` out for all): `{protocol, name, makes: [{resource, name, units}], uses: [...], fuel, tool, tool_name, hours}` |
| `GET /recipes/{resource}/tree` | `{resource}` is a **resource** id, the thing you want to make (e.g. `/recipes/4311/tree` for Pumps (A1)), not a recipe's protocol id: what one unit takes, all the way down to refined goods (metals, organics, inorganics): `{goods: [{resource, name, units}], fuel, tool_hours: [{tool, name, hours}], steps}`. `?down_to=1` goes down to ores. It follows each good's first recipe |
| `GET /queries` | the queries a drone can `query`, by ID: `{name, parameters, strings}` |

The [players' manual](players-manual.md) explains each protocol and query and how to read their results.

## Live queries

This endpoint runs one game query as a drone you name, and gives back the rows the drone's `query` would get. Use it to try a query, or to see what a drone sees from where it is:

```
GET /machine/query?id=<query>&drone=<drone id>&v[]=<parameter>&v[]=...
```

The parameters go in the order `query` takes them after the query ID. For example, `GET /machine/query?id=207&drone=108` asks for the game day:

```json
{"code": 1, "bytes": "KgFkBA==", "rows": [{"day": 298, "minute": 1124}]}
```

- `rows` is what the drone's `query(207)` returns: each row's columns by name (a Lua drone gets them by position too, in the same order).
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
    time.sleep(30)                                    # a game day passes every 48 real seconds
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

- **Poll gently.** A game day passes in about 48 real seconds, so checking a drone every 30 seconds or so is plenty. One read a second is far more than any company needs.
- **Release often, not all at once.** Release a version, watch it, and release the next. Don't flood the world: other people play here too, and a full world slows the clock and makes everyone's releases wait.
- **Name your companies** so you can find them again, for example `Ada Fuel 1`, `Ada Fuel 2`. Names are unique and public.
