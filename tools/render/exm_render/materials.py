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
