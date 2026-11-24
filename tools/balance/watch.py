#!/usr/bin/env python3
"""The balance watch (DV-05, #1031): the signals a daily balance check reads
beside the live objectives in measure_live.py, from a world's public API (no
key, no server login).

  python3 tools/balance/watch.py                  # the live game, as Markdown
  python3 tools/balance/watch.py --json           # the figures, for the log's data line
  python3 tools/balance/watch.py --save saved/    # also keep what it read
  python3 tools/balance/watch.py --from saved/    # read saved files offline
  python3 tools/balance/watch.py --base http://localhost:8000/api/v1

Signals:
  crowding   for each mineral, the near site with the most miners, its run,
             and the best run at a near site; "stuck" when miners stay on a
             crowded site that pays less than another near site
  prices     the House's buying price for each good at each exchange, from
             the latest trades (a sample: say how many trades it counted)
  frontier   working drones and trades at each frontier exchange, and its
             premium over the best inner exchange for the goods sold there
  newcomers  companies under 200 game days: how many, median age and net
             wealth, and how many are below the 3,000 credits they started with

It never names a company: newcomers are counted, not listed, so a reading
can be posted on GitHub as it is (DV-04 §4.2). It makes about 30 reads,
paced well inside the API's limit of 120 a minute.
"""
import json
import os
import statistics
import sys
import time
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import static_check  # noqa: E402  (the game data's map and the House's stations)

DEFAULT_BASE = 'https://exmachinis.com/api/v1'
NEW_DAYS = 200        # a newcomer is a company under this many game days old
START_CREDITS = 3000  # what a startup begins with
PACE = 0.6            # seconds between reads


def world():
    """Near places, the House's exchanges and which of them are inner"""
    g = static_check.load_build()
    near = set(g.NEAR_BODIES)
    house = {name: place for _id, name, place, *_ in g.STATIONS}
    inner = {name for name, place in house.items() if place in near}
    return near, house, inner


class Reader:
    def __init__(self, base=None, folder=None, save=None):
        self.base, self.folder, self.save = base, folder, save

    def get(self, key, path):
        if self.folder:
            with open(os.path.join(self.folder, key + '.json'), encoding='utf-8') as f:
                return json.load(f)
        req = urllib.request.Request(self.base.rstrip('/') + path, headers={
            'Accept': 'application/json', 'User-Agent': 'ex-machinis-balance-watch'})
        with urllib.request.urlopen(req, timeout=120) as r:
            data = json.load(r)
        time.sleep(PACE)
        if self.save:
            os.makedirs(self.save, exist_ok=True)
            with open(os.path.join(self.save, key + '.json'), 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=1)
        return data


def rows_of(x, *keys):
    if isinstance(x, list):
        return x
    for k in keys:
        if isinstance(x, dict) and isinstance(x.get(k), list):
            return x[k]
    return []


def crowding(rd, near):
    opts = rd.get('resource-map', '/resource-map')
    minerals = [o for o in rows_of(opts, 'options') if o.get('kind') == 'mineral']
    out = []
    for m in minerals:
        rid = m['resource']
        d = rd.get(f'resource-map-{rid}', f'/resource-map?resource={rid}')
        cap = d.get('crowd_capacity')
        sites = [p for b in d.get('bodies', []) for p in b.get('places', [])
                 if p.get('object_id') in near]
        if not sites:
            continue
        best = max(sites, key=lambda p: (p.get('rate', 0), -p.get('miners', 0)))
        busy = max(sites, key=lambda p: p.get('miners', 0))
        if busy.get('miners', 0) == 0:
            continue
        out.append({
            'mineral': m['name'], 'site': busy['name'], 'miners': busy['miners'],
            'run': busy.get('rate'), 'best_site': best['name'], 'best_run': best.get('rate'),
            'stuck': bool(cap and busy['miners'] > cap and best.get('rate', 0) > busy.get('rate', 0)),
        })
    out.sort(key=lambda r: -r['miners'])
    return out


def prices(rd, house, inner):
    trades = rows_of(rd.get('trades', '/trades?limit=100'), 'trades', 'data')
    bids = {}
    for t in trades:
        if t.get('buyer') in house:
            bids.setdefault((t['resource'], t['buyer']), []).append(t['price'])
    table = {}
    for (res, exch), ps in bids.items():
        table.setdefault(res, {})[exch] = {'n': len(ps), 'median': statistics.median(ps)}
    frontier = []
    for res, by in table.items():
        best_inner = max((v['median'] for k, v in by.items() if k in inner), default=None)
        for exch, v in by.items():
            if exch not in inner and best_inner:
                frontier.append({'good': res, 'exchange': exch, 'median': v['median'], 'n': v['n'],
                                 'best_inner': best_inner,
                                 'premium': round(v['median'] / best_inner - 1, 3)})
    return len(trades), table, frontier, trades


def frontier_traffic(places, trades, house, inner):
    out = []
    for name, place in house.items():
        if name in inner:
            continue
        working = next((p.get('working') for p in places if p.get('id') == place), 0)
        here = [t for t in trades if t.get('place') == next(
            (p.get('name') for p in places if p.get('id') == place), None)]
        out.append({'exchange': name, 'working': working, 'trades': len(here)})
    return out


def newcomers(rd):
    rows = rows_of(rd.get('leaderboard', f'/leaderboard?max_age={NEW_DAYS}&limit=100'), 'companies')
    if not rows:
        return {'count': 0}
    ages = [r.get('age_days', 0) for r in rows]
    wealth = [r.get('net_wealth', 0) for r in rows]
    return {'count': len(rows), 'median_age': round(statistics.median(ages), 1),
            'median_wealth': round(statistics.median(wealth)),
            'below_start': sum(1 for w in wealth if w < START_CREDITS)}


def gather(rd):
    near, house, inner = world()
    clock = rd.get('clock', '/clock')
    places = rows_of(rd.get('places', '/places'), 'places')
    n, table, prem, trades = prices(rd, house, inner)
    return {
        'game_day': clock.get('game_day'), 'when': clock.get('when'),
        'congestion': (clock.get('processor_tax') or {}).get('congestion'),
        'places': {p['name']: p.get('working') for p in places},
        'crowding': crowding(rd, near),
        'trades_counted': n, 'bids': table, 'frontier_premium': prem,
        'frontier': frontier_traffic(places, trades, house, inner),
        'newcomers': newcomers(rd),
    }


def markdown(f):
    out = [f"Balance watch, game day {f['game_day']} ({f['when']}), by `tools/balance/watch.py` (DV-05).", '',
           'Working drones: ' + ', '.join(f'{k} {v}' for k, v in f['places'].items())
           + f". Processor congestion {f['congestion']}×.", '']
    out += ['**Crowding** (the busiest near site for each mineral mined)', '',
            '| Mineral | Busiest site | Miners | Run | Best near run | Stuck |', '|---|---|---|---|---|---|']
    for r in f['crowding']:
        out.append(f"| {r['mineral']} | {r['site']} | {r['miners']} | {r['run']} | "
                   f"{r['best_run']} ({r['best_site']}) | {'**yes**' if r['stuck'] else ''} |")
    out += ['', f"**Frontier** ({f['trades_counted']} latest trades counted)", '',
            '| Exchange | Working drones | Trades in sample |', '|---|---|---|']
    out += [f"| {r['exchange']} | {r['working']} | {r['trades']} |" for r in f['frontier']]
    if f['frontier_premium']:
        out += ['', '| Good | Exchange | House bid (median, n) | Best inner bid | Premium |', '|---|---|---|---|---|']
        out += [f"| {r['good']} | {r['exchange']} | {r['median']} ({r['n']}) | {r['best_inner']} | "
                f"{r['premium']:+.0%} |" for r in f['frontier_premium']]
    nc = f['newcomers']
    out += ['', f"**Newcomers** (companies under {NEW_DAYS} game days): {nc['count']}"]
    if nc['count']:
        out[-1] += (f", median age {nc['median_age']} days, median net wealth {nc['median_wealth']}, "
                    f"{nc['below_start']} below the {START_CREDITS} they started with.")
    return '\n'.join(out)


def main(argv):
    def opt(name):
        return argv[argv.index(name) + 1] if name in argv else None
    rd = Reader(base=opt('--base') or DEFAULT_BASE, folder=opt('--from'), save=opt('--save'))
    f = gather(rd)
    print(json.dumps(f, indent=1) if '--json' in argv else markdown(f))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
