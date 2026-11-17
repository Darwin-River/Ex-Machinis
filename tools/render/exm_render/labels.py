"""Where a model's game items show in a rendered view (#802).

Parts tagged with an `exm_resource` id (drone.py tags each mounted module and
the engine block) are found in the picture: a sample of each part's vertices
is cast from the camera, and the points the camera really sees give the
part's anchor, the seen point nearest their middle. A part glimpsed only
through gaps (under a tenth of its sample) gets no anchor in this view.
"""

from __future__ import annotations

import bpy
import numpy as np
from bpy_extras.object_utils import world_to_camera_view


def anchors(scene: bpy.types.Scene, root: bpy.types.Object, sample: int = 600) -> list[dict]:
    """[{"id": resource id, "x": px, "y": px}] for each tagged part the camera sees."""
    cam = scene.camera
    depsgraph = bpy.context.evaluated_depsgraph_get()
    W, H = scene.render.resolution_x, scene.render.resolution_y
    origin = cam.matrix_world.translation
    rng = np.random.default_rng(1)
    out = []
    for obj in [o for o in root.children_recursive if o.type == "MESH" and "exm_resource" in o]:
        verts = [obj.matrix_world @ v.co for v in obj.data.vertices]
        if len(verts) > sample:
            verts = [verts[i] for i in rng.choice(len(verts), sample, replace=False)]
        seen = []
        for p in verts:
            d = p - origin
            hit, loc, _n, _i, hobj, _m = scene.ray_cast(depsgraph, origin, d.normalized(), distance=d.length + 1e-3)
            # Seen if the first thing the ray meets is this part (another face of it counts)
            if hit and (hobj.original.name == obj.name or (loc - p).length < 1e-3):
                c = world_to_camera_view(scene, cam, p)
                if 0 <= c.x <= 1 and 0 <= c.y <= 1:
                    seen.append((c.x * W, (1 - c.y) * H))
        if len(seen) < 8 or len(seen) < 0.1 * len(verts):
            continue
        pts = np.array(seen)
        a = pts[np.argmin(np.linalg.norm(pts - np.median(pts, axis=0), axis=1))]
        out.append({"id": int(obj["exm_resource"]), "x": round(float(a[0]), 1), "y": round(float(a[1]), 1)})
    return out
