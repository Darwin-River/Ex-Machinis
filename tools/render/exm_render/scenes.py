"""Wide space scenes for the website's page headers (#737).

A scene is a list of elements placed by where they appear in the frame,
not by world coordinates: `at` is (across, up), each from -1 (left/bottom
edge) to 1 (right/top edge); `depth` is the distance from the camera; and
`size` is the object's radius as a fraction of half the frame's height.
So a planet with size 3 at up -2.5 is a huge limb rising from the bottom.

The page's title sits on the left of the header, so subjects go on the
right half and the left is kept for open space and stars.
"""

from __future__ import annotations

import math
import zlib
from dataclasses import dataclass, field

import bpy
import numpy as np
from mathutils import Euler, Vector

from . import asteroid, drone, drone_presets, materials, planet, presets, scene, starfield
from .planet import PlanetParams

WIDTH, HEIGHT = 2400, 480
FOCAL, SENSOR = 35.0, 36.0


@dataclass
class El:
    kind: str  # planet, moon, asteroid, drone, field
    at: tuple[float, float]
    depth: float
    size: float
    what: object = None  # PlanetParams, an asteroid/drone preset name, or a moon surface
    seed: int = 1
    rotation: tuple[float, float, float] | None = None  # degrees
    firing: bool = False
    count: int = 0  # for a field: how many rocks
    spread: tuple[float, float] = (0.4, 0.5)  # for a field: half-width across and up


@dataclass
class HeaderScene:
    name: str
    pages: str  # what it heads, for the docs
    elements: list[El] = field(default_factory=list)
    sun: tuple[float, float] = (60.0, 15.0)  # azimuth from the camera's view (0 = behind the camera), elevation
    exposure: float = 0.0
    stars: float = 0.55
    fill: float = 0.03


def _frame_point(at, depth):
    tan_h = (SENSOR / 2) / FOCAL
    tan_v = tan_h * HEIGHT / WIDTH
    u, v = at
    return Vector((u * depth * tan_h, depth, v * depth * tan_v)), depth * tan_v


def _sun_dir(az, el):
    a, e = math.radians(az), math.radians(el)
    return Vector((math.sin(a) * math.cos(e), -math.cos(a) * math.cos(e), math.sin(e))).normalized()


def _place(obj, el: El, radius_now: float = 1.0):
    pos, half = _frame_point(el.at, el.depth)
    s = el.size * half / radius_now
    obj.location = pos
    obj.scale = (s, s, s)
    if el.rotation:
        obj.rotation_euler = Euler(tuple(math.radians(r) for r in el.rotation))


def _rock(name, seed, surface, el: El, at=None, size=None, subdiv=6):
    params, surf = presets.resolve(name, seed)
    params.subdivisions = subdiv
    obj = asteroid.build(params)
    obj.data.materials.append(materials.make(f"{name} {seed}", materials.SURFACES[surface or surf], seed))
    rng = np.random.default_rng(seed)
    e = El(el.kind, at or el.at, el.depth, size or el.size, rotation=el.rotation or tuple(rng.uniform(0, 360, 3)))
    _place(obj, e)
    return obj


def build(hs: HeaderScene) -> bpy.types.Scene:
    sc = scene.reset()
    sc.render.film_transparent = False
    sc.render.resolution_x, sc.render.resolution_y = WIDTH, HEIGHT
    sun = _sun_dir(*hs.sun)
    for el in hs.elements:
        if el.kind == "planet":
            _place(planet.build(el.what, sun), el)
        elif el.kind == "moon":
            params, surf = planet.moon(f"moon {el.seed}", el.seed, el.what or "ceres")
            params.subdivisions = 6
            obj = asteroid.build(params)
            obj.data.materials.append(materials.make(f"moon {el.seed}", materials.SURFACES[surf], el.seed))
            _place(obj, el)
        elif el.kind == "asteroid":
            _rock(el.what or "c-type", el.seed, None, el, subdiv=7)
        elif el.kind == "field":
            rng = np.random.default_rng(el.seed)
            for k in range(el.count):
                at = (el.at[0] + rng.uniform(-1, 1) * el.spread[0], el.at[1] + rng.uniform(-1, 1) * el.spread[1])
                size = el.size * rng.uniform(0.3, 1.0) ** 1.5
                _rock(str(rng.choice(["c-type", "s-type", "rubble-pile"])), int(el.seed * 100 + k), None,
                      El("asteroid", at, el.depth * rng.uniform(0.8, 1.3), size), subdiv=5)
        elif el.kind == "drone":
            params = drone_presets.resolve(el.what, el.seed)
            params.firing = el.firing
            root, radius = drone.build(params)
            _place(root, el, radius)
        else:
            raise ValueError(f"unknown element {el.kind}")

    # Light: the sun, a faint fill, and an exposure that suits sunlit planets.
    sc.view_settings.exposure = hs.exposure
    scene._sun(hs.sun[0], hs.sun[1], 4.0)
    if hs.fill:
        scene._sun(0.0, 5.0, 4.0 * hs.fill, "Fill")

    cam_data = bpy.data.cameras.new("Camera")
    cam_data.lens, cam_data.sensor_width, cam_data.sensor_fit = FOCAL, SENSOR, "HORIZONTAL"
    cam_data.clip_end = 100000
    cam = bpy.data.objects.new("Camera", cam_data)
    cam.rotation_euler = (math.pi / 2, 0, 0)
    sc.collection.objects.link(cam)
    sc.camera = cam

    if hs.stars:
        starfield.set_backdrop(sc, starfield.draw(WIDTH, HEIGHT, zlib.crc32(hs.name.encode()) % 1000, hs.stars, density=1.3))
    return sc


def _p(**kw):
    return PlanetParams(**kw)


SCENES: dict[str, HeaderScene] = {s.name: s for s in [
    HeaderScene("market", "Market, buy and sell orders, the House, market history, shares", [
        El("planet", (0.55, -3.6), 300, 3.4, _p(name="Trade world", kind="ocean", seed=3, clouds=0.6,
                                                atmosphere=(0.3, 0.55, 1.0), atmosphere_strength=3.0)),
        El("drone", (0.5, 0.3), 60, 0.6, "hauler", seed=13, rotation=(15, 0, 200), firing=True),
        El("drone", (0.84, 0.5), 110, 0.3, "courier", seed=11, rotation=(10, 0, 160), firing=True),
    ], sun=(75, 20)),
    HeaderScene("resources", "Resources and each resource's page", [
        El("asteroid", (0.72, -0.05), 40, 0.95, "rubble-pile", seed=31, rotation=(20, 10, 30)),
        El("drone", (0.4, 0.3), 40, 0.42, "miner", seed=14, rotation=(15, 0, -20)),
        El("field", (0.55, 0.0), 90, 0.18, seed=7, count=14, spread=(0.45, 0.8)),
    ], sun=(70, 25)),
    HeaderScene("spacecraft", "Spacecraft, each drone's page, protocols, lineage", [
        El("planet", (0.92, 0.1), 900, 0.6, _p(name="Far giant", kind="gas", seed=5, rings=(1.3, 2.2), tilt=18)),
        El("drone", (0.42, 0.05), 50, 0.6, "tug", seed=12, rotation=(12, 0, 195), firing=True),
        El("drone", (0.62, 0.5), 80, 0.45, "hauler", seed=13, rotation=(12, 0, 195), firing=True),
        El("drone", (0.74, -0.4), 95, 0.35, "courier", seed=11, rotation=(12, 0, 195), firing=True),
    ], sun=(80, 15)),
    HeaderScene("locations", "Locations, each place's page, the map", [
        El("planet", (0.68, -0.15), 400, 0.85, _p(name="Ringed giant", kind="gas", seed=8, rings=(1.25, 2.3),
                                                 tilt=24, oblate=0.93)),
        El("moon", (0.33, 0.45), 300, 0.12, "ceres", seed=41),
        El("moon", (0.96, 0.55), 350, 0.08, "basaltic", seed=42),
    ], sun=(65, 10)),
    HeaderScene("companies", "Companies, players, the leaderboard, the dashboard", [
        El("moon", (0.78, -2.2), 250, 2.4, "basaltic", seed=51),
        El("drone", (0.5, 0.4), 45, 0.55, "refinery", seed=15, rotation=(18, 0, 210)),
        El("drone", (0.78, 0.55), 70, 0.35, "factory-kit-1", seed=5902, rotation=(10, 0, 150)),
    ], sun=(85, 25)),
    HeaderScene("learn", "Getting Started, queries, the first startup, events, Release a drone", [
        El("planet", (0.8, 0.0), 500, 0.9, _p(name="Ice world", kind="ice", seed=9, atmosphere=(0.6, 0.8, 1.0),
                                             atmosphere_strength=1.2)),
        El("asteroid", (0.45, 0.2), 30, 0.35, "churyumov-gerasimenko", seed=199),
        El("field", (0.6, 0.0), 120, 0.1, seed=17, count=10, spread=(0.4, 0.8)),
    ], sun=(60, 20)),
    HeaderScene("about", "About, Keep the Lights On, the blog, notices", [
        El("planet", (0.35, -4.2), 260, 4.0, _p(name="Home world", kind="ocean", seed=1, clouds=0.55,
                                                atmosphere=(0.35, 0.6, 1.0), atmosphere_strength=4.0)),
        El("drone", (0.7, 0.35), 70, 0.42, "surveyor", seed=16, rotation=(10, 0, 160)),
    ], sun=(100, 5)),
    HeaderScene("account", "Account pages", [
        El("planet", (0.75, 0.0), 450, 0.95, _p(name="Lava world", kind="lava", seed=4)),
        El("moon", (0.4, 0.4), 300, 0.13, "basaltic", seed=61),
    ], sun=(70, 15)),
    HeaderScene("default", "Any other page", [
        El("planet", (0.72, -0.1), 400, 0.9, _p(name="Red world", kind="rocky", seed=6, atmosphere=(0.9, 0.6, 0.45),
                                               atmosphere_strength=1.0)),
        El("moon", (0.42, 0.35), 260, 0.1, "carbonaceous", seed=71),
        El("moon", (0.95, 0.6), 320, 0.06, "stony", seed=72),
    ], sun=(65, 15)),
]}
