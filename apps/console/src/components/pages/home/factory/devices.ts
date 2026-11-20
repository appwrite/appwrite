import {
  BoxGeometry,
  BufferAttribute,
  Matrix4,
  Vector3,
  type BufferGeometry,
} from 'three'
import { box, cylinder, merge, place, slab } from './geometry'
import { PRODUCT_KINDS, type ProductKind } from './layout'

/**
 * The products the line builds: apps on the hardware they ship to, made
 * the way Apple makes it: flat machined frames, thin even bezels, the
 * phone's island and the laptop's notch, the watch's crown, the desktop's
 * chin. Most travel flat on their carriers, glass up; the laptop rides open
 * as far as its hinge goes, and the Mac mini shows its progress on its
 * status light. Local space: origin on the carrier nest under the product's
 * center, +z faces the camera.
 */
export const DEVICE_SIZES: Record<
  ProductKind,
  {
    width: number
    depth: number
    /** Height of the surface the tools work on. */
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
    thickness: 0.022,
    corner: 0.05,
    bezel: 0.022,
  },
  laptop: {
    width: 0.58,
    depth: 0.4,
    thickness: 0.016,
    corner: 0.034,
    bezel: 0.014,
  },
  desktop: {
    width: 0.81,
    depth: 0.6,
    thickness: 0.02,
    corner: 0.026,
    bezel: 0.016,
  },
  display: {
    width: 0.9,
    depth: 0.58,
    thickness: 0.022,
    corner: 0.02,
    bezel: 0.014,
  },
  mini: {
    width: 0.46,
    depth: 0.46,
    thickness: 0.16,
    corner: 0.08,
    bezel: 0,
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

/**
 * The laptop's lid, hinged on the deck's back edge and laid back as far as
 * the hinge goes (`recline`, from the deck), so it stays under the arms
 * while the stations work.
 */
const LID = { length: 0.37, thickness: 0.01, recline: 0.63 } as const

/** The desktop's chin, under its screen. */
const CHIN = 0.095

/** The Mac mini: its shell on a dark foot, and the status light on its front. */
const MINI = { foot: 0.012, light: 0.018, ports: 0.06 } as const

/** Ports, keys and gaps: anodized black, whatever the finish. */
const DARK = 0.06

export type DeviceSpec = {
  /** Unit quad (lying in XZ, texture top at -z) → the cover glass. */
  screen: Matrix4
  /** Screen aspect, outer corner, bezel and inner corner, relative to the glass depth. */
  shape: [number, number, number, number]
  /** A black cutout over the screen: center and half size, in glass depths (zero for none). */
  cutout: [number, number, number, number]
  /** 1 for a light bezel, 0 for a black one. */
  bezelTone: number
  /** The glass lies flat under the tools, so a sweeping tool can wipe it. */
  flat: boolean
  body: BufferGeometry
}

function translate(x: number, y: number, z: number) {
  return new Matrix4().makeTranslation(x, y, z)
}

/** Gives a part a fixed shade, multiplied with the device's finish. */
function tint(geometry: BufferGeometry, value: number) {
  const count = geometry.getAttribute('position').count
  geometry.setAttribute(
    'color',
    new BufferAttribute(new Float32Array(count * 3).fill(value), 3),
  )
  return geometry
}

/** Where the laptop's lid turns: the deck's back edge, at the deck's top. */
function lidHinge() {
  const { thickness, depth } = DEVICE_SIZES.laptop
  return translate(0, thickness, -depth / 2).multiply(
    new Matrix4().makeRotationX(LID.recline),
  )
}

/** A side button: a machined bar along the frame's edge. */
function button(length: number, x: number, y: number, z: number) {
  return place(box(0.008, 0.012, length, 0.003, 1), translate(x, y, z))
}

function createBody(kind: ProductKind): BufferGeometry {
  const { width, depth, thickness, corner } = DEVICE_SIZES[kind]
  const edge = width / 2 + 0.001
  if (kind === 'phone') {
    // Side button on the right; the action button and volume on the left.
    return merge(
      [
        slab(width, depth, thickness, corner, 0.006),
        button(0.11, edge, thickness / 2, -0.1),
        button(0.032, -edge, thickness / 2, -0.205),
        button(0.06, -edge, thickness / 2, -0.135),
        button(0.06, -edge, thickness / 2, -0.06),
      ],
      true,
    )
  }
  if (kind === 'tablet') {
    // The top button on the top edge, volume on the right near the top.
    return merge(
      [
        slab(width, depth, thickness, corner, 0.005),
        place(
          box(0.07, 0.008, 0.008, 0.003, 1),
          translate(width / 2 - 0.1, thickness / 2, -depth / 2 - 0.001),
        ),
        button(0.05, edge, thickness / 2, -depth / 2 + 0.1),
        button(0.05, edge, thickness / 2, -depth / 2 + 0.16),
      ],
      true,
    )
  }
  if (kind === 'watch') {
    // The digital crown, then the side button below it.
    return merge(
      [
        slab(width, depth, thickness, corner, 0.014),
        place(
          cylinder(0.022, 0.026, 20).rotateZ(Math.PI / 2),
          translate(width / 2 + 0.01, thickness / 2, -0.045),
        ),
        place(
          box(0.007, 0.016, 0.075, 0.003, 1),
          translate(width / 2 + 0.002, thickness / 2, 0.055),
        ),
      ],
      true,
    )
  }
  if (kind === 'laptop') return createLaptop()
  if (kind === 'mini') return createMini()
  return merge([slab(width, depth, thickness, corner, 0.005)], true)
}

/** A laptop open on its carrier: the deck with its keys and trackpad, the hinge and the lid. */
function createLaptop(): BufferGeometry {
  const { width, depth, thickness, corner } = DEVICE_SIZES.laptop
  const parts: BufferGeometry[] = [slab(width, depth, thickness, corner, 0.004)]

  // Keys in the back of the deck, the space bar across the bottom row.
  const columns = 12
  const rows = 5
  const pitch = 0.039
  const row = 0.035
  const left = -(columns * pitch) / 2
  const back = -depth / 2 + 0.055
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < columns; c++) {
      const space = r === rows - 1 && c >= 4 && c <= 7
      if (space && c > 4) continue
      const span = space ? 4 : 1
      parts.push(
        place(
          tint(new BoxGeometry(pitch * span - 0.005, 0.002, row - 0.005), DARK),
          translate(
            left + pitch * (c + span / 2),
            thickness + 0.0004,
            back + row * (r + 0.5),
          ),
        ),
      )
    }
  }
  // The trackpad, a shade apart from the deck.
  parts.push(
    place(
      tint(new BoxGeometry(0.23, 0.0012, 0.125), 0.88),
      translate(0, thickness, back + row * rows + 0.085),
    ),
  )

  // The hinge, then the lid, open on it.
  parts.push(
    place(
      tint(cylinder(0.008, width - 0.07, 16).rotateZ(Math.PI / 2), DARK * 2),
      translate(0, thickness - 0.002, -depth / 2 + 0.003),
    ),
  )
  parts.push(
    place(
      slab(width, LID.length, LID.thickness, corner, 0.003),
      lidHinge().multiply(translate(0, 0, -LID.length / 2)),
    ),
  )
  return merge(parts, true)
}

/** A Mac mini: a machined shell on a dark foot, with its ports on the front. */
function createMini(): BufferGeometry {
  const { width, depth, thickness, corner } = DEVICE_SIZES.mini
  const front = depth / 2
  const portY = MINI.foot + MINI.ports
  return merge(
    [
      tint(
        slab(
          width - 0.07,
          depth - 0.07,
          MINI.foot + 0.002,
          corner * 0.8,
          0.002,
        ),
        DARK,
      ),
      place(
        slab(width, depth, thickness - MINI.foot, corner, 0.012),
        translate(0, MINI.foot, 0),
      ),
      // Two USB-C ports and the headphone jack.
      ...[-0.15, -0.115].map((x) =>
        place(
          tint(box(0.026, 0.009, 0.006, 0.0045, 1), DARK),
          translate(x, portY, front),
        ),
      ),
      place(
        tint(cylinder(0.0055, 0.006, 12).rotateX(Math.PI / 2), DARK),
        translate(-0.08, portY, front),
      ),
    ],
    true,
  )
}

/** The glass's size and where the unit quad goes to cover it. */
function createGlass(kind: ProductKind) {
  const { width, depth, thickness } = DEVICE_SIZES[kind]
  if (kind === 'laptop') {
    const glass = { width: width - FRAME * 2, depth: LID.length - FRAME * 2 }
    return {
      ...glass,
      matrix: lidHinge()
        .multiply(translate(0, LID.thickness + 0.0012, -LID.length / 2))
        .scale(new Vector3(glass.width, 1, glass.depth)),
    }
  }
  if (kind === 'mini') {
    // The status light, on the front panel.
    return {
      width: MINI.light,
      depth: MINI.light,
      matrix: translate(
        width / 2 - 0.07,
        MINI.foot + MINI.ports,
        depth / 2 + 0.0012,
      )
        .multiply(new Matrix4().makeRotationX(Math.PI / 2))
        .scale(new Vector3(MINI.light, 1, MINI.light)),
    }
  }
  // The desktop's glass stops at its chin.
  const chin = kind === 'desktop' ? CHIN : 0
  const glass = { width: width - FRAME * 2, depth: depth - FRAME * 2 - chin }
  return {
    ...glass,
    matrix: translate(0, thickness + 0.0012, -chin / 2).scale(
      new Vector3(glass.width, 1, glass.depth),
    ),
  }
}

/** The phone's island, the laptop's notch: center and half size in the glass's depths. */
function createCutout(
  kind: ProductKind,
  glassDepth: number,
): [number, number, number, number] {
  const { bezel } = DEVICE_SIZES[kind]
  if (kind === 'phone') {
    const top = bezel + 0.012 + 0.014
    return [0, 0.5 - top / glassDepth, 0.049 / glassDepth, 0.014 / glassDepth]
  }
  if (kind === 'laptop') {
    // Hangs from the top bezel, so only its rounded bottom shows.
    return [0, 0.5 - bezel / glassDepth, 0.032 / glassDepth, 0.012 / glassDepth]
  }
  return [0, 0, 0, 0]
}

export function createDeviceSpecs(): Record<ProductKind, DeviceSpec> {
  const specs = {} as Record<ProductKind, DeviceSpec>
  for (const kind of PRODUCT_KINDS) {
    const { corner, bezel } = DEVICE_SIZES[kind]
    const glass = createGlass(kind)
    const outer =
      kind === 'mini' ? 0.5 : Math.max(corner - FRAME, 0.004) / glass.depth
    specs[kind] = {
      screen: glass.matrix,
      shape: [
        glass.width / glass.depth,
        outer,
        bezel / glass.depth,
        Math.max(outer - (bezel / glass.depth) * 0.8, 0.004),
      ],
      cutout: createCutout(kind, glass.depth),
      bezelTone: kind === 'desktop' ? 1 : 0,
      flat: kind !== 'laptop' && kind !== 'mini',
      body: createBody(kind),
    }
  }
  return specs
}
