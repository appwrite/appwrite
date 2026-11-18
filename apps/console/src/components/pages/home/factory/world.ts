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
  type FactoryKit,
  type Finish,
} from './kit'
import { createChevronMaterial } from './materials'
import type { FactoryPalette } from './palette'
import {
  DOCK,
  BED_BACK,
  BED_FRONT,
  BED_TOP,
  CORE,
  COUNTER,
  CYCLE,
  EXTENSION,
  FASCIA,
  GATE,
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
  hash01,
  rackZ,
  range,
  slotX,
  stationX,
} from './layout'
import { installPower, introStep, stationPower, type FrameState } from './frame'
import {
  APPWRITE_MARK,
  DESCRIPTION_SCREEN,
  DESCRIPTION_WIDTH,
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

/** Racks stand on a low plinth; their door is glass with the servers behind it. */
const RACK_BASE = 0.06
const RACK_TOP = RACK_BASE + RACKS.height
const LOGO_Y = RACK_BASE + RACKS.height * 0.8
const LOGO_WIDTH = 0.62
const BLADE = { pitch: 0.09, height: 0.072, leds: 4 }

/** The gate's tunnel: what the carriers ride into. */
const TUNNEL = { back: TRACK_Z - 0.46, front: TRACK_Z + 0.46, top: 0.27 }
const GATE_BACK = TUNNEL.back - 0.16
const GATE_FRONT = BED_FRONT - 0.02
const GATE_CENTER = GATE.start + GATE.length / 2
const GATE_SIGN_Y = BED_TOP + GATE.height - 0.2

/** Power cables: one from the Appwrite rack to every column, the installer last. */
const FEEDS = STATION_COUNT + 1
const CABLE_RADIUS = 0.016

const matrix = new Matrix4()
const color = new Color()

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
    },
    {
      x: BED_CENTER,
      z: BED_Z,
      width: BED_LENGTH + 0.1,
      depth: BED_DEPTH + 0.16,
      blur: 0.26,
      strength: 0.75,
    },
    {
      x: RACKS.x,
      z: (rowFront + rowBack) / 2,
      width: RACKS.depth + 1.8,
      depth: rowFront - rowBack + 1.6,
      blur: 1.2,
      strength: 0.22,
    },
    {
      x: RACKS.x,
      z: (rowFront + rowBack) / 2,
      width: RACKS.depth + 0.12,
      depth: rowFront - rowBack + 0.08,
      blur: 0.22,
      strength: 0.8,
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

/** A station's unfolding display. */
type Extension = {
  group: Group
  /** The display, its origin on the hinge: slides up out of the column's slot, then rides the mast. */
  riser: Group
  /** The mast: stored in the column under the slot, it rises with the display and slides with the hinge. */
  post: Mesh
  /** The front half, which swings open about the hinge; the back half is fixed to the mast. */
  left: Group
  screen: MeshBasicMaterial
}

/** Seconds to unfold fully (folding back takes half). */
const UNFOLD = 1
/** Unfold progress at which the front half starts to swing open. */
const OPEN_START = 0.5
/** The mast: long enough to stay seated in the column at full lift. */
const MAST = { width: 0.1, depth: 0.1, length: EXTENSION.lift + 0.2 } as const
/** The mast sits under the back half, just clear of the hinge. */
const MAST_OFFSET = 0.07
/** Where the front half's hinge axis sits, just in front of the screens so the folded halves close with a hair between them. */
const HINGE_Z = 0.006

/** A screen panel showing the part of a station's description cell between `from` and `to` (meters across the open screen). */
function describedPanel(
  index: number,
  from: number,
  to: number,
  corners: readonly [boolean, boolean, boolean, boolean],
): BufferGeometry {
  const plane = roundedPlane(
    to - from,
    DESCRIPTION_SCREEN.height,
    0.04,
    corners,
  )
  const cell = getDescriptionCell(index, STATION_COUNT)
  const uv = plane.getAttribute('uv') as BufferAttribute
  const start = from / DESCRIPTION_WIDTH + 0.5
  const end = to / DESCRIPTION_WIDTH + 0.5
  for (let vertex = 0; vertex < uv.count; vertex++) {
    uv.setXY(
      vertex,
      cell.u + (start + uv.getX(vertex) * (end - start)) * cell.du,
      cell.v + uv.getY(vertex) * cell.dv,
    )
  }
  return plane
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
  private readonly chevrons = createChevronMaterial()
  private readonly curtain: ReturnType<typeof createKitBeam>
  private readonly feeds: Feed[] = []
  private readonly extensions: Extension[] = []
  /** Per station, 0 folded → 1 open; moves at a constant rate toward its target. */
  private readonly unfold: number[] = new Array(STATION_COUNT).fill(0)
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
    const add = (
      key: PartKey,
      geometry: BufferGeometry,
      x: number,
      y: number,
      z: number,
    ) => {
      parts[key].push(geometry.translate(x, y, z))
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

    // The foldable displays, one per station, stored folded inside its column.
    const { leaf, thickness, height } = EXTENSION
    const { crease } = DESCRIPTION_SCREEN
    const leafScreen = DESCRIPTION_SCREEN.leaf
    const half = (sign: number) => ({
      // Each half lies on its side of the hinge, its front face at z = 0.
      body: track(
        ground(
          panel(leaf, height, thickness, 0.06).translate(
            (sign * leaf) / 2,
            height / 2,
            -thickness,
          ),
          -100,
          1,
          0,
        ),
      ),
      face: track(
        panel(leaf - 0.012, height - 0.024, 0.004, 0.052).translate(
          (sign * (leaf - 0.012)) / 2 + sign * 0.002,
          height / 2,
          0,
        ),
      ),
    })
    const halves = { left: half(-1), right: half(1) }
    const spine = track(
      cylinder(thickness * 0.85, height - 0.02, 20).translate(
        0,
        height / 2,
        -thickness,
      ),
    )
    const mast = track(
      box(MAST.width, MAST.length, MAST.depth, 0.012, 1).translate(
        0,
        MAST.length / 2,
        0,
      ),
    )
    for (let index = 0; index < STATION_COUNT; index++) {
      const group = new Group()
      group.position.set(stationX(index), TOWER_TOP - 0.01, EXTENSION.z)
      group.visible = false
      const post = new Mesh(mast, kit.aluminum)
      group.add(post)
      const riser = new Group()
      riser.add(new Mesh(spine, kit.aluminum))
      const screen = createKitDisplay(kit, descriptionTexture)
      const hinge = (sign: number) => {
        const leafGroup = new Group()
        // The front half turns about an axis just in front of the screens.
        const pivot = sign < 0 ? HINGE_Z : 0
        leafGroup.position.z = pivot
        const parts = sign < 0 ? halves.left : halves.right
        const body = new Mesh(parts.body, kit.enclosure)
        const face = new Mesh(parts.face, kit.matte)
        body.position.z = -pivot
        face.position.z = -pivot
        leafGroup.add(body, face)
        const glass = new Mesh(
          track(
            sign < 0
              ? describedPanel(index, -(crease + leafScreen), -crease, [
                  true,
                  false,
                  false,
                  true,
                ])
              : describedPanel(index, crease, crease + leafScreen, [
                  false,
                  true,
                  true,
                  false,
                ]),
          ),
          screen,
        )
        glass.position.set(
          sign * (crease + leafScreen / 2),
          height / 2,
          0.0055 - pivot,
        )
        leafGroup.add(glass)
        riser.add(leafGroup)
        return leafGroup
      }
      const left = hinge(-1)
      hinge(1)
      group.add(riser)
      this.group.add(group)
      this.extensions.push({ group, riser, post, left, screen })
      // The slot in the column's top the display and its mast come out of.
      add(
        'matte',
        plain(leaf + 0.03, 0.004, 0.14),
        stationX(index),
        TOWER_TOP + 0.001,
        EXTENSION.z - 0.005,
      )
    }

    // The framework installer: a column like the others, with a display where the nameplate goes.
    const installX = slotX(INSTALL_SLOT)
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
    const lcd = new Mesh(
      track(roundedPlane(FASCIA.width - 0.05, FASCIA.height - 0.05, 0.035)),
      createKitDisplay(kit, this.installer.texture),
    )
    lcd.position.set(installX, FASCIA.y, TOWER_FRONT + 0.0105)
    this.group.add(lcd)

    // The server aisle: racks one after another, doors (glass, servers behind) facing the camera's side.
    const blades: { y: number; z: number }[] = []
    for (let rack = 0; rack < RACKS.count; rack++) {
      const z = rackZ(rack)
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
        blades.push({ y, z })
      }
    }
    // The Appwrite rack's end panel, facing the line: black glass, the logo set into it.
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
    const wallCenter = WALL.y + WALL.height / 2
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
    column(COUNTER.x, false)
    add(
      'matte',
      panel(COUNTER.width + 0.05, COUNTER.height + 0.05, 0.012, 0.055),
      COUNTER.x,
      COUNTER.y + COUNTER.height / 2,
      COUNTER.z - 0.002,
    )
    this.counter = new CounterDisplay(kit.palette, counterLabel)
    const screen = new Mesh(
      track(roundedPlane(COUNTER.width, COUNTER.height, 0.04)),
      createKitDisplay(kit, this.counter.texture),
    )
    screen.position.set(
      COUNTER.x,
      COUNTER.y + COUNTER.height / 2,
      COUNTER.z + 0.0105,
    )
    this.group.add(screen)

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
    const feeds = new Mesh(track(merge(tubes)), kit.cable)
    feeds.userData.noReflection = true
    this.group.add(feeds)

    // The network gate: a housing with a tunnel the carriers ride into.
    const gateTop = BED_TOP + GATE.height
    const tunnelTop = BED_TOP + TUNNEL.top
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
      TRACK_Z,
    )
    // Dark tunnel lining and a trim frame around its mouth.
    add(
      'matte',
      plain(GATE.length - 0.08, 0.01, TUNNEL.front - TUNNEL.back),
      GATE_CENTER + 0.03,
      tunnelTop - 0.006,
      TRACK_Z,
    )
    add(
      'matte',
      plain(0.02, TUNNEL.top, TUNNEL.front - TUNNEL.back),
      GATE_END - 0.06,
      BED_TOP + TUNNEL.top / 2,
      TRACK_Z,
    )
    add(
      'aluminum',
      box(0.03, 0.03, TUNNEL.front - TUNNEL.back + 0.06, 0.008, 1),
      GATE.start - 0.005,
      tunnelTop,
      TRACK_Z,
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
    // Gland plate low on the base's end, where the network cables leave.
    add(
      'aluminum',
      box(0.03, 0.15, 1.32, 0.008, 1),
      BED_END_X + 0.012,
      0.075,
      TRACK_Z,
    )
    // Display on the gate's front, and the chevron strip under it.
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
      BED_TOP + 0.34,
      GATE_FRONT - 0.001,
    )
    const chevrons = new Mesh(
      track(new PlaneGeometry(GATE.length - 0.24, 0.05)),
      this.chevrons,
    )
    chevrons.position.set(GATE_CENTER, BED_TOP + 0.34, GATE_FRONT + 0.0075)
    this.group.add(chevrons)

    this.curtain = createKitBeam(kit)
    const curtain = new Mesh(
      track(
        new PlaneGeometry(TUNNEL.front - TUNNEL.back, TUNNEL.top).rotateY(
          Math.PI / 2,
        ),
      ),
      this.curtain,
    )
    curtain.position.set(GATE.start + 0.03, BED_TOP + TUNNEL.top / 2, TRACK_Z)
    curtain.renderOrder = 8
    this.group.add(curtain)

    const network = new Mesh(track(createNetworkCables()), kit.networkCable)
    network.userData.noReflection = true
    this.group.add(network)

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
        this.bladeLeds.setMatrixAt(
          slot,
          matrix.makeTranslation(
            RACKS_DOOR - 0.0165,
            blade.y,
            blade.z + 0.2 - led * 0.045,
          ),
        )
        this.bladeLeds.setColorAt(slot, color.set(0x000000))
        this.bladeSeeds.push(hash01(slot * 1.37 + 0.5))
      }
    })

    // Names printed on the station panels, and the gate's sign (the last row).
    const plate = track(
      roundedPlane(NAMEPLATE_SIZE.width, NAMEPLATE_SIZE.height, 0.035),
    )
    this.plates = new InstancedBufferAttribute(
      new Float32Array((STATION_COUNT + 1) * 2),
      2,
    ).setUsage(DynamicDrawUsage)
    plate.setAttribute('aPlate', this.plates)
    kit.nameplate.uniforms.uMap!.value = nameplateTexture
    kit.nameplate.uniforms.uRows!.value = STATION_COUNT + 1
    this.nameplates = new InstancedMesh(plate, kit.nameplate, STATION_COUNT + 1)
    this.nameplates.renderOrder = 3

    for (let index = 0; index < STATION_COUNT; index++) {
      const x = stationX(index)
      this.plates.setXY(index, index, 0)
      this.nameplates.setMatrixAt(
        index,
        matrix.makeTranslation(x, FASCIA.y, TOWER_FRONT + 0.0105),
      )
    }
    this.plates.setXY(STATION_COUNT, STATION_COUNT, 0)
    this.nameplates.setMatrixAt(
      STATION_COUNT,
      matrix.makeTranslation(GATE_CENTER, GATE_SIGN_Y, GATE_FRONT + 0.0105),
    )
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
    for (const extension of this.extensions) {
      extension.screen.map = texture
      extension.screen.needsUpdate = true
    }
  }

  setCounterLabel(label: string) {
    this.counter.setLabel(label)
  }

  setPalette(palette: FactoryPalette) {
    this.console.setPalette(palette)
    this.installer.setPalette(palette)
    this.counter.setPalette(palette)
  }

  update(frame: FrameState) {
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

    // The hovered station unfolds: its display slides up out of the slot in
    // the column's top, folded; the mast lifts it; the front half swings open
    // about the hinge while the mast slides along the slot to centre the
    // screen, and the screen comes on. Every mast rises to the same height:
    // where the page copy is laid over the scene, only as far as keeps every
    // open display clear of it. An open display reaches over the next
    // column, so a display stays in its column until the one beside it has
    // closed; folding runs twice as fast as unfolding, so the neighbour is
    // back inside before this one opens.
    for (let index = 0; index < STATION_COUNT; index++) {
      const neighbourOpen = [index - 1, index + 1].some(
        (other) => (this.unfold[other] ?? 0) > OPEN_START,
      )
      const target = highlight === index && !neighbourOpen ? 1 : 0
      const current = this.unfold[index]!
      const unfold =
        frame.dt === 0
          ? target
          : current +
            Math.max(
              (-frame.dt * 2) / UNFOLD,
              Math.min(frame.dt / UNFOLD, target - current),
            )
      this.unfold[index] = unfold
      const extension = this.extensions[index]!
      extension.group.visible = unfold > 0.001
      if (!extension.group.visible) continue
      const rise = easeInOutCubic(range(unfold, 0, 0.3))
      const lift = easeInOutCubic(range(unfold, 0.26, OPEN_START))
      const open = easeInOutCubic(range(unfold, OPEN_START, 0.86))
      // Stored, the display's top sits just under the slot.
      const bottom =
        -(1 - rise) * (EXTENSION.height + 0.03) +
        lift * EXTENSION.lift * frame.lift
      // Folded, the hinge is at the stack's left edge, centring it over the slot.
      const hinge = -(1 - open) * (EXTENSION.leaf / 2)
      extension.riser.position.set(hinge, bottom, 0)
      // The mast waits under the slot until the display's foot reaches it.
      extension.post.position.set(
        hinge + MAST_OFFSET,
        Math.max(bottom, 0) - MAST.length,
        0,
      )
      // Folded, the front half lies over the back one, screens facing; it swings open toward the camera.
      extension.left.rotation.y = (1 - open) * Math.PI
      extension.screen.color.setScalar(range(unfold, 0.84, 1))
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
  }
}
