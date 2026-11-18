import {
  BufferAttribute,
  CanvasTexture,
  CatmullRomCurve3,
  CircleGeometry,
  Group,
  LinearFilter,
  LinearMipmapLinearFilter,
  Matrix4,
  Mesh,
  MeshBasicMaterial,
  PlaneGeometry,
  TorusGeometry,
  Vector3,
  type BufferGeometry,
} from 'three'
import type { FrameState } from './frame'
import { loadIcon } from './frameworks'
import { box, cylinder, merge, mergeFinishes, place, slab } from './geometry'
import { brandFontStack } from './textures'
import { FINISHES, createKitDisplay, type FactoryKit, type Finish } from './kit'
import {
  CYCLE,
  DOCK,
  bump,
  easeInOutCubic,
  getInstallStart,
  range,
} from './layout'

/**
 * The models whose instructions the line builds from, one drone each: the
 * drones take turns flying a model's instruction card in and plugging it
 * into the framework installer, just before it starts on the next product.
 */
const MODELS = ['claude.svg', 'chatgpt.svg', 'grok-build.svg'] as const

/** Seconds before the installer starts that a card is home in the dock. */
const LEAD = 0.15

/**
 * A trip, in seconds from the moment the card is home: the drone leaves the
 * sky, waits at the holding point beside the dock while the drone before it
 * clears out, moves in to hover over the dock, settles, plugs the card in,
 * lifts back to the hover point and is gone. One drone flies every
 * `MODELS.length` cycles, so a trip fits in that.
 */
const TRIP = {
  arrive: -3.6,
  hold: -1.15,
  hover: -0.72,
  dock: -0.42,
  plug: 0,
  lift: 0.3,
  gone: 3.15,
} as const

/**
 * The card: hung `hang` under the gimbal, which sits `mount` under the
 * drone's body; it sinks its own height into the dock's slot to go home.
 */
const CARD = { width: 0.46, height: 0.3, hang: 0.11, mount: 0.08 } as const

/** Drones are modelled at this size and flown a quarter larger, so they read across the page. */
const SCALE = 1.25

/** Where the drone's body is while its card is plugged in: the card's foot just over the slot. */
const DOCKED = new Vector3(
  DOCK.x,
  DOCK.y + (CARD.mount + CARD.hang + CARD.height) * SCALE + 0.01,
  DOCK.z,
)
const HOVER = DOCKED.clone().add(new Vector3(0, 0.32, 0))

/** Beside the dock, on the near side, just behind the arrivals' lane: where a drone waits its turn. */
const HOLD = new Vector3(DOCK.x - 2.2, 2.05, -0.35)

/**
 * In from beyond the near end of the line, where it starts, low and a
 * little behind the queue of products (under the page copy laid over that
 * end), to the holding point: a short hop that stays clear of the rest of
 * the line.
 */
const ARRIVAL = new CatmullRomCurve3([
  new Vector3(DOCK.x - 11, 2.6, -1.6),
  new Vector3(DOCK.x - 6, 2.3, -0.8),
  new Vector3(DOCK.x - 3.6, 2.1, -0.4),
  HOLD,
])

/**
 * Back out the way it came, in a lane of its own: forward and low, nearer
 * the camera than the arrivals'.
 */
const DEPARTURE = new CatmullRomCurve3([
  HOVER,
  new Vector3(DOCK.x - 1.2, 1.95, 0.8),
  new Vector3(DOCK.x - 5, 1.8, 1.4),
  new Vector3(DOCK.x - 11, 2.2, 1.8),
])

/** Rotor centers, on the arms' diagonals. */
const ROTORS = [-1, 1].flatMap((sx) =>
  [-1, 1].map((sz) => new Vector3(sx * 0.25, 0.02, sz * 0.19)),
)

const position = new Vector3()
const ahead = new Vector3()
const velocity = new Vector3()

type Drone = {
  root: Group
  body: Group
  /** The tether the card hangs from; it pays out as the card goes home. */
  tether: Mesh
  /** Gimbal-stabilized: the card stays level and facing the camera whatever the body does. */
  card: Group
}

/** The instruction carriers: quadcopters with rotor guards, status lights and a card on a gimbal. */
export class DroneFleet {
  readonly group = new Group()
  private readonly drones: Drone[] = []
  private readonly geometries: BufferGeometry[] = []
  private readonly marks: CanvasTexture
  private images: readonly (HTMLImageElement | null)[] = []
  private readonly rotorMaterial = new MeshBasicMaterial({
    color: 0x9aa0ad,
    transparent: true,
    opacity: 0.16,
    depthWrite: false,
  })

  constructor(
    kit: FactoryKit,
    /** What the cards say they carry (translated), over the model's mark. */
    private label: string,
    private rtl: boolean,
  ) {
    const track = <T extends BufferGeometry>(geometry: T) => {
      this.geometries.push(geometry)
      return geometry
    }
    const at = (x: number, y: number, z: number) =>
      new Matrix4().makeTranslation(x, y, z)

    // One body for every drone: graphite hull, aluminum canopy and guards.
    const parts: Partial<Record<Finish, BufferGeometry[]>> = {
      graphite: [box(0.3, 0.07, 0.22, 0.03, 3)],
      aluminum: [
        place(slab(0.2, 0.14, 0.025, 0.05), at(0, 0.035, 0)),
        ...ROTORS.map((rotor) =>
          place(
            new TorusGeometry(0.115, 0.008, 6, 36).rotateX(Math.PI / 2),
            at(rotor.x, rotor.y + 0.035, rotor.z),
          ),
        ),
      ],
      steel: [
        ...ROTORS.map((rotor) => {
          const length = Math.hypot(rotor.x, rotor.z)
          return place(
            box(length, 0.022, 0.028, 0.008, 1).rotateY(
              -Math.atan2(rotor.z, rotor.x),
            ),
            at(rotor.x / 2, rotor.y, rotor.z / 2),
          )
        }),
        place(cylinder(0.018, 0.05, 12), at(0, -0.055, 0)),
      ],
      matte: ROTORS.map((rotor) =>
        place(
          cylinder(0.026, 0.045, 16),
          at(rotor.x, rotor.y + 0.012, rotor.z),
        ),
      ),
      accent: [place(box(0.12, 0.012, 0.01, 0.004, 1), at(0, 0.005, 0.111))],
    }
    const hull = track(mergeFinishes(parts, FINISHES))
    const rotors = track(
      merge(
        ROTORS.map((rotor) =>
          place(
            new CircleGeometry(0.104, 28).rotateX(-Math.PI / 2),
            at(rotor.x, rotor.y + 0.045, rotor.z),
          ),
        ),
      ),
    )
    // The card: a graphite frame on a short tether, a lit face showing the model's mark.
    const frame = track(
      box(CARD.width, CARD.height, 0.014, 0.02, 2).translate(
        0,
        -CARD.height / 2,
        0,
      ),
    )
    const tether = track(cylinder(0.005, 1, 6).translate(0, -0.5, 0))

    const canvas = document.createElement('canvas')
    canvas.width = 384 * MODELS.length
    canvas.height = 240
    this.marks = new CanvasTexture(canvas)
    this.marks.minFilter = LinearMipmapLinearFilter
    this.marks.magFilter = LinearFilter
    drawMarks(canvas, [], label, rtl)
    const face = createKitDisplay(kit, this.marks)

    MODELS.forEach((_, index) => {
      const root = new Group()
      root.visible = false
      root.scale.setScalar(SCALE)
      const body = new Group()
      body.rotation.order = 'YXZ'
      body.add(new Mesh(hull, kit.finish))
      const blur = new Mesh(rotors, this.rotorMaterial)
      blur.userData.castsNoShadow = true
      body.add(blur)
      root.add(body)

      const payload = new Group()
      payload.position.y = -CARD.mount
      const line = new Mesh(tether, kit.finish)
      line.scale.y = CARD.hang
      const card = new Group()
      card.position.y = -CARD.hang
      card.add(new Mesh(frame, kit.finish))
      const glass = track(
        new PlaneGeometry(CARD.width - 0.03, CARD.height - 0.03),
      )
      // This drone's model: its cell of the marks atlas.
      const uv = glass.getAttribute('uv') as BufferAttribute
      for (let vertex = 0; vertex < uv.count; vertex++)
        uv.setX(vertex, (index + uv.getX(vertex)) / MODELS.length)
      const shown = new Mesh(glass, face)
      shown.position.set(0, -CARD.height / 2, 0.0075)
      shown.userData.castsNoShadow = true
      card.add(shown)
      payload.add(line, card)
      root.add(payload)
      this.group.add(root)
      this.drones.push({ root, body, tether: line, card })
    })
  }

  /** Draws each model's mark onto the cards (they show just their label until then). */
  async showMarks() {
    this.images = await Promise.all(MODELS.map((file) => loadIcon(file)))
    this.redraw()
  }

  /** Relabels the cards (language changed). */
  setLabel(label: string, rtl: boolean) {
    if (label === this.label && rtl === this.rtl) return
    this.label = label
    this.rtl = rtl
    this.redraw()
  }

  private redraw() {
    drawMarks(
      this.marks.image as HTMLCanvasElement,
      this.images,
      this.label,
      this.rtl,
    )
    this.marks.needsUpdate = true
  }

  update(frame: FrameState) {
    const { time, glows, palette } = frame
    // The drone on its way to (or back from) each delivery: the latest
    // deliveries around now, by cycle.
    const first = getInstallStart(0) - LEAD
    const current = Math.floor((time - first) / CYCLE)
    this.drones.forEach((drone) => {
      drone.root.visible = false
    })
    for (let cycle = current - 2; cycle <= current + 2; cycle++) {
      const index = ((cycle % MODELS.length) + MODELS.length) % MODELS.length
      const drone = this.drones[index]!
      const since = time - (getInstallStart(cycle) - LEAD)
      if (since < TRIP.arrive || since > TRIP.gone) continue
      drone.root.visible = true

      flight(since, position)
      flight(since + 0.04, ahead)
      velocity.subVectors(ahead, position).divideScalar(0.04)
      // A slight float while hovering, gone in fast flight.
      position.y += 0.012 * Math.sin(time * 3.1 + index * 2.1)
      drone.root.position.copy(position)

      // Nose into the flight, and lean into it the faster it goes.
      const speed = Math.hypot(velocity.x, velocity.z)
      const heading = Math.min(speed / 1.2, 1)
      const forward = Math.atan2(velocity.x, velocity.z)
      drone.body.rotation.y = heading > 0.01 ? forward * heading : 0
      drone.body.rotation.x = Math.min(speed * 0.08, 0.32)

      // The card: plugged in by sinking into the dock's slot, then gone with the drone.
      const plugging = easeInOutCubic(range(since, TRIP.dock, TRIP.plug))
      drone.card.visible = since < TRIP.plug
      drone.card.position.y = -CARD.hang - plugging * CARD.height
      drone.tether.scale.y = CARD.hang + plugging * CARD.height

      // Status lights: white ahead, pink behind.
      const yaw = drone.body.rotation.y
      glows.add(
        position.x + Math.sin(yaw) * 0.12 * SCALE,
        position.y + 0.005,
        position.z + Math.cos(yaw) * 0.12 * SCALE,
        0.05,
        palette.light,
        0.8 * palette.glowBoost,
      )
      glows.add(
        position.x - Math.sin(yaw) * 0.12 * SCALE,
        position.y + 0.005,
        position.z - Math.cos(yaw) * 0.12 * SCALE,
        0.05,
        palette.tones.pink,
        0.8 * palette.glowBoost,
      )
      // The dock lights up as a card goes home.
      const flash = bump(range(since, TRIP.dock + 0.1, TRIP.lift))
      if (flash > 0)
        glows.add(
          DOCK.x,
          DOCK.y + 0.02,
          DOCK.z,
          0.45,
          palette.tones.pink,
          0.35 * flash * palette.glowBoost,
        )
    }
  }

  dispose() {
    for (const geometry of this.geometries) geometry.dispose()
    this.marks.dispose()
    this.rotorMaterial.dispose()
  }
}

/** Where a drone is `since` seconds from plugging its card in (see `TRIP`). */
function flight(since: number, target: Vector3) {
  if (since < TRIP.hold) {
    // Reaching the holding point only as the drone before has flown past it.
    ARRIVAL.getPointAt(
      easeInOutCubic(range(since, TRIP.arrive, TRIP.hold)),
      target,
    )
  } else if (since < TRIP.hover) {
    target.lerpVectors(
      HOLD,
      HOVER,
      easeInOutCubic(range(since, TRIP.hold, TRIP.hover)),
    )
  } else if (since < TRIP.dock) {
    target.lerpVectors(
      HOVER,
      DOCKED,
      easeInOutCubic(range(since, TRIP.hover, TRIP.dock)),
    )
  } else if (since < TRIP.plug) {
    target.copy(DOCKED)
  } else if (since < TRIP.lift) {
    target.lerpVectors(
      DOCKED,
      HOVER,
      easeInOutCubic(range(since, TRIP.plug, TRIP.lift)),
    )
  } else {
    // Away briskly, so the hover point is clear for the next drone.
    DEPARTURE.getPointAt(range(since, TRIP.lift, TRIP.gone) ** 1.6, target)
  }
  return target
}

/**
 * Dark glass cards, one per model: a small label saying what they carry,
 * with the brand underscore, over the model's mark in the LCD ink.
 */
function drawMarks(
  canvas: HTMLCanvasElement,
  images: readonly (HTMLImageElement | null)[],
  label: string,
  rtl: boolean,
) {
  const context = canvas.getContext('2d')
  if (!context) return
  const { height } = canvas
  const cell = canvas.width / MODELS.length
  const size = Math.round(height * 0.15)
  context.font = `500 ${size}px ${brandFontStack()}`
  context.direction = rtl ? 'rtl' : 'ltr'
  context.textAlign = 'center'
  context.textBaseline = 'middle'
  const underscore = size * 0.55
  const gap = size * 0.12
  const text = context.measureText(label).width
  MODELS.forEach((_, index) => {
    const x = index * cell
    const glass = context.createLinearGradient(x, 0, x + cell, height)
    glass.addColorStop(0, '#1c1c22')
    glass.addColorStop(1, '#121216')
    context.fillStyle = glass
    context.fillRect(x, 0, cell, height)

    // The label and its underscore, centered together; the underscore trails the words.
    const labelY = height * 0.25
    const center = x + cell / 2 + ((rtl ? 1 : -1) * (underscore + gap)) / 2
    context.fillStyle = 'rgba(255, 255, 255, 0.72)'
    context.fillText(label, center, labelY)
    context.fillStyle = '#fd366e'
    context.fillRect(
      rtl ? center - text / 2 - gap - underscore : center + text / 2 + gap,
      labelY + size * 0.3,
      underscore,
      size * 0.11,
    )

    const image = images[index]
    if (image) {
      const side = height * 0.42
      context.drawImage(
        image,
        x + (cell - side) / 2,
        height * 0.63 - side / 2,
        side,
        side,
      )
    }
  })
}
