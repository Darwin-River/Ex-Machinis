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
# Thinking burns no fuel since #544 (game-engine.conf POWER_INSTRUCTIONS 0):
# the company pays processor tax in credits instead (COMPUTE_TAX_PER_MILLION)
POWER_INSTRUCTIONS = 0
COMPUTE_TAX_PER_MILLION = 15    # credits a million instructions (#544)
DISSOLVE_DAYS = 50              # game days broke before a company dissolves (#547)
resources[str(FUEL)] = {
    'id': FUEL, 'name': 'Fuel (A1)', 'mass': 1, 'capacity': 0, 'slot_size': 0,
    'description': 'Rocket fuel. Every action burns some (travel, mining, making, transfers). '
                   'A drone that runs dry becomes a wreck. Thinking burns none: the company pays processor tax.'}

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

# --- specialised kits (#380) ----------------------------------------------------
# Mining and refining gear is big: a Worm (A1) and a Refinery (A1) take 120
# cargo each, so a 500-cargo drone carries either the mining set (Engine,
# Worm, Refinery: 296) or the factory set (Engine, Smelter, Chemical Reactor,
# Spider: 267), not both (563). Refining has its own module, the Refinery,
# instead of the Smelter. A company chooses its kit at release: a Mining Kit I
# (5901) or a Factory Kit I (5902); the House sells both. So makers buy what
# miners produce instead of mining it themselves.
ENGINE, WORM, REFINERY = 5001, 5131, 5221
MINING_KIT, FACTORY_KIT = 5901, 5902
BIG_GEAR_MASS = 120
KITS = {MINING_KIT: ('25901', 'Unpack Mining Kit I', (ENGINE, WORM, REFINERY)),
        FACTORY_KIT: ('25902', 'Unpack Factory Kit I', (ENGINE, 5201, 5211, 5501))}
# The Welder makes a Refinery from what a Smelter takes
if '15201' in recipes:
    recipes['15221'] = {'inputs': dict(recipes['15201']['inputs']), 'outputs': {str(REFINERY): 1},
                        'sheet': recipes['15201']['sheet'], 'product': str(REFINERY)}

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
        return REFINERY, 2 * HOUR                      # refining ore (#380: its own module)
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

# The kits' gear (#380; see "specialised kits" above)
resources[str(WORM)]['mass'] = BIG_GEAR_MASS
resources[str(REFINERY)] = {'id': REFINERY, 'name': 'Refinery (A1)', 'mass': BIG_GEAR_MASS, 'capacity': 0, 'slot_size': 0,
                            'description': 'A heavy refining module: refines ore into metals, organics and inorganics.'}
resources[str(MINING_KIT)] = dict(resources[str(MINING_KIT)], name='Mining Kit I',
    description='A packed mining set: Engine (A1), Worm (A1) and Refinery (A1). Unpack it with unpack() (protocol 25901).')
resources[str(FACTORY_KIT)] = {'id': FACTORY_KIT, 'name': 'Factory Kit I', 'mass': 40, 'capacity': 0, 'slot_size': 0,
    'description': 'A packed factory set: Engine (A1), Smelter (A1), Chemical Reactor (A1) and Spider (A1). '
                   'Unpack it with unpack() (protocol 25902).'}

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
        # Its own number (round 31: Mine mullinite's said 11310, Mine stellarite's)
        desc = re.sub(r'^\[\d+ perform\]', f'[{pid} perform]', desc)
        p = p[:4] + [f'{desc} Takes {game_duration(MINING_TIME)}.'] + p[5:]
    if pid in ('10', '11'):
        effs = [dict(e, time=TRANSFER_TIME) if e['time'] > 0 else e for e in effs]
        # The multiplier N is required (#315): describe it
        verb = {'10': 'Delivers N units of resource R to drone D.',
                '11': 'Pulls N units of resource R from drone D.'}[pid]
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
    '[D K 12 perform]: Builds a new drone (a clone) from ship kit K in the hold, beside this one, with drone D\'s '
    'program and 20 of this drone\'s fuel; it runs launched(). Takes 1 hour plus the light time from D.',
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

# House station drones (#380): the House trades as a drone, by the same
# rules as the players, from a Drop Station hull at each exchange. Two NPC
# modules nobody can make or buy let it bring goods up from the planet's
# surface and send them down: N R 5911 perform imports N units of R, N R
# 5921 perform exports them. They burn no fuel and take the quantity as a
# parameter (like 31), not the multiplier, which would scale the module
# check too.
IMPORT_STATION, EXPORT_STATION, DROP_STATION_HULL = 5911, 5921, 9001
resources[str(IMPORT_STATION)] = {'id': IMPORT_STATION, 'name': 'Import Station', 'mass': 0, 'capacity': 0,
    'slot_size': 0, 'description': 'A House module that brings goods up from the planet surface. Only House '
                                   'station drones have one: it is not made, sold or traded.'}
resources[str(EXPORT_STATION)] = {'id': EXPORT_STATION, 'name': 'Export Station', 'mass': 0, 'capacity': 0,
    'slot_size': 0, 'description': 'A House module that sends goods down to the planet surface. Only House '
                                   'station drones have one: it is not made, sold or traded.'}
for pid, module, name, verb, event_type in (('5911', IMPORT_STATION, 'Import resource', 'Brings N units of resource R up '
                                             'from the planet surface', 1),
                                            ('5921', EXPORT_STATION, 'Export resource', 'Sends N units of resource R '
                                             'down to the planet surface', 2)):
    protocols[pid] = [pid, name, '2', '100', f'[N R {pid} perform]: {verb}. House station drones only: it needs an '
                      f'{resources[str(module)]["name"]}, which players can never get. Takes 1 game second.',
                      '1', '1', '0']
    effects[:] = [(q, e) for q, e in effects if q != int(pid)]
    add_effects(pid, [
        {'time': 0, 'drone': 0, 'resource': module, 'event_type': 1, 'local': 1, 'installed': 0, 'locked': 0,
         'deplete': 0, 'abundancies': 0, 'quantity': 1},
        {'time': 0, 'drone': 0, 'resource': module, 'event_type': 2, 'local': 1, 'installed': 0, 'locked': 0,
         'deplete': 1, 'abundancies': 0, 'quantity': 1},
        {'time': 1, 'drone': 0, 'resource': -1, 'event_type': event_type, 'local': 1, 'installed': 0, 'locked': 0,
         'deplete': 1 if event_type == 2 else 0, 'abundancies': 0, 'quantity': -2},
    ])

# The two kits (#380): unpacking one uses it up and puts its modules aboard
for kit, (pid, name, parts) in KITS.items():
    words_ = ', '.join(resources[str(r)]['name'] for r in parts)
    protocols[pid] = [pid, name, '0', '100', f'[{pid} perform]: Unpacks a {resources[str(kit)]["name"]}: {words_}.',
                      '1', '1', '0']
    effects[:] = [(q, e) for q, e in effects if q != int(pid)]
    add_effects(pid, [{'time': 0, 'drone': 0, 'resource': r, 'event_type': 1, 'local': 1, 'installed': 0, 'locked': 0,
                       'deplete': 0, 'abundancies': 0, 'quantity': 1} for r in parts] +
                     [{'time': 0, 'drone': 0, 'resource': kit, 'event_type': 2, 'local': 1, 'installed': 0, 'locked': 0,
                       'deplete': 1, 'abundancies': 0, 'quantity': 1}])

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

# House stations (#324 step 10; #380): until #380 a drone offered goods to
# the House station where it was (31) or ordered them from it (32), and the
# hour's batch cleared them (event types 9 and 10; db_station.c clears what
# is left). Now the House trades through station drones, like any other
# drone: players push into their buy orders and pull from their sell orders
# (10 and 11; the game words sell-here and buy-here). 31 and 32 are gone.
STATION_OFFER_EVENT, STATION_ORDER_EVENT = 9, 10
# Posting an order isn't a sale or a purchase (#380 round 27: players read
# "Buy resource" in the activity list as a purchase): name them for what
# they do
for pid, name in (('7', 'Post sell order'), ('8', 'Post buy order'),
                  ('17', 'Post sell order (double price)'), ('18', 'Post buy order (double price)')):
    if pid in protocols:
        protocols[pid][1] = name
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

# Broadcasts (#380): [A 40 perform] posts the counted string at address A
# (1 to 80 printable characters, e.g. from $" ...") as a Broadcast event
# (type 13), observed like any event: by drones where it happens (803) and
# by the Earth database (804), at the speed of light. The game engine reads
# the message and keeps its text (broadcasts; query 844 by event). It burns
# a unit of fuel.
BROADCAST_PROTOCOL, BROADCAST_EVENT = '40', 13
protocols[BROADCAST_PROTOCOL] = [BROADCAST_PROTOCOL, 'Broadcast', '1', '100',
                                 '[A 40 perform]: Broadcasts the counted string at address A (1 to 80 printable '
                                 'characters) as an event, seen locally (query 803) and by the Earth database '
                                 '(804); its text: query 844.', '1', '1', '0']
add_effects(BROADCAST_PROTOCOL, [
    {'time': 0, 'drone': 0, 'resource': FUEL, 'event_type': BROADCAST_EVENT, 'local': 1, 'installed': 0,
     'locked': 0, 'deplete': 0, 'abundancies': 0, 'quantity': 0}])

# The share exchange at Earth (#469, the stock market #466). Shares trade
# the way goods do: a company posts standing orders (41 sell: while it holds
# more than it keeps; 42 buy: while it holds fewer than it wants), and
# another company's drone pulls shares from a sell order (43) or pushes
# them into a buy order (44), at the order's price and within its limits.
# Each request travels to Earth at the speed of light: its one effect is a
# Share event (type 14) when it arrives, which the events engine settles
# (db_shares.c). Each burns a unit of fuel.
SHARE_EVENT = 14
for pid, verb, args, what in (
        ('41', 'Post share sell order', 'C K P', 'posts a sell order for company C\'s shares at P credits each, '
         'selling while the drone\'s company holds more than K (P 0 withdraws it)'),
        ('42', 'Post share buy order', 'C W P', 'posts a buy order for company C\'s shares at P credits each, '
         'buying while the drone\'s company holds fewer than W (P 0 withdraws it)'),
        ('43', 'Pull shares', 'N C S', 'buys N of company C\'s shares from company S\'s sell order, at its price'),
        ('44', 'Push shares', 'N C B', 'sells N of company C\'s shares into company B\'s buy order, at its price')):
    protocols[pid] = [pid, verb, '3', '100',
                      f'[{args} {pid} perform]: {what}, at the Earth exchange (it gets there at the speed of light).',
                      '1', '1', '0']
    add_effects(pid, [
        {'time': 0, 'drone': 0, 'resource': FUEL, 'event_type': SHARE_EVENT, 'local': 1, 'installed': 0,
         'locked': 0, 'deplete': 0, 'abundancies': 0, 'quantity': 0}])

# Offerings of new shares (#485): a company offers up to N new shares, at
# least M (#516), at P credits each for D game days (45; N 0 withdraws it,
# refunding everyone); other companies commit to it (46), paying when they
# commit. Fully covered, the shares are created and the company paid at once;
# at the deadline, with at least M committed, the committed shares are; short
# of M, every commitment is refunded.
# Like the share exchange, each travels to Earth at the speed of light and
# is settled by its Share event; each burns a unit of fuel.
for pid, verb, nargs, args, what in (
        ('45', 'Offer new shares', '4', 'M N P D', 'offers up to N new shares of the drone\'s company, at least M '
         '(1 to N), at P credits each, open for D game days (1 to 30): at most as many as it has, one offering '
         'at a time. Fully committed, it issues them at once; at the deadline, it issues what was committed if '
         'that is at least M, or refunds everyone. N 0 withdraws it'),
        ('46', 'Commit to an offering', '2', 'N C', 'commits to N of company C\'s offered new shares at its price, '
         'paid now; refunded if the offering lapses or is withdrawn')):
    protocols[pid] = [pid, verb, nargs, '100',
                      f'[{args} {pid} perform]: {what}, at the Earth exchange (it gets there at the speed of light).',
                      '1', '1', '0']
    add_effects(pid, [
        {'time': 0, 'drone': 0, 'resource': FUEL, 'event_type': SHARE_EVENT, 'local': 1, 'installed': 0,
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
    if p in (25901, 25902, 5911, 5921, int(POWER_PROTOCOL), 33, 35, int(WAIT_PROTOCOL)):
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
    '[N 2 perform]: Burns N fuel. Until #544 the engine performed it to pay for a drone\'s thinking; '
    'now the company pays processor tax in credits, and the engine no longer burns fuel for thinking.',
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

# What drone D holds of resource R (#380 race: the sqInventory view works out
# every drone's holdings before it filters, so each look cost ~15 ms and grew
# with the game; station drones look hundreds of times a round and the engine
# fell to under 100 slices a minute). The same sum the view gives (the latest
# inventory event of each installed/locked kind), for one drone only.
# #422: for each kind, the newer of its latest increment (1) and latest
# depletion (2). Each is one step down events_inventory (drone, resource,
# installed, locked, outcome, event_type, then id), so a holding costs the
# same on day 100 as on day 1. A NOT EXISTS for a later event, and then an
# ORDER BY id over both event types, read the drone's whole history for the
# resource (~100 to 160 ms an order book by round 41's day 70 at 1,800x, and
# the clock fell to a tenth).
KINDS = "(SELECT 0 i, 0 l UNION ALL SELECT 0, 1 UNION ALL SELECT 1, 0 UNION ALL SELECT 1, 1) k"


def latest_inventory(drone, resource, t=None):
    def newest(event_type):
        if t is None:
            return (f"IFNULL((SELECT MAX(x.id) FROM events x WHERE x.drone = {drone} AND x.resource = {resource} "
                    f"AND x.installed = k.i AND x.locked = k.l AND x.outcome = 1 AND x.event_type = {event_type}), 0)")
        # The index named (#431): with some statistics MySQL walked the
        # whole of events back from the newest row instead (ORDER BY id DESC
        # by PRIMARY), and query 615 took ~25 s a call and stalled the engine
        return (f"IFNULL((SELECT x.id FROM events x FORCE INDEX (events_inventory) WHERE x.drone = {drone} AND x.resource = {resource} "
                f"AND x.installed = k.i AND x.locked = k.l AND x.outcome = 1 AND x.event_type = {event_type} "
                f"AND x.game_t <= {t} ORDER BY x.id DESC LIMIT 1), 0)")
    return f"(SELECT h.new_quantity FROM events h WHERE h.id = GREATEST({newest(1)}, {newest(2)}))"


def holding(drone, resource):
    # #430: the holdings table (migration 0031), kept by triggers on events by
    # the same rule: one row per kind, one index step
    return (f"(SELECT IFNULL(SUM(hd.units), 0) FROM holdings hd WHERE hd.drone = {drone} "
            f"AND hd.resource = {resource} AND hd.installed IN (0, 1) AND hd.locked IN (0, 1))")


# Double-cell money (#324 step 9): a column whose name starts with d_ comes
# back as two cells (high cell first, so 2@ reads it); others as one
ORDER_COLS = "o.price AS d_price, o.quantity, o.units, o.drone"


def flying(drone):
    # A drone on a trip has a pending move still to come. The index is named
    # (events_pending: outcome, game_t), so MySQL reads only the events still
    # to come. With some statistics it planned this as a scan of the whole of
    # events instead, on every order book: 78 ms a call on a 1,000-ship
    # world's 300,000 events, and growing with the history (#450)
    return (f"EXISTS (SELECT 1 FROM events AS m FORCE INDEX (events_pending) WHERE m.drone = {drone} "
            "AND m.event_type = 3 AND m.outcome = 0 AND m.game_t > [game_now])")
HERE_ = "(SELECT object_id FROM agents WHERE agent_id = [drone_id])"


def orders(event_type, local, resource='[value_1]', extra=''):
    # Sell orders (4) are open while stock is above the kept minimum; buy
    # orders (5) while stock is below the wanted maximum. latest_orders
    # carries the drone's stock and whether the order is open (#452), so a
    # book reads only the open orders, in price order (latest_orders_open):
    # on a 3,000-ship world almost no listed order is open, and reading them
    # all took 5 ms a book
    order = 'ASC' if event_type == 4 else 'DESC'
    where = "o.is_open = 1"
    # Here, and not flying away: a drone on a trip keeps the place it left
    # until it arrives, but nobody can trade with it (#380 round 29: pulls
    # from listed miners failed with "the other drone was somewhere else")
    # The order carries its drone's place (#462: latest_orders.place, kept
    # by a trigger when the drone moves), so a local book reads only the open
    # orders here, in price order, with no lookup of each seller's place
    here = f" AND o.place = {HERE_} AND NOT {flying('o.drone')}" if local else ''
    if event_type == 5:
        # ...and only buyers who can pay for a unit (#380: round 25's sellers
        # burned fuel pushing into orders whose buyers were broke)
        where += (" AND (SELECT c.credits FROM companies AS c "
                  "INNER JOIN agents AS b ON b.company_id = c.company_id WHERE b.agent_id = o.drone) >= o.price")
    # Each drone's latest order for the resource (#430: latest_orders, kept
    # by triggers on events, in place of MAX(id) over the history). Equal
    # prices list the lowest drone ID first, as the index used to give them
    index = 'latest_orders_here' if local else 'latest_orders_open'
    # The stock a pull or push can really move (#502): what the drone holds
    # free (not installed, not locked: a factory's inputs in use are locked),
    # less what pulls and its own work already on the way will take (a sell
    # order), or plus what pushes on the way will bring (a buy order).
    # latest_orders.units counts installed and locked units too, and none of
    # what is on the way: round 55's books listed units that had just been
    # pulled, and the next buyers' pulls were refused (29,484 times)
    return (f"SELECT d_price, quantity, units, drone FROM (SELECT o.price AS d_price, o.quantity, "
            f"{movable('o.drone', 'o.resource', event_type)} AS units, o.drone "
            f"FROM latest_orders AS o FORCE INDEX ({index}) "
            f"WHERE o.resource = {resource} AND o.event_type = {event_type} AND {where}{here}{extra}) AS b "
            f"WHERE {'units > quantity' if event_type == 4 else 'units < quantity'} "
            f"ORDER BY d_price {order}, drone;")


def movable(drone, resource, event_type):
    # A drone's free stock of a resource (holdings: not installed, not
    # locked), less what is on its way out (a sell order: pending depletions)
    # or plus what is on its way in (a buy order: pending accumulations).
    # Pending events are outcome 0 and carry the change (#502)
    sign, pending = ('-', 2) if event_type == 4 else ('+', 1)
    return (f"(IFNULL((SELECT h.units FROM holdings AS h WHERE h.drone = {drone} AND h.resource = {resource} "
            f"AND h.installed = 0 AND h.locked = 0), 0) {sign} "
            f"IFNULL((SELECT ABS(SUM(p.new_quantity)) FROM events AS p FORCE INDEX (events_inventory) "
            f"WHERE p.drone = {drone} AND p.resource = {resource} AND p.installed = 0 AND p.locked = 0 "
            f"AND p.outcome = 0 AND p.event_type = {pending}), 0))")


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
    '212': (1, "SELECT " + holding('[drone_id]', '[value_1]') + ";"),
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
    '600': "Lists buy orders for resource N at the requesting spacecraft's location: price, wanted maximum, stock it holds free (plus what is on its way in), spacecraft ID.",
    '601': "Lists buy orders for resource N anywhere in the solar system: price, wanted maximum, stock it holds free (plus what is on its way in), spacecraft ID.",
    '610': "Lists sell orders for resource N at the requesting spacecraft's location: price, kept minimum, stock it holds free (less what is on its way out), spacecraft ID.",
    '611': "Lists sell orders for resource N anywhere in the solar system: price, kept minimum, stock it holds free (less what is on its way out), spacecraft ID.",
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
# Messages are private to a company (#380, after round 40): a broadcast
# (event type 13) is found, and read (844, 845), only by drones of the
# company whose drone sent it
MY_CO = "(SELECT company_id FROM agents WHERE agent_id=[drone_id])"
conditions += (" AND (e.event_type<>13 OR EXISTS(SELECT 1 FROM broadcasts b WHERE b.action_id=e.action "
               f"AND b.company_id={MY_CO}))")
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
# The Earth database (#380): events reported to the Earth Based Central
# Database (an observation for drone 0, at the event's time plus the light
# time from where it happened to Earth), seen by a drone once light has
# carried them from Earth to where the drone is now. Same filters as 803.
# Broadcasts (event type 13) are read this way, or locally with 803.
EARTH_LIGHT = ("(SELECT IFNULL(SQRT(POW(x.x_coord-h.x_coord,2)+POW(x.y_coord-h.y_coord,2)+POW(x.z_coord-h.z_coord,2))"
               "/299792.458,0) FROM objects x, objects h WHERE x.object_id=3 AND h.object_id="
               "(SELECT object_id FROM agents WHERE agent_id=[drone_id]))")
earth = ('SELECT e.id FROM events e LEFT JOIN actions a ON a.id=e.action LEFT JOIN agents g ON g.agent_id=e.drone '
         'WHERE e.outcome=1 AND EXISTS(SELECT 1 FROM observations o WHERE o.event=e.id AND o.drone=0 '
         f'AND o.game_t+{EARTH_LIGHT}<=[game_now]) {conditions} ORDER BY e.game_t DESC,e.id DESC;')
queries.append((804, 'Search Earth database',
                f'[{usage.replace("803", "804")}]: Lists the events reported to the Earth database that this '
                'spacecraft can know of (light from Earth has reached it), newest first, filtered like 803.',
                len(EVENT_FILTERS), earth))
# An event's message (#380): the text of a broadcast (event type 13), a
# counted string; no row for other events
queries.append((844, 'Get event message',
                '[N R L 844 query]: The message of event N, a broadcast (event type 13) from a drone of this '
                'drone\'s company; no row for other events or other companies\' messages.', 1,
                "SELECT b.body FROM broadcasts b INNER JOIN events e ON e.action = b.action_id "
                f"WHERE e.id = [value_1] AND e.event_type = 13 AND b.company_id = {MY_CO};"))
# How crowded a place is (#380): the drones there now, working and wrecked
queries.append((721, 'Drones at a place',
                '[P R L 721 query]: How many drones are at location P now: working drones, wrecks.', 1,
                "SELECT IFNULL(SUM(wrecked_at IS NULL),0), IFNULL(SUM(wrecked_at IS NOT NULL),0) FROM agents "
                "WHERE object_id = [value_1];"))

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
                '(a double, e.g. 7 10000 um* for 70,000), cheapest first: price (2 cells), kept minimum, free stock (less what is on its way out), drone.', 3,
                orders(4, False, resource='[value_3]', extra=' AND o.price <= [dvalue_1]')))

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
    for rid, price, demand in METALS + ((2031, 8, 200), (2041, 12, 200)):
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
# Since #506 a released company starts at Earth with credits and no kit,
# and buys its own: Earth keeps enough kits for a crowd of releases (a
# quarter of the daily units is in stock: 100 of each)
for st in (1, 2, 3):
    kits_a_day = 400 if st == 1 else 24
    STATION_GOODS.append((st, 6001, SELLS, 3000, 24, 0))
    STATION_GOODS.append((st, MINING_KIT, SELLS, 2000, kits_a_day, 0))
    STATION_GOODS.append((st, FACTORY_KIT, SELLS, 2000, kits_a_day, 0))
# The Mars Exchange buys the chemists' goods too (#380), so they can work
# Phobos and Deimos instead of crowding the Moon's yalspar
for rid, price, demand in ((2031, 8, 200), (2041, 12, 200),
                           (2032, 10, 200), (2033, 12, 200), (2042, 15, 200), (2043, 18, 200)):
    STATION_GOODS.append((3, rid, BUYS, price, demand, 0))
# Making things pays (#380): a made good is worth its inputs, plus the fuel
# its recipe burns, plus a wage for each game hour its tool works, and the
# wage rises a tier at a time. Mining and refining wolfite, the best ore,
# earns about 17 credits a game hour; alloys and polymers earn 20, parts 25,
# systems 30, so each step up the chain pays more than the one below. Until
# round 22 an alloy sold for less than its two metals and a gear box earned
# about 4 an hour: newcomers worked that out and only mined.
WAGE = {2: 20, 3: 30, 4: 45}   # credits a game hour, by tier (first digit): half as much again a step up (#380)
# Fuel was 5 credits; 3 since round 37 (#380): a trip, a round of
# thinking and a fuel reserve cost less, so hauling and long trips pay
FUEL_PRICE = 3                 # what the House sells fuel for
RAW_VALUE = {'2011': 20, '2012': 30, '2013': 40, '2031': 8, '2032': 10, '2033': 12,
             '2041': 12, '2042': 15, '2043': 18}


def made_value(rid, seen=()):
    """The House's price for a made good: its inputs, fuel and a wage for
    the time it takes, per unit made (None when no recipe makes it alone)"""
    rid = str(rid)
    if rid in RAW_VALUE:
        return RAW_VALUE[rid]
    for pid, rec in recipes.items():
        if list(rec['outputs']) != [rid] or rid in seen:
            continue
        made = rec['outputs'][rid]
        stage = production_stage(pid)
        if not stage:
            continue
        inputs = 0
        for i, q in rec['inputs'].items():
            v = made_value(i, seen + (rid,))
            if v is None:
                break
            inputs += v * q
        else:
            return round((inputs + fuel_cost(pid) * FUEL_PRICE + stage[1] / HOUR * WAGE.get(int(rid[0]), 30)) / made)
    return None


# Alloys and polymers at Earth and the Moon, from day 0
for st in (1, 2):
    for rid, demand in ((2021, 100), (2022, 80), (2023, 60), (2051, 100)):
        STATION_GOODS.append((st, rid, BUYS, made_value(rid), demand, 0))
# Mars buys polymers too, like the chemists' other goods
STATION_GOODS.append((3, 2051, BUYS, made_value(2051), 100, 0))
# Parts and systems at Earth and Mars, in larger numbers. They were bought
# from day 3 and day 7, so demand stepped up the chain; in rounds 31 and 32
# every newcomer chose products on day 0, saw no bid for them, and asked
# for a notice. Since every exchange trades every product (below), they
# are bought from day 0
for st in (1, 3):
    for rid in (3001, 3011, 3031, 3311):
        STATION_GOODS.append((st, rid, BUYS, made_value(rid), 50, 0))
    for rid in (4111, 4281, 4311):
        STATION_GOODS.append((st, rid, BUYS, made_value(rid), 10, 0))
# The big mining gear costs more (#380): each kit's modules are worth 2,400
# The modules at half as much again as their listed value, like everything
# else the House sells (below); fuel stays at FUEL_PRICE
# (#506: modules 100 a day, so new companies can equip themselves module by
# module)
for rid, price, supply in ((FUEL, FUEL_PRICE, 2000), (5001, 400, 100), (WORM, 1000, 100), (REFINERY, 1000, 100),
                           (5201, 500, 100), (5211, 600, 100), (5501, 900, 100)):
    if rid != FUEL:
        price = -(-price * 3 // 2)
    STATION_GOODS.append((1, rid, SELLS, price, supply, 0))
# Fuel at every House exchange (#367), not only on Earth; the busy Mars
# Exchange sells 6,000 a day (#378), and so does the Moon Depot since the
# kits (#380): miners can't brew, and round 25's makers often got no fuel
# there because every miner shared its 2,000
for st, supply in ((2, 6000), (3, 6000)):
    STATION_GOODS.append((st, FUEL, SELLS, FUEL_PRICE, supply, 0))
# The House sells raw goods too, dear (#380): ores, metals, organics and
# inorganics at half as much again as it pays for them at that station (an
# ore at half as much again as what it refines to, less the fuel: so buying
# ore to refine never beats buying the refined goods). A maker can always
# buy its inputs, but a miner who asks less sells first: the House's price
# is a ceiling to undercut.
HOUSE_MARKUP = 1.5
ORES = (1100, 1110, 1120, 1130)
def ore_value(ore):
    for pid, rec in recipes.items():
        if pid.startswith('21') and list(rec['inputs']) == [str(ore)]:
            out = sum(RAW_VALUE.get(r, 0) * q for r, q in rec['outputs'].items())
            return max(1, round((out - fuel_cost(pid) * FUEL_PRICE) / rec['inputs'][str(ore)]))
    return None
for st in (1, 2, 3):
    bought = {rid: price for s_, rid, side, price, _, _ in STATION_GOODS if s_ == st and side == BUYS}
    for rid in (2011, 2012, 2013, 2031, 2032, 2033, 2041, 2042, 2043):
        if rid in bought:
            STATION_GOODS.append((st, rid, SELLS, -(-bought[rid] * 3 // 2), 200, 0))
    for ore in ORES:
        value = ore_value(ore)
        if value:
            STATION_GOODS.append((st, ore, SELLS, -(-value * 3 // 2), 400, 0))
# Every product at every exchange (#380): each exchange buys and sells a
# little of everything a drone can make, mine or refine, so any good can be
# bought to use or made to sell, wherever a player starts. It buys at the
# good's value (inputs, fuel and a wage, as above) and sells at half as much
# again, like the raw goods; tens of units a day of each (fewer of the dear
# ones), so a player who makes or sells a lot still moves the price. The larger
# listings above stay as they are. Fuel is left out (its own rows above),
# and so are the House's own modules and the big ship kits (42,000 credits
# and up): the station drone and the order words take a price in one cell,
# under 32,768, and no player could buy one yet.
HOUSE_ONLY = {FUEL, IMPORT_STATION, EXPORT_STATION, DROP_STATION_HULL}
MAX_LISTED = 30000
def every_product_value(rid):
    value = made_value(rid) if int(rid) not in ORES else ore_value(int(rid))
    return value if value and value > 0 else None
def small_daily(value):
    # a quarter of it on offer: 20, 8 or 4 units (it was 5, 2 and 1, too few
    # to build on)
    return 80 if value < 100 else 32 if value < 1000 else 16
# One value for a good at every exchange: where one already sells it for less
# than its value (Earth's modules and kits), that price less the markup, or
# a player could buy it there and sell it to another exchange for more
cheapest_sale = {}
for s_, rid, side, price, _, _ in STATION_GOODS:
    if side == SELLS:
        cheapest_sale[rid] = min(price, cheapest_sale.get(rid, price))
for st in (1, 2, 3):
    listed = {(rid, side): price for s_, rid, side, price, _, _ in STATION_GOODS if s_ == st}
    for rid in sorted(int(r) for r in resources):
        if rid in HOUSE_ONLY:
            continue
        value = every_product_value(rid)
        sells = listed.get((rid, SELLS))
        if rid in cheapest_sale:
            value = min(value or cheapest_sale[rid], cheapest_sale[rid] * 2 // 3)
        if value is None:
            continue
        if value * 3 // 2 > MAX_LISTED:
            continue
        if (rid, BUYS) not in listed:
            # never more than it sells for here, less the markup
            buy = min(value, sells * 2 // 3) if sells else value
            STATION_GOODS.append((st, rid, BUYS, max(1, buy), small_daily(value), 0))
            listed[(rid, BUYS)] = buy
        if sells is None:
            sell = max(-(-value * 3 // 2), listed[(rid, BUYS)] * 3 // 2 + 1)
            STATION_GOODS.append((st, rid, SELLS, sell, small_daily(value), 0))
# The House doesn't compete with players (#380): every exchange buys at
# three quarters of a good's value and sells at half as much again (set
# above), so a player who buys or sells anywhere between beats it both ways
# and the exchanges are the market of last resort. Prices above are values;
# here the buying ones come down. Fuel, the Mining Cruiser hull and the kits
# keep their prices: they are what a company pays to run and to grow.
BUY_SHARE = 0.75
STATION_GOODS = [(st, rid, side, max(1, int(price * BUY_SHARE)) if side == BUYS else price, daily, day)
                 for st, rid, side, price, daily, day in STATION_GOODS]
# Each exchange is short of different things (#380 round 34: every exchange
# priced a good the same, asking exactly twice its bid, so there was nothing
# to carry from one place to another, and every system paid about the same
# an hour). The Moon Depot, beside the mines, has raw and refined goods to
# spare and is short of made goods; Earth's industry is short of refined
# goods and has systems to spare; far Mars is short of parts and systems.
# Both of a good's prices at an exchange move together (it still asks twice
# its bid), and each good gets its own twist of up to a tenth either way
# by exchange, so one system pays more than another, and more in one place.
# Fuel, the hull and the kits keep their prices.
PLACE_FACTOR = {   # station: {tier (a resource's first digit): factor}
    1: {1: 1.10, 2: 1.20, 3: 1.00, 4: 0.90, 5: 1.00},   # Earth Exchange
    2: {1: 0.85, 2: 0.85, 3: 1.10, 4: 1.20, 5: 1.10},   # Moon Depot
    3: {1: 1.00, 2: 1.00, 3: 1.25, 4: 1.10, 5: 1.15},   # Mars Exchange
}
FIXED_PRICES = {FUEL, 6001, MINING_KIT, FACTORY_KIT}
def place_factor(st, rid):
    twist = ((rid * 7919 + st * 104729) % 21 - 10) / 100   # -0.10 to +0.10
    return PLACE_FACTOR[st].get(int(str(rid)[0]), 1.0) + twist
STATION_GOODS = [(st, rid, side, price if rid in FIXED_PRICES else
                  max(1, min(MAX_LISTED, round(price * place_factor(st, rid)))), daily, day)
                 for st, rid, side, price, daily, day in STATION_GOODS]
# No exchange buys a good for as much as it sells it for (a free loop for
# whoever trades both), and every price fits the station drone's one cell
_sides = {}
for st, rid, side, price, _, _ in STATION_GOODS:
    assert 0 < price < 32768, f'station {st}: resource {rid} listed at {price}'
    _sides.setdefault((st, rid), {})[side] = price
for (st, rid), p in _sides.items():
    assert not (BUYS in p and SELLS in p and p[BUYS] >= p[SELLS]), f'station {st}: resource {rid} bought at {p}'
# ...nor buys it for more than another exchange sells it for (Mars pays as
# much as Earth and the Moon charge for metals: the tax and the trip eat it)
for rid in {r for _, r in _sides}:
    bids = [p[BUYS] for (s_, r), p in _sides.items() if r == rid and BUYS in p]
    asks = [p[SELLS] for (s_, r), p in _sides.items() if r == rid and SELLS in p]
    assert not (bids and asks and max(bids) > min(asks)), f'resource {rid}: bought for {max(bids)}, sold for {min(asks)}'
for st, rid, _, _, _, _ in STATION_GOODS:
    if str(rid) not in resources:
        note(f'station {st}: unknown resource {rid}')

HERE_STATION = f"(SELECT id FROM stations WHERE object_id = {HERE_})"
TODAY = "FLOOR(([game_now] - (SELECT epoch FROM game_clock WHERE id = 1)) / 86400)"
# The game time now (#380): a drone couldn't tell what day it was, so it
# couldn't plan a late selling trip or a deadline (a newcomer built a day
# counter out of the tax query)
queries.append((207, 'Game time now',
                '[R L 207 query]: The game time now: the game day (day 0 is the world\'s first) and the minutes into '
                'it (0 to 1,439).', 0,
                "SELECT FLOOR(([game_now] - epoch) / 86400) AS day, FLOOR(MOD([game_now] - epoch, 86400) / 60) AS minute "
                "FROM game_clock WHERE id = 1;"))
# What a House station drone's exchange trades (#380): its program
# (resources/house/station.lua) reads it to keep its stock. Only station
# drones get rows: to players a station drone is a drone like any other, so
# the House's listed prices and demand are not theirs to read. Queries 631
# and 632 (batch offers and budgets) and 633 (house-price) are gone. Rows
# come by resource, then side, so a good the exchange both buys and sells
# is on two rows together (the program pairs them). The prices and daily
# units are today's (house_market, migration 0028: they move over game time).
STATION_ASKING = "EXISTS (SELECT 1 FROM agents WHERE agent_id = [drone_id] AND station_id IS NOT NULL)"
queries.append((630, 'Station goods',
                '[R L 630 query]: House station drones only: what the exchange where the drone is trades now: '
                'resource, side (1: it buys, 2: it sells), listed price (2 cells), daily units, units held (2 cells).', 0,
                "SELECT g.resource, g.side, g.price AS d_price, g.daily_units, IFNULL((SELECT MAX("
                + holding('a.agent_id', 'g.resource') + ") "
                "FROM agents a WHERE a.station_id = g.station_id), 0) AS d_units FROM house_market g WHERE g.station_id IN "
                f"{HERE_STATION} AND g.from_day <= {TODAY} AND {STATION_ASKING} ORDER BY g.resource, g.side;"))

# Mining deposits here (#324 step 11): left now (refilled for the time since
# it was last worked out), and capacity
# Taxes (#324 step 12; #367): a company pays tax_flat plus tax_permille
# thousandths of what it made (its assessed wealth, credits and goods aboard
# its working drones at the House price, less its starter package: the
# company_taxable view) every tax_period, after a grace period. Placeholders:
# 0.5% a game day and no flat part, after 3 game days; a plunderer gets half
# a wreck. The starter package (1,000 credits and 100 fuel, and the kit)
# must match config/forge.php.
# A sales tax instead (#380): the daily tax took a share of goods too, but
# only credits paid it, so a trader holding stock could be eliminated with
# thousands aboard. Now the seller pays SALES_TAX_PERMILLE thousandths of
# every sale (to a player or a station) out of what the buyer pays, and the
# daily tax is off (tax_period 0).
SALES_TAX_PERMILLE = 50
# start_credits 3,000 and no kit since #506 (forge.starting_credits)
HOUSE_RULES = [(1, 0, 3 * DAY, 0, 5, 50, 3000, 100, SALES_TAX_PERMILLE, COMPUTE_TAX_PER_MILLION, DISSOLVE_DAYS)]
MY_COMPANY = "(SELECT company_id FROM agents WHERE agent_id = [drone_id])"
queries.append((650, 'My taxes',
                '[R L 650 query]: The sales tax: the share of each sale the seller pays, in thousandths (50 is 5%); '
                'what the drone\'s company paid in the last game day (2 cells), and in all (2 cells).', 0,
                "SELECT h.sales_tax_permille, "
                "(SELECT IFNULL(SUM(t.amount), 0) FROM company_taxes t WHERE t.company_id = c.company_id "
                "AND t.eliminated = 0 AND t.charged_t > [game_now] - 86400) AS d_day, "
                "(SELECT IFNULL(SUM(t.amount), 0) FROM company_taxes t WHERE t.company_id = c.company_id "
                "AND t.eliminated = 0) AS d_all "
                f"FROM companies c JOIN house_rules h ON h.id = 1 WHERE c.company_id = {MY_COMPANY};"))
# The processor tax (#544): what the drone's company pays for its drones'
# thinking. Rows: the rate (credits a million instructions), the tax and the
# instructions billed over the last game day, and what it owes
# A company's age (#549): whole game days since it was founded (founded_t,
# set at release since #548). The examples' growth cutoffs used today(), the
# world's day: a Foundry released on day 8 could grow for only 16 days
queries.append((652, 'Company age',
                'Company N\'s age: whole game days since it was founded.', 1,
                "SELECT FLOOR(GREATEST(0, [game_now] - IFNULL(founded_t, [game_now])) / 86400) FROM companies WHERE company_id = [value_1];"))
queries.append((651, 'My processor tax',
                'compute_tax(): the processor tax, credits a million instructions; what the company paid for its '
                'drones\' thinking over the last game day, instructions billed, what it owes, game days broke (-1: '
                'not broke).', 0,
                "SELECT h.compute_tax_per_million AS per_million, "
                "(SELECT IFNULL(SUM(b.amount), 0) FROM compute_bills AS b WHERE b.company_id = c.company_id "
                "AND b.charged_t > [game_now] - 86400) AS d_tax, "
                "(SELECT IFNULL(SUM(b.instructions), 0) FROM compute_bills AS b WHERE b.company_id = c.company_id "
                "AND b.charged_t > [game_now] - 86400) AS d_instructions, c.compute_debt AS d_debt, "
                "IF(c.broke_since IS NULL, -1, FLOOR(([game_now] - c.broke_since) / 86400)) AS broke_days "
                f"FROM companies AS c JOIN house_rules AS h ON h.id = 1 WHERE c.company_id = {MY_COMPANY};"))
queries.append((660, 'Wrecks here',
                '[R L 660 query]: The wrecks where the drone is: drone ID, 1 if it has been plundered (else 0).', 0,
                "SELECT agent_id, plundered_at IS NOT NULL FROM agents WHERE wrecked_at IS NOT NULL "
                f"AND object_id = {HERE_} AND agent_id <> [drone_id] ORDER BY agent_id;"))
queries.append((661, 'Wreck hold',
                '[W R L 661 query]: What is aboard the wreck W where the drone is: resource, units.', 1,
                "SELECT i.resource, i.units FROM sqInventory i JOIN agents w ON w.agent_id = i.drone "
                f"WHERE i.drone = [value_1] AND w.wrecked_at IS NOT NULL AND w.object_id = {HERE_} AND i.units > 0 "
                "ORDER BY i.resource;"))

# Recipes (#380 round 36: three of four newcomers built their own recipe
# tables from the protocol descriptions): the first protocol that makes R,
# from its resource effects: each resource it changes, by how much (what it
# makes is positive, what it uses negative, fuel included; its tool, locked
# while it works and given back, is 0), and the minutes it takes
queries.append((695, 'Recipe',
                '[R L 695 query]: The first recipe that makes resource R: protocol, resource, units (made: '
                'positive; used, fuel too: negative; the tool it needs: 0), minutes it takes. One row a resource, '
                'what it makes first.', 1,
                "SELECT e.protocol, e.resource, SUM(IF(e.event_type = 1, e.quantity, -e.quantity)) AS units, "
                "(SELECT MAX(t.time) FROM resource_effects t WHERE t.protocol = e.protocol) DIV 60 AS minutes "
                "FROM resource_effects e WHERE e.installed = 0 AND e.local = 1 AND e.event_type IN (1, 2) "
                "AND e.protocol = (SELECT MIN(m.protocol) FROM resource_effects m WHERE m.resource = [value_1] "
                "AND m.event_type = 1 AND m.locked = 0 AND m.installed = 0 AND m.local = 1 "
                "AND (SELECT SUM(IF(d.event_type = 1, d.quantity, -d.quantity)) FROM resource_effects d "
                "WHERE d.protocol = m.protocol AND d.resource = m.resource AND d.installed = 0 AND d.local = 1 "
                "AND d.event_type IN (1, 2)) > 0 "
                "AND EXISTS (SELECT 1 FROM resource_effects u WHERE u.protocol = m.protocol AND u.event_type = 2 "
                "AND u.deplete = 1 AND u.locked = 0 AND u.resource NOT IN (m.resource, " + str(FUEL) + "))) "
                "GROUP BY e.protocol, e.resource HAVING units <> 0 OR MAX(e.locked) = 1 ORDER BY units DESC, e.resource;"))

# Cloning (#324 step 13): a drone's clones and its own line
# The share exchange (#469): the standing orders for a company's shares
# that can trade now. Sell orders: the poster holds more than it keeps,
# cheapest first. Buy orders: it holds fewer than it wants and can pay for
# one, highest first. Rows: price, kept minimum or wanted maximum, shares
# held, company
SHARE_BOOK = ("SELECT o.price AS d_price, o.limit_units, IFNULL(s.units, 0), o.company FROM share_orders AS o "
              "INNER JOIN companies AS c ON c.company_id = o.company AND c.eliminated_at IS NULL "
              "INNER JOIN companies AS sc ON sc.company_id = o.share_company AND sc.eliminated_at IS NULL "
              "LEFT JOIN shares AS s ON s.company = o.share_company AND s.holder = o.company "
              "WHERE o.share_company = [value_1] AND o.side = {side} AND {cond} ORDER BY o.price {order}, o.company;")
queries.append((690, 'Share sell orders',
                'Sell orders for company N\'s shares at the Earth exchange, cheapest first: price, kept minimum, '
                'shares held, company.', 1,
                SHARE_BOOK.format(side=1, cond='IFNULL(s.units, 0) > o.limit_units', order='ASC')))
queries.append((691, 'Share buy orders',
                'Buy orders for company N\'s shares at the Earth exchange, highest first: price, wanted maximum, '
                'shares held, company.', 1,
                SHARE_BOOK.format(side=2, cond='IFNULL(s.units, 0) < o.limit_units AND c.credits >= o.price',
                                  order='DESC')))
# Shares (#471, step 3 of the stock market #466): what the company holds,
# who holds a company's shares, and its trades. A company's last trade
# price is its newest share_trades row
MY_COMPANY = "(SELECT company_id FROM agents WHERE agent_id = [drone_id])"
LAST_SHARE_PRICE = ("(SELECT t.price FROM share_trades AS t WHERE t.share_company = {c} "
                    "ORDER BY t.id DESC LIMIT 1)")
# What a share is worth (#513): the average of the company's share trades
# over the last 3 game days, weighted by shares, else its last trade price.
# As Shares::valuePrices()
VALUE_SHARE_PRICE = ("IFNULL((SELECT GREATEST(1, ROUND(SUM(v.units * v.price) / SUM(v.units))) FROM share_trades AS v "
                     "WHERE v.share_company = {c} AND v.game_t > [game_now] - 259200 AND v.units > 0 HAVING SUM(v.units) > 0), "
                     + LAST_SHARE_PRICE + ")")
queries.append((692, 'My shares',
                'The shares the drone\'s company holds, its own included: company, shares, the dividends they have '
                'paid it, what a share is worth (the last 3 game days\' average trade price, else the last; 0 if '
                'never traded, or if the company has ended).', 0,
                f"SELECT s.company, s.units, s.dividends AS d_dividends, IF(c.eliminated_at IS NULL, "
                f"IFNULL({VALUE_SHARE_PRICE.format(c='s.company')}, 0), 0) AS d_price FROM shares AS s "
                f"JOIN companies AS c ON c.company_id = s.company WHERE s.holder = {MY_COMPANY} AND s.units > 0 ORDER BY s.company;"))
queries.append((693, 'Shareholders',
                'Who holds company N\'s shares, most first: company, shares.', 1,
                "SELECT holder, units FROM shares WHERE company = [value_1] AND units > 0 ORDER BY units DESC, holder;"))
queries.append((694, 'Share trades',
                'Company N\'s last 20 share trades, newest first: price, shares, seller, buyer, game day.', 1,
                "SELECT t.price AS d_price, t.units, t.seller, t.buyer, FLOOR((t.game_t - g.epoch) / 86400) "
                "FROM share_trades AS t JOIN game_clock AS g ON g.id = 1 WHERE t.share_company = [value_1] "
                "ORDER BY t.id DESC LIMIT 20;"))
# A company's sales income after tax a day, averaged over the last 7 game
# days (#492), or over its life if it is younger: sales come in lumps, and
# one day is a poor guide to what a share earns
DAY_SALES = ("FLOOR(IFNULL((SELECT SUM(t.credits - t.tax) FROM agents AS a JOIN trades AS t ON t.seller = a.agent_id "
             "AND t.game_t > [game_now] - 604800 WHERE a.company_id = {c}), 0) / GREATEST(1, LEAST(7, "
             "([game_now] - IFNULL((SELECT f.founded_t FROM companies AS f WHERE f.company_id = {c}), 0)) / 86400)))")
# Every company's shares on offer (#473): the sell orders that can sell
# now, cheapest first, with the company's sales income after tax over the
# last game day (what its dividends come from: 0.1% a share). Rows: company,
# price, units, seller, sales
queries.append((696, 'Shares for sale',
                'Every sell order for shares at the Earth exchange that can sell now, cheapest first (50 at most): '
                'company, price, units for sale, seller, the company\'s sales after tax a day (7-day average).', 0,
                "SELECT o.share_company AS company, o.price AS d_price, IFNULL(s.units, 0) - o.limit_units AS units, "
                "o.company AS seller, "
                f"{DAY_SALES.format(c='o.share_company')} AS d_sales "
                "FROM share_orders AS o INNER JOIN companies AS c ON c.company_id = o.company AND c.eliminated_at IS NULL "
                "INNER JOIN companies AS sc ON sc.company_id = o.share_company AND sc.eliminated_at IS NULL "
                "LEFT JOIN shares AS s ON s.company = o.share_company AND s.holder = o.company "
                "WHERE o.side = 1 AND IFNULL(s.units, 0) > o.limit_units ORDER BY o.price, o.share_company LIMIT 50;"))
# Offerings of new shares (#485): every open offering, and a company's
# share count
queries.append((697, 'Share offerings',
                'Open offerings of new shares, soonest deadline first (50 at most): company, most new shares, price, '
                'shares left, game hours left, its share count, its sales after tax a day (7-day average), shares '
                'my company committed, fewest it issues.', 0,
                "SELECT o.company AS company, o.units AS units, o.price AS d_price, o.units - IFNULL((SELECT SUM(m.units) FROM "
                "share_commitments AS m WHERE m.company = o.company), 0) AS left_units, "
                "GREATEST(0, CEIL((o.deadline_t - [game_now]) / 3600)) AS hours, "
                f"c.share_count AS count, {DAY_SALES.format(c='o.company')} AS d_sales, "
                f"IFNULL((SELECT m.units FROM share_commitments AS m WHERE m.company = o.company AND m.investor = {MY_COMPANY}), 0) AS mine, "
                "IFNULL(o.min_units, o.units) AS min_units "
                "FROM share_offerings AS o INNER JOIN companies AS c ON c.company_id = o.company AND c.eliminated_at IS NULL "
                "WHERE o.deadline_t > [game_now] ORDER BY o.deadline_t, o.company LIMIT 50;"))
# A company's health (#537): its drones, their fuel and how fast it falls,
# and its credits. The same SQL as App\Services\CompanyHealth: keep the two
# in step
def company_health_sql(company, now):
    fall = lambda only: ("IFNULL((SELECT SUM(x.prev - x.q) FROM (SELECT e.game_t, e.new_quantity AS q, "
        "LAG(e.new_quantity) OVER (ORDER BY e.game_t, e.id) AS prev FROM events AS e FORCE INDEX (events_inventory_time) "
        "WHERE e.drone = a.agent_id AND e.resource = 2061 AND e.installed = 0 AND e.locked = 0 "
        f"AND e.outcome = 1 AND e.game_t > {now} - 172800) AS x WHERE x.game_t > {now} - 86400 AND {only}), 0)")
    return (f"SELECT (SELECT COUNT(*) FROM agents AS w WHERE w.company_id = {company} AND w.wrecked_at IS NULL) AS working, "
        f"(SELECT COUNT(*) FROM agents AS w WHERE w.company_id = {company} AND w.wrecked_at IS NOT NULL) AS wrecked, "
        "IFNULL(SUM(d.fuel), 0) AS fuel, IFNULL(MIN(d.fuel), 0) AS lowest, IFNULL(SUM(d.used), 0) AS used, "
        "IFNULL(MIN(IF(d.fall > 0, FLOOR(d.fuel / d.fall), NULL)), -1) AS days, "
        f"IFNULL((SELECT c.credits FROM companies AS c WHERE c.company_id = {company}), 0) AS d_credits, "
        f"IF(COUNT(d.agent_id) = 0, -1, FLOOR(({now} - IFNULL(MAX(d.last_t), {now} - 864000)) / 3600)) AS idle "
        f"FROM (SELECT a.agent_id, (SELECT w.game_t FROM events AS w FORCE INDEX (events_drone_game_t) WHERE w.drone = a.agent_id "
        f"AND w.outcome = 1 AND w.game_t > {now} - 864000 AND w.game_t <= {now} AND w.event_type IN (1, 2) "
        "AND w.resource <> 2061 ORDER BY w.game_t DESC LIMIT 1) AS last_t, "
        "IFNULL((SELECT f.units FROM holdings AS f WHERE f.drone = a.agent_id "
        "AND f.resource = 2061 AND f.installed = 0 AND f.locked = 0), 0) AS fuel, "
        + fall('x.prev > x.q') + " AS used, " + fall('x.prev IS NOT NULL') + " AS fall "
        f"FROM agents AS a WHERE a.company_id = {company} AND a.wrecked_at IS NULL) AS d;")
queries.append((699, 'Company health',
                'Company N: working drones, wrecked drones, fuel aboard, the least aboard one, fuel used over the '
                'last game day, days until one runs dry (-1: none), credits, game hours since its drones last '
                'traded or made goods.', 1,
                company_health_sql('[value_1]', '[game_now]')))
# The House's demand (#550): at each exchange that buys good N, its bid now,
# the units it still takes today, its day's demand, its listed price and the
# game hours until it refills. It buys up to a day's demand above what it
# keeps to sell; its bid falls to half its listed price at a full day's
# demand bought, and what it bought goes down to the surface at the start of
# the next game day (station.lua's ship). As App\Services\HouseMarket::demand
HOUSE_KEEP = ("IFNULL((SELECT GREATEST(1, FLOOR(GREATEST(1, k.daily_units) / 4)) FROM house_market AS k "
              f"WHERE k.station_id = g.station_id AND k.resource = g.resource AND k.side = 2 AND k.from_day <= {TODAY} LIMIT 1), 0)")
queries.append((706, 'House demand',
                'Where the House buys resource N: place, its bid now, units it still takes today, its day\'s demand, '
                'its listed price, game hours until it refills.', 1,
                "SELECT d.place, GREATEST(1, d.listed - FLOOR(d.listed * d.bought / (2 * d.daily))) AS d_price, "
                "d.daily - d.bought AS left_units, d.daily, d.listed AS d_listed, "
                f"CEIL(((SELECT epoch FROM game_clock WHERE id = 1) + ({TODAY} + 1) * 86400 - [game_now]) / 3600) AS hours "
                "FROM (SELECT s.object_id AS place, g.price AS listed, GREATEST(1, g.daily_units) AS daily, "
                f"LEAST(GREATEST(0, {holding('a.agent_id', 'g.resource')} - {HOUSE_KEEP}), GREATEST(1, g.daily_units)) AS bought "
                "FROM house_market AS g JOIN stations AS s ON s.id = g.station_id "
                "JOIN agents AS a ON a.station_id = s.id AND a.wrecked_at IS NULL "
                f"WHERE g.side = 1 AND g.resource = [value_1] AND g.from_day <= {TODAY}) AS d ORDER BY d_price DESC, d.place;"))
queries.append((698, 'Share count',
                'Company N\'s shares in all: 1,000 to start, more once it has issued new ones.', 1,
                "SELECT share_count FROM companies WHERE company_id = [value_1];"))
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
    # The best places to mine a resource (#424: it listed every place, ~177
    # for yalspar, all at abundance 5): the 10 richest, then those with most
    # left to mine now, then the nearest to the drone
    410: ('List best extraction sites',
          'The 10 best places to mine resource N: place, abundance, units left there now, distance from the drone '
          '(thousand km). Richest first, then most left, then nearest.', 1,
          "SELECT a.location AS place, a.multiplier AS abundance, FLOOR(IFNULL(LEAST(d.capacity, IFNULL(s.amount, "
          "d.capacity) + d.refill_per_day * ([game_now] - IFNULL(s.refilled_t, [game_now])) / 86400), 0)) AS d_units, "
          "IFNULL(ROUND(SQRT(POW(o.x_coord - h.x_coord, 2) + POW(o.y_coord - h.y_coord, 2) + POW(o.z_coord - h.z_coord, 2)) "
          f"/ 1000), 0) AS d_distance FROM abundancies AS a INNER JOIN objects AS o ON o.object_id = a.location "
          f"INNER JOIN objects AS h ON h.object_id = {HERE_} "
          "LEFT JOIN deposits AS d ON d.object_id = a.location AND d.resource = a.resource "
          "LEFT JOIN deposit_state AS s ON s.object_id = a.location AND s.resource = a.resource "
          "WHERE a.resource = [value_1] ORDER BY a.multiplier DESC, d_units DESC, d_distance, a.location LIMIT 10;"),
    601: ('List buy orders here', '[N R L 601 query]: Lists buy orders for resource N where the drone is (like 600): price, '
          'wanted maximum, stock it holds free (plus what is on its way in), spacecraft ID.' + LOCAL_NOTE, 1, orders(5, True)),
    611: ('List sell orders here', '[N R L 611 query]: Lists sell orders for resource N where the drone is (like 610): price, '
          'kept minimum, stock it holds free (less what is on its way out), spacecraft ID.' + LOCAL_NOTE, 1, orders(4, True)),
    612: ('Sell orders here up to a price',
          '[N P R L 612 query]: Sell orders for resource N where the drone is at a price of at most P (a double, e.g. '
          '7 10000 um* for 70,000), cheapest first: price (2 cells), kept minimum, free stock (less what is on its way out), drone.', 3,
          orders(4, True, resource='[value_3]', extra=' AND o.price <= [dvalue_1]')),
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
                "FROM trades WHERE resource=[value_1] AND location IS NOT NULL "
                f"AND game_t<=[game_now]-{BOARD_LAG} "
                f"AND game_t>[game_now]-{BOARD_LAG}-{DAY} GROUP BY location ORDER BY location;"))

# Planning trips (#380): the Game Engine stores every body's position each
# minute (objects.x_coord ...) and its travel acceleration (game_clock), so a
# drone can work out a trip's length and time before it goes. A trip takes
# 2 * sqrt(distance / acceleration), as protocol_plan_travel times it.
# Distances are in thousands of km and seconds are doubles: Neptune is 4.5
# million thousand-km away, and the trip there takes about 4 million seconds.
def trip(a, b):
    km = (f"SQRT(POW({a}.x_coord-{b}.x_coord,2)+POW({a}.y_coord-{b}.y_coord,2)+POW({a}.z_coord-{b}.z_coord,2))")
    secs = f"GREATEST(1, 2*SQRT({km}*1000/(SELECT travel_acceleration FROM game_clock WHERE id = 1)))"
    return (f"SELECT ROUND({km}/1000) AS d_thousand_km, ROUND({secs}) AS d_seconds, CEIL({secs}/3600) AS hours "
            f"FROM objects {a}, objects {b} WHERE {a}.x_coord IS NOT NULL AND {b}.x_coord IS NOT NULL")
TRIP_COLUMNS = ('the distance in thousands of km (2 cells), the trip time in game seconds (2 cells) and in game '
                'hours, rounded up')
queries = [q for q in queries if q[0] not in (703, 704, 705)]
queries.append((703, 'Get location coordinates',
                '[N R L 703 query]: Where location N is now: x, y and z from the Sun, in thousands of km (each 2 '
                'cells, 2@). Updated every few game hours.', 1,
                "SELECT ROUND(x_coord/1000) AS d_x, ROUND(y_coord/1000) AS d_y, ROUND(z_coord/1000) AS d_z "
                "FROM objects WHERE object_id=[value_1] AND x_coord IS NOT NULL;"))
queries.append((704, 'Trip from here',
                f'[P R L 704 query]: A trip from where the drone is to location P: {TRIP_COLUMNS}.', 1,
                trip('a', 'b') + f" AND a.object_id={HERE_} AND b.object_id=[value_1];"))
queries.append((705, 'Trip between places',
                f'[A B R L 705 query]: A trip from location A to location B: {TRIP_COLUMNS}.', 2,
                trip('a', 'b') + " AND a.object_id=[value_2] AND b.object_id=[value_1];"))

# Event IDs pass 65,535 (#380), too big for a cell: the event lists return
# doubles (read with 2@) and the event lookups take one (an event ID's two
# cells, as 2@ leaves them: [dvalue_1])
EVENT_ID_NOTE = ' Event IDs are doubles (2 cells, 2@).'
def double_event_ids(q):
    qid, name, desc, params, script = q
    if qid in (800, 801, 802, 803, 804):
        script = re.sub(r'^SELECT (e\.)?id ', lambda m: f'SELECT {m.group(1) or ""}id AS d_id ', script)
        return (qid, name, (desc.rstrip() + EVENT_ID_NOTE)[:255], params, script)
    if 810 <= qid <= 849 and '[value_1]' in script:
        script = script.replace('[value_1]', '[dvalue_1]')
        desc = desc.rstrip() + ' N is an event ID, a double (2 cells) as an event list (800-804) leaves it with 2@.'
        return (qid, name, desc[:255], 2, script)
    return q
queries = [double_event_ids(q) for q in queries]

# Query 681 (the House's price board) is gone (#380): the House's trades
# are on the public board, 680, like anyone's

# Sales on a day (#380): how much of resource N sold on a game day, D days
# ago (0 today so far), by place: units sold and the average price, an hour
# old like the price board. A seller compares its stock with a day's sales
# before making more. (It split the House's purchases from the players'
# trades until the House was made a drone like any other.)
DAY_START = ("((SELECT epoch FROM game_clock WHERE id = 1) + (FLOOR(([game_now] - (SELECT epoch FROM game_clock "
             "WHERE id = 1)) / 86400) - [value_1]) * 86400)")
def in_day(col):
    return (f"{col} >= {DAY_START} AND {col} < {DAY_START} + 86400 AND {col} <= [game_now] - {BOARD_LAG}")
queries.append((682, 'Sales on a day',
                '[N D R L 682 query]: How much of resource N sold on the game day D days ago (0: today so far), an hour '
                'old, for each place: the place, units sold (2 cells), average price.', 2,
                "SELECT t.location, SUM(t.units) AS d_units, ROUND(SUM(t.credits) / SUM(t.units)) AS price "
                "FROM trades t WHERE t.resource = [value_2] AND t.location IS NOT NULL "
                f"AND {in_day('t.game_t')} GROUP BY t.location ORDER BY t.location;"))

# Asking about the world (#380, after round 39): newcomers wanted quotes
# from other exchanges, stock levels, and their own profit and loss.
# Every exchange's orders for resource N, as a drone may know them: live
# where it is, and as they stood an hour of game time ago elsewhere (the
# price board's lag): each drone's latest order for N posted by then, and
# what it held by then. Rows: place, side (1 buy, 2 sell), price, units
# (what a sell order will sell, or a buy order still wants), drone, hours
# old (0 or 1). Drones flying, wrecked or arrived after then are left out,
# and buyers who can't pay for a unit.
# What a drone held of a resource as of game time t: for each kind
# (installed, locked), its latest inventory event by then (like holding())
def holding_asof(drone, resource, t):
    return f"(SELECT IFNULL(SUM({latest_inventory(drone, resource, t)}), 0) FROM {KINDS})"


ASOF = ("WITH a AS (SELECT g.agent_id, g.object_id, g.company_id, g.arrived_t, "
        f"IF(g.object_id = {HERE_}, [game_now], [game_now] - {BOARD_LAG}) AS t FROM agents g "
        f"WHERE g.wrecked_at IS NULL AND NOT {flying('g.agent_id')}), "
        "l AS (SELECT e.drone, MAX(e.id) AS id FROM events e INNER JOIN a ON a.agent_id = e.drone "
        "WHERE e.outcome = 1 AND e.event_type IN (4, 5) AND e.resource = [value_1] AND e.game_t <= a.t "
        "GROUP BY e.drone, e.event_type) "
        "SELECT a.object_id AS place, IF(o.event_type = 5, 1, 2) AS side, o.new_credits AS price, "
        "ABS(i.units - o.new_quantity) AS units, o.drone, IF(a.t = [game_now], 0, 1) AS hours_old "
        "FROM l INNER JOIN events o ON o.id = l.id INNER JOIN a ON a.agent_id = l.drone "
        f"JOIN LATERAL (SELECT {holding_asof('a.agent_id', '[value_1]', 'a.t')} AS units) i "
        "WHERE IFNULL(a.arrived_t, 0) <= a.t AND IF(o.event_type = 4, i.units > o.new_quantity, "
        "i.units < o.new_quantity AND (SELECT c.credits FROM companies c WHERE c.company_id = a.company_id) "
        ">= o.new_credits) ORDER BY place, side, price;")
queries.append((615, 'Orders everywhere',
                '[N R L 615 query]: Every exchange\'s orders for resource N: live where the drone is, an hour old '
                'elsewhere. Rows: place, side (1 buy, 2 sell), price, units on offer or wanted, drone, hours old.', 1,
                ASOF))
# The drone's company's trades over the last H game hours, by resource:
# credits from sales, units sold, sales tax paid, credits paid for
# purchases, units bought
MY_COMPANY = "(SELECT company_id FROM agents WHERE agent_id = [drone_id])"
queries.append((655, 'My company\'s trades',
                '[H R L 655 query]: The drone\'s company\'s trades over the last H game hours, by resource: '
                'credits from sales, units sold, sales tax paid, credits paid for purchases, units bought.', 1,
                "SELECT t.resource, "
                f"SUM(IF(s.company_id = {MY_COMPANY}, t.credits, 0)) AS sales, "
                f"SUM(IF(s.company_id = {MY_COMPANY}, t.units, 0)) AS sold, "
                f"SUM(IF(s.company_id = {MY_COMPANY}, t.tax, 0)) AS tax, "
                f"SUM(IF(b.company_id = {MY_COMPANY}, t.credits, 0)) AS purchases, "
                f"SUM(IF(b.company_id = {MY_COMPANY}, t.units, 0)) AS bought "
                "FROM trades AS t INNER JOIN agents AS s ON s.agent_id = t.seller "
                "INNER JOIN agents AS b ON b.agent_id = t.buyer "
                "WHERE t.game_t > [game_now] - [value_1] * 3600 AND t.game_t <= [game_now] "
                f"AND (s.company_id = {MY_COMPANY} OR b.company_id = {MY_COMPANY}) "
                "GROUP BY t.resource ORDER BY t.resource;"))
# Everything about an event in one row (810 to 844 ask one column each)
queries.append((845, 'Event details',
                '[N R L 845 query]: Event N in one row: type, type name, acting drone, protocol, affected drone, '
                'resource, new quantity, new credits, new location, place, game day, seconds into it, message (your company\'s only).', 1,
                "SELECT e.event_type AS type, t.name AS type_name, IFNULL(x.drone, 0) AS acting, "
                "IFNULL(x.protocol, 0) AS protocol, e.drone AS affected, e.resource, e.new_quantity AS quantity, "
                "e.new_credits AS credits, e.new_location AS location, e.place, "
                "FLOOR((e.game_t - g.epoch) / 86400) AS day, MOD(e.game_t - g.epoch, 86400) AS seconds, "
                "(SELECT b.body FROM broadcasts AS b WHERE b.action_id = e.action AND e.event_type = 13 "
                f"AND b.company_id = {MY_CO} LIMIT 1) "
                "AS message FROM events AS e INNER JOIN event_types AS t ON t.id = e.event_type "
                "LEFT JOIN actions AS x ON x.id = e.action INNER JOIN game_clock AS g ON g.id = 1 "
                "WHERE e.outcome = 1 AND e.id = [value_1];"))

# Queries whose money or volume columns are double cells: say so
DOUBLE_QUERIES = {101, 600, 601, 610, 611, 612, 620, 621, 841}  # 630 says so itself
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
    '-- its latest inventory event, from the holdings table (#430, migration',
    '-- 0031: triggers on events keep it by the same rule)',
    'CREATE OR REPLACE VIEW `sqInventory` AS',
    'SELECT `drone`, `resource`, CAST(SUM(`units`) AS SIGNED) AS `units`',
    'FROM `holdings`',
    'GROUP BY `drone`, `resource`;',
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
for rid, mass in (('5901', 40), ('5902', 40), ('6001', 40)):
    if rid in resources:
        resources[rid]['mass'] = mass
table('resources', ['id', 'name', 'description', 'mass', 'capacity', 'slot_size'],
      [(r['id'], r['name'], r['description'], r['mass'], r['capacity'], r['slot_size'])
       for r in sorted(resources.values(), key=lambda r: r['id'])])
# Drones are written in Lua (#380: FORTH retired): each protocol and query
# description opens with its Lua call, not the old FORTH notation
# ("[N R D 10 perform]", "[N R L 210 query]"), and drops FORTH's cells,
# doubles and counted strings. Lua names for resources come from the
# drones' library (game-engine/src/lua_library.lua).
LUA_NAMES = {}
for line in open(os.path.join(REPO, 'game-engine', 'src', 'lua_library.lua'), encoding='utf-8'):
    m = re.match(r'local ([a-z_0-9, ]+) = ([0-9, ]+)$', line.strip())
    if m:
        for name, value in zip(m.group(1).split(','), m.group(2).split(',')):
            LUA_NAMES.setdefault(int(value), name.strip())
lua_res = lambda rid: LUA_NAMES.get(rid, str(rid))
LUA_CALLS = {'1': 'jettison(N, R)', '3': 'wait(M)', '7': 'sell_order(P, M, R)', '8': 'buy_order(P, M, R)',
             '10': 'push(N, R, D)', '11': 'pull(N, R, D)', '12': 'launch(D, K)', '34': 'plunder(W)',
             '40': 'broadcast(text)', '41': 'sell_shares(company, keep, price)',
             '42': 'buy_shares(company, want, price)', '43': 'pull_shares(n, company, seller)',
             '44': 'push_shares(n, company, buyer)', '45': 'offer_shares(n, price, days, min)',
             '46': 'commit_shares(n, company)', '5001': 'travel(N)', '25901': 'unpack()', '25902': 'unpack()'}


def lua_call(pid, args):
    """The Lua that runs protocol pid: its library function where it has one"""
    i = int(pid)
    if pid in LUA_CALLS:
        return LUA_CALLS[pid]
    if 11000 <= i < 12000 and (i - 10000) in resources_by_id:
        return f'mine({lua_res(i - 10000)})'
    if 21000 <= i < 22000 and (i - 20000) in resources_by_id:
        return f'refine({lua_res(i - 20000)})'
    if 12000 <= i < 20000 and not args and (i - 10000) in resources_by_id:
        return f'make({lua_res(i - 10000)})'
    return None


FORTH_WORDING = [
    (r' Money and volumes are double cells \(2@\)\.', ''),
    (r' Event IDs are doubles \(2 cells, 2@\)\.', ''),
    (r' N is an event ID, a double \(2 cells\) as an event list \(800-804\) leaves it with 2@\.',
     ' N is an event ID, from an event list (800-804).'),
    (r' \((?:each )?2 cells(?:, 2@)?\)', ''),
    (r', a double \(two cells\),', ' (any whole number of credits),'),
    (r' \(a double, e\.g\. 7 10000 um\* for 70,000\)', ''),
    (r'the counted string at address A', 'the text'),
    (r'as a counted string \(answer count type\)', 'as a string'),
    (r'^broadcast\(text\), or perform\(40, A\)', 'broadcast(text), or perform(40, text)'),
    (r'the the astronimical object whoes', 'the astronomical object whose'),
]


# The library function that asks each query (game-engine/src/lua_library.lua)
QUERY_FUNCS = {
    '100': 'company_name(N)', '101': 'company_credits(N)', '110': 'company_drones(N)', '200': 'drone_name(N)',
    '201': 'owner(N)', '202': 'where_is(N)', '203': 'hull(N)', '204': 'cargo_mass(N)', '205': 'me()',
    '207': 'now()', '210': 'hold(N)', '212': 'units(N)', '300': 'hull_name(N)', '301': 'hull_capacity(N)',
    '400': 'resource_name(N)', '401': 'unit_mass(N)', '410': 'best_sites(N)', '500': 'protocol_name(N)',
    '501': 'protocol_minutes(N)', '510': 'consumes(N)', '511': 'produces(N)', '512': 'retains(N)',
    '520': 'consumers(N)', '521': 'producers(N)', '522': 'users(N)', '600': 'buy_orders(N)',
    '610': 'sell_orders(N)', '690': 'share_sell_orders(N)', '691': 'share_buy_orders(N)', '692': 'shares_held()', '693': 'shareholders(N)', '694': 'share_trades(N)', '696': 'shares_for_sale()', '697': 'share_offerings()', '698': 'share_count(N)', '699': 'company_health(N)', '651': 'compute_tax()', '615': 'orders_everywhere(N)', '620': 'volume_here(N, H)', '621': 'volume_everywhere(N, H)',
    '640': 'deposits()', '650': 'taxes()', '655': 'earnings(H)', '660': 'wrecks()', '661': 'wreck_hold(W)',
    '670': 'my_clones()', '671': 'lineage()', '680': 'price_board(N)', '682': 'sales(N, D)', '695': 'recipe(R)',
    '700': 'place_name(N)', '701': 'place_type(N)', '702': 'orbits(N)', '703': 'position(N)',
    '704': 'trip_to(P)', '705': 'trip_between(A, B)', '710': 'orbiting(N)', '720': 'drones_at(N)',
    '721': 'crowd(P)', '730': 'minerals(N)', '800': 'events_affecting(N)', '801': 'local_events()',
    '803': 'events_here(T)', '804': 'events_earth(T)', '820': 'event_drone(N)', '844': 'message(N)',
    '845': 'event(N)',
}


def lua_description(desc, kind):
    if kind == 'perform':
        m = re.match(r'^\[((?:[A-Z][0-9]* )*)(\d+) perform\]', desc)
        if m:
            args = m.group(1).split()
            call = 'perform(' + ', '.join([m.group(2)] + args) + ')'
            named = lua_call(m.group(2), args)
            desc = (f'{named}, or {call}' if named else call) + desc[m.end():]
    else:
        m = re.match(r'^\[((?:[A-Z][0-9]* )*)R L (\d+) query\]', desc)
        if m:
            call = 'ask(' + ', '.join([m.group(2)] + m.group(1).split()) + ')'
            named = QUERY_FUNCS.get(m.group(2))
            desc = (f'{named}, or {call}' if named else call) + desc[m.end():]
    for old, new in FORTH_WORDING:
        desc = re.sub(old, new, desc)
    return desc


def fit(desc, limit=250):
    """At most limit characters, cut after a whole sentence: the engine keeps
    descriptions in 255-byte buffers (MAX_PROTOCOL_DESCRIPTION_SIZE)"""
    if len(desc) <= limit:
        return desc
    cut = desc.rfind('. ', 0, limit)
    return desc[:cut + 1] if cut > 0 else desc[:limit].rsplit(' ', 1)[0]


resources_by_id = {int(r['id']) for r in resources.values()}
for p in protocols.values():
    p[4] = fit(lua_description(p[4], 'perform'))
queries = [(q[0], q[1], fit(lua_description(q[2], 'query')), q[3], q[4]) for q in queries]

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
# Cruisers carry 1,000 since round 37 (#380), like a new drone's hull 1
# (migration 0029): 500 left a hauler 60 metals and a long trip too little
# room for fuel
HULL_SIZES = {  # cargo capacity, A-E slots
    'Cruiser': (1000, 2, 2, 1, 0, 0),
    'Cutter': (2000, 3, 3, 2, 0, 0),
    'Frigate': (5000, 4, 3, 2, 1, 0),
    'Barge': (20000, 6, 4, 3, 2, 1),
}
hulls = [(DROP_STATION_HULL, 'Drop Station', 'A House trading station (#380): a huge hold that no player can '
          'build or buy.', 1000000, 0, 0, 0, 0, 0)]
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
lines.append("INSERT INTO `event_types` (id, name) VALUES (14, 'Share') "
             'ON DUPLICATE KEY UPDATE name = VALUES(name);')
lines.append("INSERT INTO `event_types` (id, name) VALUES (13, 'Broadcast') "
             'ON DUPLICATE KEY UPDATE name = VALUES(name);')
lines.append("INSERT INTO `event_types` (id, name) VALUES (12, 'Wait') "
             'ON DUPLICATE KEY UPDATE name = VALUES(name);')
lines.append("INSERT INTO `event_types` (id, name) VALUES (11, 'Plunder wreck') "
             'ON DUPLICATE KEY UPDATE name = VALUES(name);')
lines.append('')
lines.append('-- Taxes and plunder (#324 step 12), placeholders to tune in step 20')
table('house_rules', ['id', 'tax_period', 'tax_grace', 'tax_flat', 'tax_permille', 'plunder_share',
                      'start_credits', 'start_fuel', 'sales_tax_permille', 'compute_tax_per_million', 'dissolve_days'], HOUSE_RULES)
lines.append('')
lines.append('-- House drop stations (#324 step 10): what they trade. Their budgets')
lines.append('-- (station_state) are kept: a new station starts with a full budget.')
# Budgets that cover the demand (#380): each station's daily budget is what
# its whole daily demand costs at its listed prices, so the price curve (a
# fuller batch pays less), not the budget, limits what it buys. With a fixed
# 10,000, round 26's starter makers flooded the Earth Exchange with rods at
# about 25 each, the budget ran out, and a pump or a motor got a share of 0:
# climbing the production chain was punished.
def demand_budget(st):
    return sum(price * daily for s_, rid, side, price, daily, _ in STATION_GOODS if s_ == st and side == BUYS)
# Markets that move (#380 round 36; migration 0028): each exchange's
# character, (swing, wave length in game days, boom or slump), in
# thousandths. The Earth Exchange is a big, steady market: long, shallow
# waves and mild booms. The Moon Depot is a frontier market: short, deep
# waves. The Mars Exchange is a colony: middling waves and the sharpest
# booms and slumps. The views house_market_raw and house_market work out
# the prices now; App\Services\HouseMarket posts the House's orders.
MARKET_MOODS = {1: (80, 20, 150), 2: (200, 7, 300), 3: (140, 12, 350)}
STATIONS = [(sid, name, obj, max(cap, demand_budget(sid)), max(refill, demand_budget(sid))) + MARKET_MOODS[sid]
            for sid, name, obj, cap, refill in STATIONS]
table('stations', ['id', 'name', 'object_id', 'budget_cap', 'refill_per_day',
                   'swing_permille', 'cycle_days', 'shock_permille'], STATIONS)
table('station_goods', ['station_id', 'resource', 'side', 'price', 'daily_units', 'from_day', 'steady'],
      [row + (1 if row[1] in FIXED_PRICES else 0,) for row in STATION_GOODS])
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
