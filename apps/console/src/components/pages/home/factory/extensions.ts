import {
  BufferAttribute,
  BufferGeometry,
  CanvasTexture,
  Group,
  LinearFilter,
  LinearMipmapLinearFilter,
  Mesh,
  PlaneGeometry,
  SRGBColorSpace,
} from 'three'
import { ARRIVALS, arrive } from './assembly'
import { DEVICE_SIZES } from './devices'
import { stationPower, type FrameState } from './frame'
import { box, cylinder } from './geometry'
import {
  createKitBeam,
  createKitDisplay,
  createKitLamp,
  setLamp,
  type FactoryKit,
} from './kit'
import {
  EXTENSION_IDS,
  EXTENSION_STATION,
  PRODUCT_BASE,
  TRACK_Z,
  UNIT_HEAD,
  extensionX,
  getProductKind,
  getStationStart,
  hash01,
  lerp,
  range,
  travel,
  type FactoryExtensionId,
} from './layout'
import { Assembly, type Track } from './stations'

/**
 * The moving half of every extension (its cabinet is part of the world): a
 * head on a tilt mount at the top of the cabinet's post. Once its station is
 * done with the app stopped at the bay, the head turns to the app, works on
 * it, and turns back before the line moves. Both heads are real instruments,
 * a cinema camera for Videos and a laser line scanner for Analytics, and
 * each shows what it is doing on a small screen on its back.
 */

type ExtensionContext = {
  /** Seconds since its station started working this cycle (negative before). */
  tau: number
  cycle: number
  /** Top of the app stopped at the bay, world y. */
  top: number
  width: number
  depth: number
  /** 0 → 1 as its station powers up. */
  power: number
}

interface ExtensionRig {
  readonly group: Group
  update(frame: FrameState, context: ExtensionContext): void
  dispose(): void
}

type Shop = { kit: FactoryKit; track: Track }

/** How far the head stands in front of the app's center, across the floor. */
const REACH = UNIT_HEAD.z - TRACK_Z

/** How far a head looking at height `y`, `distance` away across the floor, tips down from level. */
function tiltTo(y: number, distance: number) {
  return Math.atan2(UNIT_HEAD.y - y, distance)
}

/** The pivot a head tilts on: on the post, facing the bay, at the head's size. */
function createPivot() {
  const pivot = new Group()
  pivot.position.set(0, UNIT_HEAD.y, UNIT_HEAD.z)
  pivot.scale.setScalar(UNIT_HEAD.scale)
  return pivot
}

/** The head's tilt when it is not looking at anything: just below level. */
const REST_TILT = 0.08

/**
 * The fork the head turns in: a base on the post and a cheek on either side
 * of the head's hub, `gap` from its middle.
 */
function mount(gap: number) {
  const fork = new Assembly().add(
    'aluminum',
    box(2 * gap + 0.04, 0.022, 0.07, 0.006, 2),
    0,
    -0.05,
    0,
  )
  for (const side of [-1, 1]) {
    fork
      .add(
        'aluminum',
        box(0.014, 0.08, 0.05, 0.004, 1),
        side * (gap + 0.007),
        -0.01,
        0,
      )
      .add(
        'steel',
        cylinder(0.017, 0.012, 20).rotateZ(Math.PI / 2),
        side * (gap + 0.02),
        0,
        0,
      )
  }
  return fork
}

/** A small backlit screen on a head's back, drawn on a canvas as it changes. */
class Readout {
  readonly texture: CanvasTexture
  readonly context: CanvasRenderingContext2D | null
  private shown = ''

  constructor(
    readonly width: number,
    readonly height: number,
  ) {
    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    this.context = canvas.getContext('2d')
    this.texture = new CanvasTexture(canvas)
    this.texture.colorSpace = SRGBColorSpace
    this.texture.generateMipmaps = true
    this.texture.minFilter = LinearMipmapLinearFilter
    this.texture.magFilter = LinearFilter
  }

  /** Redraws only when `key` (what the screen shows) changes. */
  draw(key: string, paint: (context: CanvasRenderingContext2D) => void) {
    if (key === this.shown || !this.context) return
    this.shown = key
    const { context } = this
    context.globalAlpha = 1
    context.fillStyle = '#0b0c0e'
    context.fillRect(0, 0, this.width, this.height)
    paint(context)
    this.texture.needsUpdate = true
  }
}

/**
 * Videos: a cinema camera. It turns to the app once Storage has placed its
 * files and records it; the monitor on its back shows the take.
 */
function videos({ kit, track }: Shop): ExtensionRig {
  const group = new Group()
  const pivot = createPivot()
  group.add(pivot)
  mount(0.068).build(kit, track, pivot)

  const head = new Group()
  pivot.add(head)
  const lens = (radius: number, length: number) =>
    cylinder(radius, length, 32).rotateX(Math.PI / 2)
  new Assembly()
    .add('graphite', box(0.13, 0.11, 0.21, 0.018, 3), 0, 0, 0.01)
    // Top handle.
    .add('aluminum', box(0.03, 0.018, 0.13, 0.006, 2), 0, 0.083, 0)
    .add('aluminum', box(0.02, 0.026, 0.02, 0.004, 1), 0, 0.066, -0.05)
    .add('aluminum', box(0.02, 0.026, 0.02, 0.004, 1), 0, 0.066, 0.05)
    // Lens: mount, barrel, focus ring, front ring.
    .add('steel', lens(0.05, 0.016), 0, 0, -0.102)
    .add('graphite', lens(0.044, 0.11), 0, 0, -0.165)
    .add('matte', lens(0.047, 0.032), 0, 0, -0.15)
    .add('aluminum', lens(0.047, 0.01), 0, 0, -0.218)
    // Battery and the monitor's frame on the back.
    .add('matte', box(0.11, 0.08, 0.04, 0.01, 2), 0, -0.004, 0.134)
    .add('graphite', box(0.112, 0.074, 0.01, 0.006, 1), 0, 0.002, 0.159)
    .build(kit, track, head)
  const glass = new Mesh(track(lens(0.038, 0.004)), kit.glass)
  glass.position.z = -0.222
  head.add(glass)
  const tally = createKitLamp(kit)
  const light = new Mesh(track(box(0.022, 0.01, 0.01, 0.003, 1)), tally)
  light.position.set(0.036, 0.059, -0.08)
  head.add(light)

  const readout = new Readout(256, 168)
  const monitor = new Mesh(
    track(new PlaneGeometry(0.1, 0.0656)),
    createKitDisplay(kit, readout.texture),
  )
  monitor.position.set(0, 0.002, 0.1645)
  head.add(monitor)

  /** Seconds of each take, after Storage has placed the app's files. */
  const take = { start: 0.66, end: 1.1 }

  return {
    group,
    update(frame, context) {
      const { tau, top, power, cycle } = context
      const { palette, glows } = frame
      const aim = tiltTo(top, REACH)
      const toward = travel(tau, 0.05, 0.35) * (1 - travel(tau, 1.12, 1.24))
      head.rotation.x = -lerp(REST_TILT, aim, toward)

      const rolling =
        range(tau, take.start, take.start + 0.04) *
        (1 - range(tau, take.end, take.end + 0.04))
      setLamp(
        tally,
        palette,
        (0.06 + 0.94 * rolling) * power,
        palette.tones.pink,
      )
      if (rolling > 0) {
        // The tally light, on top of the body near the lens.
        glows.add(
          extensionX('videos') + 0.036 * UNIT_HEAD.scale,
          UNIT_HEAD.y + 0.06 * UNIT_HEAD.scale,
          UNIT_HEAD.z - 0.08 * UNIT_HEAD.scale,
          0.08,
          palette.tones.pink,
          0.6 * rolling * power,
        )
      }

      // The monitor: standing by, or recording with the take filling in.
      const progress = range(tau, take.start, take.end)
      const recording = rolling > 0.5
      const level = Math.round(power * 6)
      readout.draw(
        `${recording ? Math.round(progress * 24) : -1}:${level}:${palette.dark}`,
        (draw) => {
          const { width, height } = readout
          draw.globalAlpha = level / 6
          const pink = palette.toneHex.pink
          draw.fillStyle = recording ? pink : 'rgba(255,255,255,0.32)'
          draw.beginPath()
          if (recording) draw.arc(46, 52, 15, 0, Math.PI * 2)
          else draw.rect(32, 38, 28, 28)
          draw.fill()
          draw.fillStyle = recording ? '#f2f3f6' : 'rgba(255,255,255,0.45)'
          draw.font = "600 38px 'Aeonik Pro', Inter, system-ui, sans-serif"
          draw.textBaseline = 'middle'
          draw.fillText(recording ? 'REC' : 'STBY', 76, 54)
          // The take so far, and the takes before it.
          draw.fillStyle = 'rgba(255,255,255,0.14)'
          draw.fillRect(32, height - 52, width - 64, 10)
          draw.fillStyle = recording ? pink : 'rgba(255,255,255,0.3)'
          draw.fillRect(
            32,
            height - 52,
            (width - 64) * (recording ? progress : hash01(cycle) * 0.2 + 0.8),
            10,
          )
        },
      )
    },
    dispose() {
      readout.texture.dispose()
    },
  }
}

/**
 * Analytics: a laser line scanner. Once Sites has put the app live it
 * sweeps a line of light across it, front to back; the screen on its back
 * charts each app it has scanned, the newest growing as the line passes.
 */
function analytics({ kit, track }: Shop): ExtensionRig {
  const group = new Group()
  const pivot = createPivot()
  group.add(pivot)
  mount(0.034).build(kit, track, pivot)

  const head = new Group()
  pivot.add(head)
  const bar = { width: 0.4, height: 0.06, depth: 0.08, z: -0.06 }
  new Assembly()
    // The hub in the fork, and the bar it carries in front of it.
    .add('graphite', box(0.06, 0.05, 0.05, 0.008, 2), 0, 0, 0)
    .add(
      'graphite',
      box(bar.width, bar.height, bar.depth, 0.014, 3),
      0,
      0,
      bar.z,
    )
    .add(
      'aluminum',
      box(0.018, bar.height + 0.004, bar.depth + 0.004, 0.004, 1),
      -bar.width / 2 - 0.009,
      0,
      bar.z,
    )
    .add(
      'aluminum',
      box(0.018, bar.height + 0.004, bar.depth + 0.004, 0.004, 1),
      bar.width / 2 + 0.009,
      0,
      bar.z,
    )
    .build(kit, track, head)
  // The emitter window along the bar's face.
  const emitter = createKitLamp(kit)
  const face = new Mesh(track(box(0.34, 0.016, 0.004, 0.002, 1)), emitter)
  face.position.set(0, -0.006, bar.z - bar.depth / 2 - 0.001)
  head.add(face)

  // The chart on the bar's back, above the hub.
  const readout = new Readout(320, 96)
  const screen = new Mesh(
    track(new PlaneGeometry(0.18, 0.054)),
    createKitDisplay(kit, readout.texture),
  )
  screen.position.set(0, 0.002, bar.z + bar.depth / 2 + 0.0015)
  head.add(screen)

  // The sheet of laser light, from the window to the line on the app.
  const sheet = new BufferGeometry()
  const corners = new Float32Array(12)
  sheet.setAttribute('position', new BufferAttribute(corners, 3))
  sheet.setAttribute(
    'uv',
    new BufferAttribute(new Float32Array([0, 1, 1, 1, 1, 0, 0, 0]), 2),
  )
  sheet.setIndex([0, 3, 2, 0, 2, 1])
  const sheetMaterial = createKitBeam(kit)
  const laser = new Mesh(track(sheet), sheetMaterial)
  laser.frustumCulled = false
  laser.renderOrder = 8
  const lineMaterial = createKitLamp(kit)
  const line = new Mesh(
    track(new PlaneGeometry(1, 0.008).rotateX(-Math.PI / 2)),
    lineMaterial,
  )
  for (const mesh of [laser, line]) {
    mesh.userData.castsNoShadow = true
    mesh.userData.receivesNoShadow = true
    group.add(mesh)
  }

  /** Seconds of each scan, after Sites has cured the app. */
  const scan = { start: 0.95, end: 1.35 }

  return {
    group,
    update(frame, context) {
      const { tau, top, width, depth, power, cycle } = context
      const { palette, glows } = frame
      // How far from the pivot the app's front and back edges are.
      const near = REACH - depth / 2
      const far = REACH + depth / 2
      const sweep = travel(tau, scan.start, scan.end)
      const distance = lerp(near, far, sweep)
      const scanning =
        range(tau, scan.start - 0.04, scan.start) *
        (1 - range(tau, scan.end, scan.end + 0.04))
      // Tip down to the app's front edge, follow the line across, tip back.
      const toward = travel(tau, 0.1, 0.4) * (1 - travel(tau, 1.36, 1.45))
      const aim = tiltTo(top, tau < scan.start ? near : distance)
      const tilt = lerp(REST_TILT, aim, toward)
      head.rotation.x = -tilt

      // The line on the app: across its width, `distance` out.
      const lineZ = UNIT_HEAD.z - distance

      const pink = palette.tones.pink
      setLamp(emitter, palette, (0.08 + 0.92 * scanning) * power, pink)
      laser.visible = scanning > 0.01
      line.visible = laser.visible
      if (laser.visible) {
        // The window, turned with the head: its middle, then its ends.
        const cos = Math.cos(-tilt)
        const sin = Math.sin(-tilt)
        const wy = -0.006 * UNIT_HEAD.scale
        const wz = (bar.z - bar.depth / 2 - 0.002) * UNIT_HEAD.scale
        const reach = 0.16 * UNIT_HEAD.scale
        const y = UNIT_HEAD.y + wy * cos - wz * sin
        const z = UNIT_HEAD.z + wy * sin + wz * cos
        const lineY = top + 0.002
        corners.set([
          -reach,
          y,
          z,
          reach,
          y,
          z,
          width / 2,
          lineY,
          lineZ,
          -width / 2,
          lineY,
          lineZ,
        ])
        sheet.attributes.position!.needsUpdate = true
        sheetMaterial.uniforms.uColor!.value.copy(pink)
        sheetMaterial.uniforms.uAlpha!.value =
          scanning * 0.3 * palette.beamBoost * power
        line.position.set(0, top + 0.0025, lineZ)
        line.scale.x = width
        setLamp(lineMaterial, palette, scanning * power, pink)
        glows.add(
          extensionX('analytics'),
          top + 0.01,
          lineZ,
          0.45,
          pink,
          0.35 * scanning * power,
        )
      }

      // The chart: one bar per app scanned, the newest growing with the line.
      const newest = tau >= scan.start ? Math.round(sweep * 12) : -1
      const level = Math.round(power * 6)
      readout.draw(`${cycle}:${newest}:${level}`, (draw) => {
        const bars = 10
        const pad = 16
        const step = (readout.width - pad * 2) / bars
        const floor = readout.height - 14
        draw.globalAlpha = level / 6
        for (let index = 0; index < bars; index++) {
          const age = bars - 1 - index
          const current = age === 0
          if (current && newest < 0) continue
          const value = 0.25 + 0.7 * hash01(cycle - age + 7)
          const grown = current ? value * (newest / 12) : value
          const height = (floor - 14) * grown
          draw.fillStyle = current
            ? palette.toneHex.pink
            : 'rgba(196,198,215,0.55)'
          draw.fillRect(
            pad + index * step + 4,
            floor - height,
            step - 8,
            height,
          )
        }
      })
    },
    dispose() {
      readout.texture.dispose()
    },
  }
}

const BUILDERS: Record<FactoryExtensionId, (shop: Shop) => ExtensionRig> = {
  videos,
  analytics,
}

export class ExtensionLine {
  readonly group = new Group()
  private readonly rigs: { id: FactoryExtensionId; rig: ExtensionRig }[]
  private readonly geometries: BufferGeometry[] = []

  constructor(kit: FactoryKit) {
    const track: Track = (geometry) => {
      this.geometries.push(geometry)
      return geometry
    }
    this.rigs = EXTENSION_IDS.map((id) => {
      const rig = BUILDERS[id]({ kit, track })
      rig.group.position.x = extensionX(id)
      this.group.add(rig.group)
      return { id, rig }
    })
  }

  update(frame: FrameState) {
    const { line, assembly } = frame
    for (const { id, rig } of this.rigs) {
      const station = EXTENSION_STATION[id]
      // In front of its station's bay, it arrives with it.
      arrive(
        rig.group.position,
        extensionX(id),
        0,
        0,
        assembly,
        ARRIVALS.station(station),
      )
      // Product `cycle - station` stops at the station during this cycle.
      const size = DEVICE_SIZES[getProductKind(line.cycle - station)]
      rig.update(frame, {
        tau: frame.time - getStationStart(station, line.cycle),
        cycle: line.cycle,
        top: PRODUCT_BASE + size.thickness,
        width: size.width,
        depth: size.depth,
        power: stationPower(frame.introTime, station),
      })
    }
  }

  dispose() {
    for (const { rig } of this.rigs) rig.dispose()
    for (const geometry of this.geometries) geometry.dispose()
  }
}
