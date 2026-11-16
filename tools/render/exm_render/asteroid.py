"""Procedural asteroid and comet-nucleus meshes.

Every shape is a function of an AsteroidParams and its seed: the same
parameters always build the same mesh, vertex for vertex.

The mesh is built around a unit icosphere. Each vertex direction gets a
radius from layered effects (ellipsoid axes, a two-lobed waist, lumpy and
ridged noise, impact basins, craters, grooves), then boulders are scattered
over the surface. The finished mesh is scaled so its furthest point is 1
unit from the origin, so materials and cameras never need to know how big
the body is in kilometres.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field

import bmesh
import bpy
import numpy as np
from mathutils import Matrix, Vector
from mathutils import noise


@dataclass
class Basin:
    """A single large, named impact basin, e.g. Rheasilvia on Vesta."""

    lat: float  # degrees
    lon: float  # degrees
    radius: float  # angular radius, degrees
    depth: float  # fraction of the body's radius
    peak: float = 0.0  # central peak height, fraction of radius


@dataclass
class AsteroidParams:
    name: str = "asteroid"
    seed: int = 1
    # Overall shape -------------------------------------------------------
    axes: tuple[float, float, float] = (1.0, 0.8, 0.7)  # relative a, b, c
    waist: float = 0.0  # 0 = none; ~0.4 gives a two-lobed "peanut" or contact binary
    lobe_ratio: float = 1.0  # size of the -x lobe relative to the +x lobe
    bend: float = 0.0  # tilts the two lobes against each other (radians-ish)
    warp: float = 0.15  # domain warp: makes shapes less blob-like
    lump: float = 0.12  # amplitude of low-frequency lumps
    lump_scale: float = 1.2  # frequency of the lumps
    ridge: float = 0.03  # amplitude of sharp ridges and facets
    ridge_scale: float = 3.0
    # Surface features ----------------------------------------------------
    craters: int = 500
    crater_min: float = 1.5  # smallest crater angular radius, degrees
    crater_max: float = 18.0  # largest crater angular radius, degrees
    crater_depth: float = 0.18  # depth / diameter for fresh craters
    basins: list[Basin] = field(default_factory=list)
    grooves: float = 0.0  # equatorial troughs (Vesta's Divalia Fossae)
    boulders: int = 0
    boulder_size: float = 0.02  # largest boulder radius, fraction of body radius
    # Mesh ------------------------------------------------------------------
    subdivisions: int = 7  # icosphere level; 7 = 164k vertices


def _fbm(dirs: np.ndarray, scale: float, offset: Vector, octaves: int = 5, basis: str = "PERLIN_NEW") -> np.ndarray:
    out = np.empty(len(dirs))
    for i, d in enumerate(dirs):
        out[i] = noise.fractal(Vector(d) * scale + offset, 1.0, 2.0, octaves, noise_basis=basis)
    return out


def _ridged(dirs: np.ndarray, scale: float, offset: Vector) -> np.ndarray:
    out = np.empty(len(dirs))
    for i, d in enumerate(dirs):
        out[i] = noise.ridged_multi_fractal(Vector(d) * scale + offset, 1.0, 2.0, 4, 0.9, 2.0, noise_basis="PERLIN_NEW")
    return out


def _warp(dirs: np.ndarray, amount: float, offset: Vector) -> np.ndarray:
    if amount <= 0:
        return dirs
    warped = np.empty_like(dirs)
    for i, d in enumerate(dirs):
        warped[i] = d + amount * np.array(noise.noise_vector(Vector(d) * 1.1 + offset, noise_basis="PERLIN_NEW"))
    return warped / np.linalg.norm(warped, axis=1, keepdims=True)


def _unit(lat: float, lon: float) -> np.ndarray:
    la, lo = math.radians(lat), math.radians(lon)
    return np.array([math.cos(la) * math.cos(lo), math.cos(la) * math.sin(lo), math.sin(la)])


def _crater_profile(t: np.ndarray, depth: np.ndarray, rim: np.ndarray, floor: np.ndarray, peak: np.ndarray) -> np.ndarray:
    """Height (fraction of radius) at normalised distance t from crater centres.

    t = 0 at the centre, 1 at the rim crest. Bowl inside, raised rim and
    ejecta just outside, optional flat floor and central peak for big craters.
    """
    bowl = np.where(t < 1.0, depth * (t * t - 1.0), 0.0)
    bowl = np.maximum(bowl, -depth * floor)
    rims = rim * np.exp(-(((t - 1.0) / 0.32) ** 2))
    peaks = peak * np.exp(-((t / 0.18) ** 2))
    return bowl + rims + peaks


def _apply_craters(dirs: np.ndarray, rng: np.random.Generator, p: AsteroidParams) -> np.ndarray:
    h = np.zeros(len(dirs))
    if p.craters <= 0:
        return h
    # Power-law size distribution, N(>r) ~ r^-2: many small, few large.
    rmin, rmax = math.radians(p.crater_min), math.radians(p.crater_max)
    u = rng.random(p.craters)
    radii = rmin * (1.0 - u * (1.0 - (rmin / rmax) ** 2)) ** -0.5
    centres = rng.normal(size=(p.craters, 3))
    centres /= np.linalg.norm(centres, axis=1, keepdims=True)
    freshness = rng.uniform(0.25, 1.0, p.craters) ** 0.7  # old craters are shallower
    diameter = 2.0 * radii
    depth = p.crater_depth * diameter * freshness
    rim = 0.045 * diameter * freshness
    big = radii > math.radians(9)
    floor = np.where(big, 0.65, 1.0)  # big craters get flatter floors
    peak = np.where(big, 0.25 * depth, 0.0)

    # Largest first so later, smaller craters land on top of them.
    order = np.argsort(-radii)
    for chunk in np.array_split(order, max(1, len(order) // 64)):
        cosang = np.clip(dirs @ centres[chunk].T, -1.0, 1.0)
        t = np.arccos(cosang) / radii[chunk]
        near = t < 2.5
        if not near.any():
            continue
        prof = _crater_profile(t, depth[chunk], rim[chunk], floor[chunk], peak[chunk])
        h += np.where(near, prof, 0.0).sum(axis=1)
    return h


def _apply_basins(dirs: np.ndarray, p: AsteroidParams) -> np.ndarray:
    h = np.zeros(len(dirs))
    for b in p.basins:
        c = _unit(b.lat, b.lon)
        t = np.arccos(np.clip(dirs @ c, -1, 1)) / math.radians(b.radius)
        bowl = np.where(t < 1.0, -b.depth * np.clip(1.0 - t * t, 0.0, None) ** 1.5, 0.0)
        rim = 0.25 * b.depth * np.exp(-(((t - 1.0) / 0.25) ** 2))
        peak = b.peak * np.exp(-((t / 0.22) ** 2))
        # Concentric scarps make big basins read as basins, not dents.
        scarps = np.where(t < 1.0, 0.04 * b.depth * np.sin(t * 18.0) * t, 0.0)
        h += bowl + rim + peak + scarps
    return h


def _apply_grooves(dirs: np.ndarray, p: AsteroidParams) -> np.ndarray:
    if p.grooves <= 0:
        return np.zeros(len(dirs))
    lat = np.arcsin(np.clip(dirs[:, 2], -1, 1))
    band = np.exp(-((lat / math.radians(14)) ** 2))
    troughs = np.maximum(0.0, np.sin(lat * 70.0)) ** 6
    return -p.grooves * band * troughs


def _shape_radius(dirs: np.ndarray, p: AsteroidParams, rng: np.random.Generator) -> np.ndarray:
    o1 = Vector(rng.uniform(-500, 500, 3))
    o2 = Vector(rng.uniform(-500, 500, 3))
    o3 = Vector(rng.uniform(-500, 500, 3))

    wdirs = _warp(dirs, p.warp, o1)

    # Ellipsoid: distance from centre to the surface along each direction.
    a, b, c = p.axes
    x, y, z = dirs[:, 0], dirs[:, 1], dirs[:, 2]
    r = 1.0 / np.sqrt((x / a) ** 2 + (y / b) ** 2 + (z / c) ** 2)

    if p.waist > 0:
        # Pinch around x = 0 to make two lobes, and size the lobes unequally.
        xb = x + p.bend * z
        r *= 1.0 - p.waist * np.exp(-((xb / 0.42) ** 2))
        r *= np.where(xb < 0, p.lobe_ratio, 1.0) ** (np.abs(xb) ** 0.5)

    r *= 1.0 + p.lump * _fbm(wdirs, p.lump_scale, o2, octaves=4)
    if p.ridge > 0:
        r *= 1.0 + p.ridge * (_ridged(wdirs, p.ridge_scale, o3) - 0.6)
    r *= 1.0 + _apply_basins(dirs, p)
    r *= 1.0 + _apply_grooves(dirs, p)
    r *= 1.0 + _apply_craters(dirs, rng, p)
    return r


def _add_boulders(bm: bmesh.types.BMesh, verts: np.ndarray, normals: np.ndarray, rng: np.random.Generator, p: AsteroidParams) -> None:
    if p.boulders <= 0:
        return
    idx = rng.choice(len(verts), p.boulders, replace=False)
    u = rng.random(p.boulders)
    rmax = p.boulder_size
    rmin = rmax / 8.0
    sizes = rmin * (1.0 - u * (1.0 - (rmin / rmax) ** 2.5)) ** -0.4
    for k, (i, s) in enumerate(zip(idx, sizes)):
        pos = Vector(verts[i]) + Vector(normals[i]) * s * 0.35  # half buried
        rot = Matrix.Rotation(rng.uniform(0, math.tau), 4, Vector(rng.normal(size=3)).normalized())
        stretch = Matrix.Diagonal((1.0, rng.uniform(0.6, 1.0), rng.uniform(0.45, 0.8), 1.0))
        geom = bmesh.ops.create_icosphere(bm, subdivisions=2, radius=s, matrix=Matrix.Translation(pos) @ rot @ stretch)
        off = Vector(rng.uniform(-500, 500, 3))
        centre = pos
        for v in geom["verts"]:
            d = v.co - centre
            v.co = centre + d * (1.0 + 0.35 * noise.noise(d / s * 1.3 + off, noise_basis="PERLIN_NEW"))


def build(p: AsteroidParams, collection: bpy.types.Collection | None = None) -> bpy.types.Object:
    """Build the asteroid as a new mesh object at the origin and return it."""
    rng = np.random.default_rng(p.seed)

    bm = bmesh.new()
    bmesh.ops.create_icosphere(bm, subdivisions=p.subdivisions, radius=1.0)
    bm.verts.ensure_lookup_table()
    dirs = np.array([v.co[:] for v in bm.verts])
    dirs /= np.linalg.norm(dirs, axis=1, keepdims=True)

    r = _shape_radius(dirs, p, rng)
    pts = dirs * r[:, None]
    for v, co in zip(bm.verts, pts):
        v.co = co

    bm.normal_update()
    normals = np.array([v.normal[:] for v in bm.verts])
    _add_boulders(bm, pts, normals, rng, p)

    # Normalise so the furthest point is 1 unit from the origin.
    scale = 1.0 / max(v.co.length for v in bm.verts)
    bmesh.ops.scale(bm, vec=(scale, scale, scale), verts=bm.verts)

    mesh = bpy.data.meshes.new(p.name)
    bm.to_mesh(mesh)
    bm.free()
    for poly in mesh.polygons:
        poly.use_smooth = True

    obj = bpy.data.objects.new(p.name, mesh)
    (collection or bpy.context.scene.collection).objects.link(obj)
    obj["exm_kind"] = "asteroid"
    obj["exm_seed"] = p.seed
    return obj
