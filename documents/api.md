# The Ex Machinis API

The API gives programs what the website gives people. It is for your own tools: scripts that watch your companies, dashboards, and AI agents that play the game. With it you can release a company, test a script, and follow your drones, all as JSON.

- **Base address:** `https://<the game's domain>/api/v1`. On a server you run yourself, that is your own domain.
- **No account and no key**, just as on the website. Everything the API shows is already public on the website.
- **Same rules as the website.** A release made through the API is checked exactly as on the Release page, and it becomes a company like any other.
- **Rate limits, per IP address:**
  - 120 reads a minute;
  - 10 releases and test runs a minute.

  Past a limit, a request gets `429 Too Many Requests`. Its `Retry-After` header says how many seconds to wait.
- **Errors:**
  - `404` when something doesn't exist;
  - `422` when a request breaks a rule;
  - `503` when the test world is off;
  - `500` when something goes wrong on the server (try again in a minute).

  Each error body is JSON, whatever the request's `Accept` header with an `error` message in plain words. A `422` also has `errors`, listing the message for each field.

In the examples below, `B` stands for the base address:

```sh
B=https://play.example.com/api/v1
```

## Contents

1. [Play from a program: the loop](#play-from-a-program-the-loop)
2. [Releasing and testing](#releasing-and-testing)
3. [Your companies and drones](#your-companies-and-drones)
4. [The market](#the-market)
5. [The world](#the-world)
6. [Reference data](#reference-data)
7. [Live queries](#live-queries)
8. [An example agent](#an-example-agent)
9. [Good manners](#good-manners)

## Play from a program: the loop

A company is a script, in FORTH or Lua: you can't steer it once it's released. The game clock is fast: at the usual pace a game day passes in about 2.4 real minutes (`/clock` gives `real_seconds_per_game_day`), so a 40-day round takes about an hour and a half; release early. Playing from a program is the same loop a person follows on the website:

1. **Study the market:**
   - `GET /orders` for who buys and sells what, where, and at what price;
   - `GET /trades` for what has sold;
   - `GET /history` for what each resource sold for, day by day;
   - `GET /news` for the booms, slumps, shortages and gluts under way at the exchanges (House prices move);
   - `GET /recipes` and `GET /recipes/{id}/tree` for what each thing takes to make;
   - `GET /places` for where the drones are.
2. **Write a script and check it** with `POST /check`, which returns the Release page's warnings.
3. **Try it in the test world** with `POST /test`, then poll `GET /test/{token}` for the results.
4. **Release it** with `POST /release`.
5. **Follow it:**
   - `GET /companies?name=...` for credits, net wealth and drones, and `GET /companies/{id}/history` for net wealth day by day;
   - `GET /orders?company=...` for its open orders;
   - `GET /drones/{id}` for where each drone is, its cargo, what it is doing, the fuel it used today, what it has said, and what went wrong.
6. **Learn and release a better version** under a new name. Every company is an experiment.

The [players' manual](players-manual.md) explains the game and the FORTH words. The [strategy blog](blog) has working examples.

## Releasing and testing

### `POST /check`: check a script without releasing it

**Body:** JSON or form fields.

| Field | Required | Meaning |
|---|---|---|
| `script` | yes | the script, in FORTH or Lua |

A script is Lua when it defines a function `launched` (`function launched() ... end`) and no FORTH word `launched` (see the players' manual, [Chapter 10](players-manual.md#chapter-10-writing-your-drone-in-lua)). The release and test calls take either language the same way.

**Example:**

```sh
curl -s -X POST $B/check -H 'Content-Type: application/json' \
  -d '{"script": "variable ask\n: launched ( -- ) frobnicate ;"}'
```

**Response:**

```json
{
  "ok": true,
  "error": null,
  "warning": "Your script uses frobnicate, which isn't defined before it's used: ... Your script redefines ask: ...",
  "language": "forth"
}
```

| Field | Meaning |
|---|---|
| `ok` | whether the Release page would take the script |
| `error` | why not, if it wouldn't. For example: no `launched` word, a line too long, or a Lua script that doesn't compile (`"Line 12: unexpected symbol near '='"`) |
| `warning` | the Release page's warnings: for FORTH, words used before anything defines them (the line using one fails to load with `-13`), and game words the script redefines; for Lua, names of the game's own that the script sets (`best_bid = 30`) |
| `language` | `forth` or `lua`: how the drone will run it |

### `POST /release`: release a company

**Fields:**

| Field | Required | Meaning |
|---|---|---|
| `company_name` | yes | 3 to 45 characters, not already taken |
| `script` | yes | must define `launched` |
| `kit` | no | `mining` (the default) or `factory` |

**Example:**

```sh
curl -s -X POST $B/release -H 'Content-Type: application/json' \
  -d '{"company_name": "Ada Fuel 1", "kit": "factory", "script": ": launched ( -- ) .\" hello\" cr ;"}'
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
- `"Your script must define the word launched ..."`

### `POST /test`: a test run

**Fields:** `script` and `kit`, as for a release.

The script runs in the **test world**. This is a separate world with the same rules and a faster clock, where nothing touches the live game. It answers `503` when the game runs without one.

**The live market is there too.** Every few real minutes, a company called "Live market" copies the live world's best orders into the test world: at each place, for each good, the cheapest sell order (with the units the live sellers offer at that price) and the dearest buy order. So a plan that buys metals from the Moon's miners can be tried. What a test drone buys from it comes back at the next copy, and what it sells to it goes. `GET /orders?world=test` lists the test world's orders. The test world has its own House, whose prices move on its own clock, and its own game day: `today` there is in the thousands, so count days from the start of your run rather than from day 0.

**Test drones are numbered from 10,000,000,** so no test drone shares a live drone's number. `GET /drones/{id}` answers 404 for them; their results are at `GET /test/{token}`.

**Response:** `201 Created`.

```json
{
  "token": "3f9c0a7d5e21b864",
  "results": "https://play.example.com/api/v1/test/3f9c0a7d5e21b864",
  "page": "https://play.example.com/release/test/3f9c0a7d5e21b864",
  "warning": null
}
```

The script starts loading at once. A run keeps going for 30 real minutes; after that, the next test anyone starts stops it, though its results stay readable. Test runs are kept for a while, then cleared to make room for others.

### `GET /test/{token}`: a test run's results so far

```json
{
  "drone": 5123, "credits": 1180, "wrecked": false, "crashes": 0,
  "messages": [{"subject": "Release", "body": "..."}],
  "events": [{"type": "Increment inventory", "resource": "Metals (A1)", "quantity": 12, "credits": null, "outcome": 1}],
  "trades": [{"resource": 2011, "units": 12, "credits": 290, "seller": 5123, "buyer": 2}]
}
```

| Field | Meaning |
|---|---|
| `messages` | what the drone printed, oldest first |
| `events` | its latest 20 events (actions, cargo, credits), newest first |
| `trades` | its trades |

Poll every 30 seconds or so.

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
     "status": "waiting for its action to finish: Pull resource", "fuel": 69, "wrecked": false, "wreck_cause": null},
    {"id": 111, "name": "SN156871", "place_id": 3, "place": "Earth",
     "status": "waiting for its action to finish: Make Fuel (A1)", "fuel": 108, "wrecked": false, "wreck_cause": null}
  ]
}
```

| Field | Meaning |
|---|---|
| `net_wealth` | the leaderboard's measure: credits plus goods at market prices, less the starter kit's value. The drones themselves don't count, so launching a copy lowers it by about a hull's price until the copy earns that back |
| `drones` | the drone you released, its clones, and their clones |

### `GET /drones/{id}`

Use this to see what a drone is doing. It gives what the drone's page shows:

| Field | Meaning |
|---|---|
| `place`, `place_id` | where it is (an object ID, as the game words use); while it flies, where it left from |
| `flying_to` | while it flies: `{place_id, place, arrives, arrives_game_time}`; otherwise null |
| `status` | what it's doing, in words: `loading its script (3 pieces to go)` and `about to launch` before it starts, `running its launched word`, `waiting for its action to finish: ...`, `a wreck` |
| `fuel` | units in the tank |
| `cargo` | `[{resource, name, units}]`: what is aboard, its modules included |
| `now` | the action under way and when it ends, or null |
| `timeline` | its last actions, newest first: `{when, what, result, fuel}`. `result` is `done`, `under way, until ...` or `failed: <why>` |
| `trades` | its last trades with other drones: `{when, what, with, credits}` |
| `messages` | what it has said: its `report` lines and outputs, newest first, once they reach Earth (they travel at the speed of light) |
| `post_mortem` | for a wreck: `{cause}`, why it died, in plain words |
| `parent`, `clones`, `generation` | its family |
| `instructions`, `crashes` | how much it has computed (1 fuel per 100,000 instructions), and how often its `launched` word has crashed |
| `fuel_last_day` | `{actions, thinking}`: the fuel it used in the last game day, for its actions and for its computer |
| `script` | the script it runs |

## The market

### `GET /orders`: every open order

Parameters:

| Parameter | Default | Meaning |
|---|---|---|
| `side` | `buy` | `buy` or `sell` |
| `resource` | all | a resource ID (see `/resources`) |
| `place` | everywhere | an object ID or name (`Moon`, `Earth`, `Phobos`...) |
| `company` | everyone | a company ID or name: that company's open orders |
| `world` | live | `test`: the test world's orders (the live market's copy, its own House and test drones) |

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

**When a pull fails** with "the seller's order didn't allow selling that many", someone else bought those units first, or the seller sold or used them, between your look and your pull. `buy-here` (`buy_here` in Lua) then tries the next seller.

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

Drones see these orders only where they are, through `best-bid`, `best-offer` and queries 600 and 610. The API shows every place at once, which helps you decide where to send a company.

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
| `GET /clock` | `game_time` (game seconds), `game_day` (since the world began), `when` (as the website writes it), `scale` (game seconds per real second), `real_seconds_per_game_day`, `world_full` (releases are queueing), `releases_waiting`, and `engine`: `running` (false when the game engine hasn't reported for 3 real minutes: drones are waiting for it, not slow), `last_seen_seconds_ago`, `turns_per_minute`, `behind_seconds` |
| `GET /places` | where drones are, busiest first: `{id, name, working, wrecks}` |
| `GET /leaderboard?limit=50` | net wealth, richest first: `{company_id, company, drones, net_wealth, released_drone}`. `limit` can be up to 500 |

## Reference data

| Request | What it gives |
|---|---|
| `GET /resources` | every resource and module: `{id, name, mass}`. The IDs are what the game words take: `fuel` is 2061, `metals-a1` is 2011 |
| `GET /protocols` | the actions a drone can `perform`: `{id, name, description}` |
| `GET /recipes?resource=4311` | the recipes (leave `resource` out for all): `{protocol, name, makes: [{resource, name, units}], uses: [...], fuel, tool, tool_name, hours}` |
| `GET /recipes/{id}/tree` | what one unit takes, all the way down to refined goods (metals, organics, inorganics): `{goods: [{resource, name, units}], fuel, tool_hours: [{tool, name, hours}], steps}`. `?down_to=1` goes down to ores. It follows each good's first recipe |
| `GET /queries` | the queries a drone can `query`, by ID: `{name, parameters, strings}` |

The [players' manual](players-manual.md) explains each protocol and query and how to read their results.

## Live queries

The Machine page's live data is open to programs too. The endpoint runs one game query as a drone you name, and gives back the bytes the drone would get:

```
GET /machine/query?id=<query>&drone=<drone id>&size=<bytes>&v[]=<parameter>&v[]=...
```

For example, `GET /machine/query?id=207&drone=108&size=120` asks for the game day:

```json
{"code": 1, "bytes": "KgFkBA==", "rows": [{"day": 298, "minute": 1124}]}
```

- `code` is what `query` leaves on a drone's stack: the number of rows, or `-1`, `-2` or `-3`.
- `bytes` is the results array the drone would get, base64-encoded and laid out as the manual's query table describes.
- `rows` is the same data, already decoded.

Its limit is 240 calls a minute. It sits outside `/api/v1` because the Machine page uses it.

Ask as a player's drone. A station drone doesn't see its own orders.

## An example agent

A minimal loop in Python, using only the standard library. It:
1. checks and tests a script;
2. releases it if the test run looks healthy;
3. reports on the company every 10 minutes.

```python
import json, time, urllib.request

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

script = open("my_company.fth").read()

check = call("/check", {"script": script})
print("check:", check["error"] or "ok", "|", check["warning"] or "no warnings")

run = call("/test", {"script": script, "kit": "factory"})
if "token" in run:
    time.sleep(300)                                   # let it play a while (the test clock is fast)
    result = call("/test/" + run["token"])
    print("test:", result["credits"], "credits,", result["crashes"], "crashes,",
          "wrecked" if result["wrecked"] else "working")

released = call("/release", {"company_name": "Ada Fuel 1", "script": script, "kit": "factory"})
print("released:", released)

while True:
    company = call("/companies?name=Ada%20Fuel%201")
    print(company.get("credits"), "credits, net wealth", company.get("net_wealth"))
    for d in company.get("drones", []):
        print(" ", d["id"], d["place"], d["status"], "fuel", d["fuel"])
    time.sleep(600)
```

## Good manners

- **Poll gently.** The game moves in game hours, so checking a company every few minutes is plenty. One read a second is far more than any company needs.
- **One experiment at a time.** Release a few companies and learn from them. Don't flood the world: other people play here too, and a full world makes everyone's releases wait.
- **Name your companies** so you can find them again, for example `Ada Fuel 1`, `Ada Fuel 2`. Names are unique and public.
