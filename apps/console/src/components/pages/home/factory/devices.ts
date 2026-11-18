import { Matrix4, Vector3, type BufferGeometry } from 'three'
import { box, cylinder, merge, place, slab } from './geometry'
import { PRODUCT_KINDS, type ProductKind } from './layout'

/**
 * The products the line builds: app screens in the formats apps ship in.
 * They travel flat on their carriers, glass up. Local space: origin on the
 * carrier nest under the product's center, +z faces the camera.
 */
export const DEVICE_SIZES: Record<
  ProductKind,
  {
    width: number
    depth: number
    thickness: number
    /** Plan-view corner radius. */
    corner: number
    /** Black border between the frame and the lit screen. */
    bezel: number
  }
> = {
  phone: {
    width: 0.34,
    depth: 0.7,
    thickness: 0.03,
    corner: 0.058,
    bezel: 0.013,
  },
  tablet: {
    width: 0.57,
    depth: 0.76,
    thickness: 0.026,
    corner: 0.05,
    bezel: 0.02,
  },
  desktop: {
    width: 0.8,
    depth: 0.52,
    thickness: 0.02,
    corner: 0.022,
    bezel: 0.012,
  },
  display: {
    width: 0.9,
    depth: 0.58,
    thickness: 0.022,
    corner: 0.02,
    bezel: 0.014,
  },
  watch: {
    width: 0.27,
    depth: 0.31,
    thickness: 0.05,
    corner: 0.078,
    bezel: 0.024,
  },
}

/** Metal frame left visible around the cover glass. */
const FRAME = 0.008

export type DeviceSpec = {
  /** Unit quad (lying in XZ, texture top at -z) → the cover glass. */
  screen: Matrix4
  /** Screen aspect, outer corner, bezel and inner corner, relative to the glass depth. */
  shape: [number, number, number, number]
  body: BufferGeometry
}

function translate(x: number, y: number, z: number) {
  return new Matrix4().makeTranslation(x, y, z)
}

function createBody(kind: ProductKind): BufferGeometry {
  const { width, depth, thickness, corner } = DEVICE_SIZES[kind]
  const frame = slab(width, depth, thickness, corner, 0.006)
  if (kind === 'phone') {
    return merge([
      frame,
      place(
        box(0.008, 0.012, 0.09, 0.003, 1),
        translate(width / 2 + 0.001, thickness / 2, -0.12),
      ),
    ])
  }
  if (kind === 'watch') {
    return merge([
      frame,
      place(
        cylinder(0.02, 0.022, 16).rotateZ(Math.PI / 2),
        translate(width / 2 + 0.006, thickness / 2, -0.04),
      ),
    ])
  }
  return frame
}

export function createDeviceSpecs(): Record<ProductKind, DeviceSpec> {
  const specs = {} as Record<ProductKind, DeviceSpec>
  for (const kind of PRODUCT_KINDS) {
    const { width, depth, thickness, corner, bezel } = DEVICE_SIZES[kind]
    const glassWidth = width - FRAME * 2
    const glassDepth = depth - FRAME * 2
    const outer = Math.max(corner - FRAME, 0.004) / glassDepth
    specs[kind] = {
      screen: new Matrix4()
        .makeTranslation(0, thickness + 0.0012, 0)
        .scale(new Vector3(glassWidth, 1, glassDepth)),
      shape: [
        glassWidth / glassDepth,
        outer,
        bezel / glassDepth,
        Math.max(outer - (bezel / glassDepth) * 0.8, 0.004),
      ],
      body: createBody(kind),
    }
  }
  return specs
}
