import {
  Color,
  Group,
  Mesh,
  PlaneGeometry,
  TorusGeometry,
  type BufferGeometry,
  type Material,
} from 'three'
import { DEVICE_SIZES } from './devices'
import { FRAMEWORKS, getProductFramework } from './frameworks'
import { box, cylinder, mergeFinishes, prism, slab } from './geometry'
import {
  FINISHES,
  createKitBeam,
  createKitLamp,
  setLamp,
  type FactoryKit,
  type Finish,
} from './kit'
import {
  ARM,
  ARM_REST,
  BED_TOP,
  HEAD_GAP,
  INSTALL_HIT,
  INSTALL_SLOT,
  PRODUCT_BASE,
  STATION_COUNT,
  STATION_HIT,
  SWEEP,
  TOOL_TIP,
  TOWER_FRONT,
  TRACK_Z,
  bump,
  clamp01,
  easeInOutCubic,
  getInstallStart,
  getProductKind,
  getStationStart,
  lerp,
  range,
  slotX,
  stationX,
  travel,
} from './layout'
import { ARRIVALS, arrive } from './assembly'
import { installPower, stationPower, type FrameState } from './frame'

/**
 * The moving half of every station: an arm riding the column's vertical
 * rails, and at its end the tool for one Appwrite service. Each tool is a
 * real process (pressing, writing, placing, machining, probing, dispensing,
 * inspecting, curing); every move is a servo profile and the only light is
 * what such a tool would emit.
 */

/** World y of a head's origin (just under the arm) when the arm is parked. */
const REST = ARM_REST - HEAD_GAP
const ARM_LENGTH = ARM.front - ARM.back

export type Track = <T extends BufferGeometry>(geometry: T) => T
type PartKey = Extract<
  Finish,
  'aluminum' | 'steel' | 'graphite' | 'matte' | 'accent'
>

/** Collects parts by finish and merges them all into one mesh (one draw). */
export class Assembly {
  private readonly parts: Partial<Record<PartKey, BufferGeometry[]>> = {}

  add(key: PartKey, geometry: BufferGeometry, x = 0, y = 0, z = 0) {
    ;(this.parts[key] ??= []).push(geometry.translate(x, y, z))
    return this
  }

  build(kit: FactoryKit, track: Track, group = new Group()) {
    group.add(new Mesh(track(mergeFinishes(this.parts, FINISHES)), kit.finish))
    return group
  }
}

type Shop = {
  kit: FactoryKit
  track: Track
  /** Carriage plate, arm and slide: the same on every station. */
  axis: Group
}

export type StationContext = {
  /** Seconds since this station started working this cycle (negative before). */
  tau: number
  cycle: number
  x: number
  /** Top of the product stopped at the station, world y. */
  top: number
  /** Cover glass of that product, in world units. */
  width: number
  depth: number
  /** 0 → 1 as the station powers up. */
  power: number
  /** Framework of that product (index into `FRAMEWORKS`). */
  framework: number
}

export interface StationRig {
  readonly group: Group
  /** Moves the rig for this frame and returns its activity, 0..1. */
  update(frame: FrameState, context: StationContext): number
}

type Builder = (shop: Shop) => StationRig

function part(
  geometry: BufferGeometry,
  material: Material,
  x = 0,
  y = 0,
  z = 0,
) {
  const mesh = new Mesh(geometry, material)
  mesh.position.set(x, y, z)
  return mesh
}

/** Down over [a, b], held, back up over [c, d]. */
function stroke(tau: number, a: number, b: number, c: number, d: number) {
  if (tau <= a || tau >= d) return 0
  return travel(tau, a, b) * (1 - travel(tau, c, d))
}

function createAxisParts(kit: FactoryKit, track: Track): Group {
  // Just the arm: a compact carriage riding in the column's slot, nothing above or beside it.
  return new Assembly()
    .add(
      'aluminum',
      box(0.24, 0.22, 0.05, 0.022, 3),
      0,
      ARM.height / 2,
      TOWER_FRONT + 0.03,
    )
    .add(
      'aluminum',
      box(ARM.width, ARM.height, ARM_LENGTH, 0.032, 3),
      0,
      ARM.height / 2,
      (ARM.back + ARM.front) / 2,
    )
    .add(
      'graphite',
      box(ARM.width + 0.008, ARM.height + 0.008, 0.024, 0.008, 1),
      0,
      ARM.height / 2,
      ARM.front + 0.006,
    )
    .add(
      'steel',
      box(0.032, HEAD_GAP, ARM_LENGTH - 0.05, 0.003, 1),
      0,
      -HEAD_GAP / 2,
      (ARM.back + ARM.front) / 2 + 0.015,
    )
    .build(kit, track)
}

/** The arm on its carriage, and the head that hangs from it over the track. */
function createAxis(shop: Shop) {
  const group = new Group()
  const carriage = new Group()
  const head = new Group()
  for (const child of shop.axis.children)
    carriage.add((child as Mesh).clone(false))
  head.position.set(0, -HEAD_GAP, TRACK_Z)
  carriage.position.y = ARM_REST
  carriage.add(head)
  group.add(carriage)
  return {
    group,
    head,
    /** Places the head's origin at world height `y`, sliding `z` along the arm. */
    place(y: number, z = 0) {
      carriage.position.y = y + HEAD_GAP
      head.position.z = TRACK_Z + z
    },
  }
}

/** Status LED on the end of the arm: white when idle, pink while working. */
function armLed(
  frame: FrameState,
  context: StationContext,
  headY: number,
  level: number,
) {
  const { palette, glows } = frame
  glows.add(
    context.x,
    headY + HEAD_GAP + ARM.height / 2,
    ARM.front + 0.024,
    0.05,
    level > 0.04 ? palette.tones.pink : palette.light,
    (0.4 + 0.5 * level) * context.power,
  )
}

/** Auth: a servo press seats identity into the product. */
const auth: Builder = (shop) => {
  const { kit, track } = shop
  const axis = createAxis(shop)
  new Assembly()
    .add('graphite', box(0.21, 0.09, 0.21, 0.012, 2), 0, -0.045, 0)
    .add('aluminum', cylinder(0.074, 0.1, 36), 0, -0.14, 0)
    .add('accent', cylinder(0.042, 0.012, 28), 0, -0.197, 0)
    .add('steel', cylinder(0.028, 0.066, 24), 0, -0.233, 0)
    .add('aluminum', slab(0.22, 0.2, 0.024, 0.03, 0.004), 0, -TOOL_TIP, 0)
    .build(kit, track, axis.head)

  return {
    group: axis.group,
    update(frame, context) {
      const { tau, top, x } = context
      const press = stroke(tau, 0, STATION_HIT.auth, 0.48, 0.95)
      const headY = lerp(REST, top + TOOL_TIP + 0.001, press)
      axis.place(headY)
      const level = bump(range(tau, 0, 0.95))
      const since = tau - STATION_HIT.auth
      if (since >= 0 && since < 0.5) {
        frame.glows.add(
          x,
          top + 0.02,
          TRACK_Z,
          0.7,
          frame.palette.light,
          0.22 * Math.exp(-since * 9) * context.power,
        )
      }
      frame.glows.add(
        x,
        headY - 0.045,
        TRACK_Z + 0.108,
        0.035,
        frame.palette.light,
        (0.3 + 0.6 * press) * context.power,
      )
      armLed(frame, context, headY, level)
      return level
    },
  }
}

/** Databases: a write head sweeps a line of light across the product, laying down rows. */
const databases: Builder = (shop) => {
  const { kit, track } = shop
  const axis = createAxis(shop)
  const barBottom = 0.222
  /** The write bar rides a boom ahead of its carriage, so it can pass the end of the arm. */
  const boom = 0.3
  new Assembly()
    .add('graphite', box(0.16, 0.07, 0.12, 0.01, 1), 0, -0.035, 0)
    .add(
      'aluminum',
      box(0.05, 0.03, boom + 0.06, 0.006, 1),
      0,
      -0.085,
      boom / 2,
    )
    .add('aluminum', box(0.05, 0.085, 0.05, 0.006, 1), 0, -0.1425, boom)
    .add('graphite', box(0.66, 0.05, 0.07, 0.008, 1), 0, -0.195, boom)
    .add('accent', box(0.012, 0.038, 0.058, 0.003, 1), 0.336, -0.195, boom)
    .add('accent', box(0.012, 0.038, 0.058, 0.003, 1), -0.336, -0.195, boom)
    .build(kit, track, axis.head)

  const lamp = createKitLamp(kit)
  axis.head.add(
    part(track(box(0.6, 0.004, 0.012, 0.001, 1)), lamp, 0, -barBottom, boom),
  )
  const line = part(
    track(new PlaneGeometry(1, 0.014).rotateX(-Math.PI / 2)),
    lamp,
  )
  line.position.z = boom
  line.renderOrder = 6
  const curtainMaterial = createKitBeam(kit)
  const curtain = part(track(new PlaneGeometry(1, 1)), curtainMaterial)
  curtain.position.z = boom
  curtain.renderOrder = 8
  axis.head.add(line, curtain)

  const hit = STATION_HIT.databases

  return {
    group: axis.group,
    update(frame, context) {
      const { tau, top, width, depth } = context
      const { palette } = frame
      const down = stroke(tau, 0, 0.18, 0.68, 0.96)
      const headY = lerp(REST, top + 0.085 + barBottom, down)

      // Park over the far edge, then follow the reveal exactly (see the screen shader).
      const far = -depth / 2
      const near = depth / 2 + 0.02
      let z = 0
      if (tau >= 0 && tau < hit) z = lerp(0, far, travel(tau, 0, hit - 0.01))
      else if (tau >= hit && tau < 0.68) {
        const reveal = easeInOutCubic(clamp01((tau - hit) / SWEEP))
        z = Math.min(far + reveal * 1.12 * depth, near)
      } else if (tau >= 0.68 && tau < 0.96)
        z = lerp(near, 0, travel(tau, 0.68, 0.96))
      axis.place(headY, z - boom)

      const writing =
        tau >= hit - 0.04 && tau < hit + SWEEP
          ? Math.min(
              range(tau, hit - 0.04, hit),
              1 - range(tau, hit + SWEEP - 0.06, hit + SWEEP),
            )
          : 0
      setLamp(lamp, palette, (0.2 + 0.8 * writing) * context.power)
      const gap = headY - barBottom - (top + 0.003)
      line.visible = writing > 0.01
      line.position.y = -barBottom - gap
      line.scale.set(width, 1, 1)
      curtain.visible = writing > 0.01
      curtain.position.y = -barBottom - gap / 2
      curtain.scale.set(width, Math.max(gap, 0.001), 1)
      curtainMaterial.uniforms.uColor!.value.copy(palette.light)
      curtainMaterial.uniforms.uAlpha!.value =
        writing * 0.32 * palette.beamBoost * context.power

      const level = Math.max(writing, bump(range(tau, 0, 0.96)) * 0.6)
      armLed(frame, context, headY, level)
      return level
    },
  }
}

/** Storage: a vacuum head picks a file tile from the feeder and seats it in the product. */
const storage: Builder = (shop) => {
  const { kit, track } = shop
  const axis = createAxis(shop)
  const feedZ = -0.44
  const tile = { width: 0.2, depth: 0.16, height: 0.012 }
  const stackBase = BED_TOP + 0.05
  const stackTop = stackBase + 4 * 0.0135

  // Feeder on the bed, between the track and the column.
  const feeder = new Assembly()
    .add('graphite', box(0.28, 0.05, 0.24, 0.008, 1), 0, BED_TOP + 0.025, feedZ)
    .add(
      'graphite',
      box(0.014, 0.08, 0.24, 0.004, 1),
      0.133,
      BED_TOP + 0.09,
      feedZ,
    )
    .add(
      'graphite',
      box(0.014, 0.08, 0.24, 0.004, 1),
      -0.133,
      BED_TOP + 0.09,
      feedZ,
    )
  for (let level = 0; level < 4; level++) {
    feeder.add(
      'aluminum',
      slab(tile.width, tile.depth, tile.height, 0.016, 0.003),
      0,
      stackBase + level * 0.0135,
      feedZ,
    )
  }
  feeder.build(kit, track, axis.group)

  new Assembly()
    .add('graphite', box(0.1, 0.1, 0.1, 0.01, 1), 0, -0.05, 0)
    .add('accent', cylinder(0.02, 0.014, 20), 0, -0.107, 0)
    .add('steel', cylinder(0.012, 0.15, 14), 0, -0.19, 0)
    .add('matte', cylinder(0.03, 0.025, 20, 0.02), 0, -0.2775, 0)
    .build(kit, track, axis.head)
  const carried = part(
    track(slab(tile.width, tile.depth, tile.height, 0.016, 0.003)),
    kit.aluminum,
  )
  axis.head.add(carried)

  const hit = STATION_HIT.storage
  const feed = feedZ - TRACK_Z
  // As high as it goes, to carry the tile over an open laptop's lid.
  const cruise = REST

  return {
    group: axis.group,
    update(frame, context) {
      const { tau, top } = context
      const pickY = stackTop + TOOL_TIP
      const placeY = top + tile.height + TOOL_TIP
      let y = REST
      let z = feed
      if (tau >= 0 && tau < 1.08) {
        if (tau < 0.12) y = lerp(REST, pickY, travel(tau, 0, 0.12))
        else if (tau < 0.44) y = lerp(pickY, cruise, travel(tau, 0.12, 0.26))
        else if (tau < hit) y = lerp(cruise, placeY, travel(tau, 0.44, hit))
        else if (tau < 0.86) y = lerp(placeY, cruise, travel(tau, hit, 0.8))
        else y = lerp(cruise, REST, travel(tau, 0.86, 1.08))
        if (tau < 0.8) z = lerp(feed, 0, travel(tau, 0.18, 0.44))
        else z = lerp(0, feed, travel(tau, 0.8, 1.06))
      }
      axis.place(y, z)

      // The tile leaves the stack with the head, stays on the product and sinks in.
      const holding = tau >= 0.12 && tau < hit
      const seating = tau >= hit && tau < hit + 0.14
      carried.visible = holding || seating
      if (holding) {
        carried.position.y = -TOOL_TIP - tile.height
        carried.scale.setScalar(1)
      } else if (seating) {
        const sink = travel(tau, hit, hit + 0.14)
        carried.position.y = top - y - sink * tile.height
        carried.scale.set(1, Math.max(1 - sink, 0.001), 1)
      }

      const level = bump(range(tau, 0, 1.08))
      armLed(frame, context, y, level)
      return level
    },
  }
}

/** Functions: a spindle spins up and runs logic into the product. */
const functions: Builder = (shop) => {
  const { kit, track } = shop
  const axis = createAxis(shop)
  const body = new Assembly().add(
    'graphite',
    cylinder(0.078, 0.15, 36),
    0,
    -0.075,
    0,
  )
  for (const y of [-0.035, -0.065, -0.095])
    body.add('aluminum', cylinder(0.085, 0.008, 36), 0, y, 0)
  body.build(kit, track, axis.head)

  const lamp = createKitLamp(kit)
  axis.head.add(
    part(
      track(new TorusGeometry(0.064, 0.007, 8, 56).rotateX(Math.PI / 2)),
      lamp,
      0,
      -0.156,
      0,
    ),
  )
  // Hexagonal chuck, so its facets catch the light as it turns.
  const chuck = new Assembly()
    .add('steel', prism(0.05, 0.05, 6), 0, -0.185, 0)
    .add('accent', prism(0.034, 0.02, 6), 0, -0.22, 0)
    .add('steel', cylinder(0.004, 0.06, 12, 0.011), 0, -0.26, 0)
    .build(kit, track)
  axis.head.add(chuck)

  const speed = 52
  /** Turns made by `tau`: ramp up over 0.3 s, hold, ramp down from 0.7 s to 1 s. */
  const turned = (tau: number) => {
    if (tau <= 0) return 0
    if (tau <= 0.3) return (tau * tau) / 0.6
    if (tau <= 0.7) return 0.15 + (tau - 0.3)
    if (tau <= 1) return 0.55 + (tau - 0.7) - (tau - 0.7) ** 2 / 0.6
    return 0.7
  }

  return {
    group: axis.group,
    update(frame, context) {
      const { tau, top, x, cycle } = context
      const { palette, glows } = frame
      const plunge = stroke(tau, 0, 0.26, 0.7, 0.98)
      const headY = lerp(REST, top + TOOL_TIP + 0.001, plunge)
      axis.place(headY)
      chuck.rotation.y = -speed * (cycle * 0.7 + turned(tau))
      const running =
        tau > 0 && tau < 1 ? range(tau, 0, 0.3) * (1 - range(tau, 0.7, 1)) : 0
      setLamp(lamp, palette, (0.15 + 0.85 * running) * context.power)
      if (plunge > 0.97) {
        glows.add(
          x,
          top + 0.008,
          TRACK_Z,
          0.16,
          palette.light,
          0.75 * running * context.power,
        )
      }
      const level = Math.max(running, bump(range(tau, 0, 0.98)) * 0.6)
      armLed(frame, context, headY, level)
      return level
    },
  }
}

/** Realtime: a probe card lands on the product and keeps a live link open. */
const realtime: Builder = (shop) => {
  const { kit, track } = shop
  const axis = createAxis(shop)
  const columns = 8
  const pitch = 0.044
  const first = -((columns - 1) / 2) * pitch
  const card = new Assembly()
    .add('aluminum', box(0.03, 0.06, 0.03, 0.004, 1), 0.12, -0.03, 0)
    .add('aluminum', box(0.03, 0.06, 0.03, 0.004, 1), -0.12, -0.03, 0)
    .add('graphite', box(0.36, 0.07, 0.24, 0.01, 1), 0, -0.095, 0)
    .add('aluminum', box(0.38, 0.012, 0.26, 0.004, 1), 0, -0.136, 0)
    .add('accent', box(0.05, 0.012, 0.004, 0.002, 1), -0.14, -0.072, 0.121)
  for (const z of [-0.09, 0.09]) {
    for (let column = 0; column < columns; column++) {
      card.add(
        'steel',
        cylinder(0.006, 0.148, 8),
        first + column * pitch,
        -0.216,
        z,
      )
    }
  }
  card.build(kit, track, axis.head)

  const hit = STATION_HIT.realtime

  return {
    group: axis.group,
    update(frame, context) {
      const { tau, top, x } = context
      const { palette, glows, time } = frame
      const landed = stroke(tau, 0, 0.26, 0.82, 1.06)
      const headY = lerp(REST, top + TOOL_TIP + 0.001, landed)
      axis.place(headY)
      const live = tau >= hit && tau < 0.82
      for (let column = 0; column < columns; column++) {
        const chase = Math.sin(time * 30 - column * 1.15) > 0.25
        const idle = 0.2 + 0.1 * Math.sin(time * 1.6 + column * 0.7)
        glows.add(
          x + first + column * pitch,
          headY - 0.104,
          TRACK_Z + 0.123,
          0.03,
          palette.light,
          (live ? (chase ? 0.95 : 0.18) : idle) * context.power,
        )
      }
      const level = live ? 1 : bump(range(tau, 0, 1.06)) * 0.6
      armLed(frame, context, headY, level)
      return level
    },
  }
}

/** Messaging: a dispensing valve lays down three notifications. */
const messaging: Builder = (shop) => {
  const { kit, track } = shop
  const axis = createAxis(shop)
  new Assembly()
    .add('graphite', box(0.09, 0.12, 0.08, 0.008, 1), 0, -0.06, 0)
    .add('aluminum', cylinder(0.034, 0.17, 28), 0.078, -0.1, 0)
    .add('matte', cylinder(0.037, 0.022, 28), 0.078, -0.006, 0)
    .add('accent', cylinder(0.02, 0.02, 20), 0, -0.13, 0)
    .add('steel', cylinder(0.006, 0.05, 14, 0.012), 0, -0.165, 0)
    .add('steel', cylinder(0.0035, 0.1, 8), 0, -0.24, 0)
    .build(kit, track, axis.head)

  const shots = [0.26, 0.42, 0.58]
  const offsets = [-0.15, -0.045, 0.06]

  return {
    group: axis.group,
    update(frame, context) {
      const { tau, top, x } = context
      const { palette, glows } = frame
      const hover = top + TOOL_TIP + 0.035
      const down = stroke(tau, 0, 0.2, 0.68, 0.96)
      let y = lerp(REST, hover, down)
      let z = 0
      if (tau >= 0 && tau < 0.96) {
        z = lerp(0, offsets[0]!, travel(tau, 0, 0.2))
        z = lerp(
          z,
          offsets[1]!,
          travel(tau, shots[0]! + 0.05, shots[1]! - 0.05),
        )
        z = lerp(
          z,
          offsets[2]!,
          travel(tau, shots[1]! + 0.05, shots[2]! - 0.05),
        )
        z = lerp(z, 0, travel(tau, 0.68, 0.96))
      }
      shots.forEach((shot, index) => {
        y -= 0.031 * bump(range(tau, shot - 0.05, shot + 0.05))
        const since = tau - shot
        if (since >= 0 && since < 0.55) {
          glows.add(
            x,
            top + 0.006,
            TRACK_Z + offsets[index]!,
            0.05,
            palette.light,
            0.9 * (1 - since / 0.55) ** 2 * context.power,
          )
        }
      })
      axis.place(y, z)
      const level = bump(range(tau, 0, 0.96))
      armLed(frame, context, y, level)
      return level
    },
  }
}

/** Firewall: a camera and ring light inspect the product and clear it. */
const firewall: Builder = (shop) => {
  const { kit, track } = shop
  const axis = createAxis(shop)
  const ringBottom = 0.228
  const vision = new Assembly()
    .add('graphite', box(0.12, 0.13, 0.12, 0.01, 1), 0, -0.065, 0)
    .add('matte', cylinder(0.042, 0.06, 28), 0, -0.16, 0)
    .add('steel', cylinder(0.046, 0.01, 28), 0, -0.192, 0)
    .add(
      'graphite',
      new TorusGeometry(0.115, 0.02, 10, 56)
        .rotateX(Math.PI / 2)
        .scale(1, 0.6, 1),
      0,
      -0.212,
      0,
    )
  for (let strut = 0; strut < 3; strut++) {
    const angle = (strut / 3) * Math.PI * 2 + Math.PI / 6
    vision.add(
      'aluminum',
      box(0.012, 0.012, 0.07, 0.003, 1).translate(0, 0, 0.082).rotateY(angle),
      0,
      -0.204,
      0,
    )
  }
  vision.build(kit, track, axis.head)
  const lamp = createKitLamp(kit)
  axis.head.add(
    part(
      track(
        new TorusGeometry(0.115, 0.011, 8, 56)
          .rotateX(Math.PI / 2)
          .scale(1, 0.5, 1),
      ),
      lamp,
      0,
      -0.222,
      0,
    ),
  )

  const flashes = [0.3, STATION_HIT.firewall]

  return {
    group: axis.group,
    update(frame, context) {
      const { tau, top, x } = context
      const { palette, glows } = frame
      const down = stroke(tau, 0, 0.22, 0.64, 0.95)
      const headY = lerp(REST, top + 0.11 + ringBottom, down)
      axis.place(headY)
      let strobe = 0
      for (const flash of flashes) {
        if (tau >= flash)
          strobe = Math.max(strobe, Math.exp(-(tau - flash) * 20))
      }
      setLamp(lamp, palette, (0.14 * down + strobe) * context.power)
      glows.add(
        x,
        top + 0.04,
        TRACK_Z,
        1.2,
        palette.light,
        0.42 * strobe * context.power,
      )
      glows.add(
        x + 0.045,
        headY - 0.03,
        TRACK_Z + 0.062,
        0.03,
        palette.light,
        (0.3 + 0.6 * down) * context.power,
      )
      const level = Math.max(strobe, bump(range(tau, 0, 0.95)) * 0.6)
      armLed(frame, context, headY, level)
      return level
    },
  }
}

/** Sites: a curing lamp sets the finished app and it goes live. */
const sites: Builder = (shop) => {
  const { kit, track } = shop
  const axis = createAxis(shop)
  const lampBottom = 0.148
  const head = new Assembly()
    .add('aluminum', cylinder(0.012, 0.1, 12), 0.17, -0.05, 0)
    .add('aluminum', cylinder(0.012, 0.1, 12), -0.17, -0.05, 0)
    .add('aluminum', slab(0.56, 0.42, 0.04, 0.03, 0.005), 0, -0.142, 0)
  for (let fin = 0; fin < 6; fin++)
    head.add(
      'graphite',
      box(0.5, 0.018, 0.01, 0.003, 1),
      0,
      -0.093,
      -0.15 + fin * 0.06,
    )
  head.build(kit, track, axis.head)

  const lamp = createKitLamp(kit)
  // The diffuser faces the product; its lit edge is what shows from above.
  axis.head.add(
    part(
      track(box(0.572, 0.007, 0.432, 0.003, 1)),
      lamp,
      0,
      -lampBottom + 0.002,
      0,
    ),
  )
  const curtainMaterial = createKitBeam(kit)
  const curtain = part(track(new PlaneGeometry(0.5, 1)), curtainMaterial)
  curtain.position.z = 0.2
  curtain.renderOrder = 8
  axis.head.add(curtain)

  const hit = STATION_HIT.sites

  return {
    group: axis.group,
    update(frame, context) {
      const { tau, top, x } = context
      const { palette, glows } = frame
      const down = stroke(tau, 0, 0.24, 0.7, 0.98)
      const headY = lerp(REST, top + 0.075 + lampBottom, down)
      axis.place(headY)
      const on =
        tau > 0.2 && tau < 0.76
          ? range(tau, 0.2, hit) * (1 - range(tau, 0.62, 0.76))
          : 0
      setLamp(lamp, palette, (0.12 + 0.88 * on) * context.power)
      const gap = headY - lampBottom - top
      curtain.visible = on > 0.01
      curtain.position.y = -lampBottom - gap / 2
      curtain.scale.set(1, Math.max(gap, 0.001), 1)
      curtainMaterial.uniforms.uColor!.value.copy(palette.light)
      curtainMaterial.uniforms.uAlpha!.value =
        on * 0.28 * palette.beamBoost * context.power
      glows.add(
        x,
        top + 0.04,
        TRACK_Z,
        1.25,
        palette.light,
        0.26 * on * context.power,
      )
      const level = Math.max(on, bump(range(tau, 0, 0.98)) * 0.6)
      armLed(frame, context, headY, level)
      return level
    },
  }
}

const FRAMEWORK_COLORS = FRAMEWORKS.map(
  (framework) => new Color(framework.color),
)

/** The installer: an injector loads the app's framework into the product. */
const install: Builder = (shop) => {
  const { kit, track } = shop
  const axis = createAxis(shop)
  new Assembly()
    .add('graphite', box(0.13, 0.1, 0.13, 0.01, 1), 0, -0.05, 0)
    .add('aluminum', cylinder(0.05, 0.1, 32), 0, -0.15, 0)
    .add('accent', cylinder(0.034, 0.012, 24), 0, -0.206, 0)
    .add('steel', cylinder(0.012, 0.05, 16, 0.028), 0, -0.237, 0)
    .add('steel', cylinder(0.0035, 0.03, 8), 0, -0.277, 0)
    .build(kit, track, axis.head)
  // A lit window in the cartridge, in the color of the framework it holds.
  const lamp = createKitLamp(kit)
  axis.head.add(part(track(cylinder(0.052, 0.03, 32)), lamp, 0, -0.15, 0))

  return {
    group: axis.group,
    update(frame, context) {
      const { tau, top, x, framework } = context
      const { palette, glows } = frame
      const tone = FRAMEWORK_COLORS[framework] ?? palette.light
      const down = stroke(tau, 0.04, 0.34, INSTALL_HIT + 0.1, 0.98)
      const headY = lerp(REST, top + TOOL_TIP + 0.002, down)
      axis.place(headY)
      const progress = range(tau, 0.06, INSTALL_HIT)
      const loading = tau >= 0 && tau < INSTALL_HIT + 0.1 ? 1 - progress : 0
      setLamp(lamp, palette, (0.25 + 0.75 * loading) * context.power, tone)
      // The charge runs down the needle as the framework goes in.
      if (tau > 0.34 && tau < INSTALL_HIT) {
        const run = range(tau, 0.34, INSTALL_HIT)
        glows.add(
          x,
          headY - 0.17 - run * 0.11,
          TRACK_Z,
          0.05,
          tone,
          0.9 * context.power,
        )
      }
      const since = tau - INSTALL_HIT
      if (since >= 0 && since < 0.6) {
        glows.add(
          x,
          top + 0.01,
          TRACK_Z,
          0.9,
          tone,
          0.32 * Math.exp(-since * 6) * context.power * palette.glowBoost,
        )
      }
      const level = bump(range(tau, 0, 0.98))
      armLed(frame, context, headY, level)
      return level
    },
  }
}

const BUILDERS: Builder[] = [
  auth,
  databases,
  storage,
  functions,
  realtime,
  messaging,
  firewall,
  sites,
]

/** Metal frame around the cover glass (see `devices.ts`). */
const GLASS_INSET = 0.016

export class StationLine {
  readonly group = new Group()
  private readonly rigs: StationRig[]
  private readonly installer: StationRig
  private readonly geometries: BufferGeometry[] = []

  constructor(kit: FactoryKit) {
    const track: Track = (geometry) => {
      this.geometries.push(geometry)
      return geometry
    }
    const shop: Shop = { kit, track, axis: createAxisParts(kit, track) }
    this.rigs = BUILDERS.map((build, index) => {
      const rig = build(shop)
      rig.group.position.x = stationX(index)
      this.group.add(rig.group)
      return rig
    })
    this.installer = install(shop)
    this.installer.group.position.x = slotX(INSTALL_SLOT)
    this.group.add(this.installer.group)
  }

  update(frame: FrameState) {
    const { line, assembly } = frame
    // Every rig glides in on the bed with its column (see `assembly.ts`).
    arrive(
      this.installer.group.position,
      slotX(INSTALL_SLOT),
      0,
      0,
      assembly,
      ARRIVALS.installer,
    )
    this.rigs.forEach((rig, index) => {
      arrive(
        rig.group.position,
        stationX(index),
        0,
        0,
        assembly,
        ARRIVALS.station(index),
      )
    })
    const installing = line.cycle - INSTALL_SLOT
    const kind = getProductKind(installing)
    const size = DEVICE_SIZES[kind]
    this.installer.update(frame, {
      tau: frame.time - getInstallStart(line.cycle),
      cycle: line.cycle,
      x: slotX(INSTALL_SLOT),
      top: PRODUCT_BASE + size.thickness,
      width: size.width - GLASS_INSET,
      depth: size.depth - GLASS_INSET,
      power: installPower(frame.introTime),
      framework: getProductFramework(installing, kind),
    })
    for (let index = 0; index < STATION_COUNT; index++) {
      // Product `cycle - index` stops at station `index` during this cycle.
      const size = DEVICE_SIZES[getProductKind(line.cycle - index)]
      const level = this.rigs[index]!.update(frame, {
        tau: frame.time - getStationStart(index, line.cycle),
        cycle: line.cycle,
        x: stationX(index),
        top: PRODUCT_BASE + size.thickness,
        width: size.width - GLASS_INSET,
        depth: size.depth - GLASS_INSET,
        power: stationPower(frame.introTime, index),
        framework: 0,
      })
      frame.levels[index] = Math.min(
        1,
        level + (frame.highlight === index ? 0.5 : 0),
      )
    }
  }

  dispose() {
    for (const geometry of this.geometries) geometry.dispose()
  }
}
