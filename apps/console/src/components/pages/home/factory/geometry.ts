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

/**
 * Built shapes by their parameters: the line repeats the same housings,
 * blades and fittings many times, so each is built once and copied.
 * `releaseShapes` frees them once the machine is built.
 */
const shapes = new Map<string, BufferGeometry>()

function shape(key: string, build: () => BufferGeometry): BufferGeometry {
  let geometry = shapes.get(key)
  if (!geometry) {
    // Kept as a plain geometry: cloning a parametric one rebuilds a default
    // shape in its constructor before copying.
    const built = build()
    geometry = new BufferGeometry().copy(built)
    built.dispose()
    shapes.set(key, geometry)
  }
  return geometry.clone()
}

export function releaseShapes() {
  for (const geometry of shapes.values()) geometry.dispose()
  shapes.clear()
}

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
  return shape(
    `slab ${width} ${depth} ${thickness} ${cornerRadius} ${bevel}`,
    () => {
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
    },
  )
}

export function box(
  width: number,
  height: number,
  depth: number,
  radius = Math.min(width, height, depth) * 0.18,
  segments = 3,
): BufferGeometry {
  return shape(
    `box ${width} ${height} ${depth} ${radius} ${segments}`,
    () => new RoundedBoxGeometry(width, height, depth, segments, radius),
  )
}

export function cylinder(
  radius: number,
  height: number,
  segments = 28,
  top = radius,
  openEnded = false,
): BufferGeometry {
  return shape(
    `cylinder ${radius} ${height} ${segments} ${top} ${openEnded}`,
    () => new CylinderGeometry(top, radius, height, segments, 1, openEnded),
  )
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

const MERGED_ATTRIBUTES = [
  'position',
  'normal',
  'uv',
  'color',
  'aFinish',
  'aArrive',
]

/**
 * Merges parts into one geometry (one draw call), straight into typed
 * arrays. The result is indexed, so vertices parts share are shaded once
 * instead of once per triangle; if any part carries baked vertex colors, the
 * others get plain white.
 */
export function merge(
  parts: BufferGeometry[],
  colored = false,
): BufferGeometry {
  const withColor = colored || parts.some((part) => part.hasAttribute('color'))
  const prepared = parts.map((part) => {
    // A part without normals shades flat, as it always has.
    const geometry =
      part.index && !part.attributes.normal ? part.toNonIndexed() : part
    if (!geometry.attributes.normal) geometry.computeVertexNormals()
    return geometry
  })
  const first = prepared[0]
  if (!first) throw new Error('Factory geometry merge failed')
  const names = MERGED_ATTRIBUTES.filter((name) =>
    name === 'color' ? withColor : first.hasAttribute(name),
  )
  let vertices = 0
  let indices = 0
  for (const geometry of prepared) {
    const count = geometry.getAttribute('position').count
    vertices += count
    indices += geometry.index ? geometry.index.count : count
  }

  const merged = new BufferGeometry()
  for (const name of names) {
    const size = name === 'color' ? 3 : first.getAttribute(name).itemSize
    const array = new Float32Array(vertices * size)
    let offset = 0
    for (const geometry of prepared) {
      const length = geometry.getAttribute('position').count * size
      const attribute = geometry.getAttribute(name) as
        | BufferAttribute
        | undefined
      if (
        attribute?.itemSize === size &&
        attribute.array instanceof Float32Array
      ) {
        array.set(attribute.array.subarray(0, length), offset)
      } else if (!attribute && name === 'color') {
        array.fill(1, offset, offset + length)
      } else {
        throw new Error(`Factory geometry merge failed: ${name}`)
      }
      offset += length
    }
    merged.setAttribute(name, new BufferAttribute(array, size))
  }

  const index = new (vertices > 65535 ? Uint32Array : Uint16Array)(indices)
  let base = 0
  let at = 0
  for (const geometry of prepared) {
    const count = geometry.getAttribute('position').count
    if (geometry.index) {
      const source = geometry.index.array
      for (let item = 0; item < geometry.index.count; item++)
        index[at++] = source[item]! + base
    } else {
      for (let vertex = 0; vertex < count; vertex++) index[at++] = base + vertex
    }
    base += count
  }
  merged.setIndex(new BufferAttribute(index, 1))

  for (const part of parts) part.dispose()
  for (const part of prepared) part.dispose()
  return merged
}

/**
 * Merges parts in several finishes into one geometry for the kit's `finish`
 * material: each vertex records its finish (`aFinish`, an index into
 * `FINISHES`), so a rigid assembly of aluminum, steel and rubber parts is a
 * single draw in every pass. Parts not marked as on their way into place
 * (`aArrive`, see `assembly.ts`) stand still.
 */
export function mergeFinishes(
  parts: Partial<Record<string, BufferGeometry[]>>,
  finishes: readonly string[],
): BufferGeometry {
  const tagged: BufferGeometry[] = []
  finishes.forEach((finish, index) => {
    for (const part of parts[finish] ?? []) {
      const count = part.getAttribute('position').count
      part.setAttribute(
        'aFinish',
        new BufferAttribute(new Float32Array(count).fill(index), 1),
      )
      if (!part.hasAttribute('aArrive'))
        part.setAttribute(
          'aArrive',
          new BufferAttribute(new Float32Array(count * 4), 4),
        )
      tagged.push(part)
    }
  })
  return merge(tagged, true)
}
