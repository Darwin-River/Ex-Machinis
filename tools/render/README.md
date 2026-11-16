# Render toolkit

Scripts that build Ex Machinis objects in Blender and render them for the website. Every model is generated from parameters and a seed, so the same command always produces the same picture, and a new seed gives a new variation.

The toolkit can make asteroids and comet nuclei (#705) and uncrewed drones (#706) so far. Resource and product models (#707) will follow.

## Setup

It runs on Blender's Python module (`bpy`), so you don't need to install Blender or have a GPU. `bpy` 5.3 needs Python 3.13.

```bash
cd tools/render
uv venv -p 3.13 .venv
uv pip install -p .venv -r requirements.txt
```

Without `uv`, run `python3.13 -m venv .venv` and then `.venv/bin/pip install -r requirements.txt`.

## Rendering asteroids

```bash
.venv/bin/python render.py asteroid --list
.venv/bin/python render.py asteroid vesta --size hero
.venv/bin/python render.py asteroid ceres pallas juno --size card
.venv/bin/python render.py asteroid c-type --seed 42 --phase 110 --background transparent
```

Images go to `tools/render/output/`, which git ignores. Copy the ones you want into the site, for example under `html/images/`.

Names are presets, or any asteroid or comet in `game-engine/sql/game-data/bodies.csv`:

- **Named bodies** (`ceres`, `pallas`, `juno`, `vesta`, `hygiea`, `psyche`, `halley`, `encke`, `churyumov-gerasimenko`, `tempel 1`): tuned to the body's known shape and surface. A body added to `bodies.csv` without a preset gets the generic look for its type, seeded by its id.
- **Generic types** (`c-type`, `s-type`, `m-type`, `rubble-pile`): unnamed rocks. Use `--seed` to make as many different ones as you need.

## Rendering drones

```bash
.venv/bin/python render.py drone --list
.venv/bin/python render.py drone miner --size hero
.venv/bin/python render.py drone hauler --firing --rotation 20 0 200
.venv/bin/python render.py drone random --seed 7
```

Drones carry no crew, so they have no pressurised hull. Each one is an open, square-section truss of bays: engines and the thrust structure that spreads their load into the truss sit at the aft end, and a fitting sits at the bow (`dock`, `drill`, `grapple` or `sensor`). Every other part bolts onto one of the truss's four long faces (`top`, `bottom`, `port`, `starboard`) and spans one or more bays. Parts are braced to the truss nodes with struts, so they read as rigidly fixed for hard acceleration and braking.

- **Role presets:** `courier`, `tug`, `hauler`, `miner`, `refinery` and `surveyor`. Each layout is a list of mounts in `exm_render/drone_presets.py`.
- **`random`:** builds a new, plausible layout from `--seed`.
- **Parts:** `tank`, `module`, `cargo` (an open rack of strapped crates), `radiator`, `solar`, `dish` and `sensor`, in `exm_render/drone_parts.py`. Tanks and modules can be painted or wrapped in gold insulation foil. Spherical tanks can also sit inside the truss (`core_tanks`).
- **Drone-only options:** `--firing` lights the engines with exhaust plumes, and `--accent R G B` sets the accent paint (default safety orange).

By default a drone is shown from a rear three-quarter angle, engines towards the camera, with a little fill light so the shaded side still reads. It is framed by its outline as the camera sees it, so long drones fill wide frames.

### The mounting standard

Every side part is built in its own frame: the origin sits in the middle of the truss face, +Z points out from the face, +X runs along the thrust axis towards the bow, and the truss nodes are at y = ±0.5 on the plane z = 0. The truss is 1 unit wide. A new part only has to follow this standard to fit any face of any drone. This is how the resource models in #707 will become drone parts.

## Shot options

These work for every subject.


| Option | Default | What it does |
|---|---|---|
| `--size` | `card` | `hero` 1920×1080, `banner` 2400×800, `card` 1200×800, `social` 1200×630, `square` 1024, `thumb` 512, `preview` 640×360 |
| `--width`, `--height` | | Custom size in pixels; overrides `--size` |
| `--phase` | 70 (drones 45) | Sun–subject–camera angle in degrees: 0 is fully lit, 90 is half lit, 150 is a thin crescent. Negative values put the sun on the left |
| `--sun-elevation` | 20 (drones 40) | How far the sun sits above the camera's horizon, in degrees |
| `--view-elevation` | 10 (drones 18) | Camera height above the subject's equator, in degrees |
| `--rotation X Y Z` | from seed | The subject's orientation, in degrees |
| `--fill` | 0.75 | How much of the frame's short side the subject fills |
| `--offset X Y` | 0 0 | Moves the subject off-centre, as a fraction of the frame, e.g. `0.25 0` to leave room for text on the left |
| `--background` | `stars` | `stars`, `black` or `transparent` (transparent needs PNG, and loses glows such as exhaust plumes) |
| `--stars` | 0.6 | Star brightness |
| `--exposure` | 0 | Extra stops of exposure |
| `--fill-light` | 0 (drones 0.15) | Faint light from the camera side; 0 keeps shadows black, as in space |
| `--samples` | 96 | Render quality; 24 is fine for drafts |
| `--subdivisions` | 7 | Asteroids only. Mesh detail: 6 for fast drafts, 8 for close-ups |
| `--format` | `png` | `png` or `jpg` |
| `--blend` | | Also save a `.blend` file to open and edit in Blender |

On four CPU cores, a `preview` draft takes about 5 seconds. A full-size `hero` render takes about 90 seconds for an asteroid at `--subdivisions 8`, and about a minute for a drone.

## How it works

- `exm_render/asteroid.py` builds the mesh. It starts from an icosphere and gives each direction a radius from the ellipsoid axes, an optional two-lobed waist, lumpy and ridged noise, named basins, craters (with a power-law size spread, raised rims, and flat floors and central peaks on big ones), grooves and scattered boulders. The finished mesh is scaled so its furthest point is 1 unit from the centre.
- `exm_render/materials.py` holds the surface types (carbonaceous, B-type, stony, basaltic, metallic, Ceres, cometary). Each is a colour pair plus roughness. The shader adds the detail too fine to model: three layers of small craters, fine grit and colour patches.
- `exm_render/scene.py` sets up the sun, the camera, the exposure and the output sizes. Exposure follows the surface's albedo, so dark comets and bright Vesta both come out well exposed.
- `exm_render/starfield.py` draws the stars as an image at the output size and shows it behind the subject, seen by the camera only. Stars stay pin-sharp, never light the subject, and glows add over them naturally.
- `exm_render/presets.py` holds the named asteroid presets and the lookup into `bodies.csv`.
- `exm_render/geom.py` is a small hard-surface kit (struts, boxes, lathed shapes, clamp rings). `drone.py` builds the truss and places parts, `drone_parts.py` holds the parts, and `materials.hard_surface` holds the paint, foil, metal, solar-cell and glow materials.
- Each drone part is its own object in the `.blend` file, parented to the drone, so it can be edited or swapped in Blender.

To add an asteroid preset, add an entry to `PRESETS` in `presets.py`; every parameter is documented in `AsteroidParams` in `asteroid.py`. To add a drone, add a `DroneParams` to `PRESETS` in `drone_presets.py`; to add a part, write a function in `drone_parts.py` that follows the mounting standard and list it in `SIDE_PARTS`.
