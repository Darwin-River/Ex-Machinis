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


def light_and_frame(scene: bpy.types.Scene, shot: Shot, albedo: float, radius: float = 1.0) -> None:
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

    cam_data = bpy.data.cameras.new("Camera")
    cam_data.lens = shot.focal
    cam_data.sensor_width = 36.0
    cam_data.sensor_fit = "AUTO"
    cam_data.clip_end = 10000
    cam = bpy.data.objects.new("Camera", cam_data)
    scene.collection.objects.link(cam)
    scene.camera = cam

    long_side, short_side = max(shot.width, shot.height), min(shot.width, shot.height)
    half_fov_short = math.atan((cam_data.sensor_width / 2) / shot.focal * short_side / long_side)
    dist = radius / (shot.fill * math.tan(half_fov_short))
    el = math.radians(shot.view_elevation)
    cam.location = Vector((0.0, -dist * math.cos(el), dist * math.sin(el)))
    cam.rotation_euler = Euler((math.pi / 2 - el, 0.0, 0.0))

    # Lens shift moves the subject off-centre without changing perspective.
    # Shift is a fraction of the long side; offset is a fraction of each side.
    cam_data.shift_x = -shot.offset[0] * shot.width / long_side
    cam_data.shift_y = -shot.offset[1] * shot.height / long_side
