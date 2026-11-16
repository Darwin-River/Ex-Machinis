"""Models for in-game resources: systems (4xxx) and modules (5xxx).

The resource list is read from game-engine/sql/game_data.sql, so names and
descriptions always match the game. Each resource id maps to a builder.

- System builders, fn(bm, rng), make a standalone component resting on
  z = 0, about half a truss width across.
- Module builders, fn(bm, nodes, W, rng, foil), follow the drone mounting
  standard in drone_parts.py, so every module can also bolt onto a drone.
  On its own, a module is shown standing on its mounting pallet.

Resources without a builder yet (minerals, materials, parts, kits, craft)
are listed by `catalogue()` but can't be rendered until #707 reaches them.
"""

from __future__ import annotations

import math
import re
from dataclasses import dataclass
from pathlib import Path

import numpy as np
from mathutils import Vector

from . import geom, kit
from .drone_parts import ACCENT, DARK, ENGINE, FOIL, GLOW, METAL, PANEL, RADIATOR, SOLAR, TRUSS, WHITE, brace

V = Vector
GAME_DATA = Path(__file__).resolve().parents[3] / "game-engine" / "sql" / "game_data.sql"


@dataclass
class Resource:
    id: int
    name: str
    description: str
    mass: float

    @property
    def tier(self) -> str:
        return {1: "mineral", 2: "material", 3: "part", 4: "system", 5: "module", 6: "craft"}.get(self.id // 1000, "other")

    @property
    def slug(self) -> str:
        return re.sub(r"[^a-z0-9]+", "-", re.sub(r"\(.*?\)", "", self.name).lower()).strip("-")


def catalogue() -> dict[int, Resource]:
    """Every resource in the game data, by id."""
    sql = GAME_DATA.read_text()
    block = sql.split("INSERT INTO `resources`", 1)[1].split(";\n", 1)[0]
    rows = re.findall(r"\((\d+),\s*'((?:[^']|'')*)',\s*'((?:[^']|'')*)',\s*([\d.]+)", block)
    return {int(i): Resource(int(i), n.replace("''", "'"), d.replace("''", "'"), float(m)) for i, n, d, m in rows}


# ===================================================================== systems

def bore(bm, rng):
    kit.housing(bm, V((0, 0, 0.12)), (0.3, 0.26, 0.24), PANEL, rng)
    kit.cylinder(bm, V((0.15, 0, 0.12)), V((0.3, 0, 0.12)), 0.07, METAL)
    geom.lathe(bm, [(0.1, 0.0), (0.1, 0.05), (0.0, 0.32)], DARK, geom.frame(V((0.3, 0, 0.12)), V((1, 0, 0))), seg=32, cap_start=True)
    for k in range(3):
        pts = [geom.frame(V((0.3, 0, 0.12)), V((1, 0, 0))) @ V((0.1 * (1 - t) * math.cos(k * 2.1 + t * 5), 0.1 * (1 - t) * math.sin(k * 2.1 + t * 5), 0.32 * t)) for t in np.linspace(0, 0.9, 10)]
        for a, b in zip(pts, pts[1:]):
            geom.strut(bm, a, b, 0.012, METAL, seg=6)


def gear_box(bm, rng):
    kit.housing(bm, V((0, 0, 0.13)), (0.32, 0.18, 0.26), METAL, rng, vents=False)
    for (x, y, r) in ((-0.07, -0.11, 0.1), (0.09, -0.11, 0.06)):
        kit.disc(bm, V((x, y, 0.15)), V((0, 1, 0)), r, 0.03, ENGINE)
        for k in range(int(r * 160)):
            a = math.tau * k / int(r * 160)
            geom.box(bm, V((x + r * math.cos(a), y, 0.15 + r * math.sin(a))), (0.015, 0.03, 0.015), ENGINE)
    kit.cylinder(bm, V((0.16, 0, 0.08)), V((0.26, 0, 0.08)), 0.025, METAL)
    kit.cylinder(bm, V((-0.16, 0, 0.2)), V((-0.26, 0, 0.2)), 0.02, METAL)


def gyroscope(bm, rng):
    c = V((0, 0, 0.22))
    kit.disc(bm, V((0, 0, 0.02)), V((0, 0, 1)), 0.14, 0.04, METAL)
    geom.strut(bm, V((0, 0, 0.04)), c - V((0, 0, 0.2)), 0.02, METAL)
    for axis, rr in ((V((0, 1, 0)), 0.2), (V((1, 0, 0)), 0.17)):
        geom.ring(bm, c, axis, rr, 0.015, 0.03, ACCENT if rr > 0.19 else METAL)
    kit.disc(bm, c, V((0.3, 0.2, 1)).normalized(), 0.12, 0.04, ENGINE)
    kit.cylinder(bm, c - V((0, 0, 0.16)), c + V((0, 0, 0.16)), 0.012, METAL)


def heat_sink(bm, rng):
    geom.box(bm, V((0, 0, 0.03)), (0.4, 0.3, 0.06), METAL)
    kit.fins(bm, V((0, 0, 0.2)), 0.38, 0.28, 16, "x", METAL)
    kit.pipe(bm, [V((-0.2, -0.1, 0.05)), V((-0.24, -0.1, 0.05)), V((-0.24, 0.1, 0.05)), V((-0.2, 0.1, 0.05))], 0.015)


def heat_shield(bm, rng):
    m = geom.frame(V((0, 0, 0.0)), V((0, 0, 1)))
    prof = [(0.0, 0.18), (0.12, 0.165), (0.25, 0.11), (0.33, 0.04), (0.34, 0.0)]
    geom.lathe(bm, prof, DARK, m, seg=48)
    for k in range(5):
        geom.ring(bm, V((0, 0, 0.18 - 0.17 * (k + 0.5) / 5 * (k / 5))), V((0, 0, 1)), 0.07 * (k + 1), 0.004, 0.004, METAL)
    geom.ring(bm, V((0, 0, 0.01)), V((0, 0, 1)), 0.33, 0.015, 0.02, METAL)


def heater(bm, rng):
    kit.housing(bm, V((0, 0, 0.11)), (0.3, 0.22, 0.22), PANEL, rng, vents=False)
    for k in range(5):
        kit.cylinder(bm, V((-0.12 + 0.06 * k, -0.115, 0.04)), V((-0.12 + 0.06 * k, -0.115, 0.2)), 0.012, GLOW)
    for zz in (0.06, 0.18):
        geom.box(bm, V((0, -0.135, zz)), (0.3, 0.008, 0.008), METAL)


def solar_arrays(bm, rng):
    for k in range(4):
        geom.box(bm, V((0, 0, 0.012 + 0.025 * k)), (0.42, 0.3, 0.018), SOLAR)
    geom.box(bm, V((0, 0, 0.05)), (0.44, 0.03, 0.11), METAL)
    kit.cylinder(bm, V((-0.22, 0, 0.06)), V((-0.3, 0, 0.06)), 0.02, METAL)


def arc_welder(bm, rng):
    kit.housing(bm, V((0, 0, 0.1)), (0.26, 0.2, 0.2), PANEL, rng)
    kit.pipe(bm, [V((0.13, 0, 0.12)), V((0.2, 0.0, 0.14)), V((0.24, 0.08, 0.05)), V((0.3, 0.1, 0.05))], 0.012, DARK)
    kit.cylinder(bm, V((0.3, 0.1, 0.05)), V((0.4, 0.1, 0.05)), 0.02, METAL)
    geom.lathe(bm, [(0.018, 0), (0.006, 0.04)], DARK, geom.frame(V((0.4, 0.1, 0.05)), V((1, 0, 0))), seg=12)
    for k in range(2):
        kit.disc(bm, V((-0.05 + 0.1 * k, -0.105, 0.15)), V((0, 1, 0)), 0.02, 0.012, ACCENT)


def laser(bm, rng):
    kit.cylinder(bm, V((-0.22, 0, 0.1)), V((0.18, 0, 0.1)), 0.07, PANEL)
    for x in (-0.15, 0.0, 0.12):
        geom.ring(bm, V((x, 0, 0.1)), V((1, 0, 0)), 0.07, 0.012, 0.025, METAL)
    geom.lathe(bm, [(0.07, 0), (0.05, 0.06), (0.04, 0.08)], METAL, geom.frame(V((0.18, 0, 0.1)), V((1, 0, 0))), seg=24)
    kit.disc(bm, V((0.26, 0, 0.1)), V((1, 0, 0)), 0.035, 0.005, GLOW)
    for sx in (-1, 1):
        geom.box(bm, V((sx * 0.1, 0, 0.015)), (0.04, 0.16, 0.03), METAL)


def transceiver(bm, rng):
    kit.housing(bm, V((0, 0, 0.05)), (0.22, 0.22, 0.1), PANEL, rng, vents=False)
    geom.strut(bm, V((0, 0, 0.1)), V((0, 0, 0.2)), 0.02, METAL)
    m = geom.frame(V((0.0, 0, 0.2)), V((0.5, 0, 1)))
    prof = [(0.0, 0.0)] + [(0.2 * t, 0.07 * t * t) for t in np.linspace(0.1, 1, 10)]
    geom.lathe(bm, prof, WHITE, m, seg=40)
    geom.strut(bm, m @ V((0, 0, 0)), m @ V((0, 0, 0.14)), 0.008, METAL)
    geom.sphere(bm, m @ V((0, 0, 0.14)), 0.02, DARK, 2)


def scanner(bm, rng):
    kit.disc(bm, V((0, 0, 0.03)), V((0, 0, 1)), 0.13, 0.06, METAL)
    geom.box(bm, V((0, 0, 0.13)), (0.24, 0.2, 0.14), PANEL)
    for k, (y, r) in enumerate(((-0.05, 0.045), (0.05, 0.03))):
        geom.lathe(bm, [(r, 0), (r, 0.05), (r * 0.85, 0.055)], DARK, geom.frame(V((0.12, y, 0.13)), V((1, 0, 0))), seg=24, cap_end=True)
    for k in range(5):
        geom.box(bm, V((-0.06 + 0.03 * k, 0, 0.23 + 0.01 * (k % 2))), (0.008, 0.16, 0.06), METAL)


def computer(bm, rng):
    kit.housing(bm, V((0, 0, 0.1)), (0.3, 0.2, 0.2), PANEL, rng)
    for k in range(4):
        geom.box(bm, V((-0.105 + 0.07 * k, -0.105, 0.1)), (0.05, 0.012, 0.14), DARK)
        geom.box(bm, V((-0.105 + 0.07 * k, -0.112, 0.16)), (0.012, 0.004, 0.006), GLOW)
    kit.fins(bm, V((0, 0, 0.23)), 0.26, 0.05, 10, "x", METAL)


def solenoid(bm, rng):
    kit.coil(bm, V((-0.12, 0, 0.09)), V((0.1, 0, 0.09)), 0.075, 9, 0.012, ENGINE)
    kit.cylinder(bm, V((-0.16, 0, 0.09)), V((0.2, 0, 0.09)), 0.035, METAL)
    for x in (-0.13, 0.11):
        geom.box(bm, V((x, 0, 0.09)), (0.02, 0.2, 0.18), DARK)


def motor(bm, rng):
    kit.cylinder(bm, V((-0.15, 0, 0.11)), V((0.12, 0, 0.11)), 0.1, PANEL, seg=32)
    for k in range(10):
        a = math.tau * k / 10
        geom.box(bm, V((-0.015, 0.105 * math.cos(a), 0.11 + 0.105 * math.sin(a))), (0.26, 0.012, 0.012), METAL)
    kit.disc(bm, V((0.125, 0, 0.11)), V((1, 0, 0)), 0.09, 0.02, METAL)
    kit.cylinder(bm, V((0.13, 0, 0.11)), V((0.24, 0, 0.11)), 0.02, METAL)
    geom.box(bm, V((-0.02, 0, 0.02)), (0.2, 0.18, 0.04), METAL)
    geom.box(bm, V((-0.05, 0, 0.22)), (0.08, 0.06, 0.04), ACCENT)


def valve(bm, rng):
    kit.cylinder(bm, V((-0.2, 0, 0.08)), V((0.2, 0, 0.08)), 0.04, METAL)
    for x in (-0.2, 0.2):
        kit.disc(bm, V((x, 0, 0.08)), V((1, 0, 0)), 0.07, 0.02, METAL)
    geom.sphere(bm, V((0, 0, 0.08)), 0.08, ENGINE, 3)
    geom.strut(bm, V((0, 0, 0.12)), V((0, 0, 0.24)), 0.015, METAL)
    geom.ring(bm, V((0, 0, 0.24)), V((0, 0, 1)), 0.08, 0.012, 0.02, ACCENT)
    for k in range(3):
        a = math.tau * k / 3
        geom.strut(bm, V((0, 0, 0.24)), V((0.08 * math.cos(a), 0.08 * math.sin(a), 0.24)), 0.008, ACCENT)


def pump(bm, rng):
    geom.box(bm, V((0, 0, 0.02)), (0.42, 0.22, 0.04), METAL)
    kit.cylinder(bm, V((-0.2, 0, 0.12)), V((0.0, 0, 0.12)), 0.08, PANEL, seg=32)
    geom.lathe(bm, [(0.0, -0.05), (0.11, -0.05), (0.11, 0.05), (0.0, 0.05)], ACCENT, geom.frame(V((0.08, 0, 0.12)), V((1, 0, 0))), seg=32)
    kit.pipe(bm, [V((0.08, 0, 0.23)), V((0.08, 0, 0.3)), V((0.2, 0, 0.3))], 0.025)
    kit.pipe(bm, [V((0.14, 0, 0.12)), V((0.24, 0, 0.12))], 0.03)


def piston(bm, rng):
    kit.cylinder(bm, V((-0.2, 0, 0.08)), V((0.05, 0, 0.08)), 0.06, PANEL)
    kit.cylinder(bm, V((0.05, 0, 0.08)), V((0.22, 0, 0.08)), 0.025, METAL)
    for x in (-0.2, 0.22):
        geom.box(bm, V((x, 0, 0.08)), (0.04, 0.05, 0.1), METAL)
        kit.disc(bm, V((x, 0, 0.08)), V((0, 1, 0)), 0.03, 0.07, DARK)
    kit.pipe(bm, [V((-0.15, 0.06, 0.08)), V((-0.15, 0.1, 0.12))], 0.01)


def heat_pump(bm, rng):
    kit.housing(bm, V((0, 0, 0.12)), (0.24, 0.24, 0.24), PANEL, rng, vents=False)
    kit.disc(bm, V((0, -0.125, 0.12)), V((0, 1, 0)), 0.09, 0.01, DARK)
    for k in range(5):
        a = math.tau * k / 5
        geom.box(bm, V((0.05 * math.cos(a), -0.13, 0.12 + 0.05 * math.sin(a))), (0.07, 0.005, 0.02), METAL)
    kit.coil(bm, V((0.13, 0.0, 0.03)), V((0.13, 0.0, 0.22)), 0.04, 6, 0.008, METAL)


def thruster(bm, rng):
    geom.box(bm, V((-0.1, 0, 0.08)), (0.14, 0.16, 0.16), METAL)
    kit.disc(bm, V((-0.025, 0, 0.08)), V((1, 0, 0)), 0.07, 0.015, ACCENT)
    geom.lathe(bm, [(0.05, 0.0), (0.03, 0.05), (0.1, 0.22)], ENGINE, geom.frame(V((-0.02, 0, 0.08)), V((1, 0, 0))), seg=32)
    kit.pipe(bm, [V((-0.1, 0.08, 0.12)), V((-0.1, 0.12, 0.12)), V((-0.2, 0.12, 0.12))], 0.012)


def battery(bm, rng):
    geom.box(bm, V((0, 0, 0.02)), (0.36, 0.26, 0.04), METAL)
    for i in range(4):
        for j in range(3):
            geom.box(bm, V((-0.135 + 0.09 * i, -0.08 + 0.08 * j, 0.11)), (0.08, 0.07, 0.14), PANEL)
            kit.cylinder(bm, V((-0.135 + 0.09 * i, -0.08 + 0.08 * j, 0.18)), V((-0.135 + 0.09 * i, -0.08 + 0.08 * j, 0.2)), 0.012, ACCENT if j == 1 else METAL)
    geom.box(bm, V((0, 0, 0.19)), (0.34, 0.02, 0.01), METAL)


def torch(bm, rng):
    for y in (-0.06, 0.06):
        kit.vessel(bm, V((-0.12, y, 0.0)), 0.05, 0.22, ACCENT if y > 0 else WHITE)
    kit.pipe(bm, [V((-0.12, -0.06, 0.24)), V((0.0, 0.0, 0.26)), V((0.12, 0, 0.2))], 0.01, DARK)
    kit.pipe(bm, [V((-0.12, 0.06, 0.24)), V((0.0, 0.0, 0.26))], 0.01, DARK)
    kit.cylinder(bm, V((0.12, 0, 0.2)), V((0.24, 0, 0.14)), 0.018, METAL)
    geom.lathe(bm, [(0.02, 0), (0.008, 0.05)], DARK, geom.frame(V((0.24, 0, 0.14)), V((2, 0, -1))), seg=12)


def reactor(bm, rng):
    geom.box(bm, V((0, 0, 0.02)), (0.4, 0.4, 0.04), METAL)
    kit.vessel(bm, V((0, 0, 0.04)), 0.13, 0.38, WHITE)
    for k in range(8):
        a = math.tau * k / 8
        geom.box(bm, V((0.17 * math.cos(a), 0.17 * math.sin(a), 0.22)), (0.012, 0.012, 0.3), METAL)
    geom.lathe(bm, [(0.0, 0.0), (0.06, 0.0), (0.06, 0.04), (0.0, 0.04)], ACCENT, geom.frame(V((0, 0, 0.42)), V((0, 0, 1))), seg=24)
    for k in range(4):
        a = math.tau * k / 4 + 0.4
        kit.pipe(bm, [V((0.13 * math.cos(a), 0.13 * math.sin(a), 0.12)), V((0.2 * math.cos(a), 0.2 * math.sin(a), 0.12)), V((0.2 * math.cos(a), 0.2 * math.sin(a), 0.05))], 0.015)


SYSTEMS = {
    4101: bore, 4111: gear_box, 4121: gyroscope, 4131: heat_sink, 4141: heat_shield, 4201: heater,
    4211: solar_arrays, 4221: arc_welder, 4231: laser, 4241: transceiver, 4251: scanner, 4261: computer,
    4271: solenoid, 4281: motor, 4301: valve, 4311: pump, 4321: piston, 4401: heat_pump, 4411: thruster,
    4421: battery, 4431: torch, 4501: reactor,
}


# ===================================================================== modules

def _pallet(bm, nodes, W):
    """The standard mounting pallet. Returns usable (length, width, deck height)."""
    L = nodes[-1] - nodes[0]
    z0 = 0.1 * W
    geom.box(bm, V((0, 0, z0 - 0.02 * W)), (L * 0.92, W * 0.9, 0.04 * W), TRUSS)
    for sx in (-1, 1):
        geom.box(bm, V((0, sx * W * 0.45, z0 - 0.02 * W)), (L * 0.92, 0.04 * W, 0.06 * W), ACCENT)
    feet = [V((sx * L * 0.43, sy * W * 0.42, z0 - 0.04 * W)) for sx in (-1, 1) for sy in (-1, 1)]
    brace(bm, feet, nodes, W, 0.02 * W, k=1)
    return L * 0.88, W * 0.84, z0


def m_engine(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    geom.box(bm, V((L * 0.3, 0, z + 0.08)), (0.2, 0.3, 0.16), METAL)  # thrust frame
    m = geom.frame(V((L * 0.2, 0, z + 0.25)), V((-1, 0, 0)), V((0, 0, 1)))
    geom.lathe(bm, [(0.11, 0.0), (0.12, 0.05), (0.12, 0.22), (0.06, 0.3)], ENGINE, m, seg=32, cap_start=True)
    geom.lathe(bm, [(0.06 + 0.22 * t ** 0.7, 0.3 + 0.5 * t) for t in np.linspace(0, 1, 12)], ENGINE, m, seg=40)
    kit.vessel(bm, V((L * 0.32, 0.0, z + 0.16)), 0.09, 0.28, FOIL if foil else WHITE)
    kit.pipe(bm, [V((L * 0.32, 0, z + 0.44)), V((L * 0.2, 0, z + 0.44)), V((L * 0.2, 0, z + 0.36))], 0.02)
    for sy in (-1, 1):
        geom.strut(bm, V((L * 0.3, sy * 0.15, z + 0.16)), m @ V((sy * 0.1, 0, 0.1)), 0.015, METAL)


def m_shuttle(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    for sx in (-1, 1):  # docking cradle
        geom.box(bm, V((sx * L * 0.3, 0, z + 0.06)), (0.05, Y * 0.8, 0.12), METAL)
    body = [(0.0, -0.4), (0.12, -0.38), (0.18, -0.2), (0.18, 0.15), (0.12, 0.32), (0.0, 0.4)]
    m = geom.frame(V((0, 0, z + 0.3)), V((1, 0, 0)), V((0, 0, 1)))
    geom.lathe(bm, [(r, zz * L * 1.1) for r, zz in body], WHITE, m, seg=32)
    geom.box(bm, V((L * 0.3, 0, z + 0.36)), (0.12, 0.2, 0.05), DARK)  # sensor window
    for sy in (-1, 1):
        geom.box(bm, V((-L * 0.15, sy * 0.25, z + 0.28)), (0.25, 0.14, 0.02), ACCENT)
    geom.lathe(bm, [(0.05, 0), (0.09, 0.1)], ENGINE, geom.frame(V((-L * 0.44, 0, z + 0.3)), V((-1, 0, 0))), seg=24)


def m_slug(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    for sy in (-1, 1):
        kit.tread(bm, V((0, sy * Y * 0.36, z + 0.1)), L * 0.75, 0.09, 0.14)
    kit.housing(bm, V((0, 0, z + 0.3)), (L * 0.7, Y * 0.5, 0.2), PANEL, rng)
    geom.lathe(bm, [(0.0, 0.0), (0.12, 0.0), (0.16, 0.08), (0.16, 0.18), (0.0, 0.18)], METAL, geom.frame(V((L * 0.38, 0, z + 0.22)), V((1, 0, 0))), seg=24)
    for k in range(6):  # scoop teeth
        a = math.tau * k / 6
        geom.box(bm, V((L * 0.38 + 0.2, 0.13 * math.cos(a), z + 0.22 + 0.13 * math.sin(a))), (0.05, 0.03, 0.03), DARK)
    kit.vessel(bm, V((-L * 0.25, 0, z + 0.4)), 0.07, 0.18, ACCENT, V((0, 0, 1)))


def m_crawler(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    body = V((0, 0, z + 0.35))
    geom.sphere(bm, body, 0.17, PANEL, 3)
    geom.box(bm, body + V((0, 0, 0.06)), (0.36, 0.24, 0.1), ACCENT)
    kit.legs(bm, body - V((0, 0, 0.05)), 6, Y * 0.7, 0.2, 0.022, METAL, phase=0.5)
    kit.cylinder(bm, body, body + V((0.32, 0, -0.1)), 0.04, METAL)
    geom.lathe(bm, [(0.06, 0), (0.0, 0.16)], DARK, geom.frame(body + V((0.32, 0, -0.1)), V((1, 0, -0.3))), seg=24, cap_start=True)


def m_skimmer(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    for sx in (-1, 1):
        geom.box(bm, V((sx * L * 0.25, 0, z + 0.08)), (0.05, Y * 0.6, 0.16), METAL)
    m = geom.frame(V((0, 0, z + 0.3)), V((1, 0, 0)), V((0, 0, 1)))
    prof = [(0.0, -0.45), (0.1, -0.42), (0.15, -0.2), (0.16, 0.2), (0.2, 0.42), (0.17, 0.45)]
    geom.lathe(bm, [(r, zz * L) for r, zz in prof], WHITE, m, seg=32)
    geom.lathe(bm, [(0.16, 0.44 * L), (0.07, 0.3 * L)], DARK, m, seg=32)  # intake throat
    for sy in (-1, 1):
        geom.box(bm, V((-L * 0.25, sy * 0.28, z + 0.3)), (L * 0.3, 0.26, 0.02), PANEL)
        geom.box(bm, V((-L * 0.36, sy * 0.4, z + 0.36)), (0.08, 0.02, 0.12), ACCENT)


def m_worm(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    n = 7
    for k in range(n):
        x = -L * 0.42 + L * 0.8 * k / (n - 1)
        zc = z + 0.22 + 0.05 * math.sin(k * 0.9)
        half = L * 0.8 / (n - 1) * 0.55
        geom.lathe(bm, [(0.0, -half), (0.14, -half), (0.17, -half * 0.4), (0.17, half * 0.4), (0.14, half), (0.0, half)], PANEL if k % 2 else METAL, geom.frame(V((x, 0, zc)), V((1, 0, 0))), seg=24)
        geom.ring(bm, V((x, 0, zc)), V((1, 0, 0)), 0.165, 0.01, 0.02, ACCENT)
    head = V((L * 0.45, 0, z + 0.22))
    geom.lathe(bm, [(0.17, 0.0), (0.17, 0.06), (0.0, 0.3)], DARK, geom.frame(head, V((1, 0, 0))), seg=32, cap_start=True)
    for k in range(4):
        a = math.tau * k / 4
        geom.strut(bm, head + V((0, 0.17 * math.cos(a), 0.17 * math.sin(a))), head + V((0.3, 0, 0)), 0.012, METAL)
    for sx in (-1, 1):
        geom.box(bm, V((sx * L * 0.3, 0, z + 0.04)), (0.05, Y * 0.5, 0.08), METAL)


def m_smelter(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    c = V((-L * 0.08, 0, z))
    kit.vessel(bm, c, 0.24, 0.5, FOIL if foil else PANEL, domes=False)
    geom.lathe(bm, [(0.24, 0.5), (0.16, 0.62), (0.08, 0.66), (0.08, 0.76)], METAL, geom.frame(c, V((0, 0, 1))), seg=32)
    kit.disc(bm, c + V((0.0, -0.245, 0.22)), V((0, 1, 0)), 0.07, 0.01, GLOW)  # sight glass
    geom.box(bm, c + V((0.0, -0.25, 0.22)), (0.18, 0.02, 0.18), METAL)
    geom.box(bm, V((L * 0.32, 0, z + 0.1)), (0.18, 0.2, 0.2), DARK)  # crucible tilt drive
    kit.fins(bm, V((L * 0.32, 0, z + 0.36)), 0.18, 0.2, 8, "x", RADIATOR)
    kit.pipe(bm, [c + V((0.0, 0, 0.76)), c + V((0.25, 0, 0.76)), V((L * 0.32, 0, z + 0.48))], 0.03)


def m_chemical_reactor(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    spots = [(-0.3, -0.18, 0.1, 0.45), (-0.3, 0.18, 0.1, 0.4), (0.0, 0.0, 0.14, 0.55), (0.3, -0.18, 0.09, 0.35), (0.3, 0.18, 0.09, 0.42)]
    tops = []
    for x, y, r, h in spots:
        b = V((x * L, y, z))
        kit.vessel(bm, b, r, h, [WHITE, ACCENT, FOIL if foil else WHITE][int(rng.integers(3))])
        tops.append(b + V((0, 0, h + 0.03)))
    for a, b in zip(tops, tops[1:]):
        mid = (a + b) / 2 + V((0, 0, 0.12))
        kit.pipe(bm, [a, V((a.x, a.y, mid.z)), V((b.x, b.y, mid.z)), b], 0.018)


def m_refinery(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    cols = [(-0.32, 0.12, 0.85), (-0.1, 0.1, 0.7), (0.12, 0.08, 0.55)]
    for x, r, h in cols:
        b = V((x * L, -0.12, z))
        kit.vessel(bm, b, r, h, WHITE)
        for t in np.linspace(0.25, 0.85, 4):
            geom.ring(bm, b + V((0, 0, h * t)), V((0, 0, 1)), r, 0.02, 0.012, METAL)
        kit.pipe(bm, [b + V((0, 0, h + 0.04)), b + V((0, 0.25, h + 0.04)), b + V((0, 0.25, 0.12))], 0.02)
    kit.vessel(bm, V((L * 0.36, 0.0, z)), 0.15, 0.3, FOIL if foil else ACCENT, V((0, 0, 1)))
    kit.housing(bm, V((L * 0.05, 0.25, z + 0.1)), (L * 0.6, 0.18, 0.2), PANEL, rng)


def m_bioreactor(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    for x in (-0.25, 0.25):
        b = V((x * L, 0, z))
        kit.vessel(bm, b, 0.17, 0.42, WHITE)
        for k in range(6):  # viewing ports
            a = math.tau * k / 6
            kit.disc(bm, b + V((0.172 * math.cos(a), 0.172 * math.sin(a), 0.25)), V((math.cos(a), math.sin(a), 0)), 0.035, 0.008, SOLAR)
    kit.pipe(bm, [V((-0.25 * L, 0, z + 0.48)), V((-0.25 * L, 0, z + 0.56)), V((0.25 * L, 0, z + 0.56)), V((0.25 * L, 0, z + 0.48))], 0.02)
    geom.box(bm, V((0, 0, z + 0.08)), (0.12, 0.3, 0.16), DARK)


def m_greenhouse(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    h = 0.4
    for x in np.linspace(-L / 2, L / 2, 5):  # arched ribs
        pts = [V((x, Y / 2 * math.cos(a), z + h * math.sin(a))) for a in np.linspace(0, math.pi, 9)]
        for a, b in zip(pts, pts[1:]):
            geom.strut(bm, a, b, 0.012, METAL, seg=6)
    arc = [V((0, Y / 2 * math.cos(a), h * math.sin(a))) for a in np.linspace(0, math.pi, 13)]
    for p0, p1 in zip(arc, arc[1:]):  # glazing panes
        mid = (p0 + p1) / 2
        out = V((0, mid.y, mid.z)).normalized()
        geom.box_between(bm, V((-L / 2, mid.y, z + mid.z)), V((L / 2, mid.y, z + mid.z)), (p1 - p0).length, 0.006, SOLAR, up=out)
    for k in range(6):  # plants
        geom.sphere(bm, V((-L * 0.35 + L * 0.14 * k, 0, z + 0.08)), 0.08, ACCENT, 2)


def _machine_base(bm, L, Y, z, rng, h=0.12):
    kit.housing(bm, V((0, 0, z + h / 2)), (L * 0.9, Y * 0.8, h), PANEL, rng, vents=False)
    return z + h


def m_lathe(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    top = _machine_base(bm, L, Y, z, rng)
    geom.box(bm, V((-L * 0.35, 0, top + 0.12)), (0.16, 0.22, 0.24), ACCENT)  # headstock
    kit.disc(bm, V((-L * 0.27, 0, top + 0.16)), V((1, 0, 0)), 0.08, 0.04, METAL)  # chuck
    kit.cylinder(bm, V((-L * 0.25, 0, top + 0.16)), V((L * 0.25, 0, top + 0.16)), 0.035, METAL)  # workpiece
    geom.box(bm, V((L * 0.34, 0, top + 0.1)), (0.12, 0.16, 0.2), ACCENT)  # tailstock
    for sy in (-1, 1):
        geom.box(bm, V((0, sy * 0.08, top + 0.015)), (L * 0.85, 0.03, 0.03), METAL)  # ways
    geom.box(bm, V((0, -0.1, top + 0.08)), (0.1, 0.06, 0.1), DARK)  # tool post


def m_roller(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    top = _machine_base(bm, L, Y, z, rng)
    for sy in (-1, 1):
        geom.box(bm, V((0, sy * Y * 0.38, top + 0.22)), (0.18, 0.06, 0.44), ACCENT)
    for zz in (0.13, 0.33):
        kit.cylinder(bm, V((0, -Y * 0.38, top + zz)), V((0, Y * 0.38, top + zz)), 0.09, METAL, seg=32)
    geom.box(bm, V((L * 0.25, 0, top + 0.23)), (L * 0.4, Y * 0.6, 0.008), METAL)  # sheet
    geom.box(bm, V((0, 0, top + 0.46)), (0.2, Y * 0.85, 0.06), DARK)


def m_extruder(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    top = _machine_base(bm, L, Y, z, rng)
    kit.cylinder(bm, V((-L * 0.4, 0, top + 0.12)), V((L * 0.3, 0, top + 0.12)), 0.07, METAL)
    for x in np.linspace(-L * 0.3, L * 0.2, 5):
        geom.ring(bm, V((x, 0, top + 0.12)), V((1, 0, 0)), 0.07, 0.02, 0.04, ACCENT)
    geom.lathe(bm, [(0.05, 0.0), (0.16, 0.22), (0.16, 0.3)], WHITE, geom.frame(V((-L * 0.28, 0, top + 0.19)), V((0, 0, 1))), seg=24)  # hopper
    geom.lathe(bm, [(0.07, 0), (0.03, 0.08)], DARK, geom.frame(V((L * 0.3, 0, top + 0.12)), V((1, 0, 0))), seg=24)
    geom.box(bm, V((-L * 0.42, 0, top + 0.12)), (0.12, 0.2, 0.2), DARK)


def m_cutter(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    top = _machine_base(bm, L, Y, z, rng)
    geom.box(bm, V((0, 0, top + 0.01)), (L * 0.8, Y * 0.7, 0.02), DARK)  # bed
    for sy in (-1, 1):
        geom.box(bm, V((L * 0.05, sy * Y * 0.38, top + 0.15)), (0.06, 0.05, 0.3), ACCENT)
    geom.box(bm, V((L * 0.05, 0, top + 0.3)), (0.08, Y * 0.8, 0.06), ACCENT)  # gantry
    geom.box(bm, V((L * 0.05, 0.05, top + 0.22)), (0.1, 0.08, 0.12), METAL)  # head
    kit.cylinder(bm, V((L * 0.05, 0.05, top + 0.16)), V((L * 0.05, 0.05, top + 0.05)), 0.012, GLOW)


def m_grinder(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    top = _machine_base(bm, L, Y, z, rng)
    geom.box(bm, V((-L * 0.1, 0, top + 0.15)), (0.2, 0.2, 0.3), ACCENT)
    kit.cylinder(bm, V((-L * 0.1, -0.12, top + 0.25)), V((-L * 0.1, 0.12, top + 0.25)), 0.03, METAL)
    for sy in (-1, 1):
        kit.disc(bm, V((-L * 0.1, sy * 0.17, top + 0.25)), V((0, 1, 0)), 0.14, 0.05, DARK)
        geom.lathe(bm, [(0.15, -0.04), (0.15, 0.04)], METAL, geom.frame(V((-L * 0.1, sy * 0.17, top + 0.25)), V((0, 1, 0))), seg=32)
    geom.box(bm, V((L * 0.3, 0, top + 0.06)), (0.2, Y * 0.6, 0.12), METAL)


def m_press(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    geom.box(bm, V((0, 0, z + 0.06)), (L * 0.6, Y * 0.7, 0.12), METAL)  # bolster
    for sx in (-1, 1):
        for sy in (-1, 1):
            kit.cylinder(bm, V((sx * L * 0.25, sy * Y * 0.28, z + 0.12)), V((sx * L * 0.25, sy * Y * 0.28, z + 0.62)), 0.03, METAL)
    geom.box(bm, V((0, 0, z + 0.66)), (L * 0.65, Y * 0.75, 0.1), ACCENT)  # crown
    kit.cylinder(bm, V((0, 0, z + 0.62)), V((0, 0, z + 0.36)), 0.08, METAL)  # ram
    geom.box(bm, V((0, 0, z + 0.32)), (L * 0.45, Y * 0.55, 0.06), DARK)  # die
    kit.vessel(bm, V((0, 0, z + 0.71)), 0.09, 0.14, WHITE)


def m_copier(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    kit.housing(bm, V((0, 0, z + 0.15)), (L * 0.8, Y * 0.7, 0.3), PANEL, rng)
    geom.box(bm, V((0, 0, z + 0.305)), (L * 0.6, Y * 0.5, 0.01), SOLAR)  # scan bed
    geom.box(bm, V((-L * 0.1, 0, z + 0.32)), (0.03, Y * 0.55, 0.02), GLOW)
    geom.box(bm, V((L * 0.35, -Y * 0.36, z + 0.2)), (0.1, 0.02, 0.05), DARK)


def m_spider(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    body = V((0, 0, z + 0.28))
    geom.lathe(bm, [(0.0, -0.07), (0.16, -0.05), (0.18, 0.0), (0.12, 0.08), (0.0, 0.09)], PANEL, geom.frame(body, V((0, 0, 1))), seg=32)
    kit.legs(bm, body, 8, Y * 0.75, 0.18, 0.016, ACCENT)
    for k in range(3):
        geom.sphere(bm, body + V((0.15, -0.05 + 0.05 * k, 0.03)), 0.02, GLOW, 1)
    kit.arm(bm, body + V((0, 0, 0.08)), 0.35, rng, "gripper", METAL)


def m_welder(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    for x in (-L * 0.22, L * 0.22):
        kit.arm(bm, V((x, 0, z)), 0.55, rng, "torch")
    geom.box(bm, V((0, -Y * 0.3, z + 0.1)), (0.16, 0.12, 0.2), DARK)


def m_riveter(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    kit.arm(bm, V((-L * 0.2, 0, z)), 0.6, rng, "riveter")
    geom.box(bm, V((L * 0.25, 0, z + 0.1)), (0.24, 0.24, 0.2), PANEL)  # rivet hopper
    geom.lathe(bm, [(0.06, 0), (0.1, 0.08)], METAL, geom.frame(V((L * 0.25, 0, z + 0.2)), V((0, 0, 1))), seg=24)


def m_rail_gun(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    mount = kit.turret(bm, V((-L * 0.3, 0, z)), 0.2)
    end = mount + V((L * 0.95, 0, 0.08))
    for sy in (-1, 1):
        geom.box_between(bm, mount + V((0, sy * 0.05, 0)), end + V((0, sy * 0.05, 0)), 0.03, 0.08, METAL)
    for t in np.linspace(0.1, 0.9, 6):
        p = mount.lerp(end, t)
        geom.box(bm, p, (0.03, 0.16, 0.12), ACCENT)


def m_plasma_gun(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    mount = kit.turret(bm, V((-L * 0.15, 0, z)), 0.22)
    end = mount + V((L * 0.6, 0, 0.05))
    kit.cylinder(bm, mount, end, 0.05, METAL)
    kit.coil(bm, mount + V((0.05, 0, 0.01)), end - V((0.05, 0, 0)), 0.07, 8, 0.012, ACCENT)
    kit.disc(bm, end, V((1, 0, 0)), 0.04, 0.01, GLOW)


def m_beam_weapon(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    mount = kit.turret(bm, V((-L * 0.1, 0, z)), 0.22)
    m = geom.frame(mount, V((1, 0, 0.15)))
    geom.lathe(bm, [(0.08, 0.0), (0.1, 0.25), (0.14, 0.35), (0.12, 0.36)], PANEL, m, seg=32)
    geom.lathe(bm, [(0.12, 0.36), (0.0, 0.33)], SOLAR, m, seg=32)  # lens
    kit.fins(bm, mount + V((-0.1, 0, 0.12)), 0.18, 0.1, 7, "x", RADIATOR)


def m_missile_launcher(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    mount = kit.turret(bm, V((-L * 0.25, 0, z)), 0.2)
    box_c = mount + V((L * 0.25, 0, 0.12))
    geom.box(bm, box_c, (L * 0.6, 0.36, 0.26), PANEL)
    for i in range(3):
        for j in range(2):
            kit.disc(bm, box_c + V((L * 0.3 + 0.003, -0.11 + 0.11 * i, -0.06 + 0.12 * j)), V((1, 0, 0)), 0.045, 0.006, DARK)
            geom.sphere(bm, box_c + V((L * 0.3 - 0.02, -0.11 + 0.11 * i, -0.06 + 0.12 * j)), 0.035, ACCENT, 2)


def _charges(bm, nodes, W, rng, foil, shape: str, mat: int):
    L, Y, z = _pallet(bm, nodes, W)
    for i in range(4):
        for j in range(3):
            c = V((-L * 0.36 + L * 0.24 * i, -Y * 0.3 + Y * 0.3 * j, z))
            if shape == "shell":
                geom.lathe(bm, [(0.0, 0.0), (0.06, 0.0), (0.06, 0.2), (0.0, 0.3)], mat, geom.frame(c, V((0, 0, 1))), seg=20)
            elif shape == "cell":
                kit.vessel(bm, c, 0.06, 0.24, mat)
                geom.sphere(bm, c + V((0, 0, 0.26)), 0.03, GLOW, 1)
            elif shape == "capacitor":
                kit.cylinder(bm, c, c + V((0, 0, 0.22)), 0.065, mat)
                kit.cylinder(bm, c + V((0.02, 0, 0.22)), c + V((0.02, 0, 0.26)), 0.01, METAL)
                kit.cylinder(bm, c + V((-0.02, 0, 0.22)), c + V((-0.02, 0, 0.26)), 0.01, METAL)
            elif shape == "missile":
                if j != 1:
                    m = geom.frame(c + V((-L * 0.12, 0, 0.12)), V((1, 0, 0)))
                    geom.lathe(bm, [(0.0, 0.0), (0.05, 0.0), (0.05, L * 0.75), (0.0, L * 0.85)], mat, m, seg=20)
    for zz in (0.08, 0.2):
        geom.box(bm, V((0, 0, z + zz)), (L * 0.95, 0.02, 0.02), METAL)


def m_kinetic_charges(bm, nodes, W, rng, foil):
    _charges(bm, nodes, W, rng, foil, "shell", ENGINE)


def m_plasma_charges(bm, nodes, W, rng, foil):
    _charges(bm, nodes, W, rng, foil, "cell", WHITE)


def m_beam_charges(bm, nodes, W, rng, foil):
    _charges(bm, nodes, W, rng, foil, "capacitor", ACCENT)


def m_missiles(bm, nodes, W, rng, foil):
    _charges(bm, nodes, W, rng, foil, "missile", WHITE)


def m_kinetic_armor(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    for k in range(5):
        geom.box(bm, V((0, 0, z + 0.03 + 0.07 * k)), (L * (0.86 - 0.04 * k), Y * (0.8 - 0.04 * k), 0.05), METAL if k % 2 else DARK)


def m_thermal_armor(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    n, m = 6, 4
    for i in range(n):
        for j in range(m):
            geom.box(bm, V((-L * 0.42 + L * 0.84 * (i + 0.5) / n, -Y * 0.4 + Y * 0.8 * (j + 0.5) / m, z + 0.06 + 0.005 * ((i + j) % 2))), (L * 0.84 / n * 0.95, Y * 0.8 / m * 0.95, 0.1), DARK if (i * 3 + j) % 5 else PANEL)


def m_shield_generator(bm, nodes, W, rng, foil):
    L, Y, z = _pallet(bm, nodes, W)
    kit.housing(bm, V((0, 0, z + 0.1)), (L * 0.6, Y * 0.6, 0.2), PANEL, rng)
    geom.strut(bm, V((0, 0, z + 0.2)), V((0, 0, z + 0.45)), 0.04, METAL)
    geom.ring(bm, V((0, 0, z + 0.45)), V((0, 0, 1)), 0.2, 0.04, 0.05, ENGINE)
    geom.sphere(bm, V((0, 0, z + 0.45)), 0.07, GLOW, 3)
    for k in range(4):
        a = math.tau * k / 4
        geom.strut(bm, V((0, 0, z + 0.45)), V((0.2 * math.cos(a), 0.2 * math.sin(a), z + 0.45)), 0.01, METAL)


MODULES = {
    5001: m_engine, 5011: m_shuttle, 5101: m_slug, 5111: m_crawler, 5121: m_skimmer, 5131: m_worm,
    5201: m_smelter, 5211: m_chemical_reactor, 5221: m_refinery, 5301: m_bioreactor, 5311: m_greenhouse,
    5401: m_lathe, 5411: m_roller, 5421: m_extruder, 5431: m_cutter, 5441: m_grinder, 5451: m_press,
    5461: m_copier, 5501: m_spider, 5511: m_welder, 5521: m_riveter, 5601: m_rail_gun, 5611: m_plasma_gun,
    5621: m_beam_weapon, 5631: m_missile_launcher, 5701: m_kinetic_charges, 5711: m_plasma_charges,
    5721: m_beam_charges, 5731: m_missiles, 5801: m_kinetic_armor, 5811: m_thermal_armor, 5821: m_shield_generator,
}

# Heavier modules take two truss bays.
TWO_BAY = {5011, 5101, 5121, 5131, 5221, 5311, 5401, 5451, 5601}


def span(rid: int) -> int:
    return 2 if rid in TWO_BAY else 1


def find(query: str) -> list[Resource]:
    """Resources matching an id, a name, a slug or a tier (systems, modules, all)."""
    cat = catalogue()
    q = query.strip().lower()
    renderable = {**SYSTEMS, **MODULES}
    if q in ("all", "systems", "modules"):
        return [r for i, r in sorted(cat.items()) if i in renderable and (q == "all" or r.tier + "s" == q)]
    if q.isdigit() and int(q) in cat:
        return [cat[int(q)]]
    hits = [r for r in cat.values() if q in (r.slug, r.name.lower(), re.sub(r"\s*\(.*?\)", "", r.name).lower())]
    if not hits:
        raise SystemExit(f"No resource matches '{query}'. Use an id, a name, 'systems', 'modules' or 'all'.")
    return hits


def module_builder(name):
    """The builder for a module, by resource id or name, for mounting on drones."""
    hits = [r for r in find(str(name)) if r.id in MODULES]
    if not hits:
        raise SystemExit(f"'{name}' is not a drone part or a module resource")
    return MODULES[hits[0].id]


def build(res: Resource, seed: int = 0):
    """Build one resource as a standalone object at the origin. Returns it."""
    import bmesh

    from . import materials
    from .drone_parts import MATERIAL_SLOTS

    rng = np.random.default_rng(res.id + seed)
    bm = bmesh.new()
    if res.id in SYSTEMS:
        SYSTEMS[res.id](bm, rng)
    elif res.id in MODULES:
        bay = 1.2
        n = span(res.id)
        nodes = [bay * (k - n / 2) for k in range(n + 1)]
        MODULES[res.id](bm, nodes, 1.0, rng, False)
    else:
        raise SystemExit(f"{res.name} ({res.id}) has no model yet: only systems and modules do so far (#707)")
    obj = geom.make_object(res.name, bm, materials.hard_surface(MATERIAL_SLOTS))
    obj["exm_kind"] = "resource"
    obj["exm_resource_id"] = res.id
    return obj
