"""Render Ex Machinis space objects with Blender.

    python tools/render/render.py asteroid vesta --size hero
    python tools/render/render.py asteroid c-type --seed 42 --phase 100
    python tools/render/render.py drone miner --size hero
    python tools/render/render.py drone random --seed 7 --firing
    python tools/render/render.py asteroid --list

Run it with the Python from tools/render/.venv (see README.md).
"""

from __future__ import annotations

import argparse
import math
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import bpy  # noqa: E402
import numpy as np  # noqa: E402
from mathutils import Vector  # noqa: E402

from exm_render import asteroid, drone, drone_presets, materials, presets, resources, scene, starfield  # noqa: E402

DEFAULT_OUT = HERE / "output"
SITE_IMAGES = HERE.parents[1] / "html" / "images" / "resources"


def slug(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def _shot_args(p: argparse.ArgumentParser, phase: float, fill_light: float, view_elevation: float) -> None:
    p.add_argument("names", nargs="*", help="presets to render (see --list)")
    p.add_argument("--list", action="store_true", help="list the known names and exit")
    p.add_argument("--seed", type=int, help="override the preset's seed for a new variant")
    p.add_argument("--rotation", type=float, nargs=3, metavar=("X", "Y", "Z"), help="orientation, degrees (default: from the seed)")
    p.add_argument("--size", choices=sorted(scene.SIZES), default="card")
    p.add_argument("--width", type=int)
    p.add_argument("--height", type=int)
    p.add_argument("--samples", type=int, default=96)
    p.add_argument("--phase", type=float, default=phase, help="Sun-subject-camera angle in degrees: 0 full-lit, 90 half-lit, 150 thin crescent")
    p.add_argument("--sun-elevation", type=float, default=20.0)
    p.add_argument("--view-elevation", type=float, default=view_elevation)
    p.add_argument("--fill", type=float, default=0.75, help="fraction of the frame's short side the subject fills")
    p.add_argument("--offset", type=float, nargs=2, default=(0.0, 0.0), metavar=("X", "Y"), help="move the subject off-centre, fraction of the frame")
    p.add_argument("--exposure", type=float, default=0.0, help="extra stops of exposure")
    p.add_argument("--fill-light", type=float, default=fill_light, help="faint light from the camera, 0..1")
    p.add_argument("--background", choices=("stars", "black", "transparent", "studio"), default="stars",
                   help="studio is a flat backdrop near the website's colour")
    p.add_argument("--stars", type=float, default=0.6, help="star brightness")
    p.add_argument("--format", choices=("png", "jpg"), default="png")
    p.add_argument("--out-dir", type=Path, default=DEFAULT_OUT)
    p.add_argument("--out", type=Path, help="output file (one name only)")
    p.add_argument("--blend", action="store_true", help="also save the .blend file next to the image")
    p.add_argument("--spin", type=int, metavar="FRAMES", help="render a turntable: an animated WebP of one full turn in this many frames, plus a still")
    p.add_argument("--fps", type=float, default=10.0, help="turntable frames a second")
    p.add_argument("--quality", type=int, default=72, help="WebP quality, 0-100")


def parse(argv: list[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="kind", required=True)

    a = sub.add_parser("asteroid", help="asteroids and comet nuclei; names are presets or bodies in bodies.csv")
    _shot_args(a, phase=70.0, fill_light=0.0, view_elevation=10.0)
    a.add_argument("--subdivisions", type=int, help="mesh detail: 6 fast, 7 default, 8 close-ups")

    d = sub.add_parser("drone", help="uncrewed drones; names are role presets, or 'random'")
    _shot_args(d, phase=45.0, fill_light=0.15, view_elevation=18.0)
    d.set_defaults(sun_elevation=40.0)
    d.add_argument("--firing", action="store_true", help="engines lit, with exhaust plumes")
    d.add_argument("--accent", type=float, nargs=3, metavar=("R", "G", "B"), help="accent paint colour, linear 0..1")

    r = sub.add_parser("resource", help="in-game resources; names are ids, names, 'systems', 'modules' or 'all'")
    _shot_args(r, phase=40.0, fill_light=0.25, view_elevation=28.0)
    r.set_defaults(sun_elevation=40.0, size="square", background="transparent", fill=0.8)
    r.add_argument("--site", action="store_true",
                   help="write the resource pages' turntables: html/images/resources/<id>.webp and <id>-still.webp")
    return ap.parse_args(argv)


def asteroid_rotation(seed: int) -> tuple[float, float, float]:
    # Keep the long (X) axis roughly across the frame; spin freely about it.
    rng = np.random.default_rng(seed + 77)
    return (rng.uniform(0, 360), rng.uniform(-20, 20), rng.uniform(-25, 25))


def drone_rotation(seed: int) -> tuple[float, float, float]:
    # Rear three-quarter view: engines towards the camera and the sun, bow
    # away to the left, rolled a little so the top and one side both show.
    rng = np.random.default_rng(seed + 77)
    return (rng.uniform(12, 30), rng.uniform(-6, 6), rng.uniform(135, 155))


def spin(sc, args: argparse.Namespace, obj, out: Path, still: Path) -> None:
    """Render one full turn of obj about the vertical axis and encode it as WebP."""
    if not shutil.which("ffmpeg"):
        raise SystemExit("--spin needs ffmpeg (apt-get install ffmpeg)")
    base = obj.rotation_euler.z
    with tempfile.TemporaryDirectory() as tmp:
        for f in range(args.spin):
            obj.rotation_euler.z = base + math.tau * f / args.spin
            sc.render.filepath = str(Path(tmp) / f"f{f:04d}.png")
            bpy.ops.render.render(write_still=True)
        frames = str(Path(tmp) / "f%04d.png")
        alpha = ["-pix_fmt", "yuva420p"] if sc.render.film_transparent else []
        common = ["ffmpeg", "-loglevel", "error", "-y"]
        subprocess.run(common + ["-framerate", str(args.fps), "-i", frames, "-c:v", "libwebp_anim", *alpha,
                                 "-lossless", "0", "-quality", str(args.quality), "-loop", "0", str(out)], check=True)
        subprocess.run(common + ["-i", str(Path(tmp) / "f0000.png"), "-frames:v", "1", "-c:v", "libwebp", *alpha,
                                 "-quality", str(args.quality + 10), str(still)], check=True)
    obj.rotation_euler.z = base


def shoot(sc, args: argparse.Namespace, name: str, seed: int, albedo: float, radius: float, points=None, spin_obj=None) -> Path:
    """Light, frame, render and save the scene's subject."""
    w, h = scene.SIZES[args.size]
    shot = scene.Shot(
        width=args.width or w, height=args.height or h, samples=args.samples, phase=args.phase,
        sun_elevation=args.sun_elevation, fill=args.fill, offset=tuple(args.offset),
        exposure=args.exposure, view_elevation=args.view_elevation, fill_light=args.fill_light,
    )
    scene.light_and_frame(sc, shot, albedo, radius, points)

    args.out_dir.mkdir(parents=True, exist_ok=True)
    stem = f"{slug(name)}-{args.size}" + (f"-s{args.seed}" if args.seed is not None else "")
    if getattr(args, "site", False):
        stem = name.split("-", 1)[0]  # the resource id
    out = args.out or args.out_dir / f"{stem}.{args.format}"
    if args.spin and spin_obj is not None:
        out = out.with_suffix(".webp")

    transparent = args.background == "transparent" and out.suffix.lower() in (".png", ".webp")
    sc.render.film_transparent = transparent
    if args.background == "stars":
        stars = starfield.draw(shot.width, shot.height, seed, args.stars)
        starfield.set_backdrop(sc, stars)
    fmt = sc.render.image_settings
    fmt.file_format = "JPEG" if out.suffix.lower() in (".jpg", ".jpeg") else "PNG"
    fmt.color_mode = "RGBA" if transparent else "RGB"
    fmt.quality = 92
    if args.spin and spin_obj is not None:
        fmt.file_format, fmt.color_mode = "PNG", "RGBA" if transparent else "RGB"
        spin(sc, args, spin_obj, out, out.with_name(out.stem + "-still.webp"))
    else:
        sc.render.filepath = str(out)
        bpy.ops.render.render(write_still=True)

    if args.blend:
        bpy.ops.wm.save_as_mainfile(filepath=str(out.with_suffix(".blend")))
    print(f"wrote {out}")
    return out


def render_asteroid(name: str, args: argparse.Namespace) -> Path:
    params, surface_key = presets.resolve(name, args.seed)
    if args.subdivisions:
        params.subdivisions = args.subdivisions
    surface = materials.SURFACES[surface_key]

    sc = scene.reset()
    obj = asteroid.build(params)
    obj.data.materials.append(materials.make(f"{params.name} surface", surface, params.seed))
    rot = args.rotation or asteroid_rotation(params.seed)
    obj.rotation_euler = tuple(math.radians(r) for r in rot)
    return shoot(sc, args, params.name, params.seed, sum(surface.albedo) / 3, 1.0)


def render_drone(name: str, args: argparse.Namespace) -> Path:
    params = drone_presets.resolve(name, args.seed)
    params.firing = args.firing
    if args.accent:
        params.accent = tuple(args.accent)

    sc = scene.reset()
    root, radius = drone.build(params)
    rot = args.rotation or drone_rotation(params.seed)
    root.rotation_euler = tuple(math.radians(r) for r in rot)
    bpy.context.view_layer.update()
    corners = [o.matrix_world @ Vector(c) for o in root.children_recursive if o.type == "MESH" and o.name != "Exhaust" for c in o.bound_box]
    return shoot(sc, args, params.name, params.seed, 0.3, radius, np.array(corners))


def resource_rotation(seed: int) -> tuple[float, float, float]:
    # Three-quarter view of the front (-Y) and right-hand end (+X).
    return (0.0, 0.0, -32.0)


def render_resource(name: str, args: argparse.Namespace) -> list[Path]:
    outs = []
    for res in resources.find(name):
        sc = scene.reset()
        if args.background == "studio":
            scene.studio(sc)
        else:
            sc["exm_rim"] = 3.0
            scene.reflections(sc)
        obj = resources.build(res, args.seed or 0)
        rot = args.rotation or resource_rotation(res.id)
        obj.rotation_euler = tuple(math.radians(v) for v in rot)
        bpy.context.view_layer.update()
        corners = np.array([obj.matrix_world @ Vector(c) for c in obj.bound_box])
        if args.spin:
            # Frame the cylinder the object sweeps as it turns, so it never leaves the frame.
            local = np.array([Vector(c) for c in obj.bound_box])
            r = float(np.linalg.norm(local[:, :2], axis=1).max())
            corners = np.array([(r * math.cos(a), r * math.sin(a), z) for a in np.linspace(0, math.tau, 24, endpoint=False)
                                for z in (local[:, 2].min(), local[:, 2].max())])
        outs.append(shoot(sc, args, f"{res.id}-{res.slug}", res.id, 0.3, 1.0, corners, obj if args.spin else None))
    return outs


def main(argv: list[str]) -> None:
    args = parse(argv)
    if getattr(args, "site", False):
        args.out_dir = SITE_IMAGES
        args.spin = args.spin or 40
        args.width = args.width or 400
        args.height = args.height or 400
        args.samples = min(args.samples, 32)
        args.background = "transparent"
        args.fill = 0.9
    names, render = {
        "asteroid": (presets.all_names, render_asteroid),
        "drone": (drone_presets.all_names, render_drone),
        "resource": (lambda: [f"{r.id}  {r.name}" for r in resources.find("all")], render_resource),
    }[args.kind]
    if args.list or not args.names:
        print("\n".join(names()))
        return
    if args.out and len(args.names) > 1:
        raise SystemExit("--out takes one name; use --out-dir for several")
    for name in args.names:
        render(name, args)


if __name__ == "__main__":
    main(sys.argv[1:])
