"""Uncrewed drones: an open truss with parts bolted rigidly to it.

A drone needs no pressurised hull, so it is a square-section truss of
bays. Engines and their thrust structure sit at the aft end, a fitting
(docking ring, drill, grapple or sensors) at the bow, and every other part
is mounted on one of the four long faces of the truss, spanning one or
more bays. See drone_parts.py for the mounting standard.

The truss is 1 unit wide; everything else is sized from that.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field

import bmesh
import bpy
import numpy as np
from mathutils import Vector

from . import drone_parts as parts
from . import geom, materials, resources

FACES = {
    "top": Vector((0, 0, 1)),
    "bottom": Vector((0, 0, -1)),
    "port": Vector((0, 1, 0)),
    "starboard": Vector((0, -1, 0)),
}


@dataclass
class Mount:
    part: str  # a key of drone_parts.SIDE_PARTS, or a module's resource id or name (see resources.py)
    bay: int  # first bay, counting from the aft end
    face: str  # top, bottom, port or starboard
    span: int = 1  # number of bays it covers
    foil: bool = False  # wrapped in insulating foil instead of painted


@dataclass
class DroneParams:
    name: str = "drone"
    seed: int = 1
    bays: int = 4
    bay_length: float = 1.2  # in truss widths
    engines: int = 1
    engine_size: float = 1.0
    bow: str = "dock"  # dock, drill, grapple or sensor
    core_tanks: list[int] = field(default_factory=list)  # bays with a spherical tank inside
    core_foil: bool = True
    mounts: list[Mount] = field(default_factory=list)
    rcs: bool = True
    firing: bool = False
    accent: tuple[float, float, float] = (0.85, 0.28, 0.03)  # safety orange, linear RGB


def _truss(bm, W: float, nodes: list[float]) -> None:
    h = W / 2
    corners = [(y, z) for y in (-h, h) for z in (-h, h)]
    L0, L1 = nodes[0], nodes[-1]
    for y, z in corners:  # longerons
        geom.strut(bm, Vector((L0, y, z)), Vector((L1, y, z)), 0.035 * W, parts.TRUSS)
    ring = [(-h, -h), (h, -h), (h, h), (-h, h)]
    for i, x in enumerate(nodes):
        for (y0, z0), (y1, z1) in zip(ring, ring[1:] + ring[:1]):  # battens
            geom.strut(bm, Vector((x, y0, z0)), Vector((x, y1, z1)), 0.025 * W, parts.TRUSS)
        for y, z in corners:  # node fittings
            geom.box(bm, Vector((x, y, z)), (0.09 * W, 0.09 * W, 0.09 * W), parts.METAL)
    for i, (x0, x1) in enumerate(zip(nodes, nodes[1:])):  # face diagonals, alternating
        for (y0, z0), (y1, z1) in zip(ring, ring[1:] + ring[:1]):
            a, b = (x0, x1) if i % 2 == 0 else (x1, x0)
            geom.strut(bm, Vector((a, y0, z0)), Vector((b, y1, z1)), 0.017 * W, parts.TRUSS)
    for x in (nodes[0], nodes[-1]):  # stiffen the end frames
        geom.strut(bm, Vector((x, -h, -h)), Vector((x, h, h)), 0.02 * W, parts.TRUSS)
        geom.strut(bm, Vector((x, -h, h)), Vector((x, h, -h)), 0.02 * W, parts.TRUSS)


def _plume(parent, W: float, p: DroneParams, mat) -> None:
    """A faint exhaust plume behind each engine."""
    bm = bmesh.new()
    count = p.engines
    es = W * (0.75 if count == 1 else 0.5) * p.engine_size
    if count == 1:
        centres = [Vector((0, 0, 0))]
    else:
        rr = 0.32 * W * p.engine_size * (1.0 if count <= 4 else 1.25)
        centres = [Vector((0, rr * math.cos(math.tau * k / count + math.pi / 4), rr * math.sin(math.tau * k / count + math.pi / 4))) for k in range(count)]
    x0 = -0.39 * W - 1.2 * es
    exit_r = 0.39 * es
    for c in centres:
        m = geom.frame(Vector((x0, c.y, c.z)), Vector((-1, 0, 0)), Vector((0, 0, 1)))
        prof = [(exit_r * (1 + 1.6 * t), 6 * es * t) for t in np.linspace(0, 1, 16)]
        geom.lathe(bm, prof, 0, m, seg=32)
    obj = geom.make_object("Exhaust", bm, [mat], parent)
    obj.modifiers.clear()
    obj["exm_plume_length"] = 6 * es


def build(p: DroneParams) -> tuple[bpy.types.Object, float]:
    """Build the drone. Returns its root empty, centred on the origin, and its radius."""
    rng = np.random.default_rng(p.seed)
    W = 1.0
    L = p.bays * p.bay_length
    nodes = [i * p.bay_length for i in range(p.bays + 1)]
    mats = materials.hard_surface(parts.MATERIAL_SLOTS, p.accent)

    root = bpy.data.objects.new(p.name, None)
    bpy.context.scene.collection.objects.link(root)
    root["exm_kind"] = "drone"
    root["exm_seed"] = p.seed
    body = bpy.data.objects.new(f"{p.name} body", None)
    bpy.context.scene.collection.objects.link(body)
    body.parent = root

    bm = bmesh.new()
    _truss(bm, W, nodes)
    for b in p.core_tanks:
        parts.core_tank(bm, nodes[b], nodes[b + 1], W, p.core_foil)
    geom.make_object("Truss", bm, mats, body)

    bm = bmesh.new()
    if p.engines > 0:  # a stationary facility (a Barge) has none
        parts.engine_block(bm, W, p.engines, p.engine_size, p.firing)
    if p.rcs:
        parts.rcs_quads(bm, W, L)
    prop = geom.make_object("Propulsion", bm, mats, body)
    if p.engines > 0:
        prop["exm_resource"] = resources.ENGINE_ID  # the Engine (A1) module, for labels (#802)

    bm = bmesh.new()
    parts.bow(bm, W, L, p.bow, rng)
    geom.make_object("Bow", bm, mats, body)

    for i, mt in enumerate(p.mounts):
        builder = parts.SIDE_PARTS.get(mt.part) or resources.module_builder(mt.part)
        if mt.bay < 0 or mt.bay + mt.span > p.bays:
            raise SystemExit(f"Mount {mt.part} on bays {mt.bay}-{mt.bay + mt.span - 1} is off the truss (bays 0-{p.bays - 1})")
        xs = nodes[mt.bay:mt.bay + mt.span + 1]
        xm = (xs[0] + xs[-1]) / 2
        n = FACES[mt.face]
        frame = geom.frame(Vector((xm, 0, 0)) + n * (W / 2), n, Vector((1, 0, 0)))
        bm = bmesh.new()
        builder(bm, [x - xm for x in xs], W, np.random.default_rng(p.seed * 101 + i), mt.foil)
        obj = geom.make_object(f"{str(mt.part).title()} {i + 1} ({mt.face})", bm, mats, body, frame)
        if mt.part not in parts.SIDE_PARTS:  # a game module: tag it with its resource id (#802)
            obj["exm_resource"] = resources.module_id(mt.part)

    if p.firing:
        _plume(body, W, p, materials.exhaust())

    # Centre the drone on the origin and measure it.
    bpy.context.view_layer.update()
    pts = []
    for obj in body.children:
        if obj.type == "MESH" and obj.name != "Exhaust":
            mw = obj.matrix_world
            pts.extend(mw @ v.co for v in obj.data.vertices)
    arr = np.array(pts)
    centre = (arr.min(axis=0) + arr.max(axis=0)) / 2
    body.location = Vector(-centre)
    radius = float(np.linalg.norm(arr - centre, axis=1).max())
    return root, radius
