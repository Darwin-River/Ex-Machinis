"""Small hard-surface modelling kit: struts, boxes, lathed shapes.

Everything builds into a bmesh and tags its faces with a material slot, so
a whole part can be one mesh with several materials. Positions are
mathutils Vectors in the part's local frame.
"""

from __future__ import annotations

import math

import bmesh
from mathutils import Matrix, Vector


def _tag(faces, mat: int) -> None:
    for f in faces:
        f.material_index = mat


def _faces_of(verts) -> set:
    faces = set()
    for v in verts:
        faces.update(v.link_faces)
    return faces


def frame(origin: Vector, z_axis: Vector, x_hint: Vector = Vector((1, 0, 0))) -> Matrix:
    """A 4x4 matrix at origin whose local Z points along z_axis."""
    z = z_axis.normalized()
    x = x_hint - z * x_hint.dot(z)
    if x.length < 1e-6:
        x = Vector((0, 1, 0)) - z * z.y
    x.normalize()
    y = z.cross(x)
    m = Matrix((x, y, z)).transposed().to_4x4()
    m.translation = origin
    return m


def strut(bm, a: Vector, b: Vector, r: float, mat: int, seg: int = 8) -> None:
    """A round bar from a to b."""
    d = b - a
    if d.length < 1e-6:
        return
    m = frame((a + b) / 2, d)
    res = bmesh.ops.create_cone(bm, cap_ends=True, segments=seg, radius1=r, radius2=r, depth=d.length, matrix=m)
    _tag(_faces_of(res["verts"]), mat)


def box(bm, centre: Vector, size: tuple[float, float, float], mat: int, rot: Matrix | None = None) -> None:
    m = Matrix.Translation(centre) @ (rot.to_4x4() if rot is not None else Matrix.Identity(4)) @ Matrix.Diagonal((*size, 1.0))
    res = bmesh.ops.create_cube(bm, size=1.0, matrix=m)
    _tag(_faces_of(res["verts"]), mat)


def box_between(bm, a: Vector, b: Vector, w: float, h: float, mat: int, up: Vector = Vector((0, 0, 1))) -> None:
    """A rectangular beam from a to b, w wide and h tall."""
    d = b - a
    m = frame((a + b) / 2, d, up) @ Matrix.Diagonal((h, w, d.length, 1.0))
    res = bmesh.ops.create_cube(bm, size=1.0, matrix=m)
    _tag(_faces_of(res["verts"]), mat)


def sphere(bm, centre: Vector, r: float, mat: int, subdiv: int = 3) -> None:
    res = bmesh.ops.create_icosphere(bm, subdivisions=subdiv, radius=r, matrix=Matrix.Translation(centre))
    _tag(_faces_of(res["verts"]), mat)


def lathe(bm, profile: list[tuple[float, float]], mat: int, m: Matrix = Matrix.Identity(4), seg: int = 32,
          cap_start: bool = False, cap_end: bool = False) -> None:
    """Spin a (radius, z) profile around local Z. Points run from z start to z end."""
    rings = []
    for r, z in profile:
        ring = []
        for k in range(seg):
            t = math.tau * k / seg
            ring.append(bm.verts.new(m @ Vector((r * math.cos(t), r * math.sin(t), z))))
        rings.append(ring)
    faces = []
    for r0, r1 in zip(rings, rings[1:]):
        for k in range(seg):
            k1 = (k + 1) % seg
            faces.append(bm.faces.new((r0[k], r0[k1], r1[k1], r1[k])))
    if cap_start and profile[0][0] > 1e-6:
        faces.append(bm.faces.new(list(reversed(rings[0]))))
    if cap_end and profile[-1][0] > 1e-6:
        faces.append(bm.faces.new(rings[-1]))
    _tag(faces, mat)


def capsule_profile(r: float, length: float, steps: int = 8) -> list[tuple[float, float]]:
    """A tank: cylinder of radius r with hemispherical ends, total length along Z, centred."""
    half = length / 2 - r
    pts = []
    for i in range(steps + 1):  # bottom dome
        a = -math.pi / 2 + (math.pi / 2) * i / steps
        pts.append((r * math.cos(a), -half + r * math.sin(a)))
    for i in range(steps + 1):  # top dome
        a = (math.pi / 2) * i / steps
        pts.append((r * math.cos(a), half + r * math.sin(a)))
    pts[0] = (0.0, pts[0][1])
    pts[-1] = (0.0, pts[-1][1])
    return pts


def ring(bm, centre: Vector, axis: Vector, r: float, thickness: float, width: float, mat: int, seg: int = 32) -> None:
    """A flat band around an axis, e.g. a tank clamp."""
    m = frame(centre, axis)
    lathe(bm, [(r, -width / 2), (r + thickness, -width / 2), (r + thickness, width / 2), (r, width / 2), (r, -width / 2)], mat, m, seg)
