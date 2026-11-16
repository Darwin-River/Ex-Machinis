"""Scene setup: an empty scene, the sun, the camera and render settings.

Conventions: the subject sits at the origin with radius 1. The camera
looks at it from the -Y side. The sun's position is given by its phase
angle (the Sun-subject-camera angle), so "phase 0" is lit from behind the
camera and "phase 90" is a half-lit terminator shot.
"""

from __future__ import annotations

import math
from dataclasses import dataclass

import bpy
from mathutils import Euler, Vector

# Output sizes for the website, width x height.
SIZES: dict[str, tuple[int, int]] = {
    "hero": (1920, 1080),
    "banner": (2400, 800),
    "card": (1200, 800),
    "social": (1200, 630),
    "square": (1024, 1024),
    "thumb": (512, 512),
    "preview": (640, 360),
}


@dataclass
class Shot:
    width: int = 1920
    height: int = 1080
    samples: int = 96
    phase: float = 70.0  # Sun-subject-camera angle, degrees
    sun_elevation: float = 20.0  # degrees above the camera's horizon
    fill: float = 0.75  # how much of the frame's short side the subject fills
    offset: tuple[float, float] = (0.0, 0.0)  # move the subject in frame, fraction of width/height
    focal: float = 85.0  # mm; long lenses flatten perspective like a probe's camera
    exposure: float = 0.0  # stops, on top of the automatic exposure
    view_elevation: float = 10.0  # camera height above the subject's equator, degrees
    fill_light: float = 0.0  # faint light from the camera side (0 = black shadows)


def reset() -> bpy.types.Scene:
    bpy.ops.wm.read_factory_settings(use_empty=True)
    scene = bpy.context.scene
    scene.render.engine = "CYCLES"
    scene.cycles.device = "CPU"
    scene.cycles.use_denoising = True
    scene.cycles.max_bounces = 4
    scene.render.film_transparent = True
    scene.view_settings.view_transform = "AgX"
    scene.view_settings.look = "AgX - Medium High Contrast"

    world = bpy.data.worlds.new("Space")
    world.node_tree.nodes["Background"].inputs["Color"].default_value = (0, 0, 0, 1)
    world.node_tree.nodes["Background"].inputs["Strength"].default_value = 0.0
    scene.world = world
    return scene


def _sun(phase: float, elevation: float, strength: float, name: str = "Sun") -> bpy.types.Object:
    # Direction from the subject towards the sun. The camera is on -Y.
    ph, el = math.radians(phase), math.radians(elevation)
    to_sun = Vector((math.sin(ph) * math.cos(el), -math.cos(ph) * math.cos(el), math.sin(el))).normalized()
    light = bpy.data.lights.new(name, "SUN")
    light.energy = strength
    light.angle = math.radians(0.4)  # small, distant sun: crisp shadows
    obj = bpy.data.objects.new(name, light)
    obj.rotation_euler = to_sun.to_track_quat("Z", "Y").to_euler()
    bpy.context.scene.collection.objects.link(obj)
    return obj


def light_and_frame(scene: bpy.types.Scene, shot: Shot, albedo: float, radius: float = 1.0, points=None) -> None:
    """Light the subject and place the camera.

    Without points, the camera frames a sphere of the given radius. With
    points (an N x 3 array of world positions, e.g. bounding-box corners),
    it frames their outline as the camera sees it, which suits long,
    thin subjects such as drones.
    """
    scene.render.resolution_x = shot.width
    scene.render.resolution_y = shot.height
    scene.render.resolution_percentage = 100
    scene.cycles.samples = shot.samples

    # Dark bodies need more light to read; scale the exposure to the albedo
    # so a coal-black comet and bright Vesta both come out well exposed,
    # while keeping their relative look.
    scene.view_settings.exposure = math.log2(0.18 / max(albedo, 0.01)) * 0.75 + shot.exposure
    _sun(shot.phase, shot.sun_elevation, 4.0)
    if shot.fill_light > 0:
        _sun(0.0, 5.0, 4.0 * shot.fill_light, "Fill")
    if scene.get("exm_rim"):
        _sun(160.0, 35.0, scene["exm_rim"], "Rim")
    _backdrop_exposure(scene)

    cam_data = bpy.data.cameras.new("Camera")
    cam_data.lens = shot.focal
    cam_data.sensor_width = 36.0
    cam_data.sensor_fit = "AUTO"
    cam_data.clip_end = 10000
    cam = bpy.data.objects.new("Camera", cam_data)
    scene.collection.objects.link(cam)
    scene.camera = cam

    long_side, short_side = max(shot.width, shot.height), min(shot.width, shot.height)
    tan_long = (cam_data.sensor_width / 2) / shot.focal
    tan_short = tan_long * short_side / long_side
    el = math.radians(shot.view_elevation)
    cam.rotation_euler = Euler((math.pi / 2 - el, 0.0, 0.0))
    forward = Vector((0.0, math.cos(el), -math.sin(el)))
    if points is None:
        dist = radius / (shot.fill * tan_short)
        cam.location = -forward * dist
    else:
        import numpy as np

        right, up = Vector((1.0, 0.0, 0.0)), forward.cross(Vector((1.0, 0.0, 0.0)))
        pts = np.asarray(points, dtype=float)
        px, py, pz = pts @ np.array(right), pts @ np.array(up), pts @ np.array(forward)
        hx, hy, hz = np.ptp(px) / 2, np.ptp(py) / 2, np.ptp(pz) / 2
        tan_x, tan_y = (tan_long, tan_short) if shot.width >= shot.height else (tan_short, tan_long)
        dist = max(hx / tan_x, hy / tan_y) / shot.fill + hz
        centre = right * (px.min() + hx) + up * (py.min() + hy) + forward * (pz.min() + hz)
        cam.location = centre - forward * dist

    # Lens shift moves the subject off-centre without changing perspective.
    # Shift is a fraction of the long side; offset is a fraction of each side.
    cam_data.shift_x = -shot.offset[0] * shot.width / long_side
    cam_data.shift_y = -shot.offset[1] * shot.height / long_side


# The website's page background (#0b0f1a), in linear RGB.
SITE_BACKGROUND = (0.0033, 0.0048, 0.0103)
# A soft, cool environment for catalogue shots, so metal has something to reflect.
ENVIRONMENT = (0.05, 0.055, 0.07)


def _softbox(nt):
    """A studio environment for reflections: dark below, bright overhead.

    Metal reflects its surroundings, so in an empty black world it turns
    black. This gives it a horizon and a bright sky to catch, like the
    softboxes of a product photo. Returns the colour socket.
    """
    coord = nt.nodes.new("ShaderNodeTexCoord")
    sep = nt.nodes.new("ShaderNodeSeparateXYZ")
    nt.links.new(coord.outputs["Generated"], sep.inputs["Vector"])  # world direction
    ramp = nt.nodes.new("ShaderNodeValToRGB")
    ramp.color_ramp.interpolation = "EASE"
    els = ramp.color_ramp.elements
    els[0].position, els[0].color = 0.35, (*ENVIRONMENT, 1)
    els[1].position, els[1].color = 0.9, (0.45, 0.47, 0.5, 1)
    mid = els.new(0.55)
    mid.color = (0.1, 0.105, 0.12, 1)
    rng = nt.nodes.new("ShaderNodeMapRange")
    rng.inputs["From Min"].default_value = -1.0
    rng.inputs["From Max"].default_value = 1.0
    nt.links.new(sep.outputs["Z"], rng.inputs["Value"])
    nt.links.new(rng.outputs["Result"], ramp.inputs["Fac"])
    return ramp.outputs["Color"]


def reflections(scene: bpy.types.Scene) -> None:
    """Give a transparent-background shot an environment for metal to reflect."""
    nt = scene.world.node_tree
    bg = nt.nodes["Background"]
    nt.links.new(_softbox(nt), bg.inputs["Color"])
    bg.inputs["Strength"].default_value = 1.0


def studio(scene: bpy.types.Scene, colour=SITE_BACKGROUND, rim: float = 3.0) -> None:
    """A catalogue look: a flat backdrop in the site's colour and a rim light.

    The backdrop is seen by the camera only, so it never tints the subject.
    Call before light_and_frame, which adds the key and fill lights.
    """
    nt = scene.world.node_tree
    bg = nt.nodes["Background"]
    path = nt.nodes.new("ShaderNodeLightPath")
    mix = nt.nodes.new("ShaderNodeMix")
    mix.data_type = "RGBA"
    nt.links.new(_softbox(nt), mix.inputs["A"])  # what shiny surfaces reflect
    mix.inputs["B"].default_value = (*colour, 1)
    nt.links.new(path.outputs["Is Camera Ray"], mix.inputs["Factor"])
    nt.links.new(mix.outputs["Result"], bg.inputs["Color"])
    bg.inputs["Strength"].default_value = 1.0
    scene.world["exm_backdrop"] = True
    scene["exm_rim"] = rim


def _backdrop_exposure(scene: bpy.types.Scene) -> None:
    """Keep a studio backdrop at the site colour whatever the exposure."""
    if not scene.world.get("exm_backdrop"):
        return
    bg = scene.world.node_tree.nodes["Background"]
    bg.inputs["Strength"].default_value = 1.0 / (2.0 ** scene.view_settings.exposure)
