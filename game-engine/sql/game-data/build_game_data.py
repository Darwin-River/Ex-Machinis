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
        'id': int(rid), 'name': r[1].strip()[:45], 'description': r[2].strip(),
        'mass': int(round(float(r[3] or 0))),
        'capacity': int(num(e[4]) or 0) if e else 0,
        'slot_size': int(num(e[5]) or 0) if e else 0,
    }
    if r[5].strip() == 'Mineral':
        mineral_tags[rid] = r[7].strip()

# --- recipes from the design workbook ------------------------------------------

recipes = {}  # protocol id -> {'inputs': {res: qty}, 'outputs': {res: qty}, 'sheet', 'product'}
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
            note(f'recipe {pid} ({sheet}): no product or no components in the sheet, skipped')
            continue
        pq = int(round(float(qty or 1))) or 1
        if product_is_input:
            recipes[pid] = {'inputs': {product: pq}, 'outputs': comps, 'sheet': sheet, 'product': product}
        else:
            recipes[pid] = {'inputs': comps, 'outputs': {product: pq}, 'sheet': sheet, 'product': product}

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
    protocols[pid] = p
    add_effects(pid, effs)

# Recipes: quantities from resources.xlsx, timing and modules from economy.xlsx
new_recipes = []
for pid, rec in sorted(recipes.items(), key=lambda kv: int(kv[0])):
    for rid in list(rec['inputs']) + list(rec['outputs']):
        if rid not in resources:
            note(f'recipe {pid}: unknown resource {rid}')
    if pid in econ_prot and econ_eff.get(pid):
        protocols[pid] = econ_prot[pid]
        old = econ_eff[pid]
        materials = set(rec['inputs']) | set(rec['outputs'])
        econ_in = {str(e['resource']) for e in old if e['deplete'] and not e['locked']}
        econ_out = {str(e['resource']) for e in old if not e['deplete'] and not e['locked']}
        # module rows: resources the recipe uses but neither consumes nor makes
        modules = {str(e['resource']) for e in old} - (econ_in ^ econ_out) - materials
        in_time = max([e['time'] for e in old if str(e['resource']) in econ_in - modules] or [NEW_RECIPE_INPUT_TIME])
        out_time = max([e['time'] for e in old if str(e['resource']) in econ_out - modules] or [NEW_RECIPE_OUTPUT_TIME])
        effs = [dict(e) for e in old if str(e['resource']) in modules]
        for rid, q in rec['inputs'].items():
            effs.append({'time': in_time, 'drone': 0, 'resource': int(rid), 'event_type': 2, 'local': 1,
                         'installed': 0, 'locked': 0, 'deplete': 1, 'abundancies': 0, 'quantity': q})
        for rid, q in rec['outputs'].items():
            effs.append({'time': out_time, 'drone': 0, 'resource': int(rid), 'event_type': 1, 'local': 1,
                         'installed': 0, 'locked': 0, 'deplete': 0, 'abundancies': 0, 'quantity': q})
        add_effects(pid, effs)
    else:
        prod = resources.get(rec['product'], {}).get('name', rec['product'])
        verb = 'Refine' if rec['sheet'].startswith('Minerals to') else 'Make'
        ins = ', '.join(f'{q} {resources.get(r, {}).get("name", r)}' for r, q in rec['inputs'].items())
        outs = ', '.join(f'{q} {resources.get(r, {}).get("name", r)}' for r, q in rec['outputs'].items())
        protocols[pid] = [pid, f'{verb} {prod}'[:45], '0', '100',
                          f'[{pid} perform]: Uses {ins} to make {outs}.'[:255], '1', '1', '0']
        effs = []
        for rid, q in rec['inputs'].items():
            effs.append({'time': NEW_RECIPE_INPUT_TIME, 'drone': 0, 'resource': int(rid), 'event_type': 2,
                         'local': 1, 'installed': 0, 'locked': 0, 'deplete': 1, 'abundancies': 0, 'quantity': q})
        for rid, q in rec['outputs'].items():
            effs.append({'time': NEW_RECIPE_OUTPUT_TIME, 'drone': 0, 'resource': int(rid), 'event_type': 1,
                         'local': 1, 'installed': 0, 'locked': 0, 'deplete': 0, 'abundancies': 0, 'quantity': q})
        add_effects(pid, effs)
        new_recipes.append(pid)
if new_recipes:
    note('%d recipes are new in resources.xlsx and got the default timing (inputs at %ds, outputs at %ds, '
         'no module): %s' % (len(new_recipes), NEW_RECIPE_INPUT_TIME, NEW_RECIPE_OUTPUT_TIME, ', '.join(new_recipes)))

# Location and market effects: only for protocols we keep
loc_eff = [r for r in econ['Location Effects'][1:] if r and num(r[1]) in protocols]
mkt_eff = [r for r in econ['Market Effects'][1:] if r and num(r[1]) in protocols]

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

# --- queries ----------------------------------------------------------------------

econ_q = {}
for r in econ['Queries'][1:]:
    r = r + [''] * 5
    if num(r[0]).isdigit():
        econ_q[num(r[0])] = {'name': r[1], 'description': r[2], 'parameters': int(num(r[3]) or 0),
                             'script': ' '.join(r[4].split())}

LATEST_ORDER = ("SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type = {t} "
                "AND resource = [value_1] GROUP BY drone")
ORDER_COLS = "o.new_credits, o.new_quantity, i.units, o.drone"
HERE_ = "(SELECT object_id FROM agents WHERE agent_id = [drone_id])"


def orders(event_type, local):
    # Sell orders (4) are open while stock is above the kept minimum; buy
    # orders (5) while stock is below the wanted maximum.
    cmp, order = ('>', 'ASC') if event_type == 4 else ('<', 'DESC')
    where = f"i.units {cmp} o.new_quantity"
    join_here = f" INNER JOIN agents AS a ON a.agent_id = o.drone AND a.object_id = {HERE_}" if local else ''
    stock = "LEFT JOIN sqInventory AS i ON i.drone = o.drone AND i.resource = [value_1]"
    if event_type == 5:
        where = "IFNULL(i.units, 0) < o.new_quantity"
        cols = ORDER_COLS.replace('i.units', 'IFNULL(i.units, 0)')
    else:
        cols = ORDER_COLS
    return (f"SELECT {cols} FROM events AS o INNER JOIN ({LATEST_ORDER.format(t=event_type)}) AS l ON l.id = o.id"
            f"{join_here} {stock} WHERE {where} ORDER BY o.new_credits {order};")


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
    '600': (1, orders(5, True)), '601': (1, orders(5, False)),
    '610': (1, orders(4, True)), '611': (1, orders(4, False)),
    '800': (1, "SELECT id FROM events WHERE outcome = 1 AND drone = [value_1] ORDER BY timestamp DESC, id DESC;"),
    '801': (0, "SELECT e.id FROM events AS e INNER JOIN agents AS a ON a.agent_id = e.drone WHERE e.outcome = 1 "
               f"AND a.object_id = {HERE_} ORDER BY e.timestamp DESC, e.id DESC;"),
    '802': (0, "SELECT id FROM events WHERE outcome = 1 ORDER BY timestamp DESC, id DESC;"),
    '811': (1, "SELECT t.name FROM events AS e INNER JOIN event_types AS t ON t.id = e.event_type "
               "WHERE e.outcome = 1 AND e.id = [value_1];"),
    '820': (1, "SELECT a.drone FROM events AS e INNER JOIN actions AS a ON a.id = e.action "
               "WHERE e.outcome = 1 AND e.id = [value_1];"),
    '821': (1, "SELECT a.protocol FROM events AS e INNER JOIN actions AS a ON a.id = e.action "
               "WHERE e.outcome = 1 AND e.id = [value_1];"),
    '832': (1, EVENT_COL.format(c='resource')),
    '840': (1, EVENT_COL.format(c='new_quantity')),
    '841': (1, EVENT_COL.format(c='new_credits')),
    '842': (1, EVENT_COL.format(c='new_location')),
    '843': (1, EVENT_COL.format(c='UNIX_TIMESTAMP(timestamp)')),
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
    '843': "Returns when event N happened, in seconds since 1970.",
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
queries.sort()
for q in queries:
    if len(q[4]) > 512:
        note(f'query {q[0]}: SQL is {len(q[4])} characters (the column holds 512)')

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


table('resources', ['id', 'name', 'description', 'mass', 'capacity', 'slot_size'],
      [(r['id'], r['name'], r['description'], r['mass'], r['capacity'], r['slot_size'])
       for r in sorted(resources.values(), key=lambda r: r['id'])])
table('protocols', ['id', 'name', 'parameters', 'bulk_modifier', 'description', 'observable', 'reportable', 'multiplier'],
      [(int(p[0]), p[1][:45], int(num(p[2]) or 0), int(num(p[3]) or 100), p[4][:255],
        int(num(p[5]) or 0), int(num(p[6]) or 0), int(num(p[7]) or 0))
       for p in sorted(protocols.values(), key=lambda p: int(p[0]))])
effect_rows = []
for n, (pid, e) in enumerate(sorted(effects, key=lambda pe: (pe[0], pe[1]['time'], pe[1]['event_type'])), start=1):
    effect_rows.append((n, e['drone'], e['resource'], pid, e['event_type'], e['local'], e['installed'],
                        e['locked'], e['deplete'], e['abundancies'], e['quantity'], e['time']))
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
