"""Surface materials for rocky bodies.

Each surface type is a small set of numbers. The shader adds the detail
that is too fine to model: colour patches, pits, grit and boulders too
small to place one by one. All texture coordinates are in object space, so
a body looks the same whichever way it is turned.
"""

from __future__ import annotations

from dataclasses import dataclass

import bpy


@dataclass
class Surface:
    albedo: tuple[float, float, float]  # base colour (linear)
    alt: tuple[float, float, float]  # second colour for patches
    patchiness: float = 0.5  # how strongly the two colours mix
    roughness: float = 0.95
    metallic: float = 0.0
    grit: float = 0.5  # strength of small-scale bump
    pits: float = 1.0  # strength of small, unmodelled craters
    sparkle: float = 0.0  # specular glints (metal grains)


SURFACES: dict[str, Surface] = {
    # Dark, carbon-rich C-types such as Hygiea; albedo around 0.05-0.07.
    "carbonaceous": Surface((0.052, 0.050, 0.048), (0.035, 0.034, 0.033), 0.45),
    # Pallas is a B-type: brighter and slightly blue-grey.
    "b-type": Surface((0.12, 0.125, 0.135), (0.085, 0.087, 0.092), 0.4),
    # S-types such as Juno: stony, a little reddish, moderately bright.
    "stony": Surface((0.21, 0.18, 0.145), (0.15, 0.125, 0.10), 0.55),
    # Vesta: bright basalt with darker, carbon-rich patches.
    "basaltic": Surface((0.34, 0.31, 0.27), (0.17, 0.155, 0.14), 0.7, grit=0.6),
    # Psyche: metal-rich, slightly glinting regolith.
    "metallic": Surface((0.17, 0.165, 0.155), (0.12, 0.118, 0.112), 0.4, roughness=0.75, metallic=0.2, sparkle=0.4),
    # Ceres: dark grey, clay and ice-rich, very uniform.
    "ceres": Surface((0.09, 0.09, 0.088), (0.07, 0.07, 0.069), 0.3, grit=0.4),
    # Comet nuclei are among the darkest things in the solar system.
    "cometary": Surface((0.04, 0.037, 0.034), (0.028, 0.026, 0.024), 0.5, grit=0.7, pits=0.6),
}


def make(name: str, surface: Surface, seed: int = 0) -> bpy.types.Material:
    mat = bpy.data.materials.new(name)
    nt = mat.node_tree
    nodes, links = nt.nodes, nt.links
    nodes.clear()

    out = nodes.new("ShaderNodeOutputMaterial")
    bsdf = nodes.new("ShaderNodeBsdfPrincipled")
    links.new(bsdf.outputs["BSDF"], out.inputs["Surface"])

    coord = nodes.new("ShaderNodeTexCoord")
    # Offset coordinates by the seed so two bodies never share a pattern.
    offset = nodes.new("ShaderNodeVectorMath")
    offset.operation = "ADD"
    offset.inputs[1].default_value = ((seed * 7.31) % 97, (seed * 3.17) % 89, (seed * 5.53) % 83)
    links.new(coord.outputs["Object"], offset.inputs[0])
    vec = offset.outputs["Vector"]

    # Colour: broad patches plus a little fine mottling.
    patches = nodes.new("ShaderNodeTexNoise")
    patches.inputs["Scale"].default_value = 2.2
    patches.inputs["Detail"].default_value = 6.0
    patches.inputs["Roughness"].default_value = 0.6
    links.new(vec, patches.inputs["Vector"])

    ramp = nodes.new("ShaderNodeMapRange")
    ramp.inputs["From Min"].default_value = 0.5 - 0.25 * surface.patchiness
    ramp.inputs["From Max"].default_value = 0.5 + 0.25 * surface.patchiness
    links.new(patches.outputs["Fac"], ramp.inputs["Value"])

    mix = nodes.new("ShaderNodeMix")
    mix.data_type = "RGBA"
    mix.inputs["A"].default_value = (*surface.alt, 1.0)
    mix.inputs["B"].default_value = (*surface.albedo, 1.0)
    links.new(ramp.outputs["Result"], mix.inputs["Factor"])

    mottle = nodes.new("ShaderNodeTexNoise")
    mottle.inputs["Scale"].default_value = 40.0
    mottle.inputs["Detail"].default_value = 4.0
    links.new(vec, mottle.inputs["Vector"])
    mottle_mix = nodes.new("ShaderNodeMix")
    mottle_mix.data_type = "RGBA"
    mottle_mix.blend_type = "MULTIPLY"
    mottle_mix.inputs["Factor"].default_value = 1.0
    mottle_range = nodes.new("ShaderNodeMapRange")
    mottle_range.inputs["To Min"].default_value = 0.85
    mottle_range.inputs["To Max"].default_value = 1.15
    links.new(mottle.outputs["Fac"], mottle_range.inputs["Value"])
    links.new(mix.outputs["Result"], mottle_mix.inputs["A"])
    links.new(mottle_range.outputs["Result"], mottle_mix.inputs["B"])
    links.new(mottle_mix.outputs["Result"], bsdf.inputs["Base Color"])

    # Bump: craters too small to model, at three scales, plus fine grit.
    # Heights are in object units (the body's radius is 1), so the bump
    # is physically sized and needs no tuning per body.
    height = None
    for scale, coverage in ((14.0, 0.45), (38.0, 0.6), (100.0, 0.75)):
        layer = _crater_layer(nodes, links, vec, scale, coverage, surface.pits)
        height = layer if height is None else _math(nodes, links, "ADD", height, layer)

    grit = nodes.new("ShaderNodeTexNoise")
    grit.inputs["Scale"].default_value = 160.0
    grit.inputs["Detail"].default_value = 10.0
    grit.inputs["Roughness"].default_value = 0.7
    links.new(vec, grit.inputs["Vector"])
    height = _math(nodes, links, "MULTIPLY_ADD", grit.outputs["Fac"], 0.0015 * surface.grit, height)

    bump = nodes.new("ShaderNodeBump")
    bump.inputs["Strength"].default_value = 1.0
    bump.inputs["Distance"].default_value = 1.0
    links.new(height, bump.inputs["Height"])
    links.new(bump.outputs["Normal"], bsdf.inputs["Normal"])

    # Regolith scatters light almost evenly: rough, matte, no sheen.
    bsdf.inputs["Roughness"].default_value = surface.roughness
    bsdf.inputs["Metallic"].default_value = surface.metallic
    bsdf.inputs["Diffuse Roughness"].default_value = 0.8
    bsdf.inputs["Specular IOR Level"].default_value = 0.2 + 0.6 * surface.sparkle
    return mat


def _math(nodes, links, op, a, b=None, c=None):
    """Add a Math node; each operand is a socket or a number. Returns its output."""
    node = nodes.new("ShaderNodeMath")
    node.operation = op
    for i, v in enumerate((a, b, c)):
        if v is None:
            continue
        if isinstance(v, (int, float)):
            node.inputs[i].default_value = v
        else:
            links.new(v, node.inputs[i])
    return node.outputs["Value"]


def _crater_layer(nodes, links, vec, scale: float, coverage: float, strength: float):
    """Bowl-shaped craters with raised rims, one per Voronoi cell (some cells empty).

    Returns a height socket in object units.
    """
    vor = nodes.new("ShaderNodeTexVoronoi")
    vor.feature = "F1"
    vor.inputs["Scale"].default_value = scale
    vor.inputs["Randomness"].default_value = 1.0
    links.new(vec, vor.inputs["Vector"])
    rand = nodes.new("ShaderNodeSeparateColor")
    links.new(vor.outputs["Color"], rand.inputs["Color"])
    r, g = rand.outputs[0], rand.outputs[1]

    size = _math(nodes, links, "MULTIPLY_ADD", r, 0.3, 0.15)  # crater radius, in cells
    t = _math(nodes, links, "DIVIDE", vor.outputs["Distance"], size)
    bowl = _math(nodes, links, "MINIMUM", _math(nodes, links, "MULTIPLY_ADD", t, t, -1.0), 0.0)
    x = _math(nodes, links, "DIVIDE", _math(nodes, links, "SUBTRACT", t, 1.0), 0.3)
    rim = _math(nodes, links, "MULTIPLY", _math(nodes, links, "EXPONENT", _math(nodes, links, "MULTIPLY", _math(nodes, links, "MULTIPLY", x, x), -1.0)), 0.3)
    shape = _math(nodes, links, "ADD", bowl, rim)
    present = _math(nodes, links, "LESS_THAN", g, coverage)
    # Depth is about 0.2 of the diameter: 0.4 of the radius, which is size/scale.
    depth = _math(nodes, links, "MULTIPLY", size, 0.4 * strength / scale)
    return _math(nodes, links, "MULTIPLY", _math(nodes, links, "MULTIPLY", shape, depth), present)


# ------------------------------------------------------------ hard surfaces

def _principled(name: str, colour, metallic: float, roughness: float):
    mat = bpy.data.materials.new(name)
    nt = mat.node_tree
    nt.nodes.clear()
    out = nt.nodes.new("ShaderNodeOutputMaterial")
    bsdf = nt.nodes.new("ShaderNodeBsdfPrincipled")
    nt.links.new(bsdf.outputs["BSDF"], out.inputs["Surface"])
    bsdf.inputs["Base Color"].default_value = (*colour, 1.0)
    bsdf.inputs["Metallic"].default_value = metallic
    bsdf.inputs["Roughness"].default_value = roughness
    return mat, nt, bsdf


def _grime(nt, bsdf, amount: float = 0.12) -> None:
    """Slight, uneven darkening so paint doesn't look factory-new."""
    coord = nt.nodes.new("ShaderNodeTexCoord")
    noise = nt.nodes.new("ShaderNodeTexNoise")
    noise.inputs["Scale"].default_value = 6.0
    noise.inputs["Detail"].default_value = 8.0
    nt.links.new(coord.outputs["Object"], noise.inputs["Vector"])
    rng = nt.nodes.new("ShaderNodeMapRange")
    rng.inputs["To Min"].default_value = 1.0 - amount
    rng.inputs["To Max"].default_value = 1.0
    nt.links.new(noise.outputs["Fac"], rng.inputs["Value"])
    mix = nt.nodes.new("ShaderNodeMix")
    mix.data_type = "RGBA"
    mix.blend_type = "MULTIPLY"
    mix.inputs["Factor"].default_value = 1.0
    mix.inputs["A"].default_value = bsdf.inputs["Base Color"].default_value
    nt.links.new(rng.outputs["Result"], mix.inputs["B"])
    nt.links.new(mix.outputs["Result"], bsdf.inputs["Base Color"])


def _panel_lines(nt, bsdf, scale: float = 6.0) -> None:
    """Shallow seams between hull panels."""
    coord = nt.nodes.new("ShaderNodeTexCoord")
    brick = nt.nodes.new("ShaderNodeTexBrick")
    brick.inputs["Scale"].default_value = scale
    brick.inputs["Mortar Size"].default_value = 0.012
    brick.offset = 0.0
    nt.links.new(coord.outputs["Object"], brick.inputs["Vector"])
    bump = nt.nodes.new("ShaderNodeBump")
    bump.inputs["Strength"].default_value = 0.35
    bump.invert = True
    nt.links.new(brick.outputs["Fac"], bump.inputs["Height"])
    nt.links.new(bump.outputs["Normal"], bsdf.inputs["Normal"])


def hard_surface(slots: list[str], accent=(0.85, 0.28, 0.03)) -> list:
    """One material per slot name, in slot order."""
    made = {}
    m, nt, b = _principled("Truss", (0.05, 0.05, 0.055), 0.85, 0.4)
    _grime(nt, b, 0.2)
    made["truss"] = m
    made["metal"] = _principled("Metal fittings", (0.55, 0.55, 0.56), 1.0, 0.3)[0]
    m, nt, b = _principled("White paint", (0.72, 0.72, 0.7), 0.0, 0.45)
    _grime(nt, b)
    _panel_lines(nt, b, 4.0)
    made["white"] = m

    m, nt, b = _principled("Insulation foil", (1.0, 0.76, 0.38), 1.0, 0.3)
    coord = nt.nodes.new("ShaderNodeTexCoord")
    crinkle = nt.nodes.new("ShaderNodeTexNoise")
    crinkle.inputs["Scale"].default_value = 18.0
    crinkle.inputs["Detail"].default_value = 3.0
    crinkle.inputs["Distortion"].default_value = 2.5
    nt.links.new(coord.outputs["Object"], crinkle.inputs["Vector"])
    bump = nt.nodes.new("ShaderNodeBump")
    bump.inputs["Strength"].default_value = 0.9
    nt.links.new(crinkle.outputs["Fac"], bump.inputs["Height"])
    nt.links.new(bump.outputs["Normal"], b.inputs["Normal"])
    made["foil"] = m

    m, nt, b = _principled("Panels", (0.36, 0.37, 0.38), 0.3, 0.5)
    _grime(nt, b)
    _panel_lines(nt, b, 5.0)
    made["panel"] = m
    made["engine"] = _principled("Engine", (0.32, 0.29, 0.26), 1.0, 0.32)[0]
    made["radiator"] = _principled("Radiator", (0.8, 0.8, 0.78), 0.0, 0.7)[0]
    m, nt, b = _principled("Accent paint", accent, 0.0, 0.45)
    _grime(nt, b, 0.18)
    made["accent"] = m

    m, nt, b = _principled("Solar cells", (0.012, 0.02, 0.06), 0.3, 0.2)
    coord = nt.nodes.new("ShaderNodeTexCoord")
    brick = nt.nodes.new("ShaderNodeTexBrick")
    brick.inputs["Scale"].default_value = 14.0
    brick.inputs["Mortar Size"].default_value = 0.03
    brick.offset = 0.0
    brick.inputs["Color1"].default_value = (0.012, 0.02, 0.06, 1)
    brick.inputs["Color2"].default_value = (0.02, 0.03, 0.08, 1)
    brick.inputs["Mortar"].default_value = (0.5, 0.5, 0.52, 1)
    nt.links.new(coord.outputs["Object"], brick.inputs["Vector"])
    nt.links.new(brick.outputs["Color"], b.inputs["Base Color"])
    made["solar"] = m

    made["dark"] = _principled("Dark fittings", (0.02, 0.02, 0.022), 0.0, 0.6)[0]
    m, nt, b = _principled("Engine glow", (0.0, 0.0, 0.0), 0.0, 1.0)
    b.inputs["Emission Color"].default_value = (1.0, 0.55, 0.25, 1.0)
    b.inputs["Emission Strength"].default_value = 6.0
    made["glow"] = m
    return [made[s] for s in slots]


def exhaust() -> bpy.types.Material:
    """A see-through glow, brightest at the nozzle and along the plume's core."""
    mat = bpy.data.materials.new("Exhaust")
    nt = mat.node_tree
    nt.nodes.clear()
    out = nt.nodes.new("ShaderNodeOutputMaterial")
    emit = nt.nodes.new("ShaderNodeEmission")
    emit.inputs["Color"].default_value = (0.55, 0.65, 1.0, 1.0)
    emit.inputs["Strength"].default_value = 6.0
    clear = nt.nodes.new("ShaderNodeBsdfTransparent")
    add = nt.nodes.new("ShaderNodeAddShader")
    nt.links.new(clear.outputs["BSDF"], add.inputs[0])

    facing = nt.nodes.new("ShaderNodeLayerWeight")
    facing.inputs["Blend"].default_value = 0.5
    core = nt.nodes.new("ShaderNodeMath")
    core.operation = "POWER"
    inv = nt.nodes.new("ShaderNodeMath")
    inv.operation = "SUBTRACT"
    inv.inputs[0].default_value = 1.0
    nt.links.new(facing.outputs["Facing"], inv.inputs[1])
    nt.links.new(inv.outputs["Value"], core.inputs[0])
    core.inputs[1].default_value = 4.0

    # Fade along the plume. Plumes point aft (-X), so the generated X
    # coordinate runs from 0 at the plume's tail to 1 at the nozzles.
    coord = nt.nodes.new("ShaderNodeTexCoord")
    sep = nt.nodes.new("ShaderNodeSeparateXYZ")
    nt.links.new(coord.outputs["Generated"], sep.inputs["Vector"])
    fade = nt.nodes.new("ShaderNodeMapRange")
    fade.interpolation_type = "SMOOTHERSTEP"
    fade.inputs["From Min"].default_value = 0.35  # the far third fades out entirely
    nt.links.new(sep.outputs["X"], fade.inputs["Value"])
    strength = nt.nodes.new("ShaderNodeMath")
    strength.operation = "MULTIPLY"
    nt.links.new(core.outputs["Value"], strength.inputs[0])
    nt.links.new(fade.outputs["Result"], strength.inputs[1])
    scaled = nt.nodes.new("ShaderNodeMath")
    scaled.operation = "MULTIPLY"
    scaled.inputs[1].default_value = 2.5
    nt.links.new(strength.outputs["Value"], scaled.inputs[0])
    nt.links.new(scaled.outputs["Value"], emit.inputs["Strength"])
    nt.links.new(emit.outputs["Emission"], add.inputs[1])
    nt.links.new(add.outputs["Shader"], out.inputs["Surface"])
    mat.surface_render_method = "BLENDED"
    return mat


def custom(name: str, colour, metallic: float = 0.0, roughness: float = 0.5, transmission: float = 0.0,
           emission: float = 0.0, bump: float = 0.0, coat: float = 0.0) -> bpy.types.Material:
    """A one-off material for a single resource: a mineral's crystals, a metal's ingots."""
    mat, nt, b = _principled(name, colour, metallic, roughness)
    if transmission:
        b.inputs["Transmission Weight"].default_value = transmission
        b.inputs["IOR"].default_value = 1.55
    if emission:
        b.inputs["Emission Color"].default_value = (*colour, 1.0)
        b.inputs["Emission Strength"].default_value = emission
    if coat:
        b.inputs["Coat Weight"].default_value = coat
    if bump:
        coord = nt.nodes.new("ShaderNodeTexCoord")
        tex = nt.nodes.new("ShaderNodeTexNoise")
        tex.inputs["Scale"].default_value = 40.0
        tex.inputs["Detail"].default_value = 8.0
        nt.links.new(coord.outputs["Object"], tex.inputs["Vector"])
        bn = nt.nodes.new("ShaderNodeBump")
        bn.inputs["Strength"].default_value = bump
        nt.links.new(tex.outputs["Fac"], bn.inputs["Height"])
        nt.links.new(bn.outputs["Normal"], b.inputs["Normal"])
        _grime(nt, b, 0.25 * bump)
    return mat
