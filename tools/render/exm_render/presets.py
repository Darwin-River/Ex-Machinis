"""Named asteroid presets.

Bodies from game-engine/sql/game-data/bodies.csv have hand-tuned presets
based on what spacecraft and telescopes have shown of them. Generic
presets ("c-type", "rubble-pile" ...) make believable unnamed rocks: give
them a different --seed for each new one.

Axis ratios are from published shape models (rounded).
"""

from __future__ import annotations

import csv
from dataclasses import replace
from pathlib import Path

from .asteroid import AsteroidParams, Basin

BODIES_CSV = Path(__file__).resolve().parents[3] / "game-engine" / "sql" / "game-data" / "bodies.csv"

# Each preset is (shape parameters, surface type).
PRESETS: dict[str, tuple[AsteroidParams, str]] = {
    # --- Large asteroids: nearly round, dense with craters, no boulders in view.
    "ceres": (AsteroidParams(
        name="Ceres", seed=191, axes=(1.0, 1.0, 0.93), warp=0.0, lump=0.008, ridge=0.0,
        craters=1800, crater_min=1.2, crater_max=15.0, crater_depth=0.12,
    ), "ceres"),
    "pallas": (AsteroidParams(
        name="Pallas", seed=192, axes=(1.0, 0.94, 0.79), warp=0.05, lump=0.035, ridge=0.006,
        craters=1500, crater_min=1.2, crater_max=16.0, crater_depth=0.15,
        basins=[Basin(lat=-30, lon=40, radius=35, depth=0.05)],
    ), "b-type"),
    "juno": (AsteroidParams(
        name="Juno", seed=193, axes=(1.0, 0.83, 0.63), warp=0.12, lump=0.07, ridge=0.015,
        craters=900, crater_min=1.2, crater_max=16.0,
    ), "stony"),
    "vesta": (AsteroidParams(
        name="Vesta", seed=194, axes=(1.0, 0.97, 0.78), warp=0.04, lump=0.03, ridge=0.008,
        craters=1400, crater_min=1.2, crater_max=13.0, crater_depth=0.16, grooves=0.012,
        basins=[
            Basin(lat=-80, lon=300, radius=58, depth=0.09, peak=0.05),  # Rheasilvia
            Basin(lat=-40, lon=180, radius=35, depth=0.035),  # Veneneia, partly overlapped
        ],
    ), "basaltic"),
    "hygiea": (AsteroidParams(
        name="Hygiea", seed=195, axes=(1.0, 0.98, 0.95), warp=0.0, lump=0.012, ridge=0.0,
        craters=1500, crater_min=1.2, crater_max=12.0, crater_depth=0.13,
    ), "carbonaceous"),
    "psyche": (AsteroidParams(
        name="Psyche", seed=196, axes=(1.0, 0.85, 0.62), warp=0.1, lump=0.06, ridge=0.012,
        craters=800, crater_min=1.2, crater_max=17.0, crater_depth=0.12,
        basins=[Basin(lat=20, lon=90, radius=30, depth=0.06)],
    ), "metallic"),
    # --- Comet nuclei: small, dark, irregular, often two-lobed, with boulders.
    "halley": (AsteroidParams(
        name="Halley", seed=197, axes=(1.0, 0.55, 0.52), waist=0.22, lobe_ratio=0.9, warp=0.2,
        lump=0.1, ridge=0.04, craters=60, crater_max=10.0, crater_depth=0.1, boulders=120, boulder_size=0.025,
    ), "cometary"),
    "encke": (AsteroidParams(
        name="Encke", seed=198, axes=(1.0, 0.85, 0.75), warp=0.2, lump=0.12, ridge=0.04,
        craters=40, crater_max=12.0, crater_depth=0.1, boulders=80, boulder_size=0.025,
    ), "cometary"),
    "churyumov-gerasimenko": (AsteroidParams(
        name="Churyumov-Gerasimenko", seed=199, axes=(1.0, 0.68, 0.6), waist=0.36, lobe_ratio=0.78,
        bend=0.35, warp=0.18, lump=0.08, ridge=0.06, ridge_scale=4.0,
        craters=25, crater_max=8.0, crater_depth=0.08, boulders=300, boulder_size=0.02,
    ), "cometary"),
    "tempel 1": (AsteroidParams(
        name="Tempel 1", seed=200, axes=(1.0, 0.8, 0.7), warp=0.15, lump=0.09, ridge=0.03,
        craters=90, crater_max=9.0, crater_depth=0.1, boulders=60, boulder_size=0.02,
    ), "cometary"),
    # --- Generic types for unnamed rocks; vary with --seed.
    "c-type": (AsteroidParams(name="C-type asteroid", seed=1, axes=(1.0, 0.8, 0.68)), "carbonaceous"),
    "s-type": (AsteroidParams(name="S-type asteroid", seed=1, axes=(1.0, 0.75, 0.6), ridge=0.04), "stony"),
    "m-type": (AsteroidParams(name="M-type asteroid", seed=1, axes=(1.0, 0.82, 0.66), lump=0.08), "metallic"),
    "rubble-pile": (AsteroidParams(
        name="Rubble-pile asteroid", seed=1, axes=(1.0, 0.62, 0.5), waist=0.3, lobe_ratio=0.75,
        warp=0.15, lump=0.08, ridge=0.02, craters=40, crater_max=12.0, crater_depth=0.06,
        boulders=700, boulder_size=0.035,
    ), "stony"),
}

_TYPE_DEFAULTS = {"Asteroid": "c-type", "Comet": "encke"}


def body_rows() -> dict[str, dict[str, str]]:
    """Asteroids and comets in bodies.csv, keyed by lower-case name."""
    rows: dict[str, dict[str, str]] = {}
    with open(BODIES_CSV, newline="") as fh:
        lines = (line for line in fh if not line.startswith("#"))
        for row in csv.DictReader(lines):
            if row["type"] in _TYPE_DEFAULTS:
                rows[row["name"].strip().lower()] = row
    return rows


def resolve(name: str, seed: int | None = None) -> tuple[AsteroidParams, str]:
    """Look up a preset or a body in bodies.csv; an explicit seed overrides."""
    key = name.strip().lower()
    if key in PRESETS:
        params, surface = PRESETS[key]
    else:
        rows = body_rows()
        if key not in rows:
            known = sorted(set(PRESETS) | set(rows))
            raise SystemExit(f"Unknown asteroid '{name}'. Known: {', '.join(known)}")
        row = rows[key]
        # A body with no hand-tuned preset gets its type's generic look,
        # seeded by its id so it always looks the same.
        params, surface = PRESETS[_TYPE_DEFAULTS[row["type"]]]
        params = replace(params, name=row["name"], seed=int(row["id"]))
    if seed is not None:
        params = replace(params, seed=seed)
    return replace(params, basins=list(params.basins)), surface


def all_names() -> list[str]:
    return sorted(set(PRESETS) | set(body_rows()))

