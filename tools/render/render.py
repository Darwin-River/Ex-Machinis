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
import os
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
# Where --site writes; EXM_SITE_IMAGES renders a set somewhere else first, to check before copying it in
HEADER_IMAGES = HERE.parents[1] / "html" / "images" / "headers"
HULL_IMAGES = HERE.parents[1] / "html" / "images" / "hulls"
SITE_IMAGES = Path(os.environ.get("EXM_SITE_IMAGES", HERE.parents[1] / "html" / "images" / "resources"))


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

    h = sub.add_parser("header", help="the website's page-header scenes (#737); names are scenes, or 'all'")
    h.add_argument("names", nargs="*")
    h.add_argument("--list", action="store_true", help="list the scenes and the pages they head")
    h.add_argument("--samples", type=int, default=64)
    h.add_argument("--draft", action="store_true", help="half size and few samples, to check a layout")
    h.add_argument("--out-dir", type=Path, default=HEADER_IMAGES)
    h.add_argument("--quality", type=int, default=80, help="WebP quality, 0-100")

    u = sub.add_parser("hull", help="pictures of hulls that aren't resources, for the drone pages (#802); names are hull ids or presets, or 'all'")
    u.add_argument("names", nargs="*")
    u.add_argument("--list", action="store_true", help="list the hulls")
    u.add_argument("--samples", type=int, default=48)
    u.add_argument("--quality", type=int, default=85, help="WebP quality, 0-100")

    r = sub.add_parser("resource", help="in-game resources; names are ids, names, 'systems', 'modules' or 'all'")
    _shot_args(r, phase=40.0, fill_light=0.25, view_elevation=28.0)
    r.set_defaults(sun_elevation=40.0, size="square", background="transparent", fill=0.8)
    r.add_argument("--thumbs", action="store_true",
                   help="only remake the resource list's thumbnails (<id>-thumb.webp) from the stills already in html/images/resources")
    r.add_argument("--parts", action="store_true",
                   help="only the craft's numbered pictures (<id>-parts.webp and .json); --site makes them too")
    r.add_argument("--site", action="store_true",
                   help="write the resource pages' turntables: html/images/resources/<id>.webp, <id>-still.webp and <id>-thumb.webp")
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


THUMB_SIZE = 96  # shown at 48 px on the resource list, sharp on high-density screens


def thumbnail(still: Path) -> Path:
    """Make <id>-thumb.webp, the resource list's small picture, from <id>-still.webp.

    It is cropped to the object's outline (plus a little room), so the
    object fills the thumbnail instead of the turntable's wide margins.
    """
    from PIL import Image

    thumb = still.with_name(still.name.replace("-still.webp", "-thumb.webp"))
    im = Image.open(still).convert("RGBA")
    box = im.getchannel("A").point(lambda a: 255 if a > 8 else 0).getbbox() or (0, 0, *im.size)
    cx, cy = (box[0] + box[2]) / 2, (box[1] + box[3]) / 2
    half = max(box[2] - box[0], box[3] - box[1]) * 0.56
    square = im.crop((round(cx - half), round(cy - half), round(cx + half), round(cy + half)))
    square.resize((THUMB_SIZE, THUMB_SIZE), Image.LANCZOS).save(thumb, "WEBP", quality=85)
    return thumb


def make_thumbnails() -> None:
    """Remake every resource-list thumbnail from the stills in SITE_IMAGES."""
    stills = sorted(SITE_IMAGES.glob("*-still.webp"))
    for still in stills:
        thumbnail(still)
    print(f"made {len(stills)} thumbnails in {SITE_IMAGES}")


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
    if getattr(args, "site", False):
        thumbnail(still)
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


PARTS_ROTATION = (18.0, 0.0, 145.0)  # top, port side and engines towards the camera


def numbered_still(root, recipe: dict[int, int], out: Path, samples: int = 48, quality: int = 85) -> Path:
    """A numbered picture (#802): out (WebP), a still of root on a clear
    background, and beside it out.json, the recipe and where each of its
    subsystems shows in the picture (x and y as fractions of it). The drone
    pages put a number on each and list the recipe beneath."""
    import json

    from PIL import Image

    from exm_render import labels

    sc = bpy.context.scene
    sc["exm_rim"] = 3.0
    scene.reflections(sc)
    root.rotation_euler = tuple(math.radians(v) for v in PARTS_ROTATION)
    bpy.context.view_layer.update()
    corners = np.array([o.matrix_world @ Vector(c) for o in root.children_recursive
                        if o.type == "MESH" and o.name != "Exhaust" for c in o.bound_box])
    shot = scene.Shot(width=1200, height=700, samples=samples, fill=1.0, view_elevation=12, fill_light=0.08)
    scene.light_and_frame(sc, shot, 0.3, 1.0, corners)
    sc.render.film_transparent = True
    sc.render.image_settings.file_format, sc.render.image_settings.color_mode = "PNG", "RGBA"
    out.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        png = Path(tmp) / "parts.png"
        sc.render.filepath = str(png)
        bpy.ops.render.render(write_still=True)
        found = labels.anchors(sc, root)
        # Crop to the craft, with a margin, and move the anchors with it.
        im = Image.open(png).convert("RGBA")
        l, t, r, b = im.getchannel("A").point(lambda a: 255 if a > 8 else 0).getbbox()
        pad = 24
        l, t, r, b = max(0, l - pad), max(0, t - pad), min(im.width, r + pad), min(im.height, b + pad)
        im = im.crop((l, t, r, b))
        im.save(out, "WEBP", quality=quality)
    data = {
        "width": im.width, "height": im.height,
        # Only the recipe's subsystems are numbered
        "parts": [{"id": a["id"], "x": round((a["x"] - l) / im.width, 4), "y": round((a["y"] - t) / im.height, 4)}
                  for a in found if a["id"] in recipe],
        "recipe": [{"id": rid, "qty": q} for rid, q in recipe.items()],
    }
    out.with_suffix(".json").write_text(json.dumps(data, indent=1) + "\n")
    print(f"wrote {out} and its labels")
    return out


def render_parts(res, args: argparse.Namespace) -> Path:
    """A craft's numbered picture: <id>-parts.webp and .json in SITE_IMAGES."""
    scene.reset()
    root = resources.build(res, 0)
    return numbered_still(root, resources.recipe(res.id), SITE_IMAGES / f"{res.id}-parts.webp",
                          min(args.samples, 48), args.quality + 5)


# Hulls that aren't resources, by hull id (the game's hulls table), and the
# drone preset that draws each. Their pictures go in html/images/hulls/.
HULLS = {1: "mosquito"}


def hull_names() -> list[str]:
    return [f"{i}  {p}" for i, p in HULLS.items()]


def render_hull(name: str, args: argparse.Namespace) -> Path:
    """A hull's picture for the drone pages: html/images/hulls/<id>.webp and .json.
    It has no recipe, so nothing on it is numbered."""
    hid = next((i for i, p in HULLS.items() if name in (str(i), p)), None)
    if hid is None:
        raise SystemExit(f"Unknown hull '{name}'. Known: {', '.join(hull_names())}")
    scene.reset()
    root, _ = drone.build(drone_presets.resolve(HULLS[hid]))
    return numbered_still(root, {}, HULL_IMAGES / f"{hid}.webp", args.samples, args.quality)


def render_resource(name: str, args: argparse.Namespace) -> list[Path]:
    outs = []
    for res in resources.find(name):
        if res.tier == "craft" and (args.parts or args.site):
            outs.append(render_parts(res, args))
            if args.parts:
                continue
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
        local = resources.local_points(obj)
        corners = np.array([obj.matrix_world @ Vector(c) for c in local])
        if args.spin:
            # Frame the cylinder the object sweeps as it turns, so it never leaves the frame.
            r = float(np.linalg.norm(local[:, :2], axis=1).max())
            corners = np.array([(r * math.cos(a), r * math.sin(a), z) for a in np.linspace(0, math.tau, 24, endpoint=False)
                                for z in (local[:, 2].min(), local[:, 2].max())])
        outs.append(shoot(sc, args, f"{res.id}-{res.slug}", res.id, 0.3, 1.0, corners, obj if args.spin else None))
    return outs


def fade_left(png: Path, out: Path, quality: int, width: float = 0.38) -> None:
    """Save a header scene as WebP whose left part fades to transparent.

    The page's own header colour then shows through, so the picture blends
    in with no seam, however wide the window is.
    """
    from PIL import Image

    im = Image.open(png).convert("RGBA")
    w, h = im.size
    x = np.clip(np.arange(w) / (w * width), 0.0, 1.0)
    ramp = (x * x * (3 - 2 * x) * 255).astype(np.uint8)  # smoothstep
    im.putalpha(Image.fromarray(np.tile(ramp, (h, 1))))
    im.save(out, "WEBP", quality=quality, alpha_quality=80)


def header_names() -> list[str]:
    from exm_render import scenes

    return [f"{s.name:12s} {s.pages}" for s in scenes.SCENES.values()]


def render_header(name: str, args: argparse.Namespace) -> Path:
    from exm_render import scenes

    if name not in scenes.SCENES:
        raise SystemExit(f"Unknown scene '{name}'. Known: {', '.join(scenes.SCENES)}")
    sc = scenes.build(scenes.SCENES[name])
    sc.cycles.samples = 16 if args.draft else args.samples
    sc.render.resolution_percentage = 50 if args.draft else 100
    sc.render.image_settings.file_format = "PNG"
    sc.render.image_settings.color_mode = "RGB"
    args.out_dir.mkdir(parents=True, exist_ok=True)
    out = args.out_dir / f"{name}{'-draft' if args.draft else ''}.webp"
    with tempfile.TemporaryDirectory() as tmp:
        png = Path(tmp) / "frame.png"
        sc.render.filepath = str(png)
        bpy.ops.render.render(write_still=True)
        fade_left(png, out, args.quality)
    print(f"wrote {out}")
    return out


def main(argv: list[str]) -> None:
    args = parse(argv)
    if getattr(args, "thumbs", False):
        make_thumbnails()
        return
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
        "header": (header_names, render_header),
        "hull": (hull_names, render_hull),
    }[args.kind]
    if args.list or not args.names:
        print("\n".join(names()))
        return
    if args.kind == "header" and args.names == ["all"]:
        from exm_render import scenes

        args.names = list(scenes.SCENES)
    if args.kind == "hull" and args.names == ["all"]:
        args.names = [str(i) for i in HULLS]
    if getattr(args, "out", None) and len(args.names) > 1:
        raise SystemExit("--out takes one name; use --out-dir for several")
    for name in args.names:
        render(name, args)


if __name__ == "__main__":
    main(sys.argv[1:])
