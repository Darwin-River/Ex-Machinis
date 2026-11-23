#!/usr/bin/env python3
"""The live measure (#961): the live playability objectives in
tools/balance/objectives.json, read from a world's public API (no key, no
server login), as a Markdown table for a tuning issue or a round on #380.

  python3 tools/balance/measure_live.py                       # the live game
  python3 tools/balance/measure_live.py --base http://localhost:8000/api/v1
  python3 tools/balance/measure_live.py --from saved/          # clock.json, dashboard.json,
                                                               # places.json, race.json saved earlier
  python3 tools/balance/measure_live.py --save saved/          # also keep what it read
  python3 tools/balance/measure_live.py --json

It makes four reads, well inside the API's limit of 120 a minute. The
startup race lists at most 24 recent startups, so PO-01 and PO-02 are a
sample: say how many it counted when you quote them (the table does).
"""
import json
import os
import statistics
import sys
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import static_check  # noqa: E402  (the objectives' bands and the game data's map)

DEFAULT_BASE = 'https://exmachinis.com/api/v1'
READS = {'clock': '/clock', 'dashboard': '/dashboard', 'places': '/places', 'race': '/startups/race'}
STARTERS = ('miner', 'refiner', 'maker')


def read_all(base=None, folder=None, save=None):
    data = {}
    for key, path in READS.items():
        if folder:
            with open(os.path.join(folder, key + '.json'), encoding='utf-8') as f:
                data[key] = json.load(f)
        else:
            req = urllib.request.Request(base.rstrip('/') + path, headers={
                'Accept': 'application/json', 'User-Agent': 'ex-machinis-balance-measure'})
            with urllib.request.urlopen(req, timeout=120) as r:
                data[key] = json.load(r)
        if save:
            os.makedirs(save, exist_ok=True)
            with open(os.path.join(save, key + '.json'), 'w', encoding='utf-8') as f:
                json.dump(data[key], f, indent=1)
    return data


def near_places():
    """Object ids in the Earth and Mars systems, the inner planets and the
    House's stations: everything else is beyond Mars"""
    g = static_check.load_build()
    return set(g.NEAR_BODIES) | set(g.bodies.get('Station', []))


def rows_of(x):
    """A list of rows whether the API wraps it or not"""
    if isinstance(x, list):
        return x
    for k in ('data', 'places', 'rows'):
        if isinstance(x, dict) and isinstance(x.get(k), list):
            return x[k]
    return []


def figures(data, near):
    f, notes = {}, {}
    # PO-01, PO-02: the startup race
    days = {s: [] for s in STARTERS}
    for s in data['race'].get('startups', []):
        if s.get('starter') in days and s.get('state') == 'matured' and s.get('matured_in') is not None:
            days[s['starter']].append(float(s['matured_in']))
    med = {s: statistics.median(v) for s, v in days.items() if v}
    if len(med) == len(STARTERS):
        f['starter_days_worst'] = round(max(med.values()), 1)
        f['starter_days_spread'] = round(max(med.values()) / min(med.values()), 2)
    sample = ', '.join(f'{s} {round(med[s], 1) if s in med else "none"} (n={len(days[s])})' for s in STARTERS)
    notes['starter_days_worst'] = notes['starter_days_spread'] = 'median days: ' + sample

    # PO-15, PO-16: where players' drones are
    places = rows_of(data['places'])
    working = sum(int(p.get('working', 0)) for p in places)
    if working:
        beyond = sum(int(p.get('working', 0)) for p in places if int(p.get('id', 0)) not in near)
        busiest = max(places, key=lambda p: int(p.get('working', 0)))
        f['beyond_mars_share'] = round(beyond / working, 3)
        f['busiest_place_share'] = round(int(busiest.get('working', 0)) / working, 3)
        notes['beyond_mars_share'] = f'{beyond} of {working} working drones'
        notes['busiest_place_share'] = f"{busiest.get('name')}: {busiest.get('working')} of {working}"

    # PO-17 to PO-19: the dashboard
    dash = data['dashboard']
    cap = (dash.get('tiles') or {}).get('capital') or {}
    f['idle_capital_percent'] = cap.get('idle_percent')
    f['return_percent'] = cap.get('return_percent')
    banner = dash.get('banner') or {}
    f['trade_ratio'] = banner.get('ratio')
    notes['trade_ratio'] = f"{banner.get('trades')} trades against a norm of {banner.get('norm_trades')}"

    # PO-20: the clock
    clock = data['clock']
    now, target = clock.get('real_seconds_per_game_day'), clock.get('target_real_seconds_per_game_day')
    if now and target:
        f['clock_slowdown'] = round(float(now) / float(target), 2)
        notes['clock_slowdown'] = f'{now} real seconds a game day, target {target}'
    f['_when'] = clock.get('when')
    f['_game_day'] = clock.get('game_day')
    return f, notes


def table(objs, f, notes):
    L = [f"Live playability measure, game day {f.get('_game_day')} ({f.get('_when')}), by "
         '`tools/balance/measure_live.py` (#961).', '',
         '| ID | Objective | Now | Band | Met | Notes |', '|---|---|---|---|---|---|']
    for o in objs:
        if o['measure'] != 'live':
            continue
        v = f.get(o['key'])
        L.append(f"| {o['id']} | {o['title']} ({o['unit']}) | {static_check.fmt(v)} | {static_check.band(o)} | "
                 f"{'yes' if static_check.passes(o, v) else '**no**'} | {notes.get(o['key'], '')} |")
    return '\n'.join(L)


def main(argv):
    def opt(name):
        return argv[argv.index(name) + 1] if name in argv else None
    data = read_all(base=opt('--base') or DEFAULT_BASE, folder=opt('--from'), save=opt('--save'))
    objs = json.load(open(static_check.OBJECTIVES, encoding='utf-8'))['objectives']
    f, notes = figures(data, near_places())
    if '--json' in argv:
        print(json.dumps({'figures': f, 'notes': notes}, indent=2))
    else:
        print(table(objs, f, notes))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
