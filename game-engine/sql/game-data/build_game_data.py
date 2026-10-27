#!/usr/bin/env python3
"""Builds game-engine/sql/game_data.sql, the game's reference data, from the
design spreadsheets:

  game-data/resources.xlsx   resources and production recipes (current design)
  game-data/queries.xlsx     the list of queries and their IDs
  documents/economy.xlsx     everything else: mining, travel, market, push/pull,
                             starter kit, recipe timings and modules, location
                             and market effects, and query SQL

Run it after changing a spreadsheet:
  python3 game-engine/sql/game-data/build_game_data.py

Only the standard library is used. The script prints what it had to assume or
drop; read that list after each run.
"""
import html
import os
import re
import sys
import zipfile
import zlib
from collections import Counter, defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..', '..'))
OUT = os.path.join(HERE, '..', 'game_data.sql')

# Abundance multiplier given to every (body, mineral) pair the generator
# creates. Tune per location in the output or here.
DEFAULT_ABUNDANCE = 5
# Minerals are tagged with the kind of body they are found on
MINERAL_BODY_TYPES = {'Planet': 'Planet', 'Moon': 'Moon',
                      'Asteroid': 'Asteroid', 'Comet': 'Comet', 'Ring': 'Ring'}
# Timing for recipes that economy.xlsx doesn't have (seconds, as in economy.xlsx)
NEW_RECIPE_INPUT_TIME = 1
NEW_RECIPE_OUTPUT_TIME = 10
# Debug protocol that creates any resource for free: never ship it
EXCLUDED_PROTOCOLS = {'30003'}

notes = []


def note(msg):
    notes.append(msg)


# Typos in names from the spreadsheets
NAME_FIXES = {'Synhtesize': 'Synthesize', 'Manurfacture': 'Manufacture', 'Priduce': 'Produce',
              'Prouce': 'Produce', 'pullies': 'pulleys', 'Chagres': 'Charges'}


def fix_name(name):
    for wrong, right in NAME_FIXES.items():
        name = name.replace(wrong, right)
    return ' '.join(name.split())


# --- xlsx reading (stdlib only) ----------------------------------------------

def read_xlsx(path):
    z = zipfile.ZipFile(path)
    wb = z.read('xl/workbook.xml').decode()
    sheets = re.findall(r'<sheet [^>]*name="([^"]+)"[^>]*r:id="([^"]+)"', wb)
    rels = dict(re.findall(r'Id="([^"]+)"[^>]*Target="([^"]+)"',
                           z.read('xl/_rels/workbook.xml.rels').decode()))
    shared = []
    if 'xl/sharedStrings.xml' in z.namelist():
        for si in re.findall(r'<si>(.*?)</si>', z.read('xl/sharedStrings.xml').decode(), re.S):
            shared.append(html.unescape(''.join(re.findall(r'<t[^>]*>([^<]*)</t>', si))))

    def col(ref):
        n = 0
        for ch in ref:
            n = n * 26 + ord(ch) - 64
        return n - 1

    out = {}
    for name, rid in sheets:
        target = rels[rid].lstrip('/')
        target = target if target.startswith('xl/') else 'xl/' + target
        rows = []
        for r in re.findall(r'<row[^>]*>(.*?)</row>', z.read(target).decode(), re.S):
            cells = {}
            # A cell is <c .../> (empty) or <c ...>...</c>
            for ref, attrs, body in re.findall(r'<c r="([A-Z]+)\d+"([^>]*?)(?:/>|>(.*?)</c>)', r, re.S):
                v = re.search(r'<v>([^<]*)</v>', body)
                t = re.search(r'<t[^>]*>([^<]*)</t>', body)
                if 't="s"' in attrs and v:
                    val = shared[int(v.group(1))]
                elif t:
                    val = html.unescape(t.group(1))
                elif v:
                    val = html.unescape(v.group(1))
                else:
                    val = ''
                cells[col(ref)] = val
            rows.append([cells.get(i, '') for i in range(max(cells) + 1)] if cells else [])
        out[name] = rows
    return out


def num(s):
    """'1000.0' -> '1000'; '' -> ''"""
    s = str(s).strip()
    if re.fullmatch(r'-?\d+(\.0+)?', s):
        return str(int(float(s)))
    return s


def sql(v):
    if v is None:
        return 'NULL'
    if isinstance(v, int):
        return str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"


# --- sources -------------------------------------------------------------------

design = read_xlsx(os.path.join(HERE, 'resources.xlsx'))
qsheet = read_xlsx(os.path.join(HERE, 'queries.xlsx'))['Sheet1']
econ = read_xlsx(os.path.join(REPO, 'documents', 'economy.xlsx'))

# --- resources -----------------------------------------------------------------

econ_res = {num(r[0]): r for r in econ['Export Resources'] if r and num(r[0]).isdigit()}
resources = {}      # id -> dict
mineral_tags = {}   # mineral id -> body type
for r in design['Resources'][1:]:
    r = r + [''] * 12
    rid = num(r[0])
    if not rid.isdigit():
        continue
    e = econ_res.get(rid)
    resources[rid] = {
        'id': int(rid), 'name': fix_name(r[1].strip())[:45], 'description': r[2].strip(),
        'mass': int(round(float(r[3] or 0))),
        'capacity': int(num(e[4]) or 0) if e else 0,
        'slot_size': int(num(e[5]) or 0) if e else 0,
    }
    if r[5].strip() == 'Mineral':
        mineral_tags[rid] = r[7].strip()

# Fuel (#324 step 8): every action burns some, and so does running a drone's
# computer (power). It is made from Organics and Inorganics (recipe 12061 in
# recipes-extra.csv), so it joins the production chain.
FUEL = 2061
POWER_PROTOCOL = '2'
POWER_INSTRUCTIONS = 100000     # instructions per unit of fuel (game-engine.conf)
resources[str(FUEL)] = {
    'id': FUEL, 'name': 'Fuel (A1)', 'mass': 1, 'capacity': 0, 'slot_size': 0,
    'description': 'Rocket fuel. Every action burns some, and running the drone\'s computer burns '
                   f'1 unit per {POWER_INSTRUCTIONS:,} instructions. A drone that runs dry becomes a wreck.'}

# --- recipes from the design workbook ------------------------------------------

recipes = {}  # protocol id -> {'inputs': {res: qty}, 'outputs': {res: qty}, 'sheet', 'product'}
empty_columns = {}  # protocol id -> sheet, for columns without a recipe
for sheet, rows in design.items():
    if sheet == 'Resources' or len(rows) < 14:
        continue
    product_is_input = sheet.startswith('Minerals to')   # refining: the mineral goes in
    for ci, pid in enumerate(rows[1]):
        pid = num(pid)
        if ci < 4 or not pid.isdigit():
            continue
        cell = lambda r: (rows[r][ci] if len(rows[r]) > ci else '')
        product, qty = num(cell(5)), cell(8)
        comps = {}
        for r in rows[13:]:
            if len(r) > ci and r[1].strip() and num(r[0]).isdigit() and r[ci] not in ('', '0', '0.0'):
                comps[num(r[0])] = int(round(float(r[ci])))
        if not product.isdigit() or not comps:
            empty_columns[pid] = sheet   # reported below unless recipes-extra.csv has it
            continue
        pq = int(round(float(qty or 1))) or 1
        if product_is_input:
            recipes[pid] = {'inputs': {product: pq}, 'outputs': comps, 'sheet': sheet, 'product': product}
        else:
            recipes[pid] = {'inputs': comps, 'outputs': {product: pq}, 'sheet': sheet, 'product': product}

# Recipes resources.xlsx doesn't have yet (recipes-extra.csv, #298); the
# spreadsheet wins when it has the same protocol
import csv
with open(os.path.join(HERE, 'recipes-extra.csv'), encoding='utf-8') as f:
    for row in csv.DictReader(l for l in f if not l.startswith('#')):
        pid = row['protocol'].strip()
        if pid in recipes:
            continue
        comps = {}
        for tok in row['components'].split():
            rid, _, n = tok.partition('*')
            comps[rid] = comps.get(rid, 0) + int(n or 1)
        recipes[pid] = {'inputs': comps, 'outputs': {row['product'].strip(): int(row['quantity'])},
                        'sheet': 'recipes-extra.csv', 'product': row['product'].strip()}
for pid, sheet in sorted(empty_columns.items()):
    if pid == '10000':
        continue  # an empty column in the kits sheet, with no product (#298)
    if pid not in recipes:
        note(f'recipe {pid} ({sheet}): no product or no components in the sheet or recipes-extra.csv, skipped')

# --- production: tools and timing (#298) ---------------------------------------
# Every recipe locks one tool module while it runs and takes game time
# (seconds), doubling at each stage. The starter kit holds the Smelter (A1),
# Chemical Reactor (A1) and Spider (A1), which cover everything up to systems;
# the Spider builds the Welder and Riveter the later stages need.
HOUR, DAY = 3600, 86400
MINING_TIME = 1 * HOUR
TRANSFER_TIME = 60          # push and pull: one game minute
SMELTER, CHEM_REACTOR, SPIDER, WELDER, RIVETER = 5201, 5211, 5501, 5511, 5521


def production_stage(pid):
    """(tool resource, game seconds) for a recipe protocol"""
    p = int(pid)
    if 21000 <= p < 22000:
        return SMELTER, 2 * HOUR                       # refining ore
    if p in (12021, 12022, 12023):
        return SMELTER, 2 * HOUR                       # alloys
    if 12000 <= p < 13000:
        return CHEM_REACTOR, 2 * HOUR                  # organics and polymers
    if p in (13141, 13301):
        return CHEM_REACTOR, 4 * HOUR                  # polymer parts (belts, hoses)
    if 13000 <= p < 14000:
        return SMELTER, 4 * HOUR                       # metal parts
    if 14000 <= p < 15000:
        return SPIDER, 8 * HOUR                        # systems
    if p in (15501, 15511, 15521):
        return SPIDER, 1 * DAY                         # Spider, Welder, Riveter
    if 15000 <= p < 16000:
        return WELDER, 1 * DAY                         # other modules
    if 16000 <= p < 17000:
        return RIVETER, 3 * DAY                        # ship kits
    return None


def production_effects(tool, total, rec):
    """The refining pattern: check the tool, use the inputs, lock the tool,
    unlock it, then produce"""
    def eff(time, resource, event_type, locked, quantity):
        return {'time': time, 'drone': 0, 'resource': int(resource), 'event_type': event_type, 'local': 1,
                'installed': 0, 'locked': locked, 'deplete': 1 if event_type == 2 else 0, 'abundancies': 0,
                'quantity': quantity}
    effs = [eff(0, tool, 1, 0, 1), eff(0, tool, 2, 0, 1)]
    effs += [eff(1, rid, 2, 0, q) for rid, q in rec['inputs'].items()]
    effs += [eff(2, tool, 2, 0, 1), eff(2, tool, 1, 1, 1)]
    effs += [eff(total - 1, tool, 2, 1, 1), eff(total - 1, tool, 1, 0, 1)]
    effs += [eff(total, rid, 1, 0, q) for rid, q in rec['outputs'].items()]
    return effs


def game_duration(seconds):
    for size, unit in ((DAY, 'day'), (HOUR, 'hour'), (60, 'minute')):
        if seconds >= size and seconds % size == 0:
            n = seconds // size
            return f'{n} {unit}' + ('s' if n != 1 else '')
    return f'{seconds} seconds'


# --- protocols and effects from economy.xlsx ----------------------------------

econ_prot = {}
for r in econ['Export Protocols']:
    if r and num(r[0]).isdigit():
        econ_prot[num(r[0])] = (r + [''] * 8)[:8]
# id, protocol, time, drone, resource, event_type, local, installed, locked, deplete, abundancies, quantity
econ_eff = defaultdict(list)
for r in econ['Export Resource Effects']:
    if r and num(r[1]).isdigit():
        r = [num(x) for x in (r + [''] * 12)[:12]]
        econ_eff[r[1]].append({'time': int(r[2] or 0), 'drone': int(r[3] or 0), 'resource': int(r[4] or 0),
                               'event_type': int(r[5] or 0), 'local': int(r[6] or 0),
                               'installed': int(r[7] or 0), 'locked': int(r[8] or 0),
                               'deplete': int(r[9] or 0), 'abundancies': int(r[10] or 0),
                               'quantity': int(r[11] or 0)})

# Resources that core protocols need but the design list dropped
needed = set()
for pid, effs in econ_eff.items():
    if pid not in recipes and pid not in EXCLUDED_PROTOCOLS:
        needed |= {str(e['resource']) for e in effs if e['resource'] > 0}
for rid in sorted(needed - set(resources), key=int):
    if rid in econ_res:
        e = econ_res[rid]
        resources[rid] = {'id': int(rid), 'name': e[1][:45], 'description': e[2], 'mass': int(num(e[3]) or 0),
                          'capacity': int(num(e[4]) or 0), 'slot_size': int(num(e[5]) or 0)}
        note(f'resource {rid} "{e[1]}" is not in resources.xlsx but core protocols need it '
             f'(e.g. the starter kit): kept from economy.xlsx')

protocols = {}   # id -> row
effects = []     # (protocol, effect dict)


def add_effects(pid, effs):
    for e in effs:
        if e['resource'] > 0 and str(e['resource']) not in resources:
            note(f'protocol {pid}: effect on unknown resource {e["resource"]} dropped')
            continue
        effects.append((int(pid), e))


# Core protocols: everything in economy.xlsx that isn't a recipe
for pid, p in econ_prot.items():
    if pid in recipes:
        continue
    if pid in EXCLUDED_PROTOCOLS:
        note(f'protocol {pid} "{p[1]}" left out: it gives any resource for free (debug)')
        continue
    if pid[:2] in ('12', '13', '14', '15', '16', '21') and len(pid) == 5:
        note(f'protocol {pid} "{p[1]}" is a recipe in economy.xlsx but not in resources.xlsx: left out')
        continue
    effs = econ_eff.get(pid, [])
    if pid == '11':
        # Pull: economy.xlsx also files three recipe rows here (use Rods I and
        # Pipes I, make Valves I). Pulling shouldn't need them.
        bad = [e for e in effs if e['resource'] > 0]
        if bad:
            note('protocol 11 (pull): dropped %d rows that look like a mis-filed recipe (%s)'
                 % (len(bad), ', '.join(str(e['resource']) for e in bad)))
        effs = [e for e in effs if e['resource'] <= 0]
    if pid in ('10', '11') and num(p[7]) != '1':
        # The player's manual moves N units: "N R D 10 perform". The quantity
        # is the process multiplier, so these protocols take one.
        p = p[:7] + ['1']
        note(f'protocol {pid} ({p[1]}): multiplier enabled, so "N R D {pid} perform" moves N units as the manual says')
    if pid == '1':
        # Dump (#380): N units for one action and 1 fuel, like push and pull;
        # one unit at a time cost a fuel a unit, so nobody dumped and holds
        # clogged with goods nobody buys
        p = (p[:4] + ['[N R 1 perform]: Dumps N units of resource R into space. N is required (1 for one unit).']
             + p[5:7] + ['1'])
    if pid == '25901':
        # The kit's Smelter I (5221) can't refine: refining needs Smelter (A1)
        effs = [dict(e, resource=SMELTER if e['resource'] == 5221 else e['resource']) for e in effs]
    if pid.startswith('11') and len(pid) == 5 and effs:
        # Mining: lock the module at 0, unlock it a second before the end,
        # the mineral arrives at the end
        old_end = max(e['time'] for e in effs)
        # The mineral is the protocol's own (economy.xlsx has Mine yalspar,
        # 11110, yield kunspar: found by the starter scripts, #324 step 15)
        mineral = int(pid) - 10000
        for e in effs:
            if e.get('abundancies') and e['resource'] != mineral:
                note(f'protocol {pid} ({p[1]}): yielded resource {e["resource"]}, fixed to {mineral}')
        effs = [dict(e, resource=mineral) if e.get('abundancies') else e for e in effs]
        effs = [dict(e, time=MINING_TIME if e['time'] == old_end else
                     MINING_TIME - 1 if e['time'] == old_end - 1 else e['time']) for e in effs]
        # "Uses a Worm I mining module": the modules are named (A1); say how long
        desc = re.sub(r'\b(Slug|Worm|Crawler|Skimmer) I mining module', r'\1 (A1) mining module', ' '.join(p[4].split()))
        p = p[:4] + [f'{desc} Takes {game_duration(MINING_TIME)}.'] + p[5:]
    if pid in ('10', '11'):
        effs = [dict(e, time=TRANSFER_TIME) if e['time'] > 0 else e for e in effs]
        # The multiplier N is required (#315): describe it
        verb = {'10': 'Delivers N units of resource R to drone D.', '11': 'Pulls N units of resource R from drone D.'}[pid]
        p = p[:4] + [f'[N R D {pid} perform]: {verb} N is required (1 for one unit). It takes one minute of game time.'] + p[5:]
    protocols[pid] = p
    add_effects(pid, effs)

# Launching a drone (#238): [D K 12 perform] uses up ship kit K and, an hour
# later (plus the light time from drone D), builds a drone with K's hull and a
# copy of D's program. The parameters come off the stack top first: K is
# parameter 1 (-1), D parameter 2 (-2). The launch event (type 8) carries D in
# its quantity; the events engine creates the drone.
LAUNCH_PROTOCOL, LAUNCH_EVENT, LAUNCH_TIME = '12', 8, 1 * HOUR
# Cloning (#324 step 13): the new drone gets LAUNCH_FUEL of the launcher's
# fuel (the events engine puts it in its hold), so it can pay for its first
# thinking; keep in step with LAUNCH_FUEL in common_types.h
LAUNCH_FUEL = 20
protocols[LAUNCH_PROTOCOL] = [
    LAUNCH_PROTOCOL, 'Launch drone', '2', '100',
    '[D K 12 perform]: Builds a new drone (a clone) from ship kit K in the cargo bay, beside this one, with a copy '
    'of drone D\'s program and 20 of this drone\'s fuel; it runs its launched word. Takes 1 hour plus the light time from D.',
    '1', '1', '0']
add_effects(LAUNCH_PROTOCOL, [
    {'time': 0, 'drone': 0, 'resource': -1, 'event_type': 2, 'local': 1, 'installed': 0, 'locked': 0,
     'deplete': 1, 'abundancies': 0, 'quantity': 1},
    {'time': LAUNCH_TIME, 'drone': 0, 'resource': -1, 'event_type': LAUNCH_EVENT, 'local': 1, 'installed': 0,
     'locked': 0, 'deplete': 0, 'abundancies': 0, 'quantity': -2},
])

# Recipes: quantities from resources.xlsx (or recipes-extra.csv); tool and
# timing by stage (production_stage); names from economy.xlsx where it has one
for pid, rec in sorted(recipes.items(), key=lambda kv: int(kv[0])):
    for rid in list(rec['inputs']) + list(rec['outputs']):
        if rid not in resources:
            note(f'recipe {pid}: unknown resource {rid}')
    stage = production_stage(pid)
    if stage is None:
        note(f'recipe {pid}: no production stage for this protocol range, left out')
        continue
    tool, total = stage
    prod = resources.get(rec['product'], {}).get('name', rec['product'])
    verb = 'Refine' if rec['sheet'].startswith('Minerals to') else 'Make'
    # Refining keeps its name ("Refine kunspar"); others are named after their
    # product: some economy.xlsx names contradict what the recipe makes
    name = fix_name(econ_prot[pid][1]) if pid in econ_prot and verb == 'Refine' else f'{verb} {prod}'
    words = lambda items: ', '.join(f'{q} {resources.get(r, {}).get("name", r)}' for r, q in items.items())
    tail = f' Needs a {resources[str(tool)]["name"]}; takes {game_duration(total)}.'
    desc = f'[{pid} perform]: Uses {words(rec["inputs"])} to make {words(rec["outputs"])}.' + tail
    if len(desc) > 255:
        desc = (f'[{pid} perform]: Makes {words(rec["outputs"])} from '
                f'{sum(rec["inputs"].values())} {"modules" if pid.startswith("16") else "components"} '
                f'(see its protocol page).' + tail)
    flags = econ_prot[pid][5:8] if pid in econ_prot else ['1', '1', '0']
    protocols[pid] = [pid, name[:45], '0', '100', desc[:255]] + list(flags)
    add_effects(pid, production_effects(tool, total, rec))

# Smelter I (5221) was only kept for the starter kit, which now gives Smelter (A1)
if '5221' in resources and not any(e['resource'] == 5221 for _, e in effects):
    del resources['5221']
    notes[:] = [n for n in notes if not n.startswith('resource 5221 ')]
    note('resource 5221 "Smelter I" dropped: the starter kit gives Smelter (A1) 5201, which refining needs')

# Double-cell prices (#324 step 9): like sell (7) and buy (8), but the price
# P is a double (two cells), so orders can go past 65,535 credits a unit.
# double_params marks the parameters taken as doubles (bit 0 = parameter 1;
# the price is parameter 3, the third taken off the stack).
DOUBLE_PARAMS = {}
for old, new, verb in (('7', '17', 'sell'), ('8', '18', 'buy')):
    p7 = protocols[old]
    keep = 'min' if verb == 'sell' else 'max'
    protocols[new] = [new, f'{verb.capitalize()} resource (double price)', '3', p7[3],
                      f'[P M R {new} perform]: Places order to {verb} resource R at price P, a double (two cells), '
                      f'keeping {keep} M units at drone\'s cargo hold.', p7[5], p7[6], p7[7]]
    DOUBLE_PARAMS[new] = 1 << 2
    add_effects(new, [dict(e) for q, e in effects if q == int(old) and e['resource'] != FUEL])

# House drop stations (#324 step 10): offer goods to the station where the
# drone is (31) or order goods from it (32). Offers and orders wait for the
# hour's batch; the events engine clears it (db_station.c). The offer
# (event type 9) is recorded at time 0 and the goods leave the hold a second
# later; an order (event type 10) is paid for and delivered at clearing.
# Deliveries and returns are actions of protocol 33, which nobody performs.
STATION_OFFER_EVENT, STATION_ORDER_EVENT = 9, 10
protocols['31'] = ['31', 'Offer to station', '2', '100',
                   '[N R 31 perform]: Offers N units of resource R to the House station where the drone is. They leave '
                   'the hold now; when the hour\'s batch clears, the station pays one price for the batch and returns '
                   'what it doesn\'t buy.', '1', '1', '0']
add_effects('31', [
    {'time': 0, 'drone': 0, 'resource': -1, 'event_type': STATION_OFFER_EVENT, 'local': 1, 'installed': 0,
     'locked': 0, 'deplete': 0, 'abundancies': 0, 'quantity': -2},
    {'time': 1, 'drone': 0, 'resource': -1, 'event_type': 2, 'local': 1, 'installed': 0, 'locked': 0,
     'deplete': 1, 'abundancies': 0, 'quantity': -2}])
protocols['32'] = ['32', 'Order from station', '2', '100',
                   '[N R 32 perform]: Orders N units of resource R from the House station where the drone is. When the '
                   'hour\'s batch clears, the company pays the station\'s price for what it gets (as many as its credits '
                   'and cargo space allow) and the goods arrive.', '1', '1', '0']
add_effects('32', [
    {'time': 0, 'drone': 0, 'resource': -1, 'event_type': STATION_ORDER_EVENT, 'local': 1, 'installed': 0,
     'locked': 0, 'deplete': 0, 'abundancies': 0, 'quantity': -2}])
protocols['33'] = ['33', 'Station delivery', '0', '100',
                   'Goods a House station delivers or returns when a batch clears. Not performed by drones.',
                   '1', '1', '0']

# Waiting (#324 step 14): [M 3 perform] pauses the script for M game
# minutes. Its one effect is a wait event (type 12) that the game engine
# puts M minutes ahead; perform returns when it is processed. A waiting
# drone runs no instructions, so it burns no power, and waiting is free.
WAIT_PROTOCOL, WAIT_EVENT = '3', 12
protocols[WAIT_PROTOCOL] = [WAIT_PROTOCOL, 'Wait', '1', '100',
                            '[M 3 perform]: Waits M game minutes (1 to 10,080: a week) doing nothing. The script pauses '
                            'and runs no instructions until then, so waiting costs no fuel.', '1', '1', '0']
add_effects(WAIT_PROTOCOL, [
    {'time': 0, 'drone': 0, 'resource': FUEL, 'event_type': WAIT_EVENT, 'local': 1, 'installed': 0,
     'locked': 0, 'deplete': 0, 'abundancies': 0, 'quantity': 0}])

# Wrecks and plunder (#324 step 12): a drone plunders the wreck W at the
# place where it is (34). The plunder event (type 11) carries W; the events
# engine checks it and moves the plunderer's share of the wreck's goods and
# parts as actions of protocol 35, which nobody performs (db_wreck.c).
PLUNDER_EVENT, PLUNDER_TIME = 11, 1 * HOUR
protocols['34'] = ['34', 'Plunder wreck', '1', '100',
                   '[W 34 perform]: Plunders the wreck W (a drone ID) where the drone is: after 1 hour the drone '
                   'gets its share of the wreck\'s goods and parts, as far as its hold has room; the rest is destroyed. '
                   'A wreck is plundered once.', '1', '1', '0']
add_effects('34', [
    {'time': PLUNDER_TIME, 'drone': 0, 'resource': FUEL, 'event_type': PLUNDER_EVENT, 'local': 1, 'installed': 0,
     'locked': 0, 'deplete': 0, 'abundancies': 0, 'quantity': -1}])
protocols['35'] = ['35', 'Plunder haul', '0', '100',
                   'Goods and parts a plunderer takes from a wreck, and their loss from the wreck. Not performed by drones.',
                   '1', '1', '0']

# Fuel burned by each action (#324 step 8), by the size of the job: 1 to 10
# units, once per action whatever its multiplier. Unpacking the starter kit
# is free. Each protocol's description says what it burns.
def fuel_cost(pid):
    p = int(pid)
    if p in (25901, int(POWER_PROTOCOL), 33, 35, int(WAIT_PROTOCOL)):
        return 0
    if p == int(LAUNCH_PROTOCOL):
        return 10 + LAUNCH_FUEL           # 10 burned, 20 for the clone's tank
    if p == 5001:
        return 5                      # travel
    if 11000 <= p < 12000:
        return 1                      # mining (#324 step 15: 2 made a simple miner lose money)
    if 21000 <= p < 22000 or 12000 <= p < 13000:
        return 2                      # refining, alloys, organics, polymers, fuel
    if 13000 <= p < 14000:
        return 3                      # parts
    if 14000 <= p < 15000:
        return 4                      # systems
    if 15000 <= p < 16000:
        return 6                      # modules
    if 16000 <= p < 17000:
        return 8                      # ship kits
    return 1                          # dump, orders, push, pull, import, export


for pid in sorted(protocols, key=int):
    cost = fuel_cost(pid)
    if cost == 0:
        continue
    add_effects(pid, [{'time': 0, 'drone': 0, 'resource': FUEL, 'event_type': 2, 'local': 1, 'installed': 0,
                       'locked': 0, 'deplete': 1, 'abundancies': 0, 'quantity': cost}])
    p = protocols[pid]
    tail = f' Burns {cost} fuel.'
    if len(p[4]) + len(tail) <= 255:
        protocols[pid] = p[:4] + [p[4] + tail] + p[5:]

# Power (#324 step 8): the game engine performs [N 2 perform] itself to burn
# 1 fuel per POWER_INSTRUCTIONS instructions a drone has run. If the fuel
# isn't there, the drone becomes a wreck.
protocols[POWER_PROTOCOL] = [
    POWER_PROTOCOL, 'Burn power', '1', '100',
    f'[N 2 perform]: Burns N fuel to power the drone\'s computer. The engine does this for every drone, '
    f'1 fuel per {POWER_INSTRUCTIONS:,} instructions; a drone that can\'t pay becomes a wreck.',
    '0', '0', '0']
add_effects(POWER_PROTOCOL, [{'time': 0, 'drone': 0, 'resource': FUEL, 'event_type': 2, 'local': 1,
                              'installed': 0, 'locked': 0, 'deplete': 1, 'abundancies': 0, 'quantity': -1}])

# Location and market effects: only for protocols we keep
loc_eff = [r for r in econ['Location Effects'][1:] if r and num(r[1]) in protocols]
mkt_eff = [r for r in econ['Market Effects'][1:] if r and num(r[1]) in protocols]
# The double-price orders (17, 18) have the same market effects as 7 and 8
next_id = max(int(num(r[0])) for r in mkt_eff) + 1
for old, new in (('7', '17'), ('8', '18')):
    for r in [r for r in mkt_eff if num(r[1]) == old]:
        mkt_eff.append([str(next_id), new] + list(r[2:]))
        next_id += 1

# --- the map: asteroids, comets, rings, and corrected planet orbits ----------------

import csv
import math

AU_KM = 149597870.7
GAUSS_DEG_PER_DAY = 0.9856076686           # Sun's mean motion constant (Gauss)
PLANET_GM_KM3_S2 = {5: 126686534.0, 6: 37931187.0, 7: 5793939.0, 8: 6836529.0}  # JPL
# JPL "Keplerian elements for approximate positions of the major planets"
# (Standish), J2000: semi-major axes in au. exmachinis_tables.sql has these
# rounded (Earth 150,000,000 km), and stores the longitude of perihelion and
# the mean longitude where the engine expects the argument of periapsis and
# the mean anomaly. The engine places bodies with the standard Kepler
# equations, so convert: w = longitude of perihelion - node, M = L - longitude of perihelion.
PLANET_A_AU = {1: 0.38709927, 2: 0.72333566, 3: 1.00000261, 4: 1.52371034, 5: 5.20288700,
               6: 9.53667594, 7: 19.18916464, 8: 30.06992276, 9: 39.48211675}


def mean_motion(a_km, central):
    if central == 0:
        return GAUSS_DEG_PER_DAY / (a_km / AU_KM) ** 1.5
    gm = PLANET_GM_KM3_S2[central]
    return math.degrees(math.sqrt(gm / a_km ** 3)) * 86400


objects_sql = open(os.path.join(REPO, 'game-engine', 'db-backup', 'exmachinis_tables.sql'), encoding='utf-8').read()
obj_insert = re.search(r'INSERT INTO `objects` VALUES (.*?);\n', objects_sql, re.S).group(1)
planet_rows = {}
for m in re.finditer(r"\((\d+),'((?:[^'\\]|\\.)*)','Planet',[^,]*,[^,]*,'[^']*',[^,]*,[^,]*,([^,]*),([^,]*),[^,]*,([^,]*),", obj_insert):
    planet_rows[int(m.group(1))] = (m.group(2), float(m.group(3)), float(m.group(4)), float(m.group(5)))
planet_updates = []
for pid, (name, lon_peri, mean_lon, node) in sorted(planet_rows.items()):
    a_km = PLANET_A_AU[pid] * AU_KM
    w = (lon_peri - node) % 360
    M = (mean_lon - lon_peri) % 360
    planet_updates.append((pid, name, round(a_km), round(w, 6), round(M, 6), round(mean_motion(a_km, 0), 9)))

new_bodies = []
with open(os.path.join(HERE, 'bodies.csv'), encoding='utf-8') as f:
    for row in csv.DictReader(line for line in f if not line.startswith('#')):
        central = int(row['central_body'])
        a_km = float(row['a']) * (AU_KM if row['unit'] == 'au' else 1)
        new_bodies.append((int(row['id']), row['name'], row['type'], None, central, row['epoch'],
                           round(a_km, 1), float(row['e']), float(row['w']), float(row['M']),
                           float(row['i']), float(row['node']), round(mean_motion(a_km, central), 9)))

# --- abundances ------------------------------------------------------------------

bodies = defaultdict(list)
for m in re.finditer(r"\((\d+),'((?:[^'\\]|\\.)*)','([^']*)'", obj_insert):
    bodies[m.group(3)].append(int(m.group(1)))
for b in new_bodies:
    bodies[b[2]].append(b[0])
abundances = []
for rid, tag in sorted(mineral_tags.items(), key=lambda kv: int(kv[0])):
    kind = MINERAL_BODY_TYPES.get(tag)
    targets = bodies.get(kind, [])
    if not targets:
        note(f'mineral {rid} "{resources[rid]["name"]}" is found on {tag}s, but the map has none: not minable')
    for obj in targets:
        abundances.append((obj, int(rid), DEFAULT_ABUNDANCE))

# Mining deposits (#324 step 11): every place a mineral is found holds a
# deposit of it, which runs out and refills slowly. Placeholders, to tune in
# step 20: a mining run yields its abundance (5 units), a deposit holds 40
# runs' worth and regains 8 runs' worth a game day. #367 made them ten times
# bigger: at 40 and 8, three moons fed only a few dozen drones.
DEPOSIT_RUNS, REFILL_RUNS_PER_DAY = 400, 80
deposits = [(obj, rid, mult * DEPOSIT_RUNS, mult * REFILL_RUNS_PER_DAY) for obj, rid, mult in abundances]

# --- queries ----------------------------------------------------------------------

econ_q = {}
for r in econ['Queries'][1:]:
    r = r + [''] * 5
    if num(r[0]).isdigit():
        econ_q[num(r[0])] = {'name': r[1], 'description': r[2], 'parameters': int(num(r[3]) or 0),
                             'script': ' '.join(r[4].split())}

LATEST_ORDER = ("SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type = {t} "
                "AND resource = [value_1] GROUP BY drone")
# Double-cell money (#324 step 9): a column whose name starts with d_ comes
# back as two cells (high cell first, so 2@ reads it); others as one
ORDER_COLS = "o.new_credits AS d_price, o.new_quantity, i.units, o.drone"
HERE_ = "(SELECT object_id FROM agents WHERE agent_id = [drone_id])"


def orders(event_type, local, resource='[value_1]', extra=''):
    # Sell orders (4) are open while stock is above the kept minimum; buy
    # orders (5) while stock is below the wanted maximum.
    cmp, order = ('>', 'ASC') if event_type == 4 else ('<', 'DESC')
    where = f"i.units {cmp} o.new_quantity"
    join_here = f" INNER JOIN agents AS a ON a.agent_id = o.drone AND a.object_id = {HERE_}" if local else ''
    stock = f"LEFT JOIN sqInventory AS i ON i.drone = o.drone AND i.resource = {resource}"
    if event_type == 5:
        where = "IFNULL(i.units, 0) < o.new_quantity"
        cols = ORDER_COLS.replace('i.units', 'IFNULL(i.units, 0)')
    else:
        cols = ORDER_COLS
    latest = LATEST_ORDER.format(t=event_type).replace('[value_1]', resource)
    return (f"SELECT {cols} FROM events AS o INNER JOIN ({latest}) AS l ON l.id = o.id"
            f"{join_here} {stock} WHERE {where}{extra} ORDER BY o.new_credits {order};")


EVENT_COL = "SELECT {c} FROM events WHERE outcome = 1 AND id = [value_1];"
# Query SQL. Taken from economy.xlsx unless replaced here (with the reason).
QUERY_SQL = {
    # economy.xlsx used the last move event, so a drone that never moved had no location
    '202': (1, "SELECT object_id FROM agents WHERE agent_id = [value_1];"),
    # economy.xlsx picked the latest event by timestamp; ties gave duplicates or stale rows
    '204': (1, "SELECT IFNULL(SUM(i.units * r.mass), 0) FROM sqInventory AS i INNER JOIN resources AS r "
               "ON r.id = i.resource WHERE i.drone = [value_1];"),
    '210': (1, "SELECT resource, units FROM sqInventory WHERE drone = [value_1] AND units > 0 ORDER BY units DESC;"),
    # economy.xlsx's version was cut off and didn't filter by drone
    '212': (1, "SELECT IFNULL(MAX(units), 0) FROM sqInventory WHERE drone = [drone_id] AND resource = [value_1];"),
    # Companies (#324 step 4): a player can own many companies, each with its
    # own bank account; agents.company_id is a drone's company
    '100': (1, "SELECT name FROM companies WHERE company_id = [value_1];"),
    '101': (1, "SELECT credits AS d_credits FROM companies WHERE company_id = [value_1];"),
    '110': (1, "SELECT agent_id FROM agents WHERE company_id = [value_1] ORDER BY agent_id;"),
    '201': (1, "SELECT company_id FROM agents WHERE agent_id = [value_1];"),
    '600': (1, orders(5, True)), '601': (1, orders(5, False)),
    '610': (1, orders(4, True)), '611': (1, orders(4, False)),
    '800': (1, "SELECT id FROM events WHERE outcome = 1 AND drone = [value_1] ORDER BY game_t DESC, id DESC;"),
    '801': (0, "SELECT e.id FROM events AS e INNER JOIN agents AS a ON a.agent_id = e.drone WHERE e.outcome = 1 "
               f"AND a.object_id = {HERE_} ORDER BY e.game_t DESC, e.id DESC;"),
    '802': (0, "SELECT id FROM events WHERE outcome = 1 ORDER BY game_t DESC, id DESC;"),
    '811': (1, "SELECT t.name FROM events AS e INNER JOIN event_types AS t ON t.id = e.event_type "
               "WHERE e.outcome = 1 AND e.id = [value_1];"),
    '820': (1, "SELECT a.drone FROM events AS e INNER JOIN actions AS a ON a.id = e.action "
               "WHERE e.outcome = 1 AND e.id = [value_1];"),
    '821': (1, "SELECT a.protocol FROM events AS e INNER JOIN actions AS a ON a.id = e.action "
               "WHERE e.outcome = 1 AND e.id = [value_1];"),
    '832': (1, EVENT_COL.format(c='resource')),
    '840': (1, EVENT_COL.format(c='new_quantity')),
    '841': (1, EVENT_COL.format(c='new_credits AS d_credits')),
    '842': (1, EVENT_COL.format(c='new_location')),
    '843': (1, EVENT_COL.format(c='FROM_UNIXTIME(game_t)')),
}
QUERY_DESC = {  # for queries without a description in either spreadsheet
    '202': "Returns the location ID of spacecraft N.",
    '204': "Returns the total cargo mass in kg of spacecraft N.",
    '210': "Lists the resources and quantities in the cargo hold of spacecraft N.",
    '212': "Returns how many units of resource N are in the requesting spacecraft's cargo hold.",
    '600': "Lists buy orders for resource N at the requesting spacecraft's location: price, wanted maximum, units held, spacecraft ID.",
    '601': "Lists buy orders for resource N anywhere in the solar system: price, wanted maximum, units held, spacecraft ID.",
    '610': "Lists sell orders for resource N at the requesting spacecraft's location: price, kept minimum, units held, spacecraft ID.",
    '611': "Lists sell orders for resource N anywhere in the solar system: price, kept minimum, units held, spacecraft ID.",
    '800': "Lists the most recent events that affected spacecraft N.",
    '801': "Lists the most recent events at the requesting spacecraft's location.",
    '802': "Lists the most recent events anywhere in the solar system.",
    '811': "Returns the name of the type of event N.",
    '820': "Returns the ID of the spacecraft whose action caused event N.",
    '821': "Returns the ID of the protocol whose action caused event N.",
    '832': "Returns the ID of the resource affected by event N.",
    '840': "Returns the new quantity set by event N.",
    '841': "Returns the new credits set by event N.",
    '842': "Returns the new location set by event N.",
    '843': "Returns when event N happened: its game day (day 0 is the world's first) and the even seconds into that day.",
}

queries = []
sheet_ids = []
category = {}
for r in qsheet[1:]:
    r = r + [''] * 7
    qid = num(r[2])
    if not qid.isdigit():
        continue
    sheet_ids.append(qid)
    category[qid] = r[0].strip()
    name = r[3].strip()
    if qid in QUERY_SQL:
        params, script = QUERY_SQL[qid]
    elif qid in econ_q and econ_q[qid]['script']:
        params, script = econ_q[qid]['parameters'], econ_q[qid]['script']
    elif r[5].strip():
        params, script = 1, ' '.join(r[5].split())
        note(f'query {qid}: SQL from queries.xlsx; assumed 1 parameter')
    else:
        note(f'query {qid} "{name}": no SQL anywhere, left out')
        continue
    script = script.replace('exmachinis.', '')
    usage = ('N ' if params == 1 else ''.join(f'N{i + 1} ' for i in range(params))) + f'R L {qid} query'
    desc = QUERY_DESC.get(qid) or re.sub(r'^\[[^\]]*\]:\s*', '', econ_q.get(qid, {}).get('description', '')) or r[4].strip()
    if desc and not desc.endswith('.'):
        desc += '.'
    queries.append((int(qid), name[:45], f'[{usage}]: {desc}'[:255], params, script))
for qid in sorted(set(econ_q) - set(sheet_ids), key=int):
    params, script = QUERY_SQL.get(qid, (econ_q[qid]['parameters'], econ_q[qid]['script']))
    desc = QUERY_DESC.get(qid) or re.sub(r'^\[[^\]]*\]:\s*', '', econ_q[qid]['description'])
    queries.append((int(qid), econ_q[qid]['name'][:45],
                    f'[{"N " * params}R L {qid} query]: {desc}'[:255], params, script.replace('exmachinis.', '')))
    note(f'query {qid} "{econ_q[qid]["name"]}" is only in economy.xlsx: kept')
# The advanced events query (#149): events this spacecraft has observed (as
# acting/affected drone or once their light has reached it: the observations
# table; locally: where it is, since it arrived), filtered by up to nine conditions; a negative value means
# "any". Pushed in this order, so the last one pushed is [value_1].
EVENT_FILTERS = [  # (letter, meaning, SQL condition on [value_n])
    ('A', 'acting spacecraft', 'a.drone={v}'),
    ('P', 'protocol', 'a.protocol={v}'),
    ('T', 'event type', 'e.event_type={v}'),
    ('M', 'at least M game minutes old', '[game_now]-e.game_t>={v}*60'),
    ('D', 'affected spacecraft', 'e.drone={v}'),
    ('N', 'resource', 'e.resource={v}'),
    ('X', 'depleted (1) or added (0)', '(e.event_type=2)=({v}<>0)'),
    ('K', 'locked (1) or not (0)', 'e.locked=({v}<>0)'),
    ('O', "at location O (the affected spacecraft's current location)", 'g.object_id={v}'),
]
conditions = ' '.join(f'AND ({{v}}<0 OR {c})'.format(v=f'[value_{len(EVENT_FILTERS) - i}]')
                      for i, (_, _, c) in enumerate(EVENT_FILTERS))
# Seen: an observation row (the acting or affected drone, or the light has
# reached it), or the event happened where the drone is, since it arrived
# (#324 step 20: local sightings are worked out here, not stored per drone)
script = ('SELECT e.id FROM events e LEFT JOIN actions a ON a.id=e.action LEFT JOIN agents g ON g.agent_id=e.drone '
          'WHERE e.outcome=1 AND (EXISTS(SELECT 1 FROM observations o WHERE o.event=e.id AND o.drone=[drone_id] '
          'AND o.game_t<=[game_now]) OR EXISTS(SELECT 1 FROM agents me WHERE me.agent_id=[drone_id] '
          'AND e.place=me.object_id AND e.game_t>=me.arrived_t AND e.game_t<=[game_now])) '
          f'{conditions} ORDER BY e.game_t DESC,e.id DESC;')
usage = ' '.join(letter for letter, _, _ in EVENT_FILTERS) + ' R L 803 query'
queries.append((803, 'Search observed events',
                f'[{usage}]: Lists the events this spacecraft has observed, newest first, that match A P T M D N X K O '
                '(see the manual); -1 means any.', len(EVENT_FILTERS), script))

# Market volume (#249): units and credits that changed hands in trades of
# resource N over the last H game hours, and how many trades, from the trades
# table the events engine fills. [value_1] is H (pushed last), [value_2] N.
VOLUME = ("SELECT IFNULL(SUM(units),0) AS d_units, IFNULL(SUM(credits),0) AS d_credits, COUNT(*) FROM trades "
          "WHERE resource=[value_2] "
          "AND [game_now]-game_t<=[value_1]*3600{where};")
queries.append((620, 'Local market volume',
                '[N H R L 620 query]: Units of resource N traded at the requesting spacecraft\'s location in the last '
                'H game hours, credits paid for them, and number of trades.', 2,
                VOLUME.format(where=f' AND location={HERE_}')))
queries.append((621, 'Solar market volume',
                '[N H R L 621 query]: Units of resource N traded anywhere in the solar system in the last H game hours, '
                'credits paid for them, and number of trades.', 2, VOLUME.format(where='')))

# A double-cell input (#324 step 9): [dvalue_1] is the double pushed last
# (its high cell is parameter 1, its low cell parameter 2); N is parameter 3
queries.append((612, 'Solar sell orders up to a price',
                '[N P R L 612 query]: Sell orders for resource N anywhere in the solar system at a price of at most P '
                '(a double, e.g. 7 10000 um* for 70,000), cheapest first: price (2 cells), kept minimum, stock, drone.', 3,
                orders(4, False, resource='[value_3]', extra=' AND o.new_credits <= [dvalue_1]')))

# House drop stations (#324 step 10), placeholders to tune in step 20.
# (id, name, object, budget cap, refill per game day). The Mars Exchange
# has three times the others' (#378): two thirds of the starter miners and
# refiners sell there, and every trader.
STATIONS = [
    (1, 'Earth Exchange', 3, 10000, 10000),
    (2, 'Moon Depot', 10, 10000, 10000),
    (3, 'Mars Exchange', 4, 15000, 15000),
]
BUYS, SELLS = 1, 2
# (station, resource, side, price, daily units, from game day): stations buy
# refined goods from day 0, parts from day 3 and systems from day 7, so their
# demand steps up the production chain; they sell basic equipment, and the
# Earth Exchange is the House fuel seller (5 credits, 2,000 units a day).
# Step 15 doubled the refined metals (10, 15, 20 left a simple Moon miner
# losing money) and has the far Mars Exchange pay half as much again for
# them, so hauling metals there pays.
METALS = ((2011, 20, 200), (2012, 30, 150), (2013, 40, 100))
STATION_GOODS = []
for st in (1, 2):
    for rid, price, demand in METALS + ((2031, 8, 200),
                               (2041, 12, 200), (2021, 30, 100), (2022, 40, 80), (2023, 50, 60),
                               (2051, 30, 100)):
        STATION_GOODS.append((st, rid, BUYS, price, demand, 0))
for rid, price, demand in METALS:
    STATION_GOODS.append((3, rid, BUYS, price * 3 // 2, demand, 0))
# By-products nobody else wants (#380): refining vladnium leaves Inorganics
# (A2), and a hold that fills up with it can't brew fuel. Earth and the Moon
# take them for a little, so every product has a buyer.
for st in (1, 2):
    for rid, price in ((2032, 10), (2033, 12), (2042, 15), (2043, 18)):
        STATION_GOODS.append((st, rid, BUYS, price, 200, 0))
# Growth (#380): every exchange sells Mining Cruiser kits (a hull) and
# Starter Kit I (its equipment), so a company with money to spare can
# launch a clone of its drone: buy both, launch, hand the clone the
# starter kit and some fuel. The kit price is the launch fee.
for st in (1, 2, 3):
    STATION_GOODS.append((st, 6001, SELLS, 3000, 24, 0))
    STATION_GOODS.append((st, 5901, SELLS, 2000, 24, 0))
# The Mars Exchange buys the chemists' goods too (#380), so they can work
# Phobos and Deimos instead of crowding the Moon's yalspar
for rid, price, demand in ((2031, 8, 200), (2041, 12, 200), (2051, 30, 100),
                           (2032, 10, 200), (2033, 12, 200), (2042, 15, 200), (2043, 18, 200)):
    STATION_GOODS.append((3, rid, BUYS, price, demand, 0))
# Parts and systems pay half as much again as they did (#380): at 60 a
# rod, making one from two metals and 5 fuel barely paid, and nobody made
# any. At 90 the Smith design became the best earner per game day.
for st in (1, 3):
    for rid, price in ((3001, 90), (3011, 90), (3031, 75), (3311, 105)):
        STATION_GOODS.append((st, rid, BUYS, price, 50, 3))
    for rid in (4111, 4281, 4311):
        STATION_GOODS.append((st, rid, BUYS, 450, 10, 7))
for rid, price, supply in ((FUEL, 5, 2000), (5001, 400, 10), (5131, 300, 10), (5201, 500, 10),
                           (5211, 600, 10), (5501, 900, 5)):
    STATION_GOODS.append((1, rid, SELLS, price, supply, 0))
# Fuel at every House exchange (#367), not only on Earth; the busy Mars
# Exchange sells 6,000 a day (#378)
for st, supply in ((2, 2000), (3, 6000)):
    STATION_GOODS.append((st, FUEL, SELLS, 5, supply, 0))
for st, rid, _, _, _, _ in STATION_GOODS:
    if str(rid) not in resources:
        note(f'station {st}: unknown resource {rid}')

HERE_STATION = f"(SELECT id FROM stations WHERE object_id = {HERE_})"
TODAY = "FLOOR(([game_now] - (SELECT epoch FROM game_clock WHERE id = 1)) / 86400)"
queries.append((630, 'Station trades here',
                '[R L 630 query]: What the House station where the drone is trades now: resource, side (1: it buys, '
                '2: it sells), price (2 cells; buying: for an empty batch, halving per daily units), daily units, '
                'units waiting in this batch (2 cells).', 0,
                "SELECT g.resource, g.side, g.price AS d_price, g.daily_units, IFNULL((SELECT SUM(o.units) "
                "FROM station_offers o WHERE o.station_id = g.station_id AND o.resource = g.resource AND o.side = g.side "
                "AND o.cleared_t IS NULL), 0) AS d_units FROM station_goods g WHERE g.station_id IN "
                f"{HERE_STATION} AND g.from_day <= {TODAY} ORDER BY g.side, g.resource;"))
queries.append((631, 'My station offers',
                '[R L 631 query]: This drone\'s station offers and orders, newest first: resource, side (1: offer, '
                '2: order), units, units filled (-1 until its batch clears), credits (2 cells).', 0,
                "SELECT resource, side, units, IFNULL(filled, -1), IFNULL(paid, 0) AS d_paid FROM station_offers "
                "WHERE drone = [drone_id] ORDER BY id DESC;"))
queries.append((632, 'Station budget here',
                '[R L 632 query]: The credits the House station where the drone is has left for buying (2 cells).', 0,
                "SELECT IFNULL(t.budget, s.budget_cap) AS d_budget FROM stations s LEFT JOIN station_state t "
                f"ON t.station_id = s.id WHERE s.object_id = {HERE_};"))

# Mining deposits here (#324 step 11): left now (refilled for the time since
# it was last worked out), and capacity
# Taxes (#324 step 12; #367): a company pays tax_flat plus tax_permille
# thousandths of what it made (its assessed wealth, credits and goods aboard
# its working drones at the House price, less its starter package: the
# company_taxable view) every tax_period, after a grace period. Placeholders:
# 0.5% a game day and no flat part, after 3 game days; a plunderer gets half
# a wreck. The starter package (1,000 credits and 100 fuel, and the kit)
# must match config/forge.php.
HOUSE_RULES = [(1, DAY, 3 * DAY, 0, 5, 50, 1000, 100)]
MY_COMPANY = "(SELECT company_id FROM agents WHERE agent_id = [drone_id])"
queries.append((650, 'My taxes',
                '[R L 650 query]: The drone\'s company\'s next tax bill (2 cells), game hours until it is due, and '
                'game hours of grace left (no tax until then). A company that can\'t pay is eliminated.', 0,
                "SELECT h.tax_flat + FLOOR(h.tax_permille * (SELECT IFNULL(w.taxable, 0) FROM company_taxable w "
                "WHERE w.company_id = c.company_id) / 1000) AS d_tax, "
                "GREATEST(0, CEIL((IFNULL(c.taxed_t, [game_now]) + h.tax_period - [game_now]) / 3600)), "
                "GREATEST(0, CEIL((IFNULL(c.founded_t, [game_now]) + h.tax_grace - [game_now]) / 3600)) "
                f"FROM companies c JOIN house_rules h ON h.id = 1 WHERE c.company_id = {MY_COMPANY};"))
queries.append((660, 'Wrecks here',
                '[R L 660 query]: The wrecks where the drone is: drone ID, 1 if it has been plundered (else 0).', 0,
                "SELECT agent_id, plundered_at IS NOT NULL FROM agents WHERE wrecked_at IS NOT NULL "
                f"AND object_id = {HERE_} AND agent_id <> [drone_id] ORDER BY agent_id;"))
queries.append((661, 'Wreck hold',
                '[W R L 661 query]: What is aboard the wreck W where the drone is: resource, units.', 1,
                "SELECT i.resource, i.units FROM sqInventory i JOIN agents w ON w.agent_id = i.drone "
                f"WHERE i.drone = [value_1] AND w.wrecked_at IS NOT NULL AND w.object_id = {HERE_} AND i.units > 0 "
                "ORDER BY i.resource;"))

# Cloning (#324 step 13): a drone's clones and its own line
queries.append((670, 'My clones',
                '[R L 670 query]: The drones this drone launched, oldest first: drone ID, 1 if it is a wreck (else 0).', 0,
                "SELECT agent_id, wrecked_at IS NOT NULL FROM agents WHERE parent_id = [drone_id] ORDER BY agent_id;"))
queries.append((671, 'My lineage',
                '[R L 671 query]: The drone that launched this one (0 if none), and its generation (0 for a drone '
                'released or registered, 1 for its clones, and so on).', 0,
                "SELECT IFNULL(parent_id, 0), generation FROM agents WHERE agent_id = [drone_id];"))

queries.append((640, 'Deposits here',
                '[R L 640 query]: The mining deposits where the drone is: resource, units left now (2 cells), '
                'capacity (2 cells), units regained per game day. A mined-out deposit yields nothing until it refills.', 0,
                "SELECT d.resource, FLOOR(LEAST(d.capacity, IFNULL(s.amount, d.capacity) + d.refill_per_day * "
                "([game_now] - IFNULL(s.refilled_t, [game_now])) / 86400)) AS d_units, d.capacity AS d_capacity, "
                "d.refill_per_day FROM deposits d LEFT JOIN deposit_state s ON s.object_id = d.object_id "
                f"AND s.resource = d.resource WHERE d.object_id = {HERE_} ORDER BY d.resource;"))

# Local information (#324 step 14): a drone sees live market orders only
# where it is. The solar order lists (601, 611, 612) now list the orders at
# the drone's location, like 600 and 610; elsewhere, the public price board
# (680) and solar volume (621) give trades as they stood an hour ago.
BOARD_LAG = HOUR
LOCAL_NOTE = ' Only where the drone is: for elsewhere, see the price board (680).'
local_queries = {
    601: ('List buy orders here', '[N R L 601 query]: Lists buy orders for resource N where the drone is (like 600): price, '
          'wanted maximum, units held, spacecraft ID.' + LOCAL_NOTE, 1, orders(5, True)),
    611: ('List sell orders here', '[N R L 611 query]: Lists sell orders for resource N where the drone is (like 610): price, '
          'kept minimum, units held, spacecraft ID.' + LOCAL_NOTE, 1, orders(4, True)),
    612: ('Sell orders here up to a price',
          '[N P R L 612 query]: Sell orders for resource N where the drone is at a price of at most P (a double, e.g. '
          '7 10000 um* for 70,000), cheapest first: price (2 cells), kept minimum, stock, drone.', 3,
          orders(4, True, resource='[value_3]', extra=' AND o.new_credits <= [dvalue_1]')),
    621: ('Solar market volume, an hour old',
          '[N H R L 621 query]: Units of resource N traded anywhere in the solar system in the H game hours up to one '
          'game hour ago, credits paid for them, and number of trades.', 2,
          "SELECT IFNULL(SUM(units),0) AS d_units, IFNULL(SUM(credits),0) AS d_credits, COUNT(*) FROM trades "
          f"WHERE resource=[value_2] AND game_t<=[game_now]-{BOARD_LAG} AND [game_now]-{BOARD_LAG}-game_t<=[value_1]*3600;"),
}
queries = [(q[0],) + local_queries[q[0]] if q[0] in local_queries else q for q in queries]
queries.append((680, 'Price board',
                '[N R L 680 query]: The public price board for resource N: for each place where it traded in the game '
                'day up to one game hour ago, the place, units (2 cells), credits paid (2 cells) and trades.', 1,
                "SELECT location, IFNULL(SUM(units),0) AS d_units, IFNULL(SUM(credits),0) AS d_credits, COUNT(*) "
                f"FROM trades WHERE resource=[value_1] AND location IS NOT NULL AND game_t<=[game_now]-{BOARD_LAG} "
                f"AND game_t>[game_now]-{BOARD_LAG}-{DAY} GROUP BY location ORDER BY location;"))

# Queries whose money or volume columns are double cells: say so
DOUBLE_QUERIES = {101, 600, 601, 610, 611, 612, 620, 621, 841}  # 630-632 say so themselves
queries = [(q[0], q[1], (q[2] if q[0] == 612 else q[2].rstrip() + ' Money and volumes are double cells (2@).')[:255],
            q[3], q[4]) if q[0] in DOUBLE_QUERIES else q for q in queries]

queries.sort()
for q in queries:
    if len(q[4]) > 2000:
        note(f'query {q[0]}: SQL is {len(q[4])} characters (the column holds 2000)')

# --- write ------------------------------------------------------------------------

lines = [
    '-- Ex Machinis game data: resources, protocols, their effects, queries and',
    '-- where minerals can be mined. GENERATED by game-data/build_game_data.py from',
    '-- game-data/resources.xlsx, game-data/queries.xlsx and documents/economy.xlsx.',
    '-- Do not edit by hand: change a spreadsheet and run the script again.',
    '--',
    '-- Loading it replaces these tables. Run it on a new database after',
    '-- create_db.sql and db-backup/exmachinis_tables.sql.',
    '',
    'SET FOREIGN_KEY_CHECKS = 0;',
    '-- An effect\'s quantity can name a stack parameter (-1, -2...): older',
    '-- databases have the column unsigned',
    'ALTER TABLE `resource_effects` MODIFY `quantity` INT NULL;',
    "-- Query 803's SQL is longer than the old 512-character column",
    'ALTER TABLE `queries` MODIFY `script` VARCHAR(2000) NOT NULL;',
    '-- What each drone holds: the sum over each state (installed, locked) of',
    '-- its latest inventory event (same as create_db.sql, for older databases)',
    'CREATE OR REPLACE VIEW `sqInventory` AS',
    'SELECT e.`drone` AS `drone`, e.`resource` AS `resource`, CAST(SUM(e.`new_quantity`) AS SIGNED) AS `units`',
    'FROM `events` e',
    'JOIN (',
    '  SELECT MAX(`id`) AS `id`',
    '  FROM `events`',
    '  WHERE `outcome` = 1 AND `event_type` IN (1, 2)',
    '  GROUP BY `drone`, `resource`, `installed`, `locked`',
    ') latest ON latest.`id` = e.`id`',
    'GROUP BY e.`drone`, e.`resource`;',
    'START TRANSACTION;',
    '',
]


def table(name, cols, rows):
    lines.append(f'DELETE FROM `{name}`;')
    for i in range(0, len(rows), 200):
        chunk = rows[i:i + 200]
        lines.append(f'INSERT INTO `{name}` ({", ".join(cols)}) VALUES')
        lines.append(',\n'.join('  (' + ', '.join(sql(v) for v in row) + ')' for row in chunk) + ';')
    lines.append('')


# Flat-packed kits (#380): a working drone's equipment takes 310 of its 500
# cargo, so a Starter Kit I (375) or a Mining Cruiser kit (224) could never
# be bought and carried to launch a clone. Packed, each takes 40.
for rid, mass in (('5901', 40), ('6001', 40)):
    if rid in resources:
        resources[rid]['mass'] = mass
table('resources', ['id', 'name', 'description', 'mass', 'capacity', 'slot_size'],
      [(r['id'], r['name'], r['description'], r['mass'], r['capacity'], r['slot_size'])
       for r in sorted(resources.values(), key=lambda r: r['id'])])
table('protocols', ['id', 'name', 'parameters', 'bulk_modifier', 'description', 'observable', 'reportable', 'multiplier',
                    'double_params'],
      [(int(p[0]), p[1][:45], int(num(p[2]) or 0), int(num(p[3]) or 100), p[4][:255],
        int(num(p[5]) or 0), int(num(p[6]) or 0), int(num(p[7]) or 0), DOUBLE_PARAMS.get(str(p[0]), 0))
       for p in sorted(protocols.values(), key=lambda p: int(p[0]))])
effect_rows = []
for n, (pid, e) in enumerate(sorted(effects, key=lambda pe: (pe[0], pe[1]['time'], pe[1]['event_type'])), start=1):
    effect_rows.append((n, e['drone'], e['resource'], pid, e['event_type'], e['local'], e['installed'],
                        e['locked'], e['deplete'], e['abundancies'], e['quantity'], e['time']))
# Hulls: each ship kit (16xxx makes kit 6xxx) launches a drone whose hull has
# the kit's ID. Upserted, not replaced: existing drones use hulls 1 and 2.
HULL_SIZES = {  # cargo capacity, A-E slots
    'Cruiser': (500, 2, 2, 1, 0, 0),
    'Cutter': (2000, 3, 3, 2, 0, 0),
    'Frigate': (5000, 4, 3, 2, 1, 0),
    'Barge': (20000, 6, 4, 3, 2, 1),
}
hulls = []
for pid, rec in sorted(recipes.items(), key=lambda kv: int(kv[0])):
    if pid.startswith('16') and rec['product'] in resources:
        kit = resources[rec['product']]
        size = next((v for k, v in HULL_SIZES.items() if k in kit['name']), None)
        if size is None:
            note(f'ship kit {kit["id"]} "{kit["name"]}": no hull size in its name, no hull made')
            continue
        hulls.append((kit['id'], kit['name'], kit['description']) + size)
lines.append('-- Event types the engines handle (8: launch a drone, #238)')
lines.append("INSERT INTO `event_types` (id, name) VALUES (8, 'Launch drone') "
             'ON DUPLICATE KEY UPDATE name = VALUES(name);')
lines.append("INSERT INTO `event_types` (id, name) VALUES (9, 'Offer to station'), (10, 'Order from station') "
             'ON DUPLICATE KEY UPDATE name = VALUES(name);')
lines.append("INSERT INTO `event_types` (id, name) VALUES (12, 'Wait') "
             'ON DUPLICATE KEY UPDATE name = VALUES(name);')
lines.append("INSERT INTO `event_types` (id, name) VALUES (11, 'Plunder wreck') "
             'ON DUPLICATE KEY UPDATE name = VALUES(name);')
lines.append('')
lines.append('-- Taxes and plunder (#324 step 12), placeholders to tune in step 20')
table('house_rules', ['id', 'tax_period', 'tax_grace', 'tax_flat', 'tax_permille', 'plunder_share',
                      'start_credits', 'start_fuel'], HOUSE_RULES)
lines.append('')
lines.append('-- House drop stations (#324 step 10): what they trade. Their budgets')
lines.append('-- (station_state) are kept: a new station starts with a full budget.')
table('stations', ['id', 'name', 'object_id', 'budget_cap', 'refill_per_day'], STATIONS)
table('station_goods', ['station_id', 'resource', 'side', 'price', 'daily_units', 'from_day'], STATION_GOODS)
lines.append('INSERT IGNORE INTO `station_state` (station_id, budget, refilled_t) '
             'SELECT id, budget_cap, 0 FROM `stations`;')
lines.append('')
lines.append('-- Hulls of drones launched from ship kits (same ID as the kit)')
lines.append('INSERT INTO `hulls` (id, name, description, cargo_capacity, a_slots, b_slots, c_slots, d_slots, '
             'e_slots) VALUES')
lines.append(',\n'.join('  (' + ', '.join(sql(v) for v in h) + ')' for h in hulls))
lines.append('ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), '
             'cargo_capacity = VALUES(cargo_capacity), a_slots = VALUES(a_slots), b_slots = VALUES(b_slots), '
             'c_slots = VALUES(c_slots), d_slots = VALUES(d_slots), e_slots = VALUES(e_slots);')
lines.append('')
table('resource_effects', ['id', 'drone', 'resource', 'protocol', 'event_type', 'local', 'installed', 'locked',
                           'deplete', 'abundancies', 'quantity', 'time'], effect_rows)
table('location_effects', ['id', 'protocol', 'event_type', 'location', 'time'],
      [tuple(int(num(x)) for x in (r + [''] * 5)[:5]) for r in loc_eff])
table('market_effects', ['id', 'protocol', 'event_type', 'resource', 'upper_limit', 'quantity', 'price', 'time'],
      [tuple(int(num(x)) for x in (r + [''] * 8)[:8]) for r in mkt_eff])
lines.append('-- Asteroids, comets and rings (game-data/bodies.csv)')
lines.append('DELETE FROM `objects` WHERE object_id IN (%s);' % ', '.join(str(b[0]) for b in new_bodies))
lines.append('INSERT INTO `objects` (object_id, object_name, object_type, gravitational_parameter, '
             'central_body_object_id, epoch, semimajor_axis, eccentricity, periapsis_argument, mean_anomaly, '
             'inclination, ascending_node_longitude, mean_angular_motion) VALUES')
lines.append(',\n'.join('  (' + ', '.join(sql(v) if not isinstance(v, float) else repr(v) for v in b) + ')'
                        for b in new_bodies) + ';')
lines.append('')
lines.append('-- Planets: argument of periapsis and mean anomaly at J2000, precise semi-major axes')
for pid, name, a_km, w, M, n in planet_updates:
    lines.append(f'UPDATE `objects` SET semimajor_axis = {a_km}, periapsis_argument = {w}, mean_anomaly = {M}, '
                 f'mean_angular_motion = {n} WHERE object_id = {pid}; -- {name}')
lines.append('')
table('abundancies', ['location', 'resource', 'multiplier'], abundances)
lines.append('-- Mining deposits (#324 step 11): what each holds; how much is left')
lines.append('-- (deposit_state) is kept. A deposit with no state yet is full.')
table('deposits', ['object_id', 'resource', 'capacity', 'refill_per_day'], deposits)
table('queries', ['id', 'name', 'description', 'parameters', 'script'], queries)
lines += ['COMMIT;', 'SET FOREIGN_KEY_CHECKS = 1;', '']

with open(OUT, 'w', encoding='utf-8') as f:
    f.write('\n'.join(lines))

# The design manual's query appendix is written from the same data
MANUAL = os.path.join(REPO, 'documents', 'design-manual.md')
START, END = '<!-- queries:start', '<!-- queries:end -->'
manual = open(MANUAL, encoding='utf-8').read()
a = manual.index(START)
a = manual.index('\n', a) + 1
b = manual.index(END)
cell = lambda v: str(v).replace('|', '\\|').replace('\n', ' ')
table_md = ['| Category | Query ID | Name | Usage and description | Parameters | MySQL script |',
            '|:--|:--|:--|:--|:--|:--|']
for qid, name, desc, params, script in queries:
    table_md.append(f'| {cell(category.get(str(qid), "Spacecraft"))} | {qid} | {cell(name)} | {cell(desc)} '
                    f'| {params} | `{cell(script)}` |')
manual = manual[:a] + '\n'.join(table_md) + '\n' + manual[b:]
with open(MANUAL, 'w', encoding='utf-8') as f:
    f.write(manual)

print(f'Wrote {os.path.relpath(OUT, REPO)}: {len(resources)} resources, {len(protocols)} protocols, '
      f'{len(effect_rows)} effects, {len(loc_eff)} location effects, {len(mkt_eff)} market effects, '
      f'{len(new_bodies)} new bodies, {len(planet_updates)} planet orbit corrections, '
      f'{len(abundances)} abundances, {len(queries)} queries.')
if notes:
    print('\nAssumptions and gaps:')
    for n in notes:
        print(' -', n)
