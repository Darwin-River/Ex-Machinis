# Render toolkit

Scripts that build Ex Machinis objects in Blender and render them for the website. Every model is generated from parameters and a seed, so the same command always produces the same picture, and a new seed gives a new variation.

The toolkit can make asteroids and comet nuclei (#705), uncrewed drones (#706), a model of every resource in the game (#707), and planets, moons and the wide scenes behind the website's page headers (#737).

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
- **Starter kits:** `mining-kit-1` and `factory-kit-1`, built from the game's real modules (see below).
- **`random`:** builds a new, plausible layout from `--seed`.
- **Parts:** `tank`, `module`, `cargo` (an open rack of strapped crates), `radiator`, `solar`, `dish` and `sensor`, in `exm_render/drone_parts.py`. Tanks and modules can be painted or wrapped in gold insulation foil. Spherical tanks can also sit inside the truss (`core_tanks`).
- **Drone-only options:** `--firing` lights the engines with exhaust plumes, and `--accent R G B` sets the accent paint (default safety orange).

By default a drone is shown from a rear three-quarter angle, engines towards the camera, with a little fill light so the shaded side still reads. It is framed by its outline as the camera sees it, so long drones fill wide frames.

### The mounting standard

Every side part is built in its own frame: the origin sits in the middle of the truss face, +Z points out from the face, +X runs along the thrust axis towards the bow, and the truss nodes are at y = ±0.5 on the plane z = 0. The truss is 1 unit wide. A new part only has to follow this standard to fit any face of any drone. This is how the resource models in #707 will become drone parts.

## Rendering resources

```bash
.venv/bin/python render.py resource --list
.venv/bin/python render.py resource 5201
.venv/bin/python render.py resource smelter "chemical reactor" gyroscopes
.venv/bin/python render.py resource minerals --size thumb
.venv/bin/python render.py resource all --background studio
```

The resource list comes from `game-engine/sql/game_data.sql`, so ids and names always match the game. A name can be an id, the resource's name with or without its grade (`Smelter (A1)` or `smelter`), or a tier: `minerals`, `materials`, `parts`, `systems`, `modules`, `craft`, or `all`. Files are named `<id>-<name>-<size>.png`, e.g. `5201-smelter-square.png`.

- **Systems** (4101–4501): the 22 components, such as bores, gyroscopes, lasers, computers, pumps, thrusters, batteries and reactors. Each is a standalone object.
- **Modules** (5001–5821): the 32 working machines, such as the engine, shuttle, miners (Slug, Crawler, Skimmer, Worm), smelter, refinery, bioreactor, greenhouse, fabricators (lathe, roller, press ...), robots (Spider, Welder, Riveter), weapons, munitions, armour and the shield generator. Each module stands on the standard mounting pallet, so it bolts onto a drone exactly as it is pictured.
- **Minerals** (1000–1430): ore samples. Each mineral has its own crystal colour, habit (prisms, needles, cubes, plates, rounded masses or blades) and lustre, on host rock that matches where it is found: dark basalt for planets, pale rock for moons, carbon-black for asteroids, dusty ice for comets, icy grit for rings.
- **Materials** (2011–2061): ingots for metals, billets for alloys, powder bins for inorganics, drums for organics, spools for polymers, and cryogenic tanks for fuel. Rarer grades look richer, and a label carries one, two or three stripes for A1, A2 or A3.
- **Parts** (3001–3331): a stack, bundle, coil or tray of each.
- **Kits** (5901, 5902): packed cargo crates of foil-wrapped bundles. **House stations** (5911, 5921) are modules: a cargo lift and a launcher.
- **Craft** (6001–6231): Mining, Industrial and Defense craft as Cruiser, Cutter, Frigate or Barge. Each is a drone of that size carrying its role's own modules; a Barge, being stationary, has no engines.

Resource shots use a catalogue look: a square frame, a three-quarter view from above, key, fill and rim lights, and a softbox environment (dark below, bright overhead) so metal has something to reflect. The background is transparent, so a picture sits on any page; use `--background studio` for a flat backdrop close to the site's colour.

### Turntables for the resource pages

Each resource page shows its system or module slowly turning: an animated WebP, with a still for visitors whose device asks to reduce motion. To make them, or remake them after changing a model:

```bash
.venv/bin/python render.py resource all --site
.venv/bin/python render.py resource 5201 --site
```

`--site` writes `html/images/resources/<id>.webp` (one full turn, 40 frames at 10 a second, 400×400, transparent) and `<id>-still.webp`, which the page picks up by itself. Each turntable is about 200 KB and takes a minute or two to render; all 121 take about three hours. Commit the new files with the change that needed them. The page shows no picture for a resource without one.

It also writes `<id>-thumb.webp`, a 96×96 still cropped to the object, for the resource list's thumbnails (shown at 48 px). `render.py resource --thumbs` remakes every thumbnail from the stills already there, without rendering.

`--spin FRAMES` makes a turntable of any resource at any size, with `--fps` and `--quality` (WebP, 0–100). It needs `ffmpeg`.

### Modules on drones

A drone mount can name a module instead of a generic part, by resource id or name:

```python
Mount("smelter", 1, "top")
Mount("chemical reactor", 2, "top")
Mount(5131, 1, "bottom", span=2)  # Worm (A1)
```

The `mining-kit-1` and `factory-kit-1` drone presets are built this way from the modules the game's starter kits contain.

## Rendering page-header scenes

```bash
.venv/bin/python render.py header --list
.venv/bin/python render.py header market --draft
.venv/bin/python render.py header all
```

Every page header on the website shows a wide space scene behind its title (#737). There are nine scenes, one for each part of the site (`render.py header --list` says which pages each heads): Market, Resources, Spacecraft, Locations, Companies, Learn, About, Account, and a default for any other page. `resources/views/components/page-header.blade.php` maps a page's address to its scene.

`header all` writes `html/images/headers/<scene>.webp`, 2400×480, with the left part fading to transparent so the header's own colour shows through behind the title. `--draft` renders at half size with few samples, to check a layout quickly; a full scene takes a few minutes.

Scenes are lists of elements in `exm_render/scenes.py`, placed by where they appear in the frame (across and up, from -1 to 1), their distance, and their size as a fraction of the frame's half-height. Elements can be planets, moons, asteroids, a field of small rocks, or drones (any drone preset, optionally firing). Keep subjects on the right half: the title sits on the left.

### Planets

`exm_render/planet.py` makes planets with a shader-only surface, so they stay sharp at any size: gas giants with turbulent bands, ocean worlds with continents, shallow seas and ice caps, dry rocky worlds, cracked ice worlds, and lava worlds with glowing rifts. Each can have a cloud layer, an atmosphere that glows on the sunlit limb, banded rings that cast shadows, an axial tilt and a polar flattening. Moons are near-round asteroids, so they get the asteroid generator's craters.

## Shot options

These work for every subject.


| Option | Default | What it does |
|---|---|---|
| `--size` | `card` (resources `square`) | `hero` 1920×1080, `banner` 2400×800, `card` 1200×800, `social` 1200×630, `square` 1024, `thumb` 512, `preview` 640×360 |
| `--width`, `--height` | | Custom size in pixels; overrides `--size` |
| `--phase` | 70 (drones 45) | Sun–subject–camera angle in degrees: 0 is fully lit, 90 is half lit, 150 is a thin crescent. Negative values put the sun on the left |
| `--sun-elevation` | 20 (drones 40) | How far the sun sits above the camera's horizon, in degrees |
| `--view-elevation` | 10 (drones 18) | Camera height above the subject's equator, in degrees |
| `--rotation X Y Z` | from seed | The subject's orientation, in degrees |
| `--fill` | 0.75 | How much of the frame's short side the subject fills |
| `--offset X Y` | 0 0 | Moves the subject off-centre, as a fraction of the frame, e.g. `0.25 0` to leave room for text on the left |
| `--background` | `stars` (resources `transparent`) | `stars`, `black`, `transparent` (needs PNG, and loses glows such as exhaust plumes) or `studio` |
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
- `exm_render/planet.py` makes planets and moons; `exm_render/scenes.py` composes the page-header scenes.
- `exm_render/goods.py` holds minerals, materials, parts, kits, the House stations and craft, with two per-resource material slots for each resource's own colours.
- `exm_render/kit.py` holds machine assemblies (housings, pressure vessels, pipe runs, robot arms, turrets, legs, treads, coils). `exm_render/resources.py` reads the resource list and maps each system and module id to its builder.

To add an asteroid preset, add an entry to `PRESETS` in `presets.py`; every parameter is documented in `AsteroidParams` in `asteroid.py`. To add a drone, add a `DroneParams` to `PRESETS` in `drone_presets.py`; to add a part, write a function in `drone_parts.py` that follows the mounting standard and list it in `SIDE_PARTS`. To add a resource model, write a builder in `resources.py` (a system takes `bm, rng`; a module follows the mounting standard) and add it to `SYSTEMS` or `MODULES` under its id.
