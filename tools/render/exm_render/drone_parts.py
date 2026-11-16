"""Parts that bolt onto a drone's truss.

Mounting standard (the "hard point"): every side-mounted part is built in
its own local frame, with
  - the origin at the middle of the truss face it mounts on,
  - +Z pointing straight out from that face,
  - +X along the drone's thrust axis, towards the bow,
  - truss nodes at x in `nodes` and y = +-W/2 on the plane z = 0.
Each part braces itself to those nodes with struts, so it reads as rigidly
fixed: drones accelerate hard, and nothing is allowed to hang off a single
bolt.

End parts (engines, bow fittings) are built in the drone's own frame:
X along the axis, the truss's aft end at x = 0 and bow end at x = length.
"""

from __future__ import annotations

import math

import numpy as np
from mathutils import Vector

from . import geom

# Material slots shared by every part of a drone.
TRUSS, METAL, WHITE, FOIL, PANEL, ENGINE, RADIATOR, ACCENT, SOLAR, DARK, GLOW = range(11)
MATERIAL_SLOTS = ["truss", "metal", "white", "foil", "panel", "engine", "radiator", "accent", "solar", "dark", "glow"]

V = Vector


def _corners(nodes: list[float], W: float) -> list[Vector]:
    return [V((x, y, 0.0)) for x in nodes for y in (-W / 2, W / 2)]


def _nearest(p: Vector, pts: list[Vector], k: int) -> list[Vector]:
    return sorted(pts, key=lambda q: (q - p).length)[:k]


def _pad(bm, p: Vector, s: float) -> None:
    """A bolting pad where a strut meets the truss."""
    geom.box(bm, p + V((0, 0, s * 0.25)), (s, s, s * 0.5), METAL)


def brace(bm, attach: list[Vector], nodes: list[float], W: float, r: float = 0.018, k: int = 2) -> None:
    """Strut every attach point to its k nearest truss nodes."""
    corners = _corners(nodes, W)
    used = set()
    for p in attach:
        for q in _nearest(p, corners, k):
            geom.strut(bm, p, q + V((0, 0, 0.03)), r, METAL)
            used.add(tuple(q))
    for q in used:
        _pad(bm, V(q), 0.07)


# --------------------------------------------------------------- side parts

def tank(bm, nodes, W, rng, foil: bool = False) -> None:
    """A propellant tank lying along the axis, held in two clamp rings."""
    L = nodes[-1] - nodes[0]
    r = W * rng.uniform(0.3, 0.38)
    length = max(L * 0.9, 2.2 * r)
    zc = 0.1 * W + r
    geom.lathe(bm, geom.capsule_profile(r, length), FOIL if foil else WHITE, geom.frame(V((0, 0, zc)), V((1, 0, 0)), V((0, 0, 1))), seg=40)
    for xr in (-(length / 2 - r * 0.9), length / 2 - r * 0.9):
        geom.ring(bm, V((xr, 0, zc)), V((1, 0, 0)), r, 0.02 * W, 0.08 * W, METAL)
        # Saddle: a beam across the face, with two uprights to the ring.
        geom.box_between(bm, V((xr, -W / 2, 0.03)), V((xr, W / 2, 0.03)), 0.06 * W, 0.05 * W, METAL)
        for s in (-1, 1):
            foot = V((xr, s * W * 0.4, 0.05))
            hold = V((xr, s * r * 0.75, zc - r * 0.66))
            geom.strut(bm, foot, hold, 0.022 * W, METAL)
        brace(bm, [V((xr, 0, zc - r))], nodes, W, 0.015 * W, k=2)
    # Feed line from the aft dome down into the truss.
    aft = V((-length / 2 + 0.05, 0, zc - r * 0.4))
    geom.strut(bm, aft, V((nodes[0] + 0.05, 0, 0.05)), 0.02 * W, METAL)


def module(bm, nodes, W, rng, foil: bool = False) -> None:
    """An equipment module: a bevelled box on a pallet, with fittings."""
    L = nodes[-1] - nodes[0]
    lx, ly = L * rng.uniform(0.6, 0.82), W * rng.uniform(0.6, 0.8)
    h = W * rng.uniform(0.35, 0.65)
    z0 = 0.12 * W
    geom.box(bm, V((0, 0, z0 - 0.02 * W)), (L * 0.9, W * 0.88, 0.04 * W), METAL)  # pallet
    geom.box(bm, V((0, 0, z0 + h / 2)), (lx, ly, h), FOIL if foil else PANEL)
    # Legs from the pallet corners to the truss nodes.
    legs = [V((sx * L * 0.42, sy * W * 0.4, z0 - 0.04 * W)) for sx in (-1, 1) for sy in (-1, 1)]
    brace(bm, legs, nodes, W, 0.02 * W, k=1)
    # Fittings: a hatch, vents, connectors and an accent band.
    geom.box(bm, V((0, -ly / 2 - 0.005, z0 + h * 0.5)), (lx * 0.3, 0.02, h * 0.5), ACCENT)
    for i in range(rng.integers(2, 5)):
        sx = rng.uniform(-lx / 2 + 0.1, lx / 2 - 0.1) * 0.8
        sy = rng.uniform(-ly / 2 + 0.1, ly / 2 - 0.1) * 0.8
        s = rng.uniform(0.06, 0.14) * W
        geom.box(bm, V((sx, sy, z0 + h + s * 0.25)), (s * rng.uniform(1, 2), s, s * 0.5), DARK if i % 2 else METAL)
    for k in range(int(lx / (0.08 * W))):
        x = -lx / 2 + 0.08 * W * (k + 0.5)
        geom.box(bm, V((x, ly / 2 + 0.01, z0 + h * 0.7)), (0.03 * W, 0.02 * W, h * 0.3), DARK)
    geom.box(bm, V((0, 0, z0 + h * 0.08)), (lx + 0.01, ly + 0.01, h * 0.06), ACCENT)


def cargo_rack(bm, nodes, W, rng, foil: bool = False) -> None:
    """An open rack frame with crates strapped inside."""
    H = W * rng.uniform(0.55, 0.8)
    z0 = 0.08 * W
    hw = W * 0.46
    xs = list(nodes)
    beam = 0.04 * W
    for z in (z0, z0 + H):
        for y in (-hw, hw):
            geom.box_between(bm, V((xs[0], y, z)), V((xs[-1], y, z)), beam, beam, METAL)
        for x in xs:
            geom.box_between(bm, V((x, -hw, z)), V((x, hw, z)), beam, beam, METAL)
    for x in xs:
        for y in (-hw, hw):
            geom.box_between(bm, V((x, y, z0)), V((x, y, z0 + H)), beam, beam, METAL, up=V((1, 0, 0)))
            geom.strut(bm, V((x, y, 0.0)), V((x, y, z0)), 0.025 * W, METAL)
            _pad(bm, V((x, y, 0.0)), 0.07)
    for x0, x1 in zip(xs, xs[1:]):
        for y in (-hw, hw):
            geom.strut(bm, V((x0, y, z0)), V((x1, y, z0 + H)), 0.012 * W, METAL)

    # Crates: fill each bay with a random stack, leaving gaps.
    colours = [PANEL, PANEL, WHITE, ACCENT, FOIL, DARK]
    for x0, x1 in zip(xs, xs[1:]):
        x = x0 + 0.04
        while x < x1 - 0.15:
            cl = min(rng.uniform(0.25, 0.5) * W, x1 - 0.04 - x)
            y = -hw + 0.03
            while y < hw - 0.12:
                cw = min(rng.uniform(0.25, 0.5) * W, hw - 0.03 - y)
                ch = rng.uniform(0.35, 0.95) * H
                if rng.random() < 0.85:
                    geom.box(bm, V((x + cl / 2, y + cw / 2, z0 + beam / 2 + ch / 2)), (cl * 0.96, cw * 0.96, ch), colours[rng.integers(len(colours))])
                y += cw
            x += cl
        # Straps over the top.
        for xm in (x0 + (x1 - x0) * 0.3, x0 + (x1 - x0) * 0.7):
            geom.box(bm, V((xm, 0, z0 + H)), (0.03 * W, 2 * hw, 0.015 * W), ACCENT)


def radiator(bm, nodes, W, rng, foil: bool = False) -> None:
    """A heat-rejection panel standing out from the truss, edge-on."""
    L = nodes[-1] - nodes[0]
    span = W * rng.uniform(1.2, 2.0)
    pl = L * 0.85
    root = 0.15 * W
    geom.box(bm, V((0, 0, root + span / 2)), (pl, 0.025 * W, span), RADIATOR)
    for k in range(9):
        x = -pl / 2 + pl * (k + 0.5) / 9
        geom.box(bm, V((x, 0, root + span / 2)), (0.02 * W, 0.04 * W, span), METAL)
    geom.box(bm, V((0, 0, root)), (pl, 0.07 * W, 0.06 * W), METAL)  # manifold
    feet = [V((sx * pl * 0.45, 0, root)) for sx in (-1, 0, 1)]
    brace(bm, feet, nodes, W, 0.02 * W, k=2)


def solar_array(bm, nodes, W, rng, foil: bool = False) -> None:
    """A mast with a wing of solar panels extending outward."""
    L = nodes[-1] - nodes[0]
    mast = 0.3 * W
    geom.strut(bm, V((0, 0, 0.05)), V((0, 0, mast)), 0.04 * W, METAL)
    geom.box(bm, V((0, 0, mast)), (0.12 * W, 0.12 * W, 0.12 * W), DARK)  # drive
    n = int(rng.integers(3, 6))
    pw, ph = L * 0.8, W * 0.55
    for i in range(n):
        z = mast + 0.1 * W + i * (ph + 0.04 * W) + ph / 2
        geom.box(bm, V((0, 0, z)), (pw, 0.015 * W, ph), SOLAR)
    geom.strut(bm, V((0, 0, mast)), V((0, 0, mast + n * (ph + 0.04 * W) + 0.08 * W)), 0.015 * W, METAL)
    brace(bm, [V((0, 0, 0.05))], nodes, W, 0.02 * W, k=4)


def dish(bm, nodes, W, rng, foil: bool = False) -> None:
    """A high-gain antenna on a tripod mast, angled towards the bow."""
    mast = 0.45 * W
    top = V((0, 0, mast))
    for q in _corners([nodes[0], nodes[-1]] if len(nodes) > 1 else nodes, W)[:4]:
        geom.strut(bm, q * 0.6 + V((0, 0, 0.03)), top, 0.018 * W, METAL)
    geom.box(bm, top, (0.12 * W, 0.12 * W, 0.12 * W), DARK)
    R = W * rng.uniform(0.45, 0.65)
    m = geom.frame(top + V((0.05, 0, 0.1 * W)), V((0.6, 0, 1)))
    prof = [(R * t, 0.3 * R * t * t) for t in np.linspace(0.0, 1.0, 12)]
    prof[0] = (0.0, 0.0)
    geom.lathe(bm, prof, WHITE, m, seg=40)
    feed = m @ V((0, 0, R * 0.75))
    for k in range(3):
        a = math.tau * k / 3
        geom.strut(bm, m @ V((R * 0.9 * math.cos(a), R * 0.9 * math.sin(a), 0.25 * R)), feed, 0.008 * W, METAL)
    geom.sphere(bm, feed, 0.04 * W, DARK, 2)


def sensor(bm, nodes, W, rng, foil: bool = False) -> None:
    """A turret of instruments on a short pedestal."""
    geom.lathe(bm, [(0.2 * W, 0.0), (0.2 * W, 0.08 * W), (0.12 * W, 0.2 * W), (0.12 * W, 0.3 * W)], METAL, cap_end=True)
    geom.box(bm, V((0, 0, 0.42 * W)), (0.35 * W, 0.28 * W, 0.24 * W), PANEL)
    for k, dy in enumerate((-0.07, 0.07)):
        geom.lathe(bm, [(0.06 * W, 0), (0.06 * W, 0.06 * W), (0.045 * W, 0.065 * W)], DARK, geom.frame(V((0.17 * W, dy * W, 0.42 * W)), V((1, 0, 0)), V((0, 0, 1))), seg=24, cap_end=True)
    brace(bm, [V((x, y, 0.02)) for x in (-0.15, 0.15) for y in (-0.15, 0.15)], nodes, W, 0.015 * W, k=1)


SIDE_PARTS = {
    "tank": tank,
    "module": module,
    "cargo": cargo_rack,
    "radiator": radiator,
    "solar": solar_array,
    "dish": dish,
    "sensor": sensor,
}


# ---------------------------------------------------------------- end parts

def _engine(bm, base: Vector, s: float, firing: bool) -> None:
    """One engine hanging aft (towards -X) from a mount point."""
    ax = V((-1, 0, 0))
    m = geom.frame(base, ax, V((0, 0, 1)))
    geom.box(bm, m @ V((0, 0, 0.05 * s)), (0.18 * s, 0.18 * s, 0.1 * s), METAL)  # gimbal block
    geom.lathe(bm, [(0.12 * s, 0.1 * s), (0.14 * s, 0.13 * s), (0.14 * s, 0.38 * s), (0.08 * s, 0.48 * s)], ENGINE, m, seg=32, cap_start=True)
    bell = [(0.08 * s + 0.32 * s * (t ** 0.7), 0.48 * s + 0.75 * s * t) for t in np.linspace(0, 1, 14)]
    geom.lathe(bm, bell, ENGINE, m, seg=48)
    inner = [(r * 0.97, z) for r, z in bell]
    geom.lathe(bm, list(reversed(inner)), GLOW if firing else DARK, m, seg=48)
    geom.ring(bm, m @ V((0, 0, 0.9 * s)), ax, 0.08 * s + 0.32 * s * (0.56 ** 0.7), 0.012 * s, 0.03 * s, METAL)
    # Turbopump and feed lines.
    tp = m @ V((0.2 * s, 0, 0.25 * s))
    geom.lathe(bm, [(0.06 * s, -0.1 * s), (0.06 * s, 0.1 * s)], METAL, geom.frame(tp, V((0, 0, 1))), seg=16, cap_start=True, cap_end=True)
    geom.strut(bm, tp, m @ V((0.13 * s, 0, 0.4 * s)), 0.02 * s, METAL)
    geom.strut(bm, tp, m @ V((0.05 * s, 0, 0.02 * s)), 0.025 * s, METAL)
    geom.strut(bm, m @ V((-0.05 * s, 0.1 * s, 0.02 * s)), m @ V((-0.14 * s, 0, 0.3 * s)), 0.018 * s, METAL)


def engine_block(bm, W: float, count: int, size: float, firing: bool = False) -> float:
    """Thrust structure and engines at the aft end. Returns how far aft it reaches."""
    depth = 0.35 * W
    plate_x = -depth
    corners = [V((0, y, z)) for y in (-W / 2, W / 2) for z in (-W / 2, W / 2)]
    if count == 1:
        mounts = [V((plate_x, 0, 0))]
    else:
        ring_r = 0.32 * W * size * (1.0 if count <= 4 else 1.25)
        mounts = [V((plate_x, ring_r * math.cos(math.tau * k / count + math.pi / 4), ring_r * math.sin(math.tau * k / count + math.pi / 4))) for k in range(count)]
    # Thrust plate and the cone of struts that spreads thrust into the truss.
    pr = max(0.3 * W, max((m.yz.length for m in mounts), default=0) + 0.15 * W * size)
    geom.lathe(bm, [(0.0, -0.04 * W), (pr, -0.04 * W), (pr, 0.04 * W), (0.0, 0.04 * W)], METAL, geom.frame(V((plate_x, 0, 0)), V((1, 0, 0))), seg=24)
    for c in corners:
        for k in range(4):
            a = math.tau * k / 4 + math.pi / 4
            p = V((plate_x + 0.04 * W, pr * 0.9 * math.cos(a), pr * 0.9 * math.sin(a)))
            if (p - c).length < W * 0.9:
                geom.strut(bm, c, p, 0.025 * W, TRUSS)
    for c in corners:
        geom.box(bm, c, (0.1 * W, 0.1 * W, 0.1 * W), METAL)
    es = W * (0.75 if count == 1 else 0.5) * size
    for mp in mounts:
        _engine(bm, mp + V((-0.04 * W, 0, 0)), es, firing)
    return depth + 1.25 * es


def rcs_quads(bm, W: float, length: float) -> None:
    """Attitude thruster clusters on the four corners at each end."""
    for x in (0.15 * W, length - 0.15 * W):
        for y in (-1, 1):
            for z in (-1, 1):
                c = V((x, y * (W / 2 + 0.07 * W), z * (W / 2 + 0.07 * W)))
                geom.box(bm, c, (0.12 * W, 0.12 * W, 0.12 * W), METAL)
                for d in (V((1, 0, 0)), V((-1, 0, 0)), V((0, y, 0)), V((0, 0, z))):
                    p = c + d * 0.06 * W
                    geom.lathe(bm, [(0.015 * W, 0), (0.03 * W, 0.05 * W)], DARK, geom.frame(p, d), seg=12)


def bow(bm, W: float, length: float, kind: str, rng) -> float:
    """Fittings on the bow end. Returns how far forward they reach."""
    x0 = length
    corners = [V((x0, y, z)) for y in (-W / 2, W / 2) for z in (-W / 2, W / 2)]
    if kind == "dock":
        hub = V((x0 + 0.3 * W, 0, 0))
        for c in corners:
            geom.strut(bm, c, hub + V((0, c.y * 0.5, c.z * 0.5)), 0.025 * W, TRUSS)
        m = geom.frame(hub, V((1, 0, 0)))
        geom.lathe(bm, [(0.0, 0.0), (0.35 * W, 0.0), (0.35 * W, 0.12 * W), (0.3 * W, 0.18 * W), (0.22 * W, 0.18 * W), (0.22 * W, 0.12 * W)], PANEL, m, seg=40)
        geom.ring(bm, m @ V((0, 0, 0.16 * W)), V((1, 0, 0)), 0.3 * W, 0.03 * W, 0.04 * W, ACCENT)
        for k in range(3):
            a = math.tau * k / 3
            geom.box(bm, m @ V((0.3 * W * math.cos(a), 0.3 * W * math.sin(a), 0.22 * W)), (0.06 * W, 0.06 * W, 0.1 * W), METAL)
        return 0.5 * W
    if kind == "drill":
        hub = V((x0 + 0.25 * W, 0, 0))
        for c in corners:
            geom.strut(bm, c, hub + V((0, c.y * 0.6, c.z * 0.6)), 0.035 * W, TRUSS)
        m = geom.frame(hub, V((1, 0, 0)))
        geom.lathe(bm, [(0.0, -0.05 * W), (0.42 * W, -0.05 * W), (0.42 * W, 0.15 * W), (0.25 * W, 0.25 * W), (0.25 * W, 0.6 * W)], METAL, m, seg=40)
        geom.lathe(bm, [(0.32 * W, 0.6 * W), (0.32 * W, 0.75 * W), (0.05 * W, 1.45 * W), (0.0, 1.5 * W)], DARK, m, seg=40, cap_start=True)
        # Cutting flutes spiralling round the bit.
        for k in range(4):
            pts = []
            for t in np.linspace(0.0, 1.0, 16):
                a = math.tau * k / 4 + t * 3.0
                rr = 0.33 * W * (1 - t) + 0.04 * W
                pts.append(m @ V((rr * math.cos(a), rr * math.sin(a), 0.72 * W + 0.7 * W * t)))
            for p0, p1 in zip(pts, pts[1:]):
                geom.strut(bm, p0, p1, 0.035 * W, METAL, seg=6)
                geom.sphere(bm, p1, 0.035 * W, METAL, 1)
        for k in range(4):
            a = math.tau * k / 4
            geom.lathe(bm, [(0.08 * W, -0.25 * W), (0.08 * W, 0.25 * W)], ACCENT, geom.frame(m @ V((0.48 * W * math.cos(a), 0.48 * W * math.sin(a), 0.1 * W)), V((1, 0, 0))), seg=16, cap_start=True, cap_end=True)
        return 2.0 * W
    if kind == "grapple":
        hub = V((x0 + 0.2 * W, 0, 0))
        geom.box(bm, hub, (0.3 * W, 0.5 * W, 0.5 * W), METAL)
        for c in corners:
            geom.strut(bm, c, hub, 0.03 * W, TRUSS)
        for k in range(3):
            a = math.tau * k / 3
            d = V((0, math.cos(a), math.sin(a)))
            j1 = hub + V((0.15 * W, 0, 0)) + d * 0.2 * W
            j2 = j1 + V((0.5 * W, 0, 0)) + d * 0.35 * W
            j3 = j2 + V((0.45 * W, 0, 0)) - d * 0.3 * W
            geom.strut(bm, j1, j2, 0.04 * W, ACCENT)
            geom.strut(bm, j2, j3, 0.03 * W, ACCENT)
            geom.sphere(bm, j2, 0.055 * W, METAL, 2)
        return 1.3 * W
    # "sensor": a plain nose with an instrument cluster.
    sensor(bm, [-0.5, 0.5], W, rng)
    return 0.3 * W


def core_tank(bm, x0: float, x1: float, W: float, foil: bool) -> None:
    """A spherical tank nested inside a truss bay, slung from its corner nodes."""
    xc = (x0 + x1) / 2
    r = min(0.44 * W, (x1 - x0) * 0.44)
    geom.sphere(bm, V((xc, 0, 0)), r, FOIL if foil else WHITE, 4)
    geom.ring(bm, V((xc, 0, 0)), V((1, 0, 0)), r, 0.02 * W, 0.06 * W, METAL)
    for x in (x0, x1):
        for y in (-W / 2, W / 2):
            for z in (-W / 2, W / 2):
                hold = V((xc, 0, 0)) + V((0, y, z)).normalized() * (r + 0.02 * W)
                geom.strut(bm, V((x, y, z)), hold, 0.012 * W, METAL)
