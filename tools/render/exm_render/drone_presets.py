"""Named drone layouts.

Each preset is a role: what the drone is for decides what is bolted to it.
"random" builds a new layout from the seed. Bays count from the aft
(engine) end, starting at 0.
"""

from __future__ import annotations

from dataclasses import replace

import numpy as np

from .drone import FACES, DroneParams, Mount
from .drone_parts import SIDE_PARTS

M = Mount

PRESETS: dict[str, DroneParams] = {
    # Small and fast: one engine, two tanks, a little equipment.
    "courier": DroneParams(
        name="Courier drone", seed=11, bays=3, engines=1, engine_size=0.9, bow="dock",
        mounts=[
            M("tank", 0, "top", span=2), M("tank", 0, "bottom", span=2, foil=True),
            M("module", 2, "port"), M("sensor", 2, "starboard"), M("dish", 2, "top"),
        ],
    ),
    # Moves other craft and cargo: big engine cluster, lots of propellant.
    "tug": DroneParams(
        name="Tug drone", seed=12, bays=4, engines=4, engine_size=1.1, bow="grapple", core_tanks=[0, 1],
        mounts=[
            M("tank", 0, "port", span=2, foil=True), M("tank", 0, "starboard", span=2, foil=True),
            M("tank", 0, "top", span=2), M("tank", 0, "bottom", span=2),
            M("radiator", 2, "port", span=2), M("radiator", 2, "starboard", span=2),
            M("module", 3, "top"), M("sensor", 3, "bottom"),
        ],
    ),
    # Long-haul freighter: a long spine of cargo racks.
    "hauler": DroneParams(
        name="Hauler drone", seed=13, bays=7, engines=3, engine_size=1.0, bow="dock", core_tanks=[0],
        mounts=[
            M("tank", 0, "top", span=2), M("tank", 0, "bottom", span=2),
            M("tank", 0, "port", span=2, foil=True), M("tank", 0, "starboard", span=2, foil=True),
            M("cargo", 2, "top", span=2), M("cargo", 4, "top", span=2),
            M("cargo", 2, "bottom", span=2), M("cargo", 4, "bottom", span=2),
            M("cargo", 2, "port", span=2), M("cargo", 4, "starboard", span=2),
            M("radiator", 2, "starboard", span=2), M("solar", 4, "port", span=2),
            M("module", 6, "top"), M("dish", 6, "bottom"),
        ],
    ),
    # Asteroid miner: a drill on the bow, ore racks, a processing module.
    "miner": DroneParams(
        name="Mining drone", seed=14, bays=5, engines=1, engine_size=1.2, bow="drill", core_tanks=[0],
        mounts=[
            M("tank", 0, "port", span=2), M("tank", 0, "starboard", span=2),
            M("cargo", 2, "top", span=2), M("cargo", 2, "bottom", span=2),
            M("module", 4, "port", foil=True), M("radiator", 2, "port", span=2),
            M("sensor", 4, "top"), M("module", 4, "starboard"), M("module", 0, "top", span=2),
        ],
    ),
    # Industrial: smelters and reactors need big radiators.
    "refinery": DroneParams(
        name="Refinery drone", seed=15, bays=5, engines=2, engine_size=0.9, bow="sensor",
        mounts=[
            M("tank", 0, "top"), M("tank", 0, "bottom"),
            M("module", 1, "top", span=2), M("module", 1, "bottom", span=2, foil=True),
            M("radiator", 1, "port", span=3), M("radiator", 1, "starboard", span=3),
            M("cargo", 3, "top", span=2), M("cargo", 3, "bottom", span=2),
        ],
    ),
    # Scout: light, solar powered, all antennas and sensors.
    "surveyor": DroneParams(
        name="Survey drone", seed=16, bays=3, engines=1, engine_size=0.8, bow="sensor",
        mounts=[
            M("tank", 0, "top", foil=True), M("tank", 0, "bottom", foil=True),
            M("solar", 1, "port"), M("solar", 1, "starboard"),
            M("dish", 2, "top"), M("sensor", 2, "bottom"), M("module", 1, "top", foil=True),
        ],
    ),
    # The game's starter kits, built from their actual modules (resources.py).
    # Mining Kit I: Engine (A1), Worm (A1) and Refinery (A1).
    "mining-kit-1": DroneParams(
        name="Mining Kit I", seed=5901, bays=4, engines=1, engine_size=1.0, bow="dock",
        mounts=[
            M("tank", 0, "port"), M("tank", 0, "starboard"),
            M("worm", 1, "bottom", span=2), M("refinery", 1, "top", span=2),
            M("radiator", 1, "port", span=2), M("sensor", 3, "starboard"), M("dish", 3, "top"),
        ],
    ),
    # Factory Kit I: Engine (A1), Smelter (A1), Chemical Reactor (A1) and Spider (A1).
    "factory-kit-1": DroneParams(
        name="Factory Kit I", seed=5902, bays=4, engines=1, engine_size=1.0, bow="dock",
        mounts=[
            M("tank", 0, "top"), M("tank", 0, "bottom"),
            M("smelter", 1, "top"), M("chemical reactor", 2, "top"),
            M("spider", 1, "port"), M("radiator", 1, "starboard", span=2),
            M("module", 1, "bottom", span=2, foil=True), M("sensor", 3, "bottom"),
        ],
    ),
}


def random_layout(seed: int) -> DroneParams:
    """A plausible drone of random role and size."""
    rng = np.random.default_rng(seed)
    bays = int(rng.integers(3, 8))
    p = DroneParams(
        name=f"Drone {seed}", seed=seed, bays=bays,
        engines=int(rng.choice([1, 1, 2, 3, 4])), engine_size=float(rng.uniform(0.8, 1.2)),
        bow=str(rng.choice(["dock", "drill", "grapple", "sensor"])),
        core_tanks=[0] if rng.random() < 0.5 else [],
    )
    # Propellant at the aft end, then fill the rest bay by bay.
    span = 2 if bays >= 5 else 1
    foil = bool(rng.random() < 0.5)
    for face in ("top", "bottom") if rng.random() < 0.5 else FACES:
        p.mounts.append(Mount("tank", 0, face, span=span, foil=foil))
    taken = {(b, f) for m in p.mounts for b in range(m.bay, m.bay + m.span) for f in [m.face]}
    choices = [k for k in SIDE_PARTS if k != "tank"]
    weights = np.array([{"cargo": 3, "module": 3, "radiator": 2, "solar": 1, "dish": 1, "sensor": 1}[k] for k in choices], float)
    for face in FACES:
        b = 0
        while b < bays:
            if (b, face) in taken or rng.random() < 0.25:
                b += 1
                continue
            part = str(rng.choice(choices, p=weights / weights.sum()))
            sp = int(min(rng.integers(1, 3), bays - b)) if part in ("cargo", "radiator", "solar", "module") else 1
            if any((b + k, face) in taken for k in range(sp)):
                sp = 1
            p.mounts.append(Mount(part, b, face, span=sp, foil=bool(rng.random() < 0.25)))
            taken.update((b + k, face) for k in range(sp))
            b += sp
    return p


def resolve(name: str, seed: int | None = None) -> DroneParams:
    key = name.strip().lower()
    if key == "random":
        return random_layout(seed if seed is not None else 1)
    if key not in PRESETS:
        raise SystemExit(f"Unknown drone '{name}'. Known: {', '.join(all_names())}")
    p = PRESETS[key]
    p = replace(p, mounts=list(p.mounts), core_tanks=list(p.core_tanks))
    if seed is not None:
        p.seed = seed
    return p


def all_names() -> list[str]:
    return sorted(PRESETS) + ["random"]
