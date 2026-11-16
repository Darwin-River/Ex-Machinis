# Render toolkit

Scripts that build Ex Machinis objects in Blender and render them for the website. Every model is generated from parameters and a seed, so the same command always produces the same picture, and a new seed gives a new variation.

The toolkit can make asteroids and comet nuclei so far. Drones (#706) and resource models (#707) will follow.

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

### Shot options

| Option | Default | What it does |
|---|---|---|
| `--size` | `card` | `hero` 1920×1080, `banner` 2400×800, `card` 1200×800, `social` 1200×630, `square` 1024, `thumb` 512, `preview` 640×360 |
| `--width`, `--height` | | Custom size in pixels; overrides `--size` |
| `--phase` | 70 | Sun–subject–camera angle in degrees: 0 is fully lit, 90 is half lit, 150 is a thin crescent |
| `--sun-elevation` | 20 | How far the sun sits above the camera's horizon, in degrees |
| `--view-elevation` | 10 | Camera height above the subject's equator, in degrees |
| `--rotation X Y Z` | from seed | The subject's orientation, in degrees |
| `--fill` | 0.75 | How much of the frame's short side the subject fills |
| `--offset X Y` | 0 0 | Moves the subject off-centre, as a fraction of the frame, e.g. `0.25 0` to leave room for text on the left |
| `--background` | `stars` | `stars`, `black` or `transparent` (transparent needs PNG) |
| `--stars` | 0.6 | Star brightness |
| `--exposure` | 0 | Extra stops of exposure |
| `--fill-light` | 0 | Faint light from the camera side; 0 keeps shadows black, as in space |
| `--samples` | 96 | Render quality; 24 is fine for drafts |
| `--subdivisions` | 7 | Mesh detail: 6 for fast drafts, 8 for close-ups |
| `--format` | `png` | `png` or `jpg` |
| `--blend` | | Also save a `.blend` file to open and edit in Blender |

On four CPU cores, a `preview` draft takes about 5 seconds and a `hero` render at `--subdivisions 8` takes about 90.

## How it works

- `exm_render/asteroid.py` builds the mesh. It starts from an icosphere and gives each direction a radius from the ellipsoid axes, an optional two-lobed waist, lumpy and ridged noise, named basins, craters (with a power-law size spread, raised rims, and flat floors and central peaks on big ones), grooves and scattered boulders. The finished mesh is scaled so its furthest point is 1 unit from the centre.
- `exm_render/materials.py` holds the surface types (carbonaceous, B-type, stony, basaltic, metallic, Ceres, cometary). Each is a colour pair plus roughness. The shader adds the detail too fine to model: three layers of small craters, fine grit and colour patches.
- `exm_render/scene.py` sets up the sun, the camera, the exposure and the output sizes. Exposure follows the surface's albedo, so dark comets and bright Vesta both come out well exposed.
- `exm_render/starfield.py` draws the stars in 2D at the output size and places the render over them, so stars stay sharp and never light the subject.
- `exm_render/presets.py` holds the named presets and the lookup into `bodies.csv`.

To add a preset, add an entry to `PRESETS` in `presets.py`. Every parameter is documented in `AsteroidParams` in `asteroid.py`.
