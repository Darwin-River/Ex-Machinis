"""Render Ex Machinis space objects with Blender.

    python tools/render/render.py asteroid vesta --size hero
    python tools/render/render.py asteroid c-type --seed 42 --phase 100
    python tools/render/render.py asteroid --list

Run it with the Python from tools/render/.venv (see README.md).
"""

from __future__ import annotations

import argparse
import math
import re
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import bpy  # noqa: E402
import numpy as np  # noqa: E402

from exm_render import asteroid, materials, presets, scene, starfield  # noqa: E402

DEFAULT_OUT = HERE / "output"


def slug(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def parse(argv: list[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="kind", required=True)
    a = sub.add_parser("asteroid", help="asteroids and comet nuclei")
    a.add_argument("names", nargs="*", help="presets or bodies from bodies.csv (see --list)")
    a.add_argument("--list", action="store_true", help="list the known names and exit")
    a.add_argument("--seed", type=int, help="override the preset's seed for a new variant")
    a.add_argument("--rotation", type=float, nargs=3, metavar=("X", "Y", "Z"), help="orientation, degrees (default: from the seed)")
    a.add_argument("--subdivisions", type=int, help="mesh detail: 6 fast, 7 default, 8 close-ups")

    for p in (a,):  # shot options; later subjects (moons, drones) share them
        p.add_argument("--size", choices=sorted(scene.SIZES), default="card")
        p.add_argument("--width", type=int)
        p.add_argument("--height", type=int)
        p.add_argument("--samples", type=int, default=96)
        p.add_argument("--phase", type=float, default=70.0, help="Sun-subject-camera angle in degrees: 0 full-lit, 90 half-lit, 150 thin crescent")
        p.add_argument("--sun-elevation", type=float, default=20.0)
        p.add_argument("--view-elevation", type=float, default=10.0)
        p.add_argument("--fill", type=float, default=0.75, help="fraction of the frame's short side the subject fills")
        p.add_argument("--offset", type=float, nargs=2, default=(0.0, 0.0), metavar=("X", "Y"), help="move the subject off-centre, fraction of the frame")
        p.add_argument("--exposure", type=float, default=0.0, help="extra stops of exposure")
        p.add_argument("--fill-light", type=float, default=0.0, help="faint light from the camera, 0..1")
        p.add_argument("--background", choices=("stars", "black", "transparent"), default="stars")
        p.add_argument("--stars", type=float, default=0.6, help="star brightness")
        p.add_argument("--format", choices=("png", "jpg"), default="png")
        p.add_argument("--out-dir", type=Path, default=DEFAULT_OUT)
        p.add_argument("--out", type=Path, help="output file (one name only)")
        p.add_argument("--blend", action="store_true", help="also save the .blend file next to the image")
    return ap.parse_args(argv)


def default_rotation(seed: int) -> tuple[float, float, float]:
    # Keep the long (X) axis roughly across the frame; spin freely about it.
    rng = np.random.default_rng(seed + 77)
    return (rng.uniform(0, 360), rng.uniform(-20, 20), rng.uniform(-25, 25))


def save_image(pixels: np.ndarray, path: Path, alpha: bool) -> None:
    h, w = pixels.shape[:2]
    if pixels.shape[2] == 3:
        pixels = np.concatenate([pixels, np.ones((h, w, 1))], axis=2)
    img = bpy.data.images.new("output", w, h, alpha=alpha)
    img.pixels.foreach_set(pixels.astype(np.float32).ravel())
    img.filepath_raw = str(path)
    img.file_format = "JPEG" if path.suffix.lower() in (".jpg", ".jpeg") else "PNG"
    bpy.context.scene.render.image_settings.quality = 92
    img.save()


def render_asteroid(name: str, args: argparse.Namespace) -> Path:
    params, surface_key = presets.resolve(name, args.seed)
    if args.subdivisions:
        params.subdivisions = args.subdivisions
    surface = materials.SURFACES[surface_key]

    sc = scene.reset()
    obj = asteroid.build(params)
    obj.data.materials.append(materials.make(f"{params.name} surface", surface, params.seed))
    rot = args.rotation or default_rotation(params.seed)
    obj.rotation_euler = tuple(math.radians(r) for r in rot)

    w, h = scene.SIZES[args.size]
    shot = scene.Shot(
        width=args.width or w, height=args.height or h, samples=args.samples, phase=args.phase,
        sun_elevation=args.sun_elevation, fill=args.fill, offset=tuple(args.offset),
        exposure=args.exposure, view_elevation=args.view_elevation, fill_light=args.fill_light,
    )
    albedo = sum(surface.albedo) / 3
    scene.light_and_frame(sc, shot, albedo)

    args.out_dir.mkdir(parents=True, exist_ok=True)
    stem = f"{slug(params.name)}-{args.size}" + (f"-s{args.seed}" if args.seed is not None else "")
    out = args.out or args.out_dir / f"{stem}.{args.format}"

    with tempfile.TemporaryDirectory() as tmp:
        raw = Path(tmp) / "raw.png"
        sc.render.image_settings.file_format = "PNG"
        sc.render.image_settings.color_mode = "RGBA"
        sc.render.image_settings.color_depth = "8"
        sc.render.filepath = str(raw)
        bpy.ops.render.render(write_still=True)

        img = bpy.data.images.load(str(raw))
        px = np.empty(shot.width * shot.height * 4, dtype=np.float32)
        img.pixels.foreach_get(px)
        px = px.reshape(shot.height, shot.width, 4)

    transparent = args.background == "transparent" and out.suffix.lower() == ".png"
    if not transparent:
        bg = np.zeros((shot.height, shot.width, 3))
        if args.background == "stars":
            bg = starfield.draw(shot.width, shot.height, params.seed, args.stars)
        px = starfield.composite(px, bg)
    save_image(px, out, alpha=transparent)

    if args.blend:
        bpy.ops.wm.save_as_mainfile(filepath=str(out.with_suffix(".blend")))
    print(f"wrote {out}")
    return out


def main(argv: list[str]) -> None:
    args = parse(argv)
    if args.kind == "asteroid":
        if args.list or not args.names:
            print("\n".join(presets.all_names()))
            return
        if args.out and len(args.names) > 1:
            raise SystemExit("--out takes one name; use --out-dir for several")
        for name in args.names:
            render_asteroid(name, args)


if __name__ == "__main__":
    main(sys.argv[1:])
