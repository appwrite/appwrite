import {
  BoxGeometry,
  BufferAttribute,
  BufferGeometry,
  CatmullRomCurve3,
  Color,
  DynamicDrawUsage,
  ExtrudeGeometry,
  Group,
  InstancedBufferAttribute,
  InstancedMesh,
  Matrix4,
  Mesh,
  type MeshBasicMaterial,
  PlaneGeometry,
  type ShaderMaterial,
  TubeGeometry,
  Vector3,
  type Texture,
  type WebGLRenderer,
} from 'three'
import { SVGLoader } from 'three/addons/loaders/SVGLoader.js'
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js'
import {
  CounterDisplay,
  InstallerDisplay,
  getProductFramework,
} from './frameworks'
import {
  box,
  cylinder,
  ground,
  merge,
  mergeFinishes,
  panel,
  roundedPlane,
} from './geometry'
import { ConsoleDisplay } from './console'
import {
  FINISHES,
  createKitBeam,
  createKitDisplay,
  createKitLamp,
  createKitProjection,
  setLamp,
  type FactoryKit,
  type Finish,
} from './kit'
import {
  createChevronMaterial,
  createHologramMaterial,
  type HologramUniforms,
} from './materials'
import type { FactoryPalette } from './palette'
import {
  DOCK,
  EXTENSION_COUNT,
  EXTENSION_IDS,
  EXTENSION_STATION,
  BED_BACK,
  BED_FRONT,
  BED_TOP,
  CORE,
  COUNTER,
  CYCLE,
  FASCIA,
  GATE,
  HOLOGRAM,
  INSTALL_HIT,
  INSTALL_SLOT,
  MOVE,
  PITCH,
  PRODUCT_BASE,
  QUEUE_SLOTS,
  RACKS,
  RACKS_DOOR,
  RAIL_GAUGE,
  RAIL_HEIGHT,
  STATION_COUNT,
  TOWER,
  TOWER_FRONT,
  TOWER_TOP,
  TRACK_Z,
  UNIT,
  UNIT_BACK,
  UNIT_FRONT,
  UNIT_HEAD,
  UNIT_POST_TOP,
  UNIT_Z,
  WALL,
  WAVE_SPEED,
  WAVE_START,
  clamp01,
  easeInOutCubic,
  easeOutCubic,
  getInstallStart,
  getProductKind,
  getShippedCount,
  getSinceShip,
  getStationStart,
  extensionX,
  hash01,
  hologramBottom,
  rackZ,
  range,
  slotX,
  stationX,
} from './layout'
import {
  ARRIVALS,
  SETTLED,
  type Arrival,
  arrive,
  arriving,
  away,
  cablesOut,
  networkOut,
} from './assembly'
import { installPower, introStep, stationPower, type FrameState } from './frame'
import {
  APPWRITE_MARK,
  NAMEPLATE_SIZE,
  TRACK_REPEAT,
  getDescriptionCell,
  type ShadowShape,
} from './textures'

/** The bed starts past the left edge of the frame and ends inside the gate. */
const BED_START_X = slotX(-QUEUE_SLOTS - 1)
const GATE_END = GATE.start + GATE.length
const BED_END_X = GATE_END + 0.06
const BED_LENGTH = BED_END_X - BED_START_X
const BED_CENTER = (BED_START_X + BED_END_X) / 2
const BED_DEPTH = BED_FRONT - BED_BACK
const BED_Z = (BED_FRONT + BED_BACK) / 2
/** Recessed plinth under the bed, so it floats off the floor. */
const TOE = 0.05
/** Height of the power line on the bed's front face. */
const LINE_Y = 0.262
/** An extension's nameplate: the stations' LCD at three-quarter size, centered on its cabinet's front. */
const UNIT_PLATE = {
  scale: 0.75,
  width: NAMEPLATE_SIZE.width * 0.75,
  height: NAMEPLATE_SIZE.height * 0.75,
  y: TOE + (UNIT.height - TOE) / 2,
} as const

/** Racks stand on a low plinth; their door is glass with the servers behind it. */
const RACK_BASE = 0.06
const RACK_TOP = RACK_BASE + RACKS.height
const LOGO_Y = RACK_BASE + RACKS.height * 0.8
const LOGO_WIDTH = 0.62
const WALL_CENTER = WALL.y + WALL.height / 2
const COUNTER_CENTER = COUNTER.y + COUNTER.height / 2
const BLADE = { pitch: 0.09, height: 0.072, leds: 4 }

/**
 * The gate's tunnel: what the carriers ride into. Tall and deep enough for
 * the tallest product, a laptop's open lid, which leans back past the carrier.
 */
const TUNNEL = { back: TRACK_Z - 0.58, front: TRACK_Z + 0.48, top: 0.36 }
const TUNNEL_Z = (TUNNEL.front + TUNNEL.back) / 2
const GATE_BACK = TUNNEL.back - 0.16
const GATE_FRONT = BED_FRONT - 0.02
const GATE_CENTER = GATE.start + GATE.length / 2
const GATE_SIGN_Y = BED_TOP + GATE.height - 0.2
/** The chevron strip across the gate's front. */
const CHEVRON_Y = BED_TOP + 0.34

/** Power cables: one from the Appwrite rack to every column, the installer last. */
const FEEDS = STATION_COUNT + 1
const CABLE_RADIUS = 0.016

const matrix = new Matrix4()
const color = new Color()
const spot = new Vector3()

/** Static occlusion painted once into the floor. */
export function getStaticShadowShapes(): ShadowShape[] {
  const rowFront = RACKS.front
  const rowBack = rackZ(RACKS.count - 1) - RACKS.width / 2
  return [
    {
      x: BED_CENTER,
      z: BED_Z,
      width: BED_LENGTH + 2,
      depth: BED_DEPTH + 2.6,
      blur: 1.5,
      strength: 0.2,
      layer: 'bed',
    },
    {
      x: BED_CENTER,
      z: BED_Z,
      width: BED_LENGTH + 0.1,
      depth: BED_DEPTH + 0.16,
      blur: 0.26,
      strength: 0.75,
      layer: 'bed',
    },
    {
      x: RACKS.x,
      z: (rowFront + rowBack) / 2,
      width: RACKS.depth + 1.8,
      depth: rowFront - rowBack + 1.6,
      blur: 1.2,
      strength: 0.22,
      layer: 'racks',
    },
    {
      x: RACKS.x,
      z: (rowFront + rowBack) / 2,
      width: RACKS.depth + 0.12,
      depth: rowFront - rowBack + 0.08,
      blur: 0.22,
      strength: 0.8,
      layer: 'racks',
    },
  ]
}

function createLogoGeometry(): BufferGeometry {
  const loader = new SVGLoader()
  const data = loader.parse(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 25 22">${APPWRITE_MARK.map((d) => `<path d="${d}"/>`).join('')}</svg>`,
  )
  const shapes = data.paths.flatMap((path) => SVGLoader.createShapes(path))
  // A thin inlay, like a backlit emblem set into the glass.
  const geometry = new ExtrudeGeometry(shapes, {
    depth: 0.16,
    bevelEnabled: true,
    bevelThickness: 0.07,
    bevelSize: 0.06,
    bevelSegments: 2,
    curveSegments: 18,
  })
  geometry.center()
  // SVG y points down: a half turn about x flips it upright and keeps winding.
  geometry.rotateX(Math.PI)
  const scale = LOGO_WIDTH / 24.44
  geometry.scale(scale, scale, scale)
  geometry.computeVertexNormals()
  return geometry
}

/**
 * The network's cables leave the gate as one flat run of bundles. Like the
 * power runs, every bundle is routed in straight lengths with rounded right
 * angles: out of the gate, a step back (negative) or forward (positive),
 * then away to the right, well past the edge of the frame.
 */
const NETWORK = {
  cables: 5,
  spacing: 0.038,
  /** Extra space between bundles, so they read as groups (the run fits the gate's base). */
  gap: 0.07,
  radius: 0.017,
  /** Bend radius of a bundle's middle cable. */
  bend: 0.5,
  /** How far right the runs go: far beyond anything the camera sees. */
  reach: 70,
} as const

const NETWORK_ROUTES = [
  { out: 0.75, step: -3.4 },
  { out: 1.75, step: -1.7 },
  { out: 0, step: 0 },
  { out: 1.75, step: 1.7 },
  { out: 0.75, step: 3.4 },
] as const

/** Unit direction of a straight run, in the floor plane. */
function heading(from: Vector3, to: Vector3): Vector3 {
  return to.clone().sub(from).setY(0).normalize()
}

/** Side of a run that positive lane offsets sit on (+z while heading +x). */
function side(direction: Vector3): Vector3 {
  return new Vector3(-direction.z, 0, direction.x)
}

/**
 * Points along one cable of a bundle: the bundle's centerline offset by
 * `offset`, each right angle replaced by a quarter bend whose radius grows
 * or shrinks with the offset, so neighbouring cables stay evenly spaced.
 */
function routeCable(corners: Vector3[], offset: number): Vector3[] {
  const points: Vector3[] = []
  const last = corners.length - 1
  for (let index = 0; index <= last; index++) {
    const vertex = corners[index]!
    if (index === 0 || index === last) {
      const direction =
        index === 0
          ? heading(vertex, corners[1]!)
          : heading(corners[last - 1]!, vertex)
      points.push(vertex.clone().addScaledVector(side(direction), offset))
      continue
    }
    const into = heading(corners[index - 1]!, vertex)
    const out = heading(vertex, corners[index + 1]!)
    const corner = vertex
      .clone()
      .addScaledVector(side(into), offset)
      .addScaledVector(side(out), offset)
    const radius = NETWORK.bend - offset * side(into).dot(out)
    const start = corner.clone().addScaledVector(into, -radius)
    const end = corner.clone().addScaledVector(out, radius)
    for (let step = 0; step <= 10; step++) {
      const t = step / 10
      // Quadratic bend from `start` to `end` around the corner.
      points.push(
        start
          .clone()
          .multiplyScalar((1 - t) ** 2)
          .addScaledVector(corner, 2 * (1 - t) * t)
          .addScaledVector(end, t * t),
      )
    }
  }
  return points
}

/**
 * A round cable along `points` lying on the floor. Carries `aT` (distance
 * along the cable) and `aLine` (its pulse delay) for the pulse shader.
 */
function cableAlong(
  points: Vector3[],
  radius: number,
  delay: number,
): BufferGeometry {
  const radial = 8
  const up = new Vector3(0, 1, 0)
  const positions: number[] = []
  const normals: number[] = []
  const along: number[] = []
  const indices: number[] = []
  const tangent = new Vector3()
  const across = new Vector3()
  const normal = new Vector3()
  let distance = 0
  points.forEach((point, index) => {
    if (index > 0) distance += point.distanceTo(points[index - 1]!)
    tangent
      .subVectors(
        points[Math.min(index + 1, points.length - 1)]!,
        points[Math.max(index - 1, 0)]!,
      )
      .normalize()
    across.crossVectors(tangent, up).normalize()
    for (let ring = 0; ring < radial; ring++) {
      const angle = (ring / radial) * Math.PI * 2
      normal
        .copy(up)
        .multiplyScalar(Math.cos(angle))
        .addScaledVector(across, Math.sin(angle))
      positions.push(
        point.x + normal.x * radius,
        point.y + normal.y * radius,
        point.z + normal.z * radius,
      )
      normals.push(normal.x, normal.y, normal.z)
      along.push(distance)
    }
    if (index === 0) return
    const a = (index - 1) * radial
    const b = index * radial
    for (let ring = 0; ring < radial; ring++) {
      const next = (ring + 1) % radial
      indices.push(a + ring, b + ring, a + next, a + next, b + ring, b + next)
    }
  })
  const geometry = new BufferGeometry()
  geometry.setAttribute(
    'position',
    new BufferAttribute(new Float32Array(positions), 3),
  )
  geometry.setAttribute(
    'normal',
    new BufferAttribute(new Float32Array(normals), 3),
  )
  geometry.setAttribute('aT', new BufferAttribute(new Float32Array(along), 1))
  geometry.setAttribute(
    'aLine',
    new BufferAttribute(new Float32Array(along.length).fill(delay), 1),
  )
  geometry.setIndex(indices)
  return geometry
}

/** The network's cables, in bundles, on the floor beyond the gate. */
function createNetworkCables(): BufferGeometry {
  const cables: BufferGeometry[] = []
  const width = NETWORK.spacing * (NETWORK.cables - 1)
  const pitch = width + NETWORK.spacing + NETWORK.gap
  NETWORK_ROUTES.forEach((route, group) => {
    const center = (group - (NETWORK_ROUTES.length - 1) / 2) * pitch
    const start = new Vector3(GATE_END - 0.1, NETWORK.radius, TRACK_Z + center)
    const corners = [start]
    if (route.step !== 0) {
      const turn = start.clone().add(new Vector3(route.out, 0, 0))
      corners.push(turn, turn.clone().add(new Vector3(0, 0, route.step)))
    }
    const lastCorner = corners[corners.length - 1]!
    corners.push(lastCorner.clone().add(new Vector3(NETWORK.reach, 0, 0)))
    for (let cable = 0; cable < NETWORK.cables; cable++) {
      const offset = cable * NETWORK.spacing - width / 2
      cables.push(
        cableAlong(
          routeCable(corners, offset),
          NETWORK.radius,
          group * 0.18 + cable * 0.03,
        ),
      )
    }
  })
  const merged = mergeGeometries(cables, false)
  for (const cable of cables) cable.dispose()
  if (!merged) throw new Error('Network cable merge failed')
  return merged
}

type PartKey = Extract<
  Finish,
  'bed' | 'matte' | 'steel' | 'aluminum' | 'enclosure' | 'glass'
>

const PART_KEYS: readonly PartKey[] = [
  'bed',
  'matte',
  'steel',
  'aluminum',
  'enclosure',
  'glass',
]

type Feed = { points: Vector3[]; station: number }

/**
 * The render layer holograms are drawn in, so the engine can draw them by
 * themselves in front of the page copy (see its `drawFront`).
 */
export const HOLOGRAM_LAYER = 1

/** A station's hologram projector, its origin on the lens in the column's top. */
type Hologram = {
  group: Group
  /** The lens lighting up. */
  lens: MeshBasicMaterial
  /** The fan of light from the lens up to the screen. */
  fan: Mesh<BufferGeometry, ShaderMaterial>
  screen: Mesh<BufferGeometry, ShaderMaterial>
  uniforms: HologramUniforms
}

/** Seconds for a hologram to form (it goes out in half the time). */
const PROJECT = 0.6
/** How far a hologram has formed when its screen starts to draw in. */
const SCREEN_START = 0.25
/** How much a hologram's glass dims what is behind it: more on light pages, so its light still reads. */
const PANE = { dark: 0.6, light: 0.82 } as const

/** A flat fan of light, `bottom` wide at y = 0 and `top` wide at y = 1. */
function fan(bottom: number, top: number): BufferGeometry {
  const geometry = new BufferGeometry()
  geometry.setAttribute(
    'position',
    new BufferAttribute(
      new Float32Array([
        -bottom / 2,
        0,
        0,
        bottom / 2,
        0,
        0,
        top / 2,
        1,
        0,
        -top / 2,
        1,
        0,
      ]),
      3,
    ),
  )
  geometry.setIndex([0, 1, 2, 0, 2, 3])
  return geometry
}

/** The eight corners of the box from `min` to `max` in `matrix`'s space, in world space. */
function corners(
  matrix: Matrix4,
  min: readonly [number, number, number],
  max: readonly [number, number, number],
): Vector3[] {
  const points: Vector3[] = []
  for (const x of [min[0], max[0]])
    for (const y of [min[1], max[1]])
      for (const z of [min[2], max[2]])
        points.push(new Vector3(x, y, z).applyMatrix4(matrix))
  return points
}

/**
 * Everything that never moves: the floor, the bed and its track, a column
 * per station with its nameplate and vertical rails, the framework
 * installer at the head of the line, the row of server racks behind it
 * (the Appwrite rack powering every station through pink cables, the next
 * one counting shipped projects), and the gate products leave through to
 * the network.
 */
export class FactoryWorld {
  readonly group = new Group()
  private readonly logo: Mesh
  private readonly nameplates: InstancedMesh
  private readonly plates: InstancedBufferAttribute
  private readonly bladeLeds: InstancedMesh
  private readonly bladeSeeds: number[] = []
  private readonly installer: InstallerDisplay
  private readonly counter: CounterDisplay
  private readonly console: ConsoleDisplay
  private readonly wallScreen: Mesh<BufferGeometry, MeshBasicMaterial>
  private readonly installerScreen: Mesh
  private readonly counterScreen: Mesh
  private readonly chevrons = createChevronMaterial()
  private readonly chevronStrip: Mesh
  private readonly curtain: ReturnType<typeof createKitBeam>
  private readonly curtainSheet: Mesh
  private readonly feedCables: Mesh
  private readonly networkCables: Mesh
  /** Every server LED's place, and the rack it is in. */
  private readonly leds: { y: number; z: number; rack: number }[] = []
  /** Every machine is in place and every cable run out (see `assemble`). */
  private settled = false
  private readonly feeds: Feed[] = []
  private readonly holograms: Hologram[] = []
  /** Per station, 0 off → 1 formed; moves at a constant rate toward its target. */
  private readonly projected: number[] = new Array(STATION_COUNT).fill(0)
  /** Real seconds the holograms' light has been moving (scan lines, rays). */
  private shimmer = 0
  private readonly geometries: BufferGeometry[] = []
  private baseCount: number | null = null
  /** Where the counter starts: a little over 500,000, different on every visit. */
  private readonly startCount = 501_000 + Math.floor(Math.random() * 9_000)

  constructor(
    private readonly kit: FactoryKit,
    nameplateTexture: Texture,
    descriptionTexture: Texture,
    counterLabel: string,
  ) {
    const track = <T extends BufferGeometry>(geometry: T) => {
      this.geometries.push(geometry)
      return geometry
    }
    const parts = Object.fromEntries(
      PART_KEYS.map((key) => [key, [] as BufferGeometry[]]),
    ) as Record<PartKey, BufferGeometry[]>
    // The machine being built and how it comes into place as the factory
    // assembles (see `assembly.ts`); the bed and the track are there from the start.
    let machine: Arrival | null = null
    const add = (
      key: PartKey,
      geometry: BufferGeometry,
      x: number,
      y: number,
      z: number,
    ) => {
      geometry.translate(x, y, z)
      if (machine) arriving(geometry, machine)
      parts[key].push(geometry)
      return geometry
    }
    const plain = (width: number, height: number, depth: number) =>
      new BoxGeometry(width, height, depth)

    const floor = new Mesh(
      track(new PlaneGeometry(150, 80).rotateX(-Math.PI / 2)),
      kit.floor,
    )
    floor.position.set(0, 0, -6)
    floor.renderOrder = 0
    floor.name = 'floor'
    this.group.add(floor)

    // The bed: one long satin plinth floating on a recessed toe.
    ground(
      add(
        'bed',
        box(BED_LENGTH, BED_TOP - TOE, BED_DEPTH, 0.045, 4),
        BED_CENTER,
        TOE + (BED_TOP - TOE) / 2,
        BED_Z,
      ),
      TOE,
      BED_TOP - TOE,
      0.3,
    )
    add(
      'matte',
      plain(BED_LENGTH - 0.06, TOE, BED_DEPTH - 0.08),
      BED_CENTER,
      TOE / 2,
      BED_Z,
    )
    // Panel seams between bays and the channel the power line sits in.
    for (let x = slotX(-QUEUE_SLOTS) - PITCH / 2; x < GATE.start; x += PITCH) {
      add(
        'matte',
        plain(0.007, BED_TOP - TOE - 0.05, 0.004),
        x,
        TOE + (BED_TOP - TOE) / 2,
        BED_FRONT + 0.0005,
      )
    }
    add(
      'matte',
      plain(BED_LENGTH, 0.026, 0.004),
      BED_CENTER,
      LINE_Y,
      BED_FRONT + 0.0005,
    )
    const powerLine = new Mesh(
      track(new PlaneGeometry(BED_LENGTH, 0.011)),
      kit.powerLine,
    )
    powerLine.position.set(BED_CENTER, LINE_Y, BED_FRONT + 0.0035)
    this.group.add(powerLine)

    // Transfer track: two ground steel rails over the linear motor channel.
    for (const side of [-1, 1]) {
      add(
        'steel',
        box(BED_LENGTH, RAIL_HEIGHT, 0.03, 0.006, 1),
        BED_CENTER,
        BED_TOP + RAIL_HEIGHT / 2,
        TRACK_Z + (side * RAIL_GAUGE) / 2,
      )
    }
    const channel = new Mesh(
      track(
        new PlaneGeometry(BED_LENGTH, RAIL_GAUGE - 0.07).rotateX(-Math.PI / 2),
      ),
      kit.track,
    )
    channel.position.set(BED_CENTER, BED_TOP + 0.0015, TRACK_Z)
    channel.userData.castsNoShadow = true
    kit.track.map?.repeat.set(BED_LENGTH / TRACK_REPEAT, 1)
    this.group.add(channel)

    // Columns: painted housing, a printed nameplate, vertical rails for the arm.
    const railY = 0.86
    const railLength = 0.62
    const column = (x: number, bay = true) => {
      ground(
        add(
          'enclosure',
          box(TOWER.width, TOWER.height, TOWER.depth, 0.075, 5),
          x,
          BED_TOP + TOWER.lift + TOWER.height / 2,
          TOWER.z,
        ),
        BED_TOP + TOWER.lift,
        TOWER.height,
      )
      add(
        'matte',
        plain(TOWER.width - 0.06, TOWER.lift, TOWER.depth - 0.06),
        x,
        BED_TOP + TOWER.lift / 2,
        TOWER.z,
      )
      if (!bay) return
      // The slot the arm's carriage rides in.
      add(
        'matte',
        panel(0.1, railLength + 0.06, 0.006, 0.05),
        x,
        railY,
        TOWER_FRONT - 0.002,
      )
      // Rating plate, low on the housing.
      add(
        'aluminum',
        plain(0.1, 0.045, 0.003),
        x - TOWER.width / 2 + 0.1,
        BED_TOP + TOWER.lift + 0.07,
        TOWER_FRONT + 0.001,
      )
      for (const side of [-1, 1]) {
        for (let slot = 0; slot < 6; slot++) {
          add(
            'matte',
            plain(0.004, 0.012, 0.3),
            x + side * (TOWER.width / 2 + 0.0005),
            0.6 + slot * 0.034,
            TOWER.z,
          )
        }
      }
    }
    for (let index = 0; index < STATION_COUNT; index++) {
      const x = stationX(index)
      machine = ARRIVALS.station(index)
      column(x)
      // Bezel of the station's display.
      add(
        'matte',
        panel(FASCIA.width, FASCIA.height, 0.012, 0.05),
        x,
        FASCIA.y,
        TOWER_FRONT - 0.002,
      )
    }

    // The extensions: a cabinet in the bed's paint on the floor in front of
    // its station's bay, a cable duct across to the bed, its nameplate on the
    // front and, at the back of its top, the post its head turns on (see
    // `extensions.ts`).
    for (const id of EXTENSION_IDS) {
      const x = extensionX(id)
      machine = ARRIVALS.station(EXTENSION_STATION[id])
      ground(
        add(
          'bed',
          box(UNIT.width, UNIT.height - TOE, UNIT.depth, 0.045, 4),
          x,
          TOE + (UNIT.height - TOE) / 2,
          UNIT_Z,
        ),
        TOE,
        UNIT.height - TOE,
      )
      add(
        'matte',
        plain(UNIT.width - 0.05, TOE, UNIT.depth - 0.05),
        x,
        TOE / 2,
        UNIT_Z,
      )
      // The duct, from under the bed's overhang to the cabinet's toe.
      const duct = UNIT.gap + 0.08
      add(
        'matte',
        box(0.2, 0.034, duct, 0.008, 2),
        x,
        0.017,
        UNIT_BACK + 0.02 - duct / 2,
      )
      add(
        'matte',
        panel(UNIT_PLATE.width + 0.035, UNIT_PLATE.height + 0.035, 0.008, 0.03),
        x,
        UNIT_PLATE.y,
        UNIT_FRONT - 0.002,
      )
      const post = UNIT_POST_TOP - UNIT.height
      add(
        'aluminum',
        cylinder(0.085, 0.016, 32),
        x,
        UNIT.height + 0.008,
        UNIT_HEAD.z,
      )
      add(
        'steel',
        cylinder(0.04, post, 24),
        x,
        UNIT.height + post / 2,
        UNIT_HEAD.z,
      )
    }
    machine = null

    // The hologram projectors, one per station: a lens set into the column's
    // top, the fan of light it throws up, and the screen that light draws.
    // Only the lens's housing and glass are there while it is off.
    const { lens } = HOLOGRAM
    const lensGeometry = track(
      new PlaneGeometry(lens.width, lens.depth).rotateX(-Math.PI / 2),
    )
    const fanGeometry = track(fan(lens.width, HOLOGRAM.width))
    const screenGeometry = track(
      new PlaneGeometry(HOLOGRAM.width, HOLOGRAM.height),
    )
    for (let index = 0; index < STATION_COUNT; index++) {
      const group = new Group()
      group.position.set(stationX(index), TOWER_TOP, HOLOGRAM.z)
      group.visible = false
      const glow = createKitLamp(kit)
      const lit = new Mesh(lensGeometry, glow)
      lit.position.y = 0.0055
      const beam = new Mesh(fanGeometry, createKitProjection(kit))
      beam.material.uniforms.uWidths!.value.set(lens.width, HOLOGRAM.width)
      beam.position.y = 0.006
      beam.renderOrder = 8
      const { material, uniforms } = createHologramMaterial()
      uniforms.uAtlas.value = descriptionTexture
      const cell = getDescriptionCell(index, STATION_COUNT)
      uniforms.uCell.value.set(cell.u, cell.v, cell.du, cell.dv)
      uniforms.uSize.value.set(HOLOGRAM.width, HOLOGRAM.height)
      uniforms.uPane.value = kit.palette.dark ? PANE.dark : PANE.light
      const screen = new Mesh(screenGeometry, material)
      screen.renderOrder = 9
      for (const mesh of [lit, beam, screen]) {
        mesh.layers.set(HOLOGRAM_LAYER)
        mesh.userData.castsNoShadow = true
        mesh.userData.receivesNoShadow = true
      }
      group.add(lit, beam, screen)
      this.group.add(group)
      this.holograms.push({ group, lens: glow, fan: beam, screen, uniforms })
      // The lens: dark glass in a housing, flush with the column's top.
      machine = ARRIVALS.station(index)
      add(
        'matte',
        plain(lens.width + 0.08, 0.004, lens.depth + 0.06),
        stationX(index),
        TOWER_TOP + 0.001,
        HOLOGRAM.z,
      )
      add(
        'glass',
        plain(lens.width, 0.003, lens.depth),
        stationX(index),
        TOWER_TOP + 0.0035,
        HOLOGRAM.z,
      )
    }

    // The framework installer: a column like the others, with a display where the nameplate goes.
    const installX = slotX(INSTALL_SLOT)
    machine = ARRIVALS.installer
    column(installX)
    // The dock the drones plug the models' instruction cards into.
    add('matte', plain(DOCK.width, 0.004, 0.05), DOCK.x, DOCK.y + 0.001, DOCK.z)
    add(
      'matte',
      panel(FASCIA.width, FASCIA.height, 0.012, 0.05),
      installX,
      FASCIA.y,
      TOWER_FRONT - 0.002,
    )
    this.installer = new InstallerDisplay(kit.palette)
    this.installerScreen = new Mesh(
      track(roundedPlane(FASCIA.width - 0.05, FASCIA.height - 0.05, 0.035)),
      createKitDisplay(kit, this.installer.texture),
    )
    this.installerScreen.position.set(installX, FASCIA.y, TOWER_FRONT + 0.0105)
    this.group.add(this.installerScreen)

    // The server aisle: racks one after another, doors (glass, servers behind) facing the camera's side.
    const blades: { y: number; z: number; rack: number }[] = []
    for (let rack = 0; rack < RACKS.count; rack++) {
      const z = rackZ(rack)
      machine = ARRIVALS.rack(rack)
      ground(
        add(
          'enclosure',
          box(RACKS.depth, RACKS.height, RACKS.width, 0.05, 4),
          RACKS.x,
          RACK_BASE + RACKS.height / 2,
          z,
        ),
        RACK_BASE,
        RACKS.height * 0.5,
      )
      add(
        'matte',
        plain(RACKS.depth - 0.06, RACK_BASE, RACKS.width - 0.04),
        RACKS.x,
        RACK_BASE / 2,
        z,
      )
      add(
        'glass',
        box(0.014, RACKS.height - 0.1, RACKS.width - 0.08, 0.006, 1),
        RACKS_DOOR - 0.004,
        RACK_BASE + RACKS.height / 2,
        z,
      )
      add(
        'aluminum',
        box(0.02, 0.3, 0.022, 0.008, 1),
        RACKS_DOOR - 0.02,
        RACK_BASE + RACKS.height * 0.52,
        z - RACKS.width / 2 + 0.07,
      )
      // Top vent and cable entry.
      add(
        'matte',
        plain(RACKS.depth - 0.3, 0.004, RACKS.width - 0.24),
        RACKS.x,
        RACK_TOP + 0.002,
        z,
      )
      for (let y = RACK_BASE + 0.14; y <= RACK_TOP - 0.16; y += BLADE.pitch) {
        add(
          'bed',
          box(0.006, BLADE.height, RACKS.width - 0.16, 0.003, 1),
          RACKS_DOOR - 0.012,
          y,
          z,
        )
        blades.push({ y, z, rack })
      }
    }
    // The Appwrite rack's end panel, facing the line: black glass, the logo set into it.
    machine = ARRIVALS.rack(0)
    add(
      'glass',
      box(RACKS.depth - 0.08, RACKS.height - 0.08, 0.014, 0.008, 1),
      RACKS.x,
      RACK_BASE + RACKS.height / 2,
      RACKS.front + 0.004,
    )

    // The giant Console screen, flush beside the first rack: an aluminium display unit.
    this.console = new ConsoleDisplay(kit.palette)
    const frameWidth = WALL.width + 2 * WALL.frame
    const frameHeight = WALL.height + 2 * WALL.frame
    const wallCenter = WALL_CENTER
    machine = ARRIVALS.wall
    ground(
      add(
        'enclosure',
        panel(frameWidth, frameHeight, WALL.depth, 0.06),
        WALL.x,
        wallCenter,
        WALL.z - WALL.depth,
      ),
      WALL.y - WALL.frame,
      frameHeight * 0.5,
    )
    add(
      'matte',
      plain(frameWidth - 0.06, WALL.y - WALL.frame, WALL.depth - 0.06),
      WALL.x,
      (WALL.y - WALL.frame) / 2,
      WALL.z - WALL.depth / 2,
    )
    add(
      'matte',
      panel(WALL.width + 0.02, WALL.height + 0.02, 0.004, 0.035),
      WALL.x,
      wallCenter,
      WALL.z,
    )
    this.wallScreen = new Mesh(
      track(roundedPlane(WALL.width, WALL.height, 0.03)),
      createKitDisplay(kit, this.console.texture),
    )
    // A lit screen: it should not fade into the distance like the hardware does.
    this.wallScreen.material.fog = false
    this.wallScreen.position.set(WALL.x, wallCenter, WALL.z + 0.0055)
    this.group.add(this.wallScreen)

    // The shipped counter: a column on the line, after Sites, with a display for a front.
    machine = ARRIVALS.counter
    column(COUNTER.x, false)
    add(
      'matte',
      panel(COUNTER.width + 0.05, COUNTER.height + 0.05, 0.012, 0.055),
      COUNTER.x,
      COUNTER.y + COUNTER.height / 2,
      COUNTER.z - 0.002,
    )
    this.counter = new CounterDisplay(kit.palette, counterLabel)
    this.counterScreen = new Mesh(
      track(roundedPlane(COUNTER.width, COUNTER.height, 0.04)),
      createKitDisplay(kit, this.counter.texture),
    )
    this.counterScreen.position.set(
      COUNTER.x,
      COUNTER_CENTER,
      COUNTER.z + 0.0105,
    )
    this.group.add(this.counterScreen)

    // Pink power cables on the floor: out of the Appwrite rack, along the aisle
    // in one bundle, peeling off to each machine behind the line. Every
    // machine gets two, side by side, for redundancy.
    const tubes: BufferGeometry[] = []
    for (let feed = 0; feed < FEEDS; feed++) {
      const station = feed < STATION_COUNT ? feed : INSTALL_SLOT
      const machineX = feed < STATION_COUNT ? stationX(feed) : installX
      // The machine nearest the rack takes the lanes nearest the line, so no two cross.
      const order = FEEDS - 1 - (feed < STATION_COUNT ? feed + 1 : 0)
      for (let strand = 0; strand < 2; strand++) {
        const lane = order * 2 + strand
        const z = RACKS.front + 1.16 - lane * 0.042
        const startX = RACKS.x + 0.42 - lane * 0.045
        // The strand nearer the line peels off first.
        const endX = machineX + (strand === 0 ? 0.045 : -0.045)
        const r = CABLE_RADIUS
        const curve = new CatmullRomCurve3(
          [
            new Vector3(startX, r, RACKS.front - 0.02),
            new Vector3(startX, r, z - 0.3),
            new Vector3(startX - 0.3, r, z),
            new Vector3(endX + 0.4, r, z),
            new Vector3(endX, r, z + 0.4),
            new Vector3(endX, r, BED_BACK - 0.2),
            new Vector3(endX, r, BED_BACK - 0.01),
          ],
          false,
          'centripetal',
        )
        this.feeds.push({ points: curve.getSpacedPoints(96), station })
        tubes.push(new TubeGeometry(curve, 160, r, 8, false))
      }
    }
    this.feedCables = new Mesh(track(merge(tubes)), kit.cable)
    this.feedCables.userData.noReflection = true
    this.group.add(this.feedCables)

    // The network gate: a housing with a tunnel the carriers ride into.
    const gateTop = BED_TOP + GATE.height
    const tunnelTop = BED_TOP + TUNNEL.top
    machine = ARRIVALS.gate
    ground(
      add(
        'enclosure',
        box(GATE.length, GATE.height, GATE_FRONT - TUNNEL.front, 0.06, 4),
        GATE_CENTER,
        BED_TOP + GATE.height / 2,
        (GATE_FRONT + TUNNEL.front) / 2,
      ),
      BED_TOP,
      GATE.height,
    )
    ground(
      add(
        'enclosure',
        box(GATE.length, GATE.height, TUNNEL.back - GATE_BACK, 0.06, 4),
        GATE_CENTER,
        BED_TOP + GATE.height / 2,
        (TUNNEL.back + GATE_BACK) / 2,
      ),
      BED_TOP,
      GATE.height,
    )
    add(
      'enclosure',
      box(
        GATE.length,
        gateTop - tunnelTop,
        TUNNEL.front - TUNNEL.back + 0.04,
        0.02,
        2,
      ),
      GATE_CENTER,
      (gateTop + tunnelTop) / 2,
      TUNNEL_Z,
    )
    // Dark tunnel lining and a trim frame around its mouth.
    add(
      'matte',
      plain(GATE.length - 0.08, 0.01, TUNNEL.front - TUNNEL.back),
      GATE_CENTER + 0.03,
      tunnelTop - 0.006,
      TUNNEL_Z,
    )
    add(
      'matte',
      plain(0.02, TUNNEL.top, TUNNEL.front - TUNNEL.back),
      GATE_END - 0.06,
      BED_TOP + TUNNEL.top / 2,
      TUNNEL_Z,
    )
    add(
      'aluminum',
      box(0.03, 0.03, TUNNEL.front - TUNNEL.back + 0.06, 0.008, 1),
      GATE.start - 0.005,
      tunnelTop,
      TUNNEL_Z,
    )
    for (const z of [TUNNEL.back - 0.015, TUNNEL.front + 0.015]) {
      add(
        'aluminum',
        box(0.03, TUNNEL.top, 0.03, 0.008, 1),
        GATE.start - 0.005,
        BED_TOP + TUNNEL.top / 2,
        z,
      )
    }
    // Gland plate low on the base's end, where the network cables leave: part of the bed.
    machine = null
    add(
      'aluminum',
      box(0.03, 0.15, 1.32, 0.008, 1),
      BED_END_X + 0.012,
      0.075,
      TRACK_Z,
    )
    // Display on the gate's front, and the chevron strip under it.
    machine = ARRIVALS.gate
    add(
      'matte',
      panel(FASCIA.width, FASCIA.height, 0.012, 0.05),
      GATE_CENTER,
      GATE_SIGN_Y,
      GATE_FRONT - 0.002,
    )
    add(
      'matte',
      panel(GATE.length - 0.2, 0.07, 0.008, 0.03),
      GATE_CENTER,
      CHEVRON_Y,
      GATE_FRONT - 0.001,
    )
    machine = null
    this.chevronStrip = new Mesh(
      track(new PlaneGeometry(GATE.length - 0.24, 0.05)),
      this.chevrons,
    )
    this.chevronStrip.position.set(GATE_CENTER, CHEVRON_Y, GATE_FRONT + 0.0075)
    this.group.add(this.chevronStrip)

    this.curtain = createKitBeam(kit)
    this.curtainSheet = new Mesh(
      track(
        new PlaneGeometry(TUNNEL.front - TUNNEL.back, TUNNEL.top).rotateY(
          Math.PI / 2,
        ),
      ),
      this.curtain,
    )
    this.curtainSheet.position.set(
      GATE.start + 0.03,
      BED_TOP + TUNNEL.top / 2,
      TUNNEL_Z,
    )
    this.curtainSheet.renderOrder = 8
    this.group.add(this.curtainSheet)

    this.networkCables = new Mesh(
      track(createNetworkCables()),
      kit.networkCable,
    )
    this.networkCables.userData.noReflection = true
    this.group.add(this.networkCables)

    // Every static machined part, in all its finishes, is one draw.
    this.group.add(new Mesh(track(mergeFinishes(parts, FINISHES)), kit.finish))

    this.logo = new Mesh(track(createLogoGeometry()), kit.logo)
    this.logo.position.set(CORE.x, LOGO_Y, RACKS.front + 0.014)
    this.group.add(this.logo)

    // Status LEDs at the near end of every server, facing the aisle.
    const ledCount = blades.length * BLADE.leds
    this.bladeLeds = new InstancedMesh(
      track(new PlaneGeometry(0.024, 0.01).rotateY(-Math.PI / 2)),
      kit.led,
      ledCount,
    )
    blades.forEach((blade, index) => {
      for (let led = 0; led < BLADE.leds; led++) {
        const slot = index * BLADE.leds + led
        // Placed with their rack as it rolls in (see `assemble`).
        this.leds.push({
          y: blade.y,
          z: blade.z + 0.2 - led * 0.045,
          rack: blade.rack,
        })
        this.bladeLeds.setColorAt(slot, color.set(0x000000))
        this.bladeSeeds.push(hash01(slot * 1.37 + 0.5))
      }
    })

    // Names printed on the station panels, and the gate's sign (the last row).
    const plate = track(
      roundedPlane(NAMEPLATE_SIZE.width, NAMEPLATE_SIZE.height, 0.035),
    )
    this.plates = new InstancedBufferAttribute(
      new Float32Array((STATION_COUNT + 1 + EXTENSION_COUNT) * 2),
      2,
    ).setUsage(DynamicDrawUsage)
    plate.setAttribute('aPlate', this.plates)
    kit.nameplate.uniforms.uMap!.value = nameplateTexture
    // A row per station, the gate's, then one per extension.
    const rows = STATION_COUNT + 1 + EXTENSION_COUNT
    kit.nameplate.uniforms.uRows!.value = rows
    this.nameplates = new InstancedMesh(plate, kit.nameplate, rows)
    this.nameplates.renderOrder = 3

    // Each row of the atlas; placed with their machines as they arrive (see `assemble`).
    for (let index = 0; index < rows; index++)
      this.plates.setXY(index, index, 0)
    for (const mesh of [this.nameplates, this.bladeLeds]) {
      mesh.frustumCulled = false
      this.group.add(mesh)
    }
    // The polished floor would turn hundreds of server LEDs into speckle.
    this.bladeLeds.userData.noReflection = true
  }

  setFrameworkIcons(icons: readonly (HTMLImageElement | null)[]) {
    this.installer.setIcons(icons)
  }

  setDescriptionTexture(texture: Texture) {
    for (const { uniforms } of this.holograms) uniforms.uAtlas.value = texture
  }

  setCounterLabel(label: string) {
    this.counter.setLabel(label)
  }

  /**
   * Every hologram that is on, as a box of eight world corners (as of the
   * last frame's matrices) from its lens up to its screen's top edge. The
   * engine moves them in front of the page copy.
   */
  getDisplayBoxes(): Vector3[][] {
    const boxes: Vector3[][] = []
    const { width, height } = HOLOGRAM
    for (const { group, screen } of this.holograms) {
      if (!group.visible) continue
      const top = screen.position.y + height / 2
      boxes.push(
        corners(
          group.matrixWorld,
          [-width / 2, 0, -0.05],
          [width / 2, top, 0.05],
        ),
      )
    }
    return boxes
  }

  setPalette(palette: FactoryPalette) {
    this.console.setPalette(palette)
    this.installer.setPalette(palette)
    this.counter.setPalette(palette)
    for (const { uniforms } of this.holograms)
      uniforms.uPane.value = palette.dark ? PANE.dark : PANE.light
  }

  /**
   * While the factory assembles (see `assembly.ts`): moves everything that
   * is not part of the merged hardware (which the finish shader moves
   * itself) with the machine it belongs to, and pulls the cables out.
   */
  private assemble(assembly: number) {
    const at = (x: number, y: number, z: number, arrival: Arrival) =>
      arrive(spot, x, y, z, assembly, arrival)
    at(CORE.x, LOGO_Y, RACKS.front + 0.014, ARRIVALS.rack(0))
    this.logo.position.copy(spot)
    at(WALL.x, WALL_CENTER, WALL.z + 0.0055, ARRIVALS.wall)
    this.wallScreen.position.copy(spot)
    at(slotX(INSTALL_SLOT), FASCIA.y, TOWER_FRONT + 0.0105, ARRIVALS.installer)
    this.installerScreen.position.copy(spot)
    at(COUNTER.x, COUNTER_CENTER, COUNTER.z + 0.0105, ARRIVALS.counter)
    this.counterScreen.position.copy(spot)
    at(GATE_CENTER, CHEVRON_Y, GATE_FRONT + 0.0075, ARRIVALS.gate)
    this.chevronStrip.position.copy(spot)
    at(GATE.start + 0.03, BED_TOP + TUNNEL.top / 2, TUNNEL_Z, ARRIVALS.gate)
    this.curtainSheet.position.copy(spot)

    for (let index = 0; index < STATION_COUNT; index++) {
      at(
        stationX(index),
        FASCIA.y,
        TOWER_FRONT + 0.0105,
        ARRIVALS.station(index),
      )
      this.nameplates.setMatrixAt(
        index,
        matrix.makeTranslation(spot.x, spot.y, spot.z),
      )
    }
    at(GATE_CENTER, GATE_SIGN_Y, GATE_FRONT + 0.0105, ARRIVALS.gate)
    this.nameplates.setMatrixAt(
      STATION_COUNT,
      matrix.makeTranslation(spot.x, spot.y, spot.z),
    )
    // Each extension's, smaller, on its cabinet's front.
    EXTENSION_IDS.forEach((id, extension) => {
      const station = EXTENSION_STATION[id]
      at(
        extensionX(id),
        UNIT_PLATE.y,
        UNIT_FRONT + 0.0065,
        ARRIVALS.station(station),
      )
      this.nameplates.setMatrixAt(
        STATION_COUNT + 1 + extension,
        matrix
          .makeScale(UNIT_PLATE.scale, UNIT_PLATE.scale, 1)
          .setPosition(spot),
      )
    })
    this.nameplates.instanceMatrix.needsUpdate = true

    // The servers' LEDs roll in with their racks, and the racks' painted
    // shadow on the floor arrives as they do.
    let racks = 0
    for (let rack = 0; rack < RACKS.count; rack++) {
      const arrival = ARRIVALS.rack(rack)
      racks += (1 - away(assembly, arrival) / arrival.distance) ** 4
    }
    this.kit.floor.uniforms.uRacks!.value = racks / RACKS.count
    this.leds.forEach((led, index) => {
      at(RACKS_DOOR - 0.0165, led.y, led.z, ARRIVALS.rack(led.rack))
      this.bladeLeds.setMatrixAt(
        index,
        matrix.makeTranslation(spot.x, spot.y, spot.z),
      )
    })
    this.bladeLeds.instanceMatrix.needsUpdate = true

    // Cables cast their shadows once they lie in full.
    const feeds = cablesOut(assembly)
    const reach = networkOut(assembly)
    this.kit.cableUniforms.uReveal.value = feeds
    this.kit.networkUniforms.uReach.value = reach
    this.feedCables.castShadow = feeds >= 1
    this.settled = assembly >= SETTLED
    this.networkCables.castShadow = this.settled
    if (this.settled)
      this.kit.networkUniforms.uReach.value = Number.POSITIVE_INFINITY
  }

  update(frame: FrameState) {
    if (!this.settled) this.assemble(frame.assembly)
    const { time, line, glows, levels, introTime, palette, highlight } = frame
    const kit = this.kit
    const power = easeOutCubic(introStep(introTime, 0.1, 0.9))
    const pink = palette.tones.pink

    kit.floor.uniforms.uPool!.value = palette.pool * power

    // Every cycle a pulse leaves the rack and runs down the bed.
    const sinceWave = line.phase - WAVE_START
    const strip = kit.powerLine.uniforms
    strip
      .uBase!.value.copy(palette.light)
      .multiplyScalar(palette.dark ? 0.14 : 0.6)
    strip.uPulse!.value.copy(pink).multiplyScalar(palette.dark ? 1.25 : 1)
    strip.uWave!.value = sinceWave >= 0 ? sinceWave * WAVE_SPEED : -10
    strip.uPower!.value = power

    const pulse = sinceWave >= 0 ? Math.exp(-sinceWave * 4) : 0
    kit.logo.emissiveIntensity =
      ((palette.dark ? 0.3 : 0.08) + pulse * (palette.dark ? 0.22 : 0.1)) *
      power
    kit.cable.emissiveIntensity = (palette.dark ? 0.04 : 0) * power
    glows.add(
      CORE.x,
      LOGO_Y,
      RACKS.front + 0.12,
      2,
      pink,
      (0.02 + 0.03 * pulse) * power * palette.glowBoost,
    )

    // The power reaches each station down its cable, just as it starts work.
    const depart = line.cycle * CYCLE + MOVE - 0.42
    for (const feed of this.feeds) {
      const arrive =
        feed.station === INSTALL_SLOT
          ? getInstallStart(line.cycle)
          : getStationStart(feed.station, line.cycle)
      const progress = (time - depart) / (arrive - depart)
      if (progress < 0 || progress > 1.05) continue
      const point =
        feed.points[Math.round(clamp01(progress) * (feed.points.length - 1))]!
      glows.add(
        point.x,
        point.y,
        point.z,
        0.16,
        pink,
        0.45 * (1 - range(progress, 0.92, 1.05)) * power * palette.glowBoost,
      )
    }

    // Servers: slow, irregular activity; one green power LED per server.
    for (let index = 0; index < this.bladeSeeds.length; index++) {
      const led = index % BLADE.leds
      const seed = this.bladeSeeds[index]!
      const rate = 0.8 + seed * 2.2
      const tick = Math.floor(time * rate + seed * 9)
      const lit = led === 0 || hash01(index * 13 + tick * 7) > 0.5
      const tone =
        led === 0
          ? palette.tones.mint
          : hash01(index * 5 + 3) > 0.86
            ? pink
            : palette.light
      color.copy(tone).multiplyScalar((lit ? 0.9 : 0.08) * power)
      this.bladeLeds.setColorAt(index, color)
    }
    if (this.bladeLeds.instanceColor)
      this.bladeLeds.instanceColor.needsUpdate = true

    // The installer shows the framework of the product it is working on. As
    // the line moves, the last one fades out and the next one fades in.
    const installOn = easeOutCubic(installPower(introTime))
    const leaving = line.phase < 0.42
    const installing = line.cycle - INSTALL_SLOT - (leaving ? 1 : 0)
    this.console.update(time)
    this.wallScreen.material.color.setScalar(power)
    this.installer.update(
      getProductFramework(installing, getProductKind(installing)),
      leaving
        ? 1
        : range(time - getInstallStart(line.cycle), 0.06, INSTALL_HIT),
      installOn,
      leaving
        ? 1 - easeInOutCubic(range(line.phase, 0, 0.36))
        : easeInOutCubic(range(line.phase, 0.46, 0.92)),
    )

    // Shipped counter and the gate: both react as a product crosses into it.
    this.baseCount ??= getShippedCount(line)
    this.counter.update(
      this.startCount + getShippedCount(line) - this.baseCount,
      power,
    )
    const sinceShip = getSinceShip(line)
    const flare = Math.exp(-sinceShip * 5)
    this.chevrons.uniforms
      .uBase!.value.copy(palette.fascia)
      .multiplyScalar(palette.dark ? 0.55 : 0.85)
    this.chevrons.uniforms.uColor!.value.copy(pink)
    this.chevrons.uniforms.uTime!.value = time
    this.chevrons.uniforms.uSince!.value = sinceShip
    this.chevrons.uniforms.uPower!.value = power
    this.curtain.uniforms.uColor!.value.copy(pink)
    this.curtain.uniforms.uAlpha!.value =
      (0.18 + 0.7 * flare) * power * palette.beamBoost
    kit.networkUniforms.uSince.value = sinceShip
    kit.networkUniforms.uPower.value = power
    glows.add(
      GATE.start + 0.06,
      BED_TOP + 0.14,
      TRACK_Z,
      1.1,
      pink,
      (0.05 + 0.2 * flare) * power * palette.glowBoost,
    )
    glows.add(
      COUNTER.x,
      COUNTER.y + COUNTER.height / 2,
      COUNTER.z + 0.1,
      1.6,
      pink,
      0.06 * flare * power * palette.glowBoost,
    )

    // The hovered station projects its hologram: the lens lights, a fan of
    // light shoots up from it, and the screen it throws draws in from the
    // bottom, unsteady at first, then holds. Every screen floats at the same
    // height: where the page copy is laid over the scene, only as high as
    // keeps every screen clear of it. A screen is wider than the gap to the
    // next column, so a station waits for its neighbour's to go out; going
    // out takes half the time.
    this.shimmer += frame.dt
    const screenBottom = hologramBottom(frame.lift) - TOWER_TOP
    for (let index = 0; index < STATION_COUNT; index++) {
      const neighbourOn = [index - 1, index + 1].some(
        (other) => (this.projected[other] ?? 0) > SCREEN_START,
      )
      const target = highlight === index && !neighbourOn ? 1 : 0
      const current = this.projected[index]!
      const shown =
        frame.dt === 0
          ? target
          : current +
            Math.max(
              (-frame.dt * 2) / PROJECT,
              Math.min(frame.dt / PROJECT, target - current),
            )
      this.projected[index] = shown
      const hologram = this.holograms[index]!
      hologram.group.visible = shown > 0.001
      if (!hologram.group.visible) continue
      const level = range(shown, 0, 0.15)
      setLamp(hologram.lens, palette, level, pink)
      const light = hologram.fan.material.uniforms
      light.uLevel!.value = level * palette.beamBoost
      // Past 1, so the soft front of the light clears the screen's edge.
      light.uReach!.value = easeOutCubic(range(shown, 0, 0.35)) * 1.12
      light.uColor!.value.copy(pink)
      light.uTime!.value = this.shimmer
      hologram.fan.visible = screenBottom > 0.04
      hologram.fan.scale.y = Math.max(screenBottom, 0.001)
      hologram.screen.position.y = screenBottom + HOLOGRAM.height / 2
      const { uniforms } = hologram
      uniforms.uReveal.value = easeInOutCubic(range(shown, SCREEN_START, 0.85))
      uniforms.uFlicker.value = 1 - range(shown, 0.6, 1)
      uniforms.uTime.value = this.shimmer
      uniforms.uRim.value.copy(pink)
      glows.add(
        stationX(index),
        TOWER_TOP + 0.02,
        HOLOGRAM.z,
        0.5,
        pink,
        0.35 * level * palette.glowBoost,
      )
    }

    // Nameplates come on with each station's power; the bar under each shows activity.
    for (let index = 0; index < STATION_COUNT; index++) {
      const on = easeOutCubic(stationPower(introTime, index))
      const hovered = highlight === index
      const level = levels[index] ?? 0
      // A soft pulse while the machine works: the backlight swells and a status LED breathes.
      const breath = 0.5 - 0.5 * Math.cos(time * 5.2 + index * 0.9)
      this.plates.setY(index, on * (hovered ? 2 : 1 + 0.4 * level * breath))
      glows.add(
        stationX(index) + NAMEPLATE_SIZE.width / 2 - 0.07,
        FASCIA.y + NAMEPLATE_SIZE.height / 2 - 0.045,
        TOWER_FRONT + 0.02,
        0.06,
        level > 0.05 ? pink : palette.light,
        (0.18 + 0.75 * level * breath) * on * palette.glowBoost,
      )
      const x = stationX(index)
      if (hovered) {
        glows.add(
          x,
          FASCIA.y,
          TOWER_FRONT + 0.08,
          1.5,
          pink,
          0.1 * palette.glowBoost,
        )
      }
      // Work light over the product stopped at this station.
      if (palette.dark) {
        glows.add(
          x,
          PRODUCT_BASE + 0.06,
          TRACK_Z,
          1.7,
          palette.light,
          (0.03 + 0.045 * level) * on,
        )
      }
    }
    this.plates.setY(STATION_COUNT, power)
    // Extensions come on with the station they stand in front of.
    EXTENSION_IDS.forEach((id, extension) => {
      this.plates.setY(
        STATION_COUNT + 1 + extension,
        easeOutCubic(stationPower(introTime, EXTENSION_STATION[id])),
      )
    })
    this.plates.needsUpdate = true
    if (palette.dark) {
      glows.add(
        slotX(INSTALL_SLOT),
        PRODUCT_BASE + 0.06,
        TRACK_Z,
        1.7,
        palette.light,
        0.05 * installOn,
      )
    }
  }

  /** Sends this frame's screen changes to the GPU (after `update`, before rendering). */
  upload(renderer: WebGLRenderer) {
    this.console.upload(renderer)
  }

  dispose() {
    for (const geometry of this.geometries) geometry.dispose()
    for (const mesh of [this.nameplates, this.bladeLeds]) mesh.dispose()
    this.installer.dispose()
    this.counter.dispose()
    this.console.dispose()
    this.chevrons.dispose()
    for (const { screen } of this.holograms) screen.material.dispose()
  }
}
