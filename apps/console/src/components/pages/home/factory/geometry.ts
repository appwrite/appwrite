import {
  BufferAttribute,
  BufferGeometry,
  CylinderGeometry,
  ExtrudeGeometry,
  Shape,
  ShapeGeometry,
  type Matrix4,
} from 'three'
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js'
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js'

/** Rounded rectangle outline centered on the origin. */
export function roundedRectShape(
  width: number,
  depth: number,
  radius: number,
): Shape {
  const r = Math.min(radius, width / 2, depth / 2)
  const x = -width / 2
  const y = -depth / 2
  const shape = new Shape()
  shape.moveTo(x + r, y)
  shape.lineTo(x + width - r, y)
  shape.quadraticCurveTo(x + width, y, x + width, y + r)
  shape.lineTo(x + width, y + depth - r)
  shape.quadraticCurveTo(x + width, y + depth, x + width - r, y + depth)
  shape.lineTo(x + r, y + depth)
  shape.quadraticCurveTo(x, y + depth, x, y + depth - r)
  shape.lineTo(x, y + r)
  shape.quadraticCurveTo(x, y, x + r, y)
  return shape
}

/**
 * Device-style slab: big plan-view corner radius, small edge bevel, lying in
 * the XZ plane from y = 0 to y = thickness.
 */
export function slab(
  width: number,
  depth: number,
  thickness: number,
  cornerRadius: number,
  bevel = Math.min(0.012, thickness * 0.3),
): BufferGeometry {
  const geometry = new ExtrudeGeometry(
    roundedRectShape(width, depth, cornerRadius),
    {
      depth: Math.max(thickness - bevel * 2, 0.001),
      bevelEnabled: bevel > 0,
      bevelThickness: bevel,
      bevelSize: bevel,
      bevelOffset: -bevel,
      bevelSegments: 2,
      curveSegments: 5,
    },
  )
  geometry.rotateX(-Math.PI / 2)
  geometry.translate(0, bevel, 0)
  return geometry
}

export function box(
  width: number,
  height: number,
  depth: number,
  radius = Math.min(width, height, depth) * 0.18,
  segments = 3,
): BufferGeometry {
  return new RoundedBoxGeometry(width, height, depth, segments, radius)
}

export function cylinder(
  radius: number,
  height: number,
  segments = 28,
  top = radius,
  openEnded = false,
): BufferGeometry {
  return new CylinderGeometry(top, radius, height, segments, 1, openEnded)
}

/**
 * A flat panel facing +z, `thickness` deep from z = 0, with rounded corners
 * in its face (a rounded box cannot round them past half its depth).
 */
export function panel(
  width: number,
  height: number,
  thickness: number,
  cornerRadius: number,
): BufferGeometry {
  return slab(
    width,
    height,
    thickness,
    cornerRadius,
    Math.min(0.004, thickness * 0.3),
  ).rotateX(Math.PI / 2)
}

/**
 * A flat rectangle facing +z with uv 0..1 across it and, where `corners`
 * says so (top left, top right, bottom right, bottom left), rounded corners.
 */
export function roundedPlane(
  width: number,
  height: number,
  radius: number,
  corners: readonly [boolean, boolean, boolean, boolean] = [
    true,
    true,
    true,
    true,
  ],
): BufferGeometry {
  const [tl, tr, br, bl] = corners.map((round) =>
    round ? Math.min(radius, width / 2, height / 2) : 0,
  ) as [number, number, number, number]
  const left = -width / 2
  const right = width / 2
  const bottom = -height / 2
  const top = height / 2
  const shape = new Shape()
  shape.moveTo(left + bl, bottom)
  shape.lineTo(right - br, bottom)
  if (br) shape.quadraticCurveTo(right, bottom, right, bottom + br)
  shape.lineTo(right, top - tr)
  if (tr) shape.quadraticCurveTo(right, top, right - tr, top)
  shape.lineTo(left + tl, top)
  if (tl) shape.quadraticCurveTo(left, top, left, top - tl)
  shape.lineTo(left, bottom + bl)
  if (bl) shape.quadraticCurveTo(left, bottom, left + bl, bottom)
  const geometry = new ShapeGeometry(shape, 8)
  const position = geometry.getAttribute('position')
  const uv = geometry.getAttribute('uv')
  for (let index = 0; index < position.count; index++) {
    uv.setXY(
      index,
      (position.getX(index) - left) / width,
      (position.getY(index) - bottom) / height,
    )
  }
  return geometry
}

/** Flat-sided prism (hex nuts, chucks): facets stay facets instead of shading round. */
export function prism(
  radius: number,
  height: number,
  sides = 6,
): BufferGeometry {
  const geometry = new CylinderGeometry(
    radius,
    radius,
    height,
    sides,
  ).toNonIndexed()
  geometry.computeVertexNormals()
  return geometry
}

/** Applies a matrix and returns the geometry, for compact part lists. */
export function place(
  geometry: BufferGeometry,
  matrix: Matrix4,
): BufferGeometry {
  geometry.applyMatrix4(matrix)
  return geometry
}

/**
 * Bakes soft occlusion into vertex colors: full brightness at `base + height`,
 * darker toward `base`, where the part meets what it stands on. For materials
 * with `vertexColors` on.
 */
export function ground(
  geometry: BufferGeometry,
  base: number,
  height: number,
  depth = 0.3,
): BufferGeometry {
  const position = geometry.getAttribute('position')
  const colors = new Float32Array(position.count * 3)
  for (let index = 0; index < position.count; index++) {
    const t = Math.min(Math.max((position.getY(index) - base) / height, 0), 1)
    colors.fill(1 - depth * (1 - t) ** 2, index * 3, index * 3 + 3)
  }
  geometry.setAttribute('color', new BufferAttribute(colors, 3))
  return geometry
}

const MERGED_ATTRIBUTES = ['position', 'normal', 'uv', 'color']

/**
 * Merges parts into one geometry (one draw call). Normalizes every part to
 * non-indexed position/normal/uv so mixed primitives merge cleanly; if any
 * part carries baked vertex colors, the others get plain white.
 */
export function merge(parts: BufferGeometry[]): BufferGeometry {
  const colored = parts.some((part) => part.hasAttribute('color'))
  const normalized = parts.map((part) => {
    const geometry = part.index ? part.toNonIndexed() : part
    for (const name of Object.keys(geometry.attributes)) {
      if (!MERGED_ATTRIBUTES.includes(name)) geometry.deleteAttribute(name)
    }
    if (!geometry.attributes.normal) geometry.computeVertexNormals()
    if (colored && !geometry.hasAttribute('color')) {
      const count = geometry.getAttribute('position').count
      geometry.setAttribute(
        'color',
        new BufferAttribute(new Float32Array(count * 3).fill(1), 3),
      )
    }
    return geometry
  })
  const merged = mergeGeometries(normalized, false)
  if (!merged) throw new Error('Factory geometry merge failed')
  for (const part of parts) part.dispose()
  for (const part of normalized) part.dispose()
  return merged
}
