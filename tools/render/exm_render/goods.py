"""Models for the rest of the resources: minerals (1xxx), materials (2xxx),
parts (3xxx), the packed kits and House stations (59xx) and craft (6xxx).

Builders take (bm, rng) and build around the origin, resting on z = 0.
Two material slots, C1 and C2, follow the shared drone slots; each resource
says what goes in them (a mineral's crystal and its host rock, a metal's
ingot colour ...) in its LOOKS entry.

Station modules (5911, 5921) follow the drone mounting standard, like the
modules in resources.py. Craft are built as drones (drone_presets).
"""

from __future__ import annotations

import math

import bmesh
import numpy as np
from mathutils import Matrix, Vector
from mathutils import noise

from . import geom, kit
from .drone_parts import ACCENT, DARK, FOIL, METAL, PANEL, SOLAR, TRUSS, WHITE

V = Vector
C1, C2 = 11, 12  # per-resource materials


# ===================================================================== minerals
# (crystal colour, habit, lustre). Lustre: glassy, metallic, icy or dull.
MINERALS = {
    1000: ("Magmite", (0.75, 0.12, 0.03), "prism", "glassy"),
    1010: ("Formite", (0.08, 0.5, 0.18), "cube", "glassy"),
    1020: ("Voxite", (0.35, 0.1, 0.6), "needle", "glassy"),
    1030: ("Zenolite", (0.85, 0.75, 0.3), "plate", "glassy"),
    1100: ("Kunspar", (0.85, 0.45, 0.6), "prism", "glassy"),
    1110: ("Yalspar", (0.85, 0.83, 0.78), "plate", "dull"),
    1120: ("Vladnium", (0.75, 0.76, 0.78), "cube", "metallic"),
    1130: ("Wolfite", (0.18, 0.15, 0.13), "blade", "metallic"),
    1200: ("Kronite", (0.35, 0.45, 0.65), "cube", "metallic"),
    1210: ("Efrite", (0.8, 0.3, 0.08), "botryoidal", "dull"),
    1220: ("Olivium", (0.35, 0.45, 0.08), "prism", "glassy"),
    1230: ("Aladanite", (0.05, 0.12, 0.6), "prism", "glassy"),
    1300: ("Halosite", (0.6, 0.85, 0.95), "needle", "icy"),
    1310: ("Stellarite", (0.92, 0.94, 0.98), "prism", "icy"),
    1320: ("Mullinite", (0.85, 0.5, 0.12), "botryoidal", "glassy"),
    1330: ("Oznium", (0.1, 0.65, 0.6), "cube", "icy"),
    1400: ("Tephnium", (0.3, 0.27, 0.25), "plate", "glassy"),
    1410: ("Neptine", (0.15, 0.35, 0.9), "needle", "icy"),
    1420: ("Empirogen", (0.75, 0.1, 0.5), "prism", "glassy"),
    1430: ("Laudite", (0.95, 0.7, 0.25), "botryoidal", "metallic"),
}
# Host rock by where the mineral is found (the id's hundreds digit)
HOST_ROCK = {
    10: ((0.07, 0.065, 0.06), 0.9),   # planets: dark basalt
    11: ((0.2, 0.2, 0.19), 0.9),      # moons: pale anorthosite
    12: ((0.05, 0.048, 0.045), 0.95),  # asteroids: carbon-dark
    13: ((0.12, 0.12, 0.13), 0.8),    # comets: dusty ice
    14: ((0.28, 0.3, 0.33), 0.7),     # rings: icy grit
}


def _crystal_material(colour, lustre):
    if lustre == "metallic":
        return dict(colour=colour, metallic=1.0, roughness=0.28)
    if lustre == "icy":
        return dict(colour=colour, transmission=0.85, roughness=0.15)
    if lustre == "dull":
        return dict(colour=colour, roughness=0.8, bump=0.3)
    return dict(colour=colour, transmission=0.6, roughness=0.08, coat=0.5)


def _rock(bm, rng, r=0.22):
    """A lump of host rock. Returns (point, normal) pairs on its upper surface."""
    res = bmesh.ops.create_icosphere(bm, subdivisions=5, radius=r, matrix=Matrix.Diagonal((1.3, 1.0, 0.7, 1.0)))
    off = V(rng.uniform(-50, 50, 3))
    for v in res["verts"]:
        d = v.co.normalized()
        v.co *= 1.0 + 0.25 * noise.fractal(d * 1.5 + off, 1.0, 2.0, 4) + 0.04 * noise.noise(d * 6 + off)
        v.co.z += r * 0.55
    geom._tag(geom._faces_of(res["verts"]), C2)
    bm.normal_update()
    top = [(v.co.copy(), v.normal.copy()) for v in res["verts"] if v.normal.z > 0.55]
    zmax = max(p.z for p, _ in top)
    return [(p, n) for p, n in top if p.z > zmax - 0.08]


def _prism(bm, base, axis, r, h, sides=6, tip=1.0):
    geom.lathe(bm, [(0.0, 0.0), (r, 0.0), (r, h), (0.0, h + r * tip)], C1, geom.frame(base, axis), seg=sides)


def mineral(rid):
    name, colour, habit, lustre = MINERALS[rid]

    def build(bm, rng):
        surface = _rock(bm, rng)
        n = {"prism": 9, "needle": 26, "cube": 9, "plate": 7, "botryoidal": 18, "blade": 10}[habit]
        for _ in range(n):
            point, normal = surface[int(rng.integers(len(surface)))]
            axis = (normal + V(rng.normal(0, 0.3, 3))).normalized()
            base = point - normal * 0.01  # seated a little into the rock
            s = rng.uniform(0.9, 1.5)
            if habit == "prism":
                _prism(bm, base, axis, 0.035 * s, 0.16 * s)
            elif habit == "needle":
                _prism(bm, base, axis, 0.008 * s, 0.18 * s, sides=6, tip=3.0)
            elif habit == "cube":
                rot = Matrix.Rotation(rng.uniform(0, 3), 3, V(rng.normal(size=3)).normalized())
                c = 0.06 * s
                geom.box(bm, base + axis * c * 0.4, (c, c, c), C1, rot)
            elif habit == "plate":
                for k in range(3):
                    geom.lathe(bm, [(0.0, 0.0), (0.06 * s, 0.0), (0.06 * s, 0.012), (0.0, 0.012)], C1,
                               geom.frame(base + axis * 0.015 * k, axis), seg=6)
            elif habit == "botryoidal":
                geom.sphere(bm, base, 0.035 * s, C1, 2)
            elif habit == "blade":
                geom.box_between(bm, base, base + axis * 0.18 * s, 0.05 * s, 0.01, C1)
    host, rough = HOST_ROCK[rid // 100]
    return build, [("crystal", _crystal_material(colour, lustre)), ("rock", dict(colour=host, roughness=rough, bump=0.6))]


# ==================================================================== materials
GRADE_LOOK = {
    "Metals": [(0.5, 0.5, 0.52), (0.95, 0.5, 0.3), (1.0, 0.76, 0.33)],        # iron, copper, gold
    "Alloys": [(0.45, 0.5, 0.58), (0.6, 0.55, 0.5), (0.45, 0.35, 0.75)],       # steel, titanium, exotic
    "Inorganics": [(0.85, 0.85, 0.82), (0.6, 0.25, 0.1), (0.1, 0.6, 0.6)],     # silica, oxides, rare salts
    "Organics": [(0.3, 0.18, 0.08), (0.15, 0.4, 0.12), (0.9, 0.55, 0.1)],      # tars, oils, resins
    "Polymers": [(0.9, 0.9, 0.88), (0.1, 0.35, 0.8), (0.6, 0.15, 0.6)],
}


def _grade_bands(bm, centre, grade, width=0.18):
    """A label plate with one, two or three accent bands: the grade."""
    geom.box(bm, centre, (0.005, width, 0.06), DARK)
    for k in range(grade):
        geom.box(bm, centre + V((0.004, -width / 2 + width * (k + 0.5) / 3, 0)), (0.005, width / 4, 0.045), ACCENT)


def _ingot(bm, centre, rot):
    """A trapezoidal bar, narrower on top, as cast."""
    res = bmesh.ops.create_cube(bm, size=1.0)
    for v in res["verts"]:
        top = v.co.z > 0
        v.co = V((v.co.x * (0.15 if top else 0.18), v.co.y * (0.045 if top else 0.06), v.co.z * 0.045))
        v.co = centre + rot @ v.co
    geom._tag(geom._faces_of(res["verts"]), C1)


def _ingots(bm, rng, grade):
    turn = Matrix.Rotation(math.pi / 2, 3, "Z")
    for layer in range(3):
        n = 3 - layer // 2
        rot = turn if layer % 2 else Matrix.Identity(3)
        for k in range(n):
            off = (k - (n - 1) / 2) * 0.1
            c = V((0, off, 0.0325 + 0.045 * layer)) if layer % 2 == 0 else V((off, 0, 0.0325 + 0.045 * layer))
            _ingot(bm, c, rot)
    geom.box(bm, V((0, 0, 0.005)), (0.34, 0.34, 0.01), METAL)


def _billets(bm, rng, grade):
    for i, (y, z) in enumerate([(-0.07, 0.04), (0.0, 0.04), (0.07, 0.04), (-0.035, 0.1), (0.035, 0.1), (0.0, 0.16)]):
        kit.cylinder(bm, V((-0.17, y, z)), V((0.17, y, z)), 0.035, C1, seg=24)
    for x in (-0.1, 0.1):
        geom.ring(bm, V((x, 0, 0.09)), V((1, 0, 0)), 0.115, 0.008, 0.025, ACCENT)


def _powder(bm, rng, grade):
    geom.box(bm, V((0, 0, 0.06)), (0.36, 0.28, 0.12), PANEL)
    geom.box(bm, V((0, 0, 0.115)), (0.33, 0.25, 0.012), DARK)
    geom.lathe(bm, [(0.0, 0.1), (0.15, 0.115), (0.0, 0.2)], C1, seg=32)
    geom.box(bm, V((0.18, 0.0, 0.06)), (0.005, 0.2, 0.08), DARK)


def _drums(bm, rng, grade):
    for (x, y) in ((-0.09, -0.07), (0.09, -0.07), (0.0, 0.09)):
        kit.vessel(bm, V((x, y, 0.0)), 0.08, 0.24, C1, domes=False)
        geom.ring(bm, V((x, y, 0.24)), V((0, 0, 1)), 0.07, 0.01, 0.01, METAL)
        kit.disc(bm, V((x, y - 0.081, 0.13)), V((0, 1, 0)), 0.02, 0.005, SOLAR)


def _spools(bm, rng, grade):
    for k, y in enumerate((-0.1, 0.1)):
        c = V((0, y, 0.12))
        for sx in (-1, 1):
            kit.disc(bm, c + V((sx * 0.08, 0, 0)), V((1, 0, 0)), 0.12, 0.012, WHITE)
        kit.cylinder(bm, c - V((0.075, 0, 0)), c + V((0.075, 0, 0)), 0.09, C1, seg=32)
        kit.cylinder(bm, c - V((0.09, 0, 0)), c + V((0.09, 0, 0)), 0.02, METAL)
    geom.box(bm, V((0, 0, 0.005)), (0.26, 0.42, 0.01), METAL)


def _fuel(bm, rng, grade):
    for y in (-0.08, 0.08):
        geom.lathe(bm, geom.capsule_profile(0.07, 0.38), C1, geom.frame(V((0, y, 0.1)), V((1, 0, 0)), V((0, 0, 1))), seg=32)
        for x in (-0.12, 0.12):
            geom.ring(bm, V((x, y, 0.1)), V((1, 0, 0)), 0.07, 0.008, 0.02, ACCENT)
    for x in (-0.12, 0.12):
        geom.box(bm, V((x, 0, 0.02)), (0.04, 0.32, 0.04), METAL)
    kit.pipe(bm, [V((0.19, -0.08, 0.1)), V((0.23, -0.08, 0.1)), V((0.23, 0.08, 0.1)), V((0.19, 0.08, 0.1))], 0.012)


def material(res):
    cls = res.name.split(" (")[0]
    grade = int(res.name[-2]) if res.name.endswith(")") and res.name[-2].isdigit() else 1
    shape = {"Metals": _ingots, "Alloys": _billets, "Inorganics": _powder, "Organics": _drums,
             "Polymers": _spools, "Fuel": _fuel}[cls]
    if cls == "Fuel":
        look = dict(colour=(0.85, 0.87, 0.9), roughness=0.35, coat=0.3)
    else:
        colour = GRADE_LOOK[cls][grade - 1]
        look = {"Metals": dict(colour=colour, metallic=1.0, roughness=0.3 - 0.08 * grade),
                "Alloys": dict(colour=colour, metallic=1.0, roughness=0.35 - 0.08 * grade, coat=0.3 * (grade - 1)),
                "Inorganics": dict(colour=colour, roughness=0.95, bump=0.8),
                "Organics": dict(colour=colour, roughness=0.4, coat=0.6),
                "Polymers": dict(colour=colour, roughness=0.3, coat=0.4)}[cls]

    def build(bm, rng):
        shape(bm, rng, grade)
        _grade_bands(bm, V((0.2, 0, 0.05)), grade)
    return build, [("material", look)]


# ======================================================================== parts

def p_sheets(bm, rng):
    for k in range(8):
        geom.box(bm, V((rng.normal(0, 0.004), rng.normal(0, 0.004), 0.008 + 0.012 * k)), (0.4, 0.3, 0.008), C1)
    for x in (-0.12, 0.12):
        geom.box(bm, V((x, 0, 0.06)), (0.02, 0.31, 0.11), ACCENT)


def p_rods(bm, rng):
    for i in range(4):
        for j in range(4 - i % 2):
            y = -0.06 + 0.035 * j + 0.0175 * (i % 2)
            kit.cylinder(bm, V((-0.22, y, 0.02 + 0.03 * i)), V((0.22, y, 0.02 + 0.03 * i)), 0.016, C1, seg=12)
    for x in (-0.12, 0.12):
        geom.ring(bm, V((x, 0, 0.065)), V((1, 0, 0)), 0.085, 0.006, 0.02, ACCENT)


def p_cables(bm, rng):
    for sx in (-1, 1):
        kit.disc(bm, V((sx * 0.09, 0, 0.16)), V((1, 0, 0)), 0.16, 0.015, WHITE)
    for k in range(6):
        kit.coil(bm, V((-0.08, 0, 0.16)), V((0.08, 0, 0.16)), 0.06 + 0.014 * k, 9, 0.008, C1)


def p_fasteners(bm, rng):
    geom.box(bm, V((0, 0, 0.015)), (0.36, 0.26, 0.03), METAL)
    for _ in range(26):
        p = V((rng.uniform(-0.15, 0.15), rng.uniform(-0.1, 0.1), 0.04))
        if rng.random() < 0.5:  # bolt
            kit.disc(bm, p + V((0, 0, 0.01)), V((0, 0, 1)), 0.016, 0.01, C1)
            kit.cylinder(bm, p + V((0, 0, 0.012)), p + V((rng.normal(0, 0.03), rng.normal(0, 0.03), 0.06)), 0.007, C1, seg=8)
        else:  # nut
            geom.lathe(bm, [(0.006, 0.0), (0.016, 0.0), (0.016, 0.012), (0.006, 0.012), (0.006, 0.0)], C1, geom.frame(p, V((0, 0, 1))), seg=6)


def p_springs(bm, rng):
    for (x, y, r, h) in ((-0.1, -0.06, 0.05, 0.22), (0.06, -0.08, 0.035, 0.16), (0.08, 0.08, 0.045, 0.2), (-0.08, 0.1, 0.03, 0.12)):
        kit.coil(bm, V((x, y, 0.0)), V((x, y, h)), r, int(h * 45), 0.007, C1)


def _gear(bm, centre, axis, r, t, mat):
    kit.disc(bm, centre, axis, r, t, mat)
    m = geom.frame(centre, axis)
    n = int(r * 120)
    for k in range(n):
        a = math.tau * k / n
        geom.box(bm, m @ V(((r + 0.006) * math.cos(a), (r + 0.006) * math.sin(a), 0)), (0.012, 0.012, t), mat,
                 (m.to_3x3() @ Matrix.Rotation(a, 3, "Z")))
    kit.disc(bm, centre, axis, r * 0.25, t * 1.3, DARK)


def p_gears(bm, rng):
    for (x, y, z, r) in ((0.0, 0.0, 0.015, 0.14), (0.0, 0.0, 0.045, 0.1), (0.0, 0.0, 0.075, 0.06), (0.2, 0.05, 0.015, 0.06)):
        _gear(bm, V((x, y, z)), V((0, 0, 1)), r, 0.025, C1)


def p_pulleys(bm, rng):
    for k, (x, r) in enumerate(((-0.12, 0.1), (0.08, 0.075), (0.2, 0.05))):
        c = V((x, 0, r))
        geom.lathe(bm, [(0.0, -0.03), (r, -0.03), (r * 0.8, 0.0), (r, 0.03), (0.0, 0.03)], C1, geom.frame(c, V((0, 1, 0))), seg=40)
        kit.disc(bm, c, V((0, 1, 0)), 0.015, 0.07, DARK)


def p_belts(bm, rng):
    for k in range(3):
        c = V((0, 0, 0.012 + 0.025 * k))
        prof = [(0.12 - 0.01 * k, -0.01), (0.14 - 0.01 * k, -0.01), (0.14 - 0.01 * k, 0.01), (0.12 - 0.01 * k, 0.01), (0.12 - 0.01 * k, -0.01)]
        geom.lathe(bm, prof, C1, geom.frame(c, V((0, 0, 1))) @ Matrix.Diagonal((1.6, 1.0, 1.0, 1.0)), seg=48)


def p_conductors(bm, rng):
    for k in range(6):
        geom.box(bm, V((0, -0.1 + 0.04 * k, 0.015)), (0.4, 0.03, 0.03), C1)
    for x in (-0.15, 0.15):
        geom.box(bm, V((x, 0, 0.035)), (0.03, 0.26, 0.012), DARK)


def p_semiconductors(bm, rng):
    kit.disc(bm, V((-0.06, 0, 0.005)), V((0, 0, 1)), 0.15, 0.006, SOLAR)
    for i in range(5):
        for j in range(5):
            geom.box(bm, V((-0.06 - 0.1 + 0.05 * i, -0.1 + 0.05 * j, 0.01)), (0.04, 0.04, 0.003), C1)
    for k in range(4):
        geom.box(bm, V((0.17, -0.09 + 0.06 * k, 0.012)), (0.05, 0.04, 0.012), DARK)
        for t in range(4):
            geom.box(bm, V((0.17 - 0.018 + 0.012 * t, -0.09 + 0.06 * k - 0.024, 0.006)), (0.004, 0.012, 0.004), METAL)


def p_superconductors(bm, rng):
    kit.vessel(bm, V((0, 0, 0)), 0.11, 0.22, WHITE, domes=False)
    for k in range(5):
        geom.ring(bm, V((0, 0, 0.03 + 0.04 * k)), V((0, 0, 1)), 0.111, 0.006, 0.018, C1)
    kit.pipe(bm, [V((0, 0, 0.22)), V((0, 0, 0.28)), V((0.14, 0, 0.28)), V((0.14, 0, 0.1))], 0.012)
    geom.sphere(bm, V((0.14, 0, 0.08)), 0.03, FOIL, 2)


def p_hoses(bm, rng):
    for k in range(4):
        kit.coil(bm, V((0, 0, 0.02 + 0.03 * k)), V((0, 0, 0.05 + 0.03 * k)), 0.1 + 0.005 * k, 1, 0.016, C1)
    kit.cylinder(bm, V((0.1, 0, 0.13)), V((0.2, 0, 0.13)), 0.02, METAL)


def p_pipes(bm, rng):
    for i, (y, z) in enumerate([(-0.06, 0.04), (0.0, 0.04), (0.06, 0.04), (-0.03, 0.1), (0.03, 0.1)]):
        geom.lathe(bm, [(0.025, -0.2), (0.03, -0.2), (0.03, 0.2), (0.025, 0.2)], C1, geom.frame(V((0, y, z)), V((1, 0, 0))), seg=20)
        for x in (-0.2, 0.2):
            kit.disc(bm, V((x, y, z)), V((1, 0, 0)), 0.04, 0.012, METAL)


def p_tanks(bm, rng):
    kit.vessel(bm, V((0, 0, 0.02)), 0.12, 0.34, C1)
    for sx in (-1, 1):
        geom.box(bm, V((sx * 0.1, 0, 0.01)), (0.04, 0.26, 0.02), METAL)
    kit.disc(bm, V((0, 0, 0.375)), V((0, 0, 1)), 0.03, 0.03, ACCENT)


def p_nozzles(bm, rng):
    for (x, y, s) in ((-0.1, -0.06, 1.0), (0.08, -0.07, 0.8), (0.0, 0.1, 0.9), (0.14, 0.09, 0.6)):
        geom.lathe(bm, [(0.03 * s, 0.0), (0.02 * s, 0.04 * s), (0.07 * s, 0.16 * s)], C1, geom.frame(V((x, y, 0.0)), V((0, 0, 1))), seg=24)
        kit.disc(bm, V((x, y, 0.005)), V((0, 0, 1)), 0.04 * s, 0.01, METAL)


PARTS = {
    3001: (p_sheets, dict(colour=(0.6, 0.6, 0.62), metallic=1.0, roughness=0.35)),
    3011: (p_rods, dict(colour=(0.45, 0.45, 0.47), metallic=1.0, roughness=0.4)),
    3021: (p_cables, dict(colour=(0.2, 0.2, 0.22), metallic=0.6, roughness=0.5)),
    3031: (p_fasteners, dict(colour=(0.55, 0.55, 0.55), metallic=1.0, roughness=0.3)),
    3101: (p_springs, dict(colour=(0.6, 0.6, 0.63), metallic=1.0, roughness=0.25)),
    3111: (p_gears, dict(colour=(0.5, 0.48, 0.45), metallic=1.0, roughness=0.35)),
    3121: (p_pulleys, dict(colour=(0.55, 0.55, 0.58), metallic=1.0, roughness=0.3)),
    3141: (p_belts, dict(colour=(0.04, 0.04, 0.045), roughness=0.7)),
    3201: (p_conductors, dict(colour=(0.95, 0.5, 0.3), metallic=1.0, roughness=0.25)),
    3211: (p_semiconductors, dict(colour=(0.25, 0.3, 0.45), metallic=0.8, roughness=0.15)),
    3221: (p_superconductors, dict(colour=(0.2, 0.55, 0.9), metallic=0.6, roughness=0.2, emission=0.5)),
    3301: (p_hoses, dict(colour=(0.06, 0.06, 0.07), roughness=0.6)),
    3311: (p_pipes, dict(colour=(0.5, 0.5, 0.52), metallic=1.0, roughness=0.35)),
    3321: (p_tanks, dict(colour=(0.8, 0.8, 0.78), roughness=0.4)),
    3331: (p_nozzles, dict(colour=(0.3, 0.28, 0.26), metallic=1.0, roughness=0.3)),
}


# ======================================================= kits and House stations

def _kit(contents):
    """A packed kit: an open crate frame with foil-wrapped, strapped bundles."""
    def build(bm, rng):
        L, Y, H = 0.8, 0.5, 0.42
        beam = 0.025
        for z in (0.0, H):
            for y in (-Y / 2, Y / 2):
                geom.box_between(bm, V((-L / 2, y, z)), V((L / 2, y, z)), beam, beam, METAL)
            for x in (-L / 2, L / 2):
                geom.box_between(bm, V((x, -Y / 2, z)), V((x, Y / 2, z)), beam, beam, METAL)
        for x in (-L / 2, L / 2):
            for y in (-Y / 2, Y / 2):
                geom.box_between(bm, V((x, y, 0)), V((x, y, H)), beam, beam, METAL, up=V((1, 0, 0)))
        geom.box(bm, V((0, 0, 0.01)), (L, Y, 0.02), TRUSS)
        x = -L / 2 + 0.03
        for k, size in enumerate(contents):
            w = (L - 0.06) * size / sum(contents)
            h = H * (0.6 + 0.35 * size / max(contents))
            geom.box(bm, V((x + w / 2, 0, 0.02 + h / 2)), (w * 0.94, Y * 0.85, h), FOIL if k % 2 == 0 else WHITE)
            geom.box(bm, V((x + w / 2, 0, 0.02 + h + 0.004)), (0.03, Y * 0.87, 0.008), ACCENT)
            x += w
        geom.box(bm, V((0, -Y / 2 - 0.005, H * 0.3)), (0.2, 0.01, 0.08), ACCENT)
    return build


def m_import_station(bm, nodes, W, rng, foil):
    """A cargo lift: a winch tower lowering a pod on a tether to the surface."""
    from .resources import _pallet

    L, Y, z = _pallet(bm, nodes, W)
    for sx in (-1, 1):
        for sy in (-1, 1):
            geom.strut(bm, V((sx * L * 0.3, sy * Y * 0.3, z)), V((0, 0, z + 0.7)), 0.02, ACCENT)
    kit.cylinder(bm, V((-0.12, 0, z + 0.7)), V((0.12, 0, z + 0.7)), 0.07, METAL)
    kit.coil(bm, V((-0.1, 0, z + 0.7)), V((0.1, 0, z + 0.7)), 0.075, 10, 0.006, DARK)
    geom.strut(bm, V((0, -0.075, z + 0.7)), V((0, -0.075, z + 0.25)), 0.005, DARK)
    geom.lathe(bm, [(0.0, 0.0), (0.08, 0.02), (0.1, 0.1), (0.08, 0.18), (0.0, 0.2)], WHITE, geom.frame(V((0, -0.075, z + 0.05)), V((0, 0, 1))), seg=24)
    geom.box(bm, V((0, -0.075, z + 0.15)), (0.21, 0.02, 0.03), ACCENT)


def m_export_station(bm, nodes, W, rng, foil):
    """A launcher: an inclined rail that throws cargo pods down to the surface."""
    from .resources import _pallet

    L, Y, z = _pallet(bm, nodes, W)
    a, b = V((-L * 0.4, 0, z + 0.08)), V((L * 0.4, 0, z + 0.55))
    for sy in (-1, 1):
        geom.box_between(bm, a + V((0, sy * 0.06, 0)), b + V((0, sy * 0.06, 0)), 0.025, 0.04, METAL)
    for t in np.linspace(0.1, 0.9, 6):
        p = a.lerp(b, t)
        geom.ring(bm, p, b - a, 0.09, 0.015, 0.03, ACCENT)
        geom.strut(bm, V((p.x, 0, z)), p - V((0, 0, 0.09)), 0.015, METAL)
    pod = a.lerp(b, 0.35)
    geom.lathe(bm, [(0.0, -0.08), (0.05, -0.06), (0.05, 0.06), (0.0, 0.1)], WHITE, geom.frame(pod, b - a), seg=20)
    kit.housing(bm, V((-L * 0.3, 0, z + 0.08)), (0.2, Y * 0.6, 0.16), PANEL, rng)


KITS = {5901: _kit([3, 2, 3]), 5902: _kit([3, 2, 2, 1])}
STATIONS = {5911: m_import_station, 5921: m_export_station}


# ======================================================================== craft
# Mining, Industrial and Defense craft in four sizes. Each is a drone built
# from the subsystems its Make protocol uses (#802): every module in the
# recipe is mounted on the truss, and its Engines are the engine block. Tanks,
# radiators and the bow tool are decoration.
CRAFT_SIZES = {"Cruiser": (3, 0.9), "Cutter": (5, 1.1), "Frigate": (7, 1.0), "Barge": (8, 1.0)}
FACES = ("top", "bottom", "port", "starboard")


def craft_modules(res) -> list[int]:
    """The subsystems mounted on a craft, one entry per unit, engines left out."""
    from . import resources

    rec = resources.recipe(res.id)
    return [rid for rid in sorted(rec, key=lambda r: (-resources.span(r), r)) for _ in range(rec[rid]) if rid != resources.ENGINE_ID]


def _pack(modules, bays, engines):
    """Place each module on the face with most room, at its first free bays.
    Bay 0 is kept for the tanks at the sides, and for the engine mount above
    and below. Returns [(id, bay, face, span)], or None if they don't fit."""
    from . import resources

    used = {f: set() for f in FACES}
    for f in FACES:
        if f in ("port", "starboard") or engines:
            used[f].add(0)
    placed = []
    for rid in modules:
        sp = resources.span(rid)
        best = None
        for f in sorted(FACES, key=lambda f: len(used[f])):
            for b in range(bays - sp + 1):
                if not any(b + k in used[f] for k in range(sp)):
                    best = (f, b)
                    break
            if best:
                break
        if not best:
            return None
        f, b = best
        used[f].update(range(b, b + sp))
        placed.append((rid, b, f, sp))
    return placed, used


def craft(res):
    from . import resources
    from .drone import DroneParams, Mount

    role, size = res.name.split(" (")[0].split(" ", 1)
    bays, esize = CRAFT_SIZES[size]
    engines = resources.recipe(res.id).get(resources.ENGINE_ID, 0)
    modules = craft_modules(res)
    while (packed := _pack(modules, bays, engines)) is None:
        bays += 1  # a long recipe gets a longer truss
    placed, used = packed
    cat = resources.catalogue()
    mounts = [Mount("tank", 0, "port"), Mount("tank", 0, "starboard")]
    mounts += [Mount(cat[rid].name.split(" (")[0].lower(), b, f, span=sp) for rid, b, f, sp in placed]
    # Decoration in the room left at the sides: a radiator where two bays are free.
    for f in ("port", "starboard"):
        for b in range(1, bays - 1):
            if b not in used[f] and b + 1 not in used[f]:
                mounts.append(Mount("radiator", b, f, span=2))
                break
    bow = {"Mining": "drill", "Industrial": "grapple", "Defense": "sensor"}[role] if engines else "dock"
    return DroneParams(name=res.name, seed=res.id, bays=bays, engines=engines, engine_size=esize, bow=bow,
                       core_tanks=[0] if bays >= 5 else [], mounts=mounts)
