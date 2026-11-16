"""Planets: procedural surfaces, clouds, atmospheres and rings.

A planet is built at the origin with radius 1 and scaled into a scene. Its
surface is entirely in the shader, so it stays sharp at any size:

- gas: turbulent bands, like Jupiter or Saturn;
- ocean: continents, shallow seas and ice caps, with a cloud layer;
- rocky: dry highlands and lowlands, like Mars;
- ice: pale, cracked ice, like Europa;
- lava: dark crust with glowing rifts.

Atmospheres glow on the limb, brightest on the sunlit side. Rings are a flat
annulus with banded, partly see-through ringlets, and cast shadows.
Moons use asteroid.py with near-round settings (see moon()).
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field

import bmesh
import bpy
from mathutils import Vector

from . import geom


@dataclass
class PlanetParams:
    name: str = "planet"
    seed: int = 1
    kind: str = "gas"  # gas, ocean, rocky, ice, lava
    palette: list[tuple[float, float, float]] = field(default_factory=list)  # band or terrain colours, low to high
    oblate: float = 1.0  # polar / equatorial radius; gas giants spin fast and bulge
    clouds: float = 0.0  # 0 none .. 1 overcast
    atmosphere: tuple[float, float, float] | None = None  # limb glow colour
    atmosphere_strength: float = 2.0
    rings: tuple[float, float] | None = None  # inner and outer radius, in planet radii
    ring_colour: tuple[float, float, float] = (0.75, 0.68, 0.55)
    tilt: float = 0.0  # axial tilt, degrees


PALETTES = {
    "gas": [(0.55, 0.42, 0.3), (0.85, 0.75, 0.6), (0.7, 0.5, 0.35), (0.92, 0.88, 0.8), (0.6, 0.35, 0.22)],
    "ice-giant": [(0.35, 0.6, 0.75), (0.45, 0.7, 0.82), (0.3, 0.55, 0.72), (0.5, 0.75, 0.85), (0.4, 0.62, 0.78)],
    "ocean": [(0.01, 0.04, 0.12), (0.02, 0.12, 0.25), (0.55, 0.5, 0.35), (0.15, 0.3, 0.08), (0.35, 0.28, 0.18), (0.9, 0.9, 0.92)],
    "rocky": [(0.25, 0.1, 0.05), (0.5, 0.22, 0.1), (0.65, 0.35, 0.18), (0.45, 0.3, 0.22), (0.85, 0.8, 0.78)],
    "ice": [(0.55, 0.5, 0.45), (0.8, 0.82, 0.85), (0.92, 0.94, 0.97)],
    "lava": [(0.03, 0.02, 0.02), (0.1, 0.07, 0.06), (0.2, 0.15, 0.12)],
}


# ------------------------------------------------------------ node helpers

def _new(nt, kind, **inputs):
    n = nt.nodes.new(kind)
    for k, v in inputs.items():
        n.inputs[k].default_value = v
    return n


def _ramp(nt, stops, interpolation="LINEAR"):
    """A colour ramp from [(position, (r, g, b[, a]))]."""
    r = nt.nodes.new("ShaderNodeValToRGB")
    cr = r.color_ramp
    cr.interpolation = interpolation
    while len(cr.elements) < len(stops):
        cr.elements.new(0.5)
    for el, (pos, col) in zip(cr.elements, stops):
        el.position = pos
        el.color = (*col, 1.0) if len(col) == 3 else col
    return r


def _coords(nt, seed, warp=0.0, warp_scale=2.0):
    """Object coordinates, offset by the seed and optionally warped by noise."""
    tc = nt.nodes.new("ShaderNodeTexCoord")
    add = _new(nt, "ShaderNodeVectorMath")
    add.operation = "ADD"
    add.inputs[1].default_value = ((seed * 7.3) % 50, (seed * 3.1) % 50, (seed * 5.7) % 50)
    nt.links.new(tc.outputs["Object"], add.inputs[0])
    vec = add.outputs["Vector"]
    if warp:
        n = _new(nt, "ShaderNodeTexNoise", Scale=warp_scale, Detail=6.0)
        nt.links.new(vec, n.inputs["Vector"])
        sub = _new(nt, "ShaderNodeVectorMath")
        sub.operation = "SUBTRACT"
        sub.inputs[1].default_value = (0.5, 0.5, 0.5)
        nt.links.new(n.outputs["Color"], sub.inputs[0])
        sc = _new(nt, "ShaderNodeVectorMath")
        sc.operation = "SCALE"
        sc.inputs["Scale"].default_value = warp
        nt.links.new(sub.outputs["Vector"], sc.inputs[0])
        add2 = _new(nt, "ShaderNodeVectorMath")
        add2.operation = "ADD"
        nt.links.new(vec, add2.inputs[0])
        nt.links.new(sc.outputs["Vector"], add2.inputs[1])
        vec = add2.outputs["Vector"]
    return vec, tc


def _material(name):
    mat = bpy.data.materials.new(name)
    nt = mat.node_tree
    nt.nodes.clear()
    out = nt.nodes.new("ShaderNodeOutputMaterial")
    return mat, nt, out


# ---------------------------------------------------------------- surfaces

def _surface(p: PlanetParams):
    mat, nt, out = _material(f"{p.name} surface")
    bsdf = _new(nt, "ShaderNodeBsdfPrincipled")
    bsdf.inputs["Diffuse Roughness"].default_value = 0.5
    nt.links.new(bsdf.outputs["BSDF"], out.inputs["Surface"])
    pal = p.palette or PALETTES[p.kind]

    if p.kind == "gas":
        vec, _ = _coords(nt, p.seed, warp=0.12, warp_scale=2.5)
        wave = _new(nt, "ShaderNodeTexWave", Scale=1.6, Distortion=2.2, Detail=3.0, **{"Detail Roughness": 0.45})
        wave.wave_type = "BANDS"
        wave.bands_direction = "Z"
        nt.links.new(vec, wave.inputs["Vector"])
        ramp = _ramp(nt, [(i / (len(pal) - 1), c) for i, c in enumerate(pal)], "EASE")
        nt.links.new(wave.outputs["Fac"], ramp.inputs["Fac"])
        nt.links.new(ramp.outputs["Color"], bsdf.inputs["Base Color"])
        bsdf.inputs["Roughness"].default_value = 0.9
        return mat

    vec, tc = _coords(nt, p.seed, warp=0.3 if p.kind in ("ice", "lava") else 0.0)
    terrain = _new(nt, "ShaderNodeTexNoise", Scale=1.8, Detail=14.0, Roughness=0.62)
    nt.links.new(vec, terrain.inputs["Vector"])
    fac = terrain.outputs["Fac"]

    if p.kind == "ocean":
        ramp = _ramp(nt, [(0.0, pal[0]), (0.5, pal[1]), (0.52, pal[2]), (0.56, pal[3]), (0.66, pal[4]), (0.74, pal[5])])
        rough = _ramp(nt, [(0.5, (0.12, 0.12, 0.12)), (0.52, (0.85, 0.85, 0.85))], "CONSTANT")
        nt.links.new(fac, rough.inputs["Fac"])
        nt.links.new(rough.outputs["Color"], bsdf.inputs["Roughness"])
    elif p.kind == "rocky":
        ramp = _ramp(nt, [(0.3, pal[0]), (0.45, pal[1]), (0.55, pal[2]), (0.68, pal[3]), (0.78, pal[4])])
        bsdf.inputs["Roughness"].default_value = 0.95
    elif p.kind == "ice":
        ramp = _ramp(nt, [(0.35, pal[1]), (0.6, pal[2])])
        bsdf.inputs["Roughness"].default_value = 0.4
    else:  # lava
        ramp = _ramp(nt, [(0.3, pal[0]), (0.6, pal[1]), (0.75, pal[2])])
        bsdf.inputs["Roughness"].default_value = 0.85
    nt.links.new(fac, ramp.inputs["Fac"])
    colour = ramp.outputs["Color"]

    if p.kind in ("ice", "lava"):
        # Cracks: the edges of Voronoi cells, dark lines on ice, glowing rifts on lava
        vor = _new(nt, "ShaderNodeTexVoronoi", Scale=4.0, Randomness=1.0)
        vor.feature = "DISTANCE_TO_EDGE"
        nt.links.new(vec, vor.inputs["Vector"])
        edge = _ramp(nt, [(0.0, (1, 1, 1)), (0.018, (0, 0, 0))])
        nt.links.new(vor.outputs["Distance"], edge.inputs["Fac"])
        # Fade cracks in and out, so they read as rifts, not a net
        patchy = _new(nt, "ShaderNodeTexNoise", Scale=2.5, Detail=4.0)
        nt.links.new(vec, patchy.inputs["Vector"])
        mask = _ramp(nt, [(0.45, (0, 0, 0)), (0.62, (1, 1, 1))])
        nt.links.new(patchy.outputs["Fac"], mask.inputs["Fac"])
        crack = nt.nodes.new("ShaderNodeMix")
        crack.data_type = "RGBA"
        crack.blend_type = "MULTIPLY"
        crack.inputs["Factor"].default_value = 1.0
        nt.links.new(edge.outputs["Color"], crack.inputs["A"])
        nt.links.new(mask.outputs["Color"], crack.inputs["B"])
        crack_out = crack.outputs["Result"]
        if p.kind == "ice":
            mix = _new(nt, "ShaderNodeMix")
            mix.data_type = "RGBA"
            mix.inputs["B"].default_value = (*pal[0], 1)
            nt.links.new(crack_out, mix.inputs["Factor"])
            nt.links.new(colour, mix.inputs["A"])
            colour = mix.outputs["Result"]
        else:
            bsdf.inputs["Emission Color"].default_value = (1.0, 0.35, 0.05, 1)
            em = nt.nodes.new("ShaderNodeMath")
            em.operation = "MULTIPLY"
            em.inputs[1].default_value = 6.0
            nt.links.new(crack_out, em.inputs[0])
            nt.links.new(em.outputs["Value"], bsdf.inputs["Emission Strength"])

    if p.kind == "ocean":
        # Polar ice caps
        sep = nt.nodes.new("ShaderNodeSeparateXYZ")
        nt.links.new(tc.outputs["Object"], sep.inputs["Vector"])
        absz = nt.nodes.new("ShaderNodeMath")
        absz.operation = "ABSOLUTE"
        nt.links.new(sep.outputs["Z"], absz.inputs[0])
        cap = _ramp(nt, [(0.82, (0, 0, 0)), (0.86, (1, 1, 1))])
        nt.links.new(absz.outputs["Value"], cap.inputs["Fac"])
        mix = _new(nt, "ShaderNodeMix")
        mix.data_type = "RGBA"
        mix.inputs["B"].default_value = (0.92, 0.94, 0.97, 1)
        nt.links.new(cap.outputs["Color"], mix.inputs["Factor"])
        nt.links.new(colour, mix.inputs["A"])
        colour = mix.outputs["Result"]
    nt.links.new(colour, bsdf.inputs["Base Color"])
    return mat


def _clouds(p: PlanetParams):
    mat, nt, out = _material(f"{p.name} clouds")
    vec, _ = _coords(nt, p.seed + 11, warp=0.25)
    n = _new(nt, "ShaderNodeTexNoise", Scale=3.5, Detail=10.0, Roughness=0.6)
    nt.links.new(vec, n.inputs["Vector"])
    lo = 0.62 - 0.25 * p.clouds
    ramp = _ramp(nt, [(lo, (0, 0, 0)), (lo + 0.12, (1, 1, 1))])
    nt.links.new(n.outputs["Fac"], ramp.inputs["Fac"])
    white = _new(nt, "ShaderNodeBsdfPrincipled", Roughness=1.0)
    white.inputs["Base Color"].default_value = (0.95, 0.95, 0.97, 1)
    clear = nt.nodes.new("ShaderNodeBsdfTransparent")
    mix = nt.nodes.new("ShaderNodeMixShader")
    nt.links.new(ramp.outputs["Color"], mix.inputs["Fac"])
    nt.links.new(clear.outputs["BSDF"], mix.inputs[1])
    nt.links.new(white.outputs["BSDF"], mix.inputs[2])
    nt.links.new(mix.outputs["Shader"], out.inputs["Surface"])
    return mat


def _atmosphere(p: PlanetParams, sun_dir: Vector):
    """A thin glowing shell: bright at the limb, on the side facing the sun."""
    mat, nt, out = _material(f"{p.name} atmosphere")
    lw = _new(nt, "ShaderNodeLayerWeight", Blend=0.35)
    rim = nt.nodes.new("ShaderNodeMath")
    rim.operation = "POWER"
    rim.inputs[1].default_value = 2.5
    nt.links.new(lw.outputs["Facing"], rim.inputs[0])
    geo = nt.nodes.new("ShaderNodeNewGeometry")
    dot = nt.nodes.new("ShaderNodeVectorMath")
    dot.operation = "DOT_PRODUCT"
    dot.inputs[1].default_value = tuple(sun_dir)
    nt.links.new(geo.outputs["Normal"], dot.inputs[0])
    lit = _new(nt, "ShaderNodeMapRange")
    lit.inputs["From Min"].default_value = -0.25
    lit.inputs["From Max"].default_value = 0.6
    nt.links.new(dot.outputs["Value"], lit.inputs["Value"])
    strength = nt.nodes.new("ShaderNodeMath")
    strength.operation = "MULTIPLY"
    nt.links.new(rim.outputs["Value"], strength.inputs[0])
    nt.links.new(lit.outputs["Result"], strength.inputs[1])
    scale = nt.nodes.new("ShaderNodeMath")
    scale.operation = "MULTIPLY"
    scale.inputs[1].default_value = p.atmosphere_strength
    nt.links.new(strength.outputs["Value"], scale.inputs[0])
    em = nt.nodes.new("ShaderNodeEmission")
    em.inputs["Color"].default_value = (*p.atmosphere, 1)
    nt.links.new(scale.outputs["Value"], em.inputs["Strength"])
    add = nt.nodes.new("ShaderNodeAddShader")
    nt.links.new(nt.nodes.new("ShaderNodeBsdfTransparent").outputs["BSDF"], add.inputs[0])
    nt.links.new(em.outputs["Emission"], add.inputs[1])
    nt.links.new(add.outputs["Shader"], out.inputs["Surface"])
    return mat


def _rings(p: PlanetParams):
    mat, nt, out = _material(f"{p.name} rings")
    tc = nt.nodes.new("ShaderNodeTexCoord")
    length = nt.nodes.new("ShaderNodeVectorMath")
    length.operation = "LENGTH"
    nt.links.new(tc.outputs["Object"], length.inputs[0])
    ri, ro = p.rings
    t = _new(nt, "ShaderNodeMapRange")
    t.inputs["From Min"].default_value = ri
    t.inputs["From Max"].default_value = ro
    nt.links.new(length.outputs["Value"], t.inputs["Value"])
    # Ringlets: fine radial noise, plus a few broad gaps
    fine = _new(nt, "ShaderNodeTexNoise", Scale=40.0, Detail=4.0)
    fine.noise_dimensions = "1D"
    nt.links.new(t.outputs["Result"], fine.inputs["W"])
    gaps = _ramp(nt, [(0.0, (0.2, 0.2, 0.2)), (0.15, (0.9, 0.9, 0.9)), (0.45, (1, 1, 1)), (0.5, (0.05, 0.05, 0.05)),
                      (0.55, (0.85, 0.85, 0.85)), (0.9, (0.6, 0.6, 0.6)), (1.0, (0.0, 0.0, 0.0))])
    nt.links.new(t.outputs["Result"], gaps.inputs["Fac"])
    dens = nt.nodes.new("ShaderNodeMath")
    dens.operation = "MULTIPLY"
    nt.links.new(fine.outputs["Fac"], dens.inputs[0])
    nt.links.new(gaps.outputs["Color"], dens.inputs[1])
    alpha = _ramp(nt, [(0.1, (0, 0, 0)), (0.55, (1, 1, 1))])
    nt.links.new(dens.outputs["Value"], alpha.inputs["Fac"])
    bsdf = _new(nt, "ShaderNodeBsdfPrincipled", Roughness=0.9)
    tint = _ramp(nt, [(0.0, tuple(c * 0.7 for c in p.ring_colour)), (0.5, p.ring_colour), (1.0, tuple(min(1, c * 1.15) for c in p.ring_colour))])
    nt.links.new(fine.outputs["Fac"], tint.inputs["Fac"])
    nt.links.new(tint.outputs["Color"], bsdf.inputs["Base Color"])
    mix = nt.nodes.new("ShaderNodeMixShader")
    nt.links.new(alpha.outputs["Color"], mix.inputs["Fac"])
    nt.links.new(nt.nodes.new("ShaderNodeBsdfTransparent").outputs["BSDF"], mix.inputs[1])
    nt.links.new(bsdf.outputs["BSDF"], mix.inputs[2])
    nt.links.new(mix.outputs["Shader"], out.inputs["Surface"])
    return mat


def _sphere(name, r, mat, oblate=1.0, segments=160):
    bm = bmesh.new()
    bmesh.ops.create_uvsphere(bm, u_segments=segments, v_segments=segments // 2, radius=r)
    for v in bm.verts:
        v.co.z *= oblate
    obj = geom.make_object(name, bm, [mat])
    obj.modifiers.clear()
    return obj


def build(p: PlanetParams, sun_dir: Vector = Vector((1, 0, 0))) -> bpy.types.Object:
    """Build the planet at the origin, radius 1. Returns an empty holding its parts."""
    root = bpy.data.objects.new(p.name, None)
    bpy.context.scene.collection.objects.link(root)
    parts = [_sphere(f"{p.name} body", 1.0, _surface(p), p.oblate)]
    if p.clouds:
        parts.append(_sphere(f"{p.name} clouds", 1.012, _clouds(p), p.oblate))
    if p.atmosphere:
        parts.append(_sphere(f"{p.name} atmosphere", 1.035, _atmosphere(p, sun_dir.normalized()), p.oblate))
    if p.rings:
        bm = bmesh.new()
        ri, ro = p.rings
        geom.lathe(bm, [(ri, 0.0), (ro, 0.0)], 0, seg=256)
        ring = geom.make_object(f"{p.name} rings", bm, [_rings(p)])
        ring.modifiers.clear()
        parts.append(ring)
    for o in parts:
        o.parent = root
    root.rotation_euler = (math.radians(p.tilt), 0, 0)
    return root


def moon(name: str, seed: int, surface: str = "ceres", lumpy: float = 0.01):
    """A cratered moon: a near-round asteroid. Returns (params, surface key)."""
    from .asteroid import AsteroidParams

    return AsteroidParams(name=name, seed=seed, axes=(1.0, 0.99, 0.97), warp=0.0, lump=lumpy, ridge=0.0,
                          craters=2200, crater_min=0.8, crater_max=14.0, crater_depth=0.12), surface
