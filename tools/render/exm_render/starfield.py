"""Starfield backgrounds, drawn in 2D at the output resolution.

The stars are drawn as an image rather than modelled in 3D, so they stay
pin-sharp and never light the subject. They are kept faint by
default: in a real exposure that shows a sunlit body, stars barely show.
"""

from __future__ import annotations

import numpy as np

# Rough star colours, from hot blue-white to cool orange, with how common each is.
_TINTS = np.array([
    (0.80, 0.88, 1.00),
    (0.92, 0.95, 1.00),
    (1.00, 1.00, 1.00),
    (1.00, 0.96, 0.88),
    (1.00, 0.88, 0.72),
])
_TINT_WEIGHTS = np.array([0.12, 0.25, 0.30, 0.20, 0.13])


def draw(width: int, height: int, seed: int, brightness: float = 0.6, density: float = 1.0) -> np.ndarray:
    """Return an (height, width, 3) float image of stars in display space, 0..1."""
    rng = np.random.default_rng(seed + 9001)
    img = np.zeros((height, width, 3))
    n = int(900 * density * width * height / 1e6)
    xs = rng.uniform(0, width, n)
    ys = rng.uniform(0, height, n)
    # Magnitudes follow a steep power law: lots of faint stars, few bright.
    flux = rng.pareto(1.6, n) * 0.08 + 0.02
    tints = _TINTS[rng.choice(len(_TINTS), n, p=_TINT_WEIGHTS)]

    for x, y, f, tint in zip(xs, ys, flux, tints):
        sigma = 0.55 + 0.35 * min(f, 3.0) ** 0.5
        size = int(2 * np.ceil(3 * sigma) + 1)
        # Sub-pixel placement by shifting the kernel's centre.
        cx, cy = int(x), int(y)
        fx, fy = x - cx - 0.5, y - cy - 0.5
        ax = np.arange(size) - size // 2
        kern = np.exp(-((ax[None, :] - fx) ** 2 + (ax[:, None] - fy) ** 2) / (2 * sigma * sigma))
        peak = min(f, 1.5) * brightness
        x0, y0 = cx - size // 2, cy - size // 2
        xa, ya = max(x0, 0), max(y0, 0)
        xb, yb = min(x0 + size, width), min(y0 + size, height)
        if xa >= xb or ya >= yb:
            continue
        patch = kern[ya - y0:yb - y0, xa - x0:xb - x0, None] * tint[None, None, :] * peak
        img[ya:yb, xa:xb] += patch
    return np.clip(img, 0.0, 1.0)


def set_backdrop(scene, stars) -> None:
    """Show a star image behind the subject, seen by the camera only.

    The stars are fixed to the frame (window coordinates), so they stay
    pin-sharp and line up pixel for pixel. They never light the subject,
    and glows such as exhaust plumes add over them naturally.
    """
    import bpy
    import numpy as np

    h, w = stars.shape[:2]
    # Undo the display gamma and the shot's exposure (and make up for the
    # tone curve), so the stars come out roughly as drawn whatever the
    # subject needs.
    linear = 2.0 * np.clip(stars, 0, 1) ** 2.2 / (2.0 ** scene.view_settings.exposure)
    rgba = np.concatenate([linear, np.ones((h, w, 1))], axis=2).astype(np.float32)
    img = bpy.data.images.new("Stars", w, h, float_buffer=True)
    img.pixels.foreach_set(rgba.ravel())
    img.pack()

    nt = scene.world.node_tree
    bg = nt.nodes["Background"]
    coord = nt.nodes.new("ShaderNodeTexCoord")
    tex = nt.nodes.new("ShaderNodeTexImage")
    tex.image = img
    tex.interpolation = "Closest"
    tex.extension = "CLIP"
    nt.links.new(coord.outputs["Window"], tex.inputs["Vector"])
    path = nt.nodes.new("ShaderNodeLightPath")
    mix = nt.nodes.new("ShaderNodeMix")
    mix.data_type = "RGBA"
    mix.inputs["A"].default_value = (0, 0, 0, 1)
    nt.links.new(path.outputs["Is Camera Ray"], mix.inputs["Factor"])
    nt.links.new(tex.outputs["Color"], mix.inputs["B"])
    nt.links.new(mix.outputs["Result"], bg.inputs["Color"])
    bg.inputs["Strength"].default_value = 1.0
