/**
 * Shared layout and timeline for the software factory hero scene.
 *
 * World space: products travel along +x, y is up, +z faces the camera.
 * The line is one machine: a long bed carrying a transfer track, a column
 * per Appwrite service behind the track, and an arm from each column that
 * reaches over the product stopped in front of it.
 *
 * The line is indexed: every product advances one station per cycle, then
 * dwells while every station works on the product in front of it. All
 * motion is a pure function of time, so nothing drifts and any frame can be
 * rendered on its own (reduced motion renders a single one).
 */

export const STATION_IDS = [
  'auth',
  'databases',
  'storage',
  'functions',
  'realtime',
  'messaging',
  'firewall',
  'sites',
] as const

export type FactoryStationId = (typeof STATION_IDS)[number]

export type FactoryTone = 'pink' | 'purple' | 'mint' | 'orange'

/** Same tones as `PRODUCT_THEMES`, used by the hover card of each station. */
export const STATION_TONES: Record<FactoryStationId, FactoryTone> = {
  auth: 'pink',
  databases: 'purple',
  storage: 'mint',
  functions: 'orange',
  realtime: 'purple',
  messaging: 'pink',
  firewall: 'orange',
  sites: 'mint',
}

export const STATION_COUNT = STATION_IDS.length
export const PITCH = 1.55
export const FIRST_X = -((STATION_COUNT - 1) / 2) * PITCH
/** Bay after the last station, where finished apps wait for the gate. */
export const DONE_SLOT = STATION_COUNT
/** Bay before Auth, where the app's framework is installed. */
export const INSTALL_SLOT = -1

/** The machine bed: one long plinth that everything else stands on. */
export const BED_TOP = 0.34
export const BED_FRONT = 0.8
export const BED_BACK = -1.16

/** The transfer track runs along the bed, nearer the camera than the columns. */
export const TRACK_Z = 0.16
export const RAIL_GAUGE = 0.6
export const RAIL_HEIGHT = 0.024
export const CARRIER = { width: 0.98, depth: 0.82, height: 0.034 } as const
export const CARRIER_TOP = BED_TOP + RAIL_HEIGHT + CARRIER.height
/** Rubber nest on the carrier that the product rests on. */
export const PAD_HEIGHT = 0.004
/** Where products sit: on the nest of a carrier riding the rails. */
export const PRODUCT_BASE = CARRIER_TOP + PAD_HEIGHT

/** One column per station, standing on the bed behind the track. */
export const TOWER = {
  width: 1.2,
  depth: 0.5,
  height: 1.1,
  z: -0.84,
  /** Shadow gap between the bed and the column. */
  lift: 0.03,
} as const
export const TOWER_FRONT = TOWER.z + TOWER.depth / 2
export const TOWER_TOP = BED_TOP + TOWER.lift + TOWER.height
/** Black glass band at the top of every column; the service name is printed on it. */
export const FASCIA = {
  width: 1.12,
  height: 0.25,
  y: TOWER_TOP - 0.045 - 0.125,
} as const

/**
 * On hover a station unfolds a display like a foldable phone. Folded shut it
 * is stored upright inside the column, reaching down into the bed, so each
 * half is as wide as a slot in the column's top: it rises out of the slot, a
 * mast lifts it, and its front half swings open about the hinge while the
 * mast slides along the slot to bring the open screen to the column's centre.
 */
export const EXTENSION = {
  /** Width of each half. */
  leaf: 1.1,
  /** Thickness of each half. */
  thickness: 0.032,
  height: 1.4,
  /** The hinge line. */
  z: TOWER.z + 0.04,
  /** Border around the screen. */
  border: 0.05,
  /** How far a mast lifts the display above the column once it has risen. */
  lift: 0.9,
} as const

/**
 * The arm rides the column's vertical rails and reaches over the track. It
 * ends just past the track's centerline, so the tool hangs from its tip.
 */
export const ARM = {
  width: 0.15,
  height: 0.13,
  back: TOWER_FRONT + 0.06,
  front: TRACK_Z + 0.11,
} as const
/** Underside of the arm when parked. */
export const ARM_REST = 0.97
/** Tool heads hang this far under the arm (their tip, in head space). */
export const TOOL_TIP = 0.29
/** Gap between the arm underside and the head that slides along it. */
export const HEAD_GAP = 0.012

/**
 * The server aisle behind the line: racks one after another, running away
 * from the line, doors facing the aisle on the camera's side. The first
 * rack's end panel faces the line and carries the Appwrite logo.
 */
export const RACKS = {
  count: 7,
  x: FIRST_X + 8 * PITCH - 0.4,
  /** End panel of the first rack, facing the line. */
  front: -4.9,
  /** Along z (one rack after another). */
  width: 0.7,
  /** Along x (door to back). */
  depth: 1.0,
  height: 2.4,
} as const
/** The door side of the aisle (racks face -x, toward the camera). */
export const RACKS_DOOR = RACKS.x - RACKS.depth / 2

export function rackZ(index: number): number {
  return RACKS.front - RACKS.width / 2 - index * (RACKS.width + 0.012)
}

/** The Appwrite rack: the first in the aisle, which powers every station. */
export const CORE = {
  x: RACKS.x,
  z: rackZ(0),
  height: RACKS.height,
} as const

/**
 * The giant Console screen: a display unit standing a step right of the first
 * rack, its face in the plane of the rack's end panel, as tall as the rack.
 */
const WALL_FRAME = 0.05
/** Floor between the first rack's side and the screen's frame. */
const WALL_GAP = 0.9
const WALL_HEIGHT = RACKS.height - 2 * WALL_FRAME
/** The Console capture's shape (1920 x 1234). */
const WALL_ASPECT = 1920 / 1234
export const WALL = {
  /** Outer frame, from the floor plinth up, like a rack. */
  frame: WALL_FRAME,
  width: WALL_HEIGHT * WALL_ASPECT,
  height: WALL_HEIGHT,
  /** Bottom edge of the screen. */
  y: 0.06 + WALL_FRAME,
  x:
    RACKS.x +
    RACKS.depth / 2 +
    WALL_GAP +
    (WALL_HEIGHT * WALL_ASPECT) / 2 +
    WALL_FRAME,
  /** The screen's face, flush with the rack row's end. */
  z: RACKS.front,
  depth: 0.34,
} as const

/** The display on the line, after Sites, counting shipped projects. */
export const COUNTER = {
  x: FIRST_X + DONE_SLOT * PITCH,
  z: TOWER_FRONT,
  width: 1.06,
  height: 0.62,
  /** Bottom edge of the screen. */
  y: BED_TOP + TOWER.lift + 0.33,
} as const

/** Products leave through this gate onto the Appwrite Network. */
export const GATE = {
  start: FIRST_X + (DONE_SLOT + 0.5) * PITCH + 0.08,
  length: 1.5,
  height: 0.98,
} as const
/** Slot at which a product crosses into the gate (and counts as shipped). */
export const SHIP_SLOT = (GATE.start - FIRST_X) / PITCH
/** Slot after which a product is fully inside the gate and no longer drawn. */
export const GONE_SLOT = SHIP_SLOT + 0.75

/** Seconds per indexed step: move, then dwell while stations work. */
export const CYCLE = 2.3
export const MOVE = 0.78
export const DWELL = CYCLE - MOVE
/** Seconds the screen takes to reveal what a station added. */
export const SWEEP = 0.42

/** Bays of blank products waiting before the installer. */
export const QUEUE_SLOTS = 8
/** Ring buffer size for every per-product instanced mesh. */
export const MAX_PRODUCTS = Math.ceil(GONE_SLOT) + QUEUE_SLOTS + 3

export function slotX(slot: number): number {
  return FIRST_X + slot * PITCH
}

export function stationX(index: number): number {
  return slotX(index)
}

const nearest = Math.abs(stationX(STATION_COUNT - 1) - CORE.x)
const farthest = Math.abs(stationX(0) - CORE.x)

/**
 * Power leaves the core as every move ends and reaches the closest stations
 * first, so the line works in a wave travelling away from the core.
 */
function slotDelay(slot: number): number {
  const distance = Math.abs(slotX(slot) - CORE.x)
  return 0.05 + 0.3 * ((distance - nearest) / (farthest - nearest))
}

export const STATION_DELAY: readonly number[] = STATION_IDS.map((_, index) =>
  slotDelay(index),
)
export const INSTALL_DELAY = slotDelay(INSTALL_SLOT)

/** World units per second of that wave along the bed. */
export const WAVE_SPEED = (farthest - nearest) / 0.3
/** Seconds into a cycle when the wave leaves the core. */
export const WAVE_START = MOVE + 0.05 - nearest / WAVE_SPEED

/** Seconds from a station's start to the moment it changes the product. */
export const STATION_HIT: Record<FactoryStationId, number> = {
  auth: 0.32,
  databases: 0.2,
  storage: 0.62,
  functions: 0.34,
  realtime: 0.3,
  messaging: 0.42,
  firewall: 0.46,
  sites: 0.36,
}

export function clamp01(value: number): number {
  return value < 0 ? 0 : value > 1 ? 1 : value
}

export function lerp(a: number, b: number, t: number): number {
  return a + (b - a) * t
}

/** 0 → 1 across [start, end]. */
export function range(value: number, start: number, end: number): number {
  return clamp01((value - start) / (end - start))
}

export function easeInOutCubic(t: number): number {
  return t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2
}

export function easeOutCubic(t: number): number {
  return 1 - (1 - t) ** 3
}

export function easeInCubic(t: number): number {
  return t * t * t
}

/** Up then down across [0, 1] (0 at both ends, 1 in the middle). */
export function bump(t: number): number {
  return t <= 0 || t >= 1 ? 0 : Math.sin(Math.PI * t)
}

/** Servo move: 0 → 1 over [start, end], eased at both ends. */
export function travel(value: number, start: number, end: number): number {
  return easeInOutCubic(range(value, start, end))
}

export type LineState = {
  cycle: number
  /** Seconds since the cycle started. */
  phase: number
  /** Eased 0 → 1 during the move, 1 while dwelling. */
  move: number
  /** Seconds since the carriers stopped, negative while moving. */
  sinceStop: number
}

export function getLineState(time: number): LineState {
  const cycle = Math.floor(time / CYCLE)
  const phase = time - cycle * CYCLE
  return {
    cycle,
    phase,
    move: phase < MOVE ? easeInOutCubic(phase / MOVE) : 1,
    sinceStop: phase - MOVE,
  }
}

/** Slot (station index, fractional while moving) of product `index`. */
export function getProductSlot(index: number, line: LineState): number {
  return line.cycle - index - 1 + line.move
}

/** Absolute time station `station` starts working in cycle `cycle`. */
export function getStationStart(station: number, cycle: number): number {
  return cycle * CYCLE + MOVE + STATION_DELAY[station]!
}

/** Absolute time station `station` changes product `index`. */
export function getStationHit(station: number, index: number): number {
  const id = STATION_IDS[station]!
  return getStationStart(station, index + station) + STATION_HIT[id]
}

/** Seconds from the installer's start to the moment the framework is in. */
export const INSTALL_HIT = 0.56

/** Absolute time the installer starts working in cycle `cycle`. */
export function getInstallStart(cycle: number): number {
  return cycle * CYCLE + MOVE + INSTALL_DELAY
}

/** Absolute time product `index` gets its framework. */
export function getInstallHit(index: number): number {
  return getInstallStart(index + INSTALL_SLOT) + INSTALL_HIT
}

/** How many products have entered the gate by `line` (any fixed offset works for differences). */
export function getShippedCount(line: LineState): number {
  return Math.floor(line.cycle - 1 + line.move - SHIP_SLOT)
}

/** Seconds into every cycle when the next product crosses into the gate. */
export const SHIP_PHASE = (() => {
  const target = SHIP_SLOT - Math.floor(SHIP_SLOT)
  let low = 0
  let high = MOVE
  for (let step = 0; step < 30; step++) {
    const middle = (low + high) / 2
    if (easeInOutCubic(middle / MOVE) < target) low = middle
    else high = middle
  }
  return (low + high) / 2
})()

/** Seconds since the last product crossed into the gate. */
export function getSinceShip(line: LineState): number {
  const since = line.phase - SHIP_PHASE
  return since >= 0 ? since : since + CYCLE
}

/**
 * What the line builds: apps on the hardware they ship to, from the watch
 * to the desktop. Most travel flat on their carriers, the way displays do
 * on a real line (see `DEVICE_SIZES`).
 */
export type ProductKind =
  | 'phone'
  | 'tablet'
  | 'laptop'
  | 'desktop'
  | 'display'
  | 'mini'
  | 'watch'

export const PRODUCT_KINDS: readonly ProductKind[] = [
  'phone',
  'tablet',
  'laptop',
  'desktop',
  'display',
  'mini',
  'watch',
]

/** Fixed order so neighbours never repeat and the mix feels hand-picked. */
const KIND_SEQUENCE: readonly ProductKind[] = [
  'phone',
  'laptop',
  'watch',
  'display',
  'tablet',
  'mini',
  'phone',
  'desktop',
  'laptop',
  'tablet',
  'watch',
  'phone',
  'mini',
  'display',
  'laptop',
  'desktop',
  'tablet',
]

export function getProductKind(index: number): ProductKind {
  const length = KIND_SEQUENCE.length
  return KIND_SEQUENCE[((index % length) + length) % length]!
}

/**
 * Where the drones plug the models' instruction cards in: a slot across the
 * top of the framework installer, at the head of the line.
 */
export const DOCK = {
  x: slotX(INSTALL_SLOT),
  y: TOWER_TOP,
  z: TOWER.z,
  width: 0.4,
} as const

/** Seven finishes, coprime with the kind sequence's 17, so pairs rarely repeat. */
export const FINISH_COUNT = 7

export function getProductFinish(index: number): number {
  return ((index % FINISH_COUNT) + FINISH_COUNT) % FINISH_COUNT
}

/** Deterministic 0..1 noise per integer. */
export function hash01(index: number): number {
  const x = Math.sin(index * 127.1 + 311.7) * 43758.5453
  return x - Math.floor(x)
}
