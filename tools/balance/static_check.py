#!/usr/bin/env python3
"""The static balance check (#961).

Works out each static playability objective in tools/balance/objectives.json
from the game data that game-engine/sql/game-data/build_game_data.py builds
(imported, so it writes nothing), and writes documents/balance-report.md.

  python3 tools/balance/static_check.py           # write the report
  python3 tools/balance/static_check.py --check   # CI: fail if a guard is broken
                                                  # or the committed report is stale
  python3 tools/balance/static_check.py --json    # the figures, for other tools

The figures are a static model at the House's listed prices: no booms or
slumps, no import price, no daily demand caps, no crowding. They show the
shape of the economy a change makes, not what a drone will earn; the live
measure (measure_live.py) and playtest rounds say that. See DV-03.
"""
import importlib.util
import json
import math
import os
import re
import statistics
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..'))
BUILD = os.path.join(REPO, 'game-engine', 'sql', 'game-data', 'build_game_data.py')
OBJECTIVES = os.path.join(HERE, 'objectives.json')
REPORT = os.path.join(REPO, 'documents', 'balance-report.md')

EARTH, MOON_DEPOT, MARS = 1, 2, 3        # station ids
MOON = 10                                # the Moon's object id
MOON_KM = 384400
NEPTUNE_KM = (30.07 - 1.0) * 149597870.7   # Earth to Neptune at their closest
JUPITER_KM = (5.20 - 1.0) * 149597870.7    # Earth to Jupiter at their closest
FUEL_RESERVE = 100                       # fuel a far hauler keeps aboard


def load_build():
    spec = importlib.util.spec_from_file_location('build_game_data', BUILD)
    g = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(g)
    return g


def travel_acceleration():
    """The engines' TRAVEL_ACCELERATION (m/s²), from game-engine.conf"""
    conf = open(os.path.join(REPO, 'game-engine', 'conf', 'game-engine.conf'), encoding='utf-8').read()
    m = re.search(r'^TRAVEL_ACCELERATION\s*=\s*"?([\d.]+)', conf, re.M)
    return float(m.group(1)) if m else 19.6


def trip_hours(km, accel):
    return 2 * math.sqrt(km * 1000 / accel) / 3600


def median(xs):
    return statistics.median(xs) if xs else None


class Model:
    def __init__(self, g):
        self.g = g
        self.tax = g.SALES_TAX_PERMILLE / 1000
        self.fuel_price = g.FUEL_PRICE
        self.names = {int(k): v['name'] for k, v in g.resources.items()}
        self.mass = {int(k): v['mass'] for k, v in g.resources.items()}
        # The ladder is priced at the inner exchanges (Earth, the Moon and
        # Mars), where a company works; a frontier exchange (#973) is days
        # away, and this model has no trips in it. bid_anywhere is every
        # exchange's best bid, for the far haul (PO-14)
        near = set(g.NEAR_BODIES) | set(g.bodies.get('Station', []))
        inner = {st[0] for st in g.STATIONS if st[2] in near}
        self.ask, self.bid, self.bid_at, self.bid_anywhere = {}, {}, {}, {}
        for st, rid, side, price, _, _ in g.STATION_GOODS:
            if side == g.BUYS:
                self.bid_anywhere[rid] = max(price, self.bid_anywhere.get(rid, price))
                self.bid_at.setdefault(st, {})[rid] = price
            if st not in inner:
                continue
            if side == g.SELLS:
                self.ask[rid] = min(price, self.ask.get(rid, price))
            else:
                self.bid[rid] = max(price, self.bid.get(rid, price))
        self.makers = {}   # resource -> (protocol, recipe), single-output recipes only
        for pid, rec in g.recipes.items():
            if len(rec['outputs']) == 1 and g.production_stage(pid):
                self.makers.setdefault(int(next(iter(rec['outputs']))), (pid, rec))

    def hours(self, pid):
        return self.g.production_stage(pid)[1] / self.g.HOUR

    def fuel(self, pid):
        return self.g.fuel_cost(pid) * self.fuel_price

    def base_price(self, rid):
        """What a refined (tier 2) good costs to buy: the House's cheapest ask,
        or, for one the House doesn't sell (from far away), its value with the
        House's markup, as a hauler might ask"""
        if rid in self.ask:
            return self.ask[rid]
        raw = self.g.RAW_VALUE.get(str(rid))
        return raw * (self.g.markup(rid) if hasattr(self.g, "markup") else self.g.HOUSE_MARKUP) if raw else None

    def chain(self, rid, seen=()):
        """(cash cost, tool-hours, fuel credits) for one unit, made all the way
        from refined goods bought from the House; None if it can't be made"""
        if str(rid)[0] == '2' and (rid in self.ask or str(rid) in self.g.RAW_VALUE):
            return self.base_price(rid), 0.0, 0.0
        if rid not in self.makers or rid in seen:
            return None
        pid, rec = self.makers[rid]
        cost, hours, fuel = 0.0, self.hours(pid), self.fuel(pid)
        for i, n in rec['inputs'].items():
            sub = self.chain(int(i), seen + (rid,))
            if sub is None:
                return None
            cost, hours, fuel = cost + sub[0] * n, hours + sub[1] * n, fuel + sub[2] * n
        q = rec['outputs'][str(rid)]
        return cost / q, hours / q, fuel / q

    def one_step(self, rid):
        """Credits a tool-hour for buying a recipe's inputs from the House,
        making one step and selling to the House's best bid; None if an input
        isn't sold"""
        if rid not in self.makers or rid not in self.bid:
            return None
        pid, rec = self.makers[rid]
        cost = 0
        for i, n in rec['inputs'].items():
            if int(i) not in self.ask:
                return None
            cost += self.ask[int(i)] * n
        q = rec['outputs'][str(rid)]
        return (self.bid[rid] * q * (1 - self.tax) - cost - self.fuel(pid)) / self.hours(pid)

    def integrated(self, rid):
        """Credits a tool-hour for making one unit all the way from refined goods"""
        c = self.chain(rid)
        if c is None or c[1] <= 0 or rid not in self.bid:
            return None
        return (self.bid[rid] * (1 - self.tax) - c[0] - c[2]) / c[1]

    def refining(self, mineral):
        for pid, rec in self.g.recipes.items():
            if pid.startswith('21') and list(rec['inputs']) == [str(mineral)]:
                return pid, rec
        return None, None

    def mine_and_refine(self, mineral, abundance, bids):
        """(credits a tool-hour, ore a batch, hours a batch, value a batch, refined units a batch)"""
        pid, rec = self.refining(mineral)
        if not pid:
            return None
        ore = rec['inputs'][str(mineral)]
        runs = ore / abundance
        hours = runs * self.g.MINING_TIME / self.g.HOUR + self.hours(pid)
        fuel = (runs * self.g.fuel_cost('11000') + self.g.fuel_cost(pid)) * self.fuel_price
        value = sum(q * bids.get(int(r), 0) for r, q in rec['outputs'].items()) * (1 - self.tax)
        units = sum(q * self.mass[int(r)] for r, q in rec['outputs'].items())
        return (value - fuel) / hours, ore, hours, value - fuel, units


def figures(g):
    m = Model(g)
    out = {'details': {}}
    far = {int(r) for r in g.FAR_MINERALS}
    moon_abundance = {rid: mult for obj, rid, mult in g.abundances if obj == MOON}

    # PO-04: mining baseline at the Moon
    rows = []
    for rid, tag in g.mineral_tags.items():
        rid = int(rid)
        if tag != 'Moon' or rid in far or rid not in moon_abundance:
            continue
        r = m.mine_and_refine(rid, moon_abundance[rid], m.bid_at[MOON_DEPOT])
        if r:
            rows.append((m.names[rid], round(r[0], 1), r[1], round(r[2], 1)))
    rows.sort(key=lambda x: -x[1])
    baseline = rows[0][1] if rows else 0
    out['mining_baseline'] = baseline
    out['details']['mining'] = rows

    # PO-05 to PO-07: the ladder
    one, whole = {}, {}
    for rid in sorted(m.makers):
        t = int(str(rid)[0])
        if t not in (2, 3, 4) or rid == g.FUEL:
            continue
        s = m.one_step(rid)
        if s is not None:
            one.setdefault(t, []).append((m.names[rid], round(s, 1)))
        w = m.integrated(rid) if t > 2 else s
        if w is not None:
            whole.setdefault(t, []).append((m.names[rid], round(w, 1)))
    med_one = {t: round(median([v for _, v in xs]), 1) for t, xs in one.items()}
    med_whole = {t: round(median([v for _, v in xs]), 1) for t, xs in whole.items()}
    out['making_over_mining'] = round(min(med_whole[3], med_whole[4]) / baseline, 2) if baseline else None

    def step(lo, hi):
        if lo <= 0:
            return math.inf if hi > 0 else 0.0
        return hi / lo
    out['tier_step'] = round(min(step(med_whole[2], med_whole[3]), step(med_whole[3], med_whole[4])), 2)
    out['specialist_floor'] = min(med_one[t] for t in (2, 3, 4))
    out['details']['ladder'] = {t: {'one_step_median': med_one.get(t), 'whole_chain_median': med_whole.get(t),
                                    'one_step': sorted(one.get(t, []), key=lambda x: x[1]),
                                    'whole_chain': sorted(whole.get(t, []), key=lambda x: x[1])}
                                for t in (2, 3, 4)}

    # PO-08: made goods with no buyer (fuel is left out: the House sells it,
    # and players sell it to each other)
    made = set()
    for rec in g.recipes.values():
        made.update(int(r) for r in rec['outputs'])
    orphans = sorted(m.names[r] for r in made
                     if str(r)[0] in '234' and r != g.FUEL and r not in m.bid)
    out['goods_without_buyer'] = len(orphans)
    out['details']['goods_without_buyer'] = orphans

    # PO-09: free loops
    loops = []
    for (st, rid) in {(s, r) for s, r, *_ in g.STATION_GOODS}:
        sides = {side: p for s, r, side, p, *_ in g.STATION_GOODS if s == st and r == rid}
        if g.BUYS in sides and g.SELLS in sides and sides[g.BUYS] >= sides[g.SELLS]:
            loops.append(f'{m.names.get(rid, rid)} at station {st}')
    for rid in m.bid:
        if rid in m.ask and m.bid[rid] > m.ask[rid]:
            loops.append(f'{m.names.get(rid, rid)} across exchanges')
    kit_price = min(m.ask[k] for k in g.KITS)
    for st, bids in m.bid_at.items():
        for kit, (_, _, parts) in g.KITS.items():
            if sum(bids.get(p, 0) for p in parts) > kit_price * g.KIT_PARTS_SHARE:
                loops.append(f'{m.names[kit]} parts at station {st}')
    out['free_loops'] = len(loops)
    out['details']['free_loops'] = sorted(loops)

    # PO-10: the kits' modules
    mods = []
    for kit, (_, _, parts) in sorted(g.KITS.items()):
        for p in parts:
            c = m.chain(p)
            if c and p in m.ask:
                mods.append((m.names[p], round(c[0] + c[2]), m.ask[p], round((c[0] + c[2]) / m.ask[p], 1)))
    mods = sorted(set(mods), key=lambda x: -x[3])
    out['module_build_ratio'] = mods[0][3] if mods else None
    out['details']['modules'] = mods

    # PO-11: the Mining Cruiser hull
    cruiser = 6001
    c = m.chain(cruiser)
    out['hull_build_ratio'] = round((c[0] + c[2]) / m.ask[cruiser], 1) if c and cruiser in m.ask else None
    out['details']['hull'] = (m.names[cruiser], round(c[0] + c[2]) if c else None, m.ask.get(cruiser))

    # PO-12: abundance spread over near minerals
    by_mineral = {}
    for obj, rid, mult in g.abundances:
        if rid not in far:
            by_mineral.setdefault(rid, []).append(mult)
    spreads = [max(v) / min(v) for v in by_mineral.values() if min(v) > 0]
    out['abundance_spread'] = round(median(spreads), 2) if spreads else None

    # PO-13: trip fuel by distance (a travel_fuel(km) function in the game
    # data takes over from the flat travel cost once there is one)
    if hasattr(g, 'travel_fuel'):
        moon_fuel, neptune_fuel = g.travel_fuel(MOON_KM), g.travel_fuel(NEPTUNE_KM)
    else:
        moon_fuel = neptune_fuel = g.fuel_cost('5001')
    out['trip_fuel_ratio'] = round(neptune_fuel / moon_fuel, 2)
    out['details']['trip_fuel'] = (moon_fuel, neptune_fuel)

    # PO-14: a far haul from near Jupiter against the Moon baseline
    accel = travel_acceleration()
    hold = 1000 - sum(m.mass[p] for p in g.KITS[g.MINING_KIT][2]) - FUEL_RESERVE
    trip = trip_hours(JUPITER_KM, accel)
    trip_fuel = g.travel_fuel(JUPITER_KM) if hasattr(g, 'travel_fuel') else g.fuel_cost('5001')
    best = None
    for rid, _, mult in g.far_minerals:
        r = m.mine_and_refine(rid, mult, m.bid_anywhere)
        if not r:
            continue
        batches = hold // r[4]
        hours = batches * r[2] + 2 * trip
        rate = (batches * r[3] - 2 * trip_fuel * m.fuel_price) / hours
        if best is None or rate > best[1]:
            best = (m.names[rid], round(rate, 1), mult, int(batches), round(hours, 1))
    out['frontier_over_mining'] = round(best[1] / baseline, 2) if best and baseline else None
    out['details']['frontier'] = {'best': best, 'hold': hold, 'trip_hours': round(trip, 1),
                                  'acceleration': accel}
    return out


def passes(o, v):
    if v is None:
        return False
    return (o['min'] is None or v >= o['min']) and (o['max'] is None or v <= o['max'])


def band(o):
    lo, hi = o['min'], o['max']
    if lo is not None and hi is not None:
        return f'{lo} to {hi}'
    return f'at least {lo}' if lo is not None else f'at most {hi}'


def fmt(v):
    if v is None:
        return 'n/a'
    if v == math.inf:
        return '∞'
    return f'{v:g}' if isinstance(v, float) else str(v)


def report(objs, f):
    d = f['details']
    L = ['# Balance report', '',
         'Generated by `tools/balance/static_check.py` from the game data (#961): do not edit by hand. '
         'After changing `game-engine/sql/game-data/` or `tools/balance/objectives.json`, run '
         '`python3 tools/balance/static_check.py` and commit this file. The process is '
         '[DV-03](../Procedures/SOPs/DV-03%20Tuning%20gameplay%20parameters.md).', '',
         'The figures are a static model at the House\'s listed prices: no booms or slumps, no import price, '
         'no daily demand caps and no crowding. They show the shape a change gives the economy. '
         '`tools/balance/measure_live.py` and the playtest rounds say what players actually get.', '',
         'A **guard** fails the build when it is broken. A **target** is reported until it is met, then promoted '
         'to a guard.', '',
         '## Static objectives', '',
         '| ID | Objective | Now | Band | Kind | Met |', '|---|---|---|---|---|---|']
    for o in objs:
        if o['measure'] != 'static':
            continue
        v = f.get(o['key'])
        L.append(f"| {o['id']} | {o['title']} ({o['unit']}) | {fmt(v)} | {band(o)} | {o['status']} | "
                 f"{'yes' if passes(o, v) else '**no**'} |")
    L += ['', '## Live and playtest objectives', '',
          'Measured on the live world with `python3 tools/balance/measure_live.py`, and in each playtest round, '
          'per DV-03.', '',
          '| ID | Objective | Metric | Band | Measured |', '|---|---|---|---|---|']
    for o in objs:
        if o['measure'] != 'static':
            L.append(f"| {o['id']} | {o['title']} | {o['metric']} ({o['unit']}) | {band(o)} | {o['measure']} |")
    L += ['', '## How the figures are worked out', '',
          '### Mining at the Moon (PO-04)', '',
          'Mined at the Moon\'s abundance, refined and sold to the Moon Depot, after 5% sales tax and fuel.', '',
          '| Mineral | Credits a tool-hour | Ore a batch | Hours a batch |', '|---|---|---|---|']
    L += [f'| {n} | {v} | {ore} | {h} |' for n, v, ore, h in d['mining']]
    L += ['', '### The production ladder (PO-05 to PO-07)', '',
          '**One step**: buy a recipe\'s inputs from the House, make it, and sell it to the House\'s best bid. '
          '**Whole chain**: make it all the way from refined goods bought from the House (far goods the House '
          'doesn\'t sell are priced at their value with its markup). Both are in credits a tool-hour.', '',
          '| Tier | One step, median | Whole chain, median | Lowest one step | Highest one step |',
          '|---|---|---|---|---|']
    for t in (2, 3, 4):
        x = d['ladder'][t]
        lo = x['one_step'][0] if x['one_step'] else ('n/a', '')
        hi = x['one_step'][-1] if x['one_step'] else ('n/a', '')
        L.append(f"| {t} | {fmt(x['one_step_median'])} | {fmt(x['whole_chain_median'])} | "
                 f"{lo[0]} {lo[1]} | {hi[0]} {hi[1]} |")
    L += ['', '### Equipment and ships (PO-10, PO-11)', '',
          'What the materials and fuel cost when made from refined goods bought from the House, against the '
          'House\'s price for the finished item.', '',
          '| Item | Cost to build | House price | Ratio |', '|---|---|---|---|']
    L += [f'| {n} | {c} | {p} | {r} |' for n, c, p, r in d['modules']]
    h = d['hull']
    if h[1] and h[2]:
        L.append(f'| {h[0]} (hull) | {h[1]} | {h[2]} | {round(h[1] / h[2], 1)} |')
    fr = d['frontier']
    b = fr['best']
    L += ['', '### Distance and the frontier (PO-13, PO-14)', '',
          f"- A trip burns {d['trip_fuel'][0]} fuel to the Moon and {d['trip_fuel'][1]} to Neptune.",
          f"- Far haul: a 1,000-cargo hull with the Mining Kit and {FUEL_RESERVE} fuel aboard has room for "
          f"{fr['hold']} refined units. Earth to Jupiter at their closest takes {fr['trip_hours']} game hours "
          f"each way at {fr['acceleration']} m/s².",
          f"- Best far mineral: {b[0]} at abundance {b[2]}, {b[3]} batches a trip, {b[4]} game hours with the "
          f"trips, {b[1]} credits a game hour at the House's best bids." if b else '- No far mineral.',
          '', '### Checks that must stay at zero (PO-08, PO-09)', '',
          '- Made goods with no buyer: ' + (', '.join(d['goods_without_buyer']) or 'none') + '.',
          '- Free loops: ' + (', '.join(d['free_loops']) or 'none') + '.', '']
    return '\n'.join(L)


def main(argv):
    g = load_build()
    objs = json.load(open(OBJECTIVES, encoding='utf-8'))['objectives']
    f = figures(g)
    if '--json' in argv:
        print(json.dumps({k: v for k, v in f.items() if k != 'details'}, indent=2, default=str))
        return 0
    text = report(objs, f)
    broken = [o for o in objs if o['measure'] == 'static' and o['status'] == 'guard' and not passes(o, f.get(o['key']))]
    missed = [o for o in objs if o['measure'] == 'static' and o['status'] == 'target' and not passes(o, f.get(o['key']))]
    for o in objs:
        if o['measure'] == 'static':
            v = f.get(o['key'])
            print(f"{o['id']} {o['status']:6} {'ok  ' if passes(o, v) else 'MISS'} {o['title']}: {fmt(v)} ({band(o)})")
    print(f'{len(broken)} guard(s) broken, {len(missed)} target(s) not yet met')
    if '--check' in argv:
        stale = not os.path.exists(REPORT) or open(REPORT, encoding='utf-8').read() != text
        if stale:
            print('documents/balance-report.md is stale: run python3 tools/balance/static_check.py and commit it')
        return 1 if broken or stale else 0
    with open(REPORT, 'w', encoding='utf-8') as fh:
        fh.write(text)
    print(f'Wrote {os.path.relpath(REPORT, REPO)}')
    return 1 if broken else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
