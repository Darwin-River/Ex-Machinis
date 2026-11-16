"""Reusable assemblies for machines: housings, vessels, pipes, arms, turrets.

Built on geom.py. Sizes are in truss widths (the truss is 1 unit wide), and
material slots are those in drone_parts (TRUSS, METAL, WHITE ...).
"""

from __future__ import annotations

import math

import numpy as np
from mathutils import Vector

from . import geom
from .drone_parts import ACCENT, DARK, ENGINE, METAL, PANEL, RADIATOR, WHITE

V = Vector


def housing(bm, centre: Vector, size, mat: int = PANEL, rng=None, vents: bool = True, band: bool = True) -> None:
    """A machine casing: a box with an accent band, vents and a service panel."""
    lx, ly, lz = size
    geom.box(bm, centre, size, mat)
    if band:
        geom.box(bm, centre + V((0, 0, -lz * 0.38)), (lx + 0.01, ly + 0.01, lz * 0.06), ACCENT)
    if vents:
        n = max(2, int(lx / 0.07))
        for k in range(n):
            x = -lx * 0.4 + lx * 0.8 * (k + 0.5) / n
            geom.box(bm, centre + V((x, ly / 2 + 0.006, lz * 0.2)), (lx * 0.35 / n, 0.012, lz * 0.25), DARK)
    geom.box(bm, centre + V((0, -ly / 2 - 0.006, 0)), (lx * 0.3, 0.012, lz * 0.45), METAL)


def vessel(bm, base: Vector, r: float, h: float, mat: int = WHITE, axis: Vector = V((0, 0, 1)), domes: bool = True) -> None:
    """An upright pressure vessel with domed ends, standing on base."""
    m = geom.frame(base, axis)
    prof = geom.capsule_profile(r, h) if domes else [(0.0, -h / 2), (r, -h / 2), (r, h / 2), (0.0, h / 2)]
    prof = [(pr, z + h / 2) for pr, z in prof]
    geom.lathe(bm, prof, mat, m, seg=32)
    for t in (0.2, 0.8):
        geom.ring(bm, base + axis.normalized() * h * t, axis, r, 0.012, 0.03, METAL)


def pipe(bm, pts: list[Vector], r: float = 0.025, mat: int = METAL) -> None:
    """A pipe run through points, with rounded elbows."""
    for a, b in zip(pts, pts[1:]):
        geom.strut(bm, a, b, r, mat, seg=10)
    for p in pts[1:-1]:
        geom.sphere(bm, p, r * 1.15, mat, 1)
    for p in (pts[0], pts[-1]):
        geom.sphere(bm, p, r * 1.4, METAL, 1)  # flanges


def fins(bm, centre: Vector, length: float, height: float, n: int, axis: str = "x", mat: int = RADIATOR) -> None:
    """A row of cooling fins."""
    for k in range(n):
        t = -length / 2 + length * (k + 0.5) / n
        off = V((t, 0, 0)) if axis == "x" else V((0, t, 0))
        size = (0.012, height, height) if axis == "x" else (height, 0.012, height)
        geom.box(bm, centre + off, size, mat)


def cylinder(bm, a: Vector, b: Vector, r: float, mat: int, seg: int = 24) -> None:
    geom.strut(bm, a, b, r, mat, seg=seg)


def disc(bm, centre: Vector, axis: Vector, r: float, thick: float, mat: int, seg: int = 32) -> None:
    geom.lathe(bm, [(0.0, -thick / 2), (r, -thick / 2), (r, thick / 2), (0.0, thick / 2)], mat, geom.frame(centre, axis), seg=seg)


def arm(bm, base: Vector, reach: float, rng, tool: str = "torch", mat: int = ACCENT) -> Vector:
    """An industrial robot arm on a turntable. Returns the tool tip."""
    s = reach
    disc(bm, base + V((0, 0, 0.03 * s)), V((0, 0, 1)), 0.16 * s, 0.06 * s, METAL)
    shoulder = base + V((0, 0, 0.18 * s))
    geom.box(bm, base + V((0, 0, 0.11 * s)), (0.16 * s, 0.14 * s, 0.14 * s), mat)
    a1 = math.radians(rng.uniform(55, 75))
    a2 = math.radians(rng.uniform(-70, -40))
    yaw = rng.uniform(-0.6, 0.6)
    d = V((math.cos(yaw), math.sin(yaw), 0))
    elbow = shoulder + (d * math.cos(a1) + V((0, 0, math.sin(a1)))) * 0.5 * s
    wrist = elbow + (d * math.cos(a1 + a2) + V((0, 0, math.sin(a1 + a2)))) * 0.45 * s
    geom.box_between(bm, shoulder, elbow, 0.09 * s, 0.11 * s, mat)
    geom.box_between(bm, elbow, wrist, 0.07 * s, 0.08 * s, mat)
    for j, r in ((shoulder, 0.08), (elbow, 0.065), (wrist, 0.05)):
        disc(bm, j, V((-d.y, d.x, 0)), r * s, 0.13 * s, DARK)
    down = V((0, 0, -1))
    tip = wrist + (d * 0.3 + down).normalized() * 0.15 * s
    if tool == "torch":
        geom.strut(bm, wrist, tip, 0.025 * s, METAL)
        geom.lathe(bm, [(0.03 * s, 0), (0.012 * s, 0.06 * s)], DARK, geom.frame(tip, tip - wrist), seg=12)
    elif tool == "riveter":
        geom.box_between(bm, wrist, tip, 0.07 * s, 0.07 * s, METAL)
        geom.strut(bm, tip, tip + (tip - wrist).normalized() * 0.06 * s, 0.012 * s, DARK)
    elif tool == "gripper":
        geom.box(bm, wrist + V((0, 0, -0.04 * s)), (0.08 * s, 0.12 * s, 0.04 * s), METAL)
        for sy in (-1, 1):
            geom.box(bm, wrist + V((0, sy * 0.05 * s, -0.1 * s)), (0.03 * s, 0.015 * s, 0.1 * s), DARK)
    return tip


def turret(bm, base: Vector, r: float, mat: int = PANEL) -> Vector:
    """A rotating weapon mount. Returns the point where the barrel attaches."""
    disc(bm, base + V((0, 0, 0.03 * r / 0.3)), V((0, 0, 1)), r, 0.06, METAL)
    geom.lathe(bm, [(r * 0.9, 0.0), (r * 0.9, 0.12), (r * 0.6, 0.3), (0.0, 0.3)], mat, geom.frame(base + V((0, 0, 0.06)), V((0, 0, 1))), seg=32)
    geom.box(bm, base + V((0.05, 0, 0.3)), (r * 1.1, r * 0.9, 0.16), mat)
    return base + V((r * 0.55, 0, 0.3))


def legs(bm, body: Vector, n: int, span: float, height: float, r: float, mat: int = METAL, phase: float = 0.0) -> None:
    """Jointed walking legs around a body."""
    for k in range(n):
        a = math.tau * k / n + phase
        d = V((math.cos(a), math.sin(a), 0))
        hip = body + d * span * 0.3
        knee = body + d * span * 0.65 + V((0, 0, height * 0.6))
        foot = body + d * span + V((0, 0, -height))
        geom.strut(bm, hip, knee, r, mat)
        geom.strut(bm, knee, foot, r * 0.8, mat)
        geom.sphere(bm, knee, r * 1.4, DARK, 1)
        geom.sphere(bm, foot, r * 1.3, DARK, 1)


def tread(bm, centre: Vector, length: float, r: float, width: float) -> None:
    """A caterpillar track unit."""
    for sx in (-1, 1):
        disc(bm, centre + V((sx * length / 2, 0, 0)), V((0, 1, 0)), r, width, METAL)
    geom.box(bm, centre, (length, width * 1.05, 2 * r), DARK)
    for k in range(int(length / (0.6 * r))):
        x = -length / 2 + (k + 0.5) * length / int(length / (0.6 * r))
        geom.box(bm, centre + V((x, 0, r + 0.005)), (0.25 * r, width * 1.1, 0.04 * r), METAL)
        geom.box(bm, centre + V((x, 0, -r - 0.005)), (0.25 * r, width * 1.1, 0.04 * r), METAL)


def coil(bm, a: Vector, b: Vector, r: float, turns: int, wire: float, mat: int = ENGINE) -> None:
    """A helical coil wound along a-b."""
    m = geom.frame(a, b - a)
    L = (b - a).length
    pts = [m @ V((r * math.cos(t), r * math.sin(t), L * t / (math.tau * turns))) for t in np.linspace(0, math.tau * turns, turns * 16)]
    for p0, p1 in zip(pts, pts[1:]):
        geom.strut(bm, p0, p1, wire, mat, seg=6)
