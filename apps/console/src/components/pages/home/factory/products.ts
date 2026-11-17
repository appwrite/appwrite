import {
  BoxGeometry,
  Color,
  DynamicDrawUsage,
  Group,
  InstancedBufferAttribute,
  InstancedMesh,
  Matrix4,
  PlaneGeometry,
  Vector3,
  type Texture,
} from 'three'
import { DEVICE_SIZES, createDeviceSpecs, type DeviceSpec } from './devices'
import { FRAMEWORK_GRID, getProductFramework } from './frameworks'
import type { FactoryKit } from './kit'
import { cylinder, merge, place, slab } from './geometry'
import { applyScreenCells } from './materials'
import type { ScreenCell } from './textures'
import {
  BED_TOP,
  CARRIER,
  CARRIER_TOP,
  GONE_SLOT,
  MAX_PRODUCTS,
  PAD_HEIGHT,
  PRODUCT_BASE,
  PRODUCT_KINDS,
  QUEUE_SLOTS,
  RAIL_HEIGHT,
  STATION_COUNT,
  SWEEP,
  TRACK_Z,
  clamp01,
  easeInOutCubic,
  easeOutCubic,
  getInstallHit,
  getProductFinish,
  getProductKind,
  getProductSlot,
  getStationHit,
  hash01,
  slotX,
  type ProductKind,
} from './layout'
import { introStep, type FrameState } from './frame'

/** The stage whose tool sweeps the screen instead of touching it (Databases). */
const WIPE_STAGE = 3
/** Hue turns (radians) apps are drawn in, so the line builds many different brands. */
const HUES = [0, 0, 0.75, -0.75, 1.6, -1.6, 2.6]

const matrix = new Matrix4()
const partMatrix = new Matrix4()
const scale = new Vector3()
const finishColor = new Color()

/**
 * Everything that rides the track: machined carriers, the product on each
 * one, and its screen, which gains a layer of UI at every station.
 */
export class ProductLine {
  readonly group = new Group()
  private readonly specs: Record<ProductKind, DeviceSpec>
  private readonly bodies: Record<ProductKind, InstancedMesh>
  private readonly carriers: InstancedMesh
  private readonly pads: InstancedMesh
  private readonly screens: InstancedMesh
  private readonly screenData: InstancedBufferAttribute
  private readonly screenFx: InstancedBufferAttribute
  private readonly counts = {} as Record<ProductKind, number>

  constructor(private readonly kit: FactoryKit) {
    this.specs = createDeviceSpecs()

    // Carrier: an anodized plate with locating pins at its corners.
    const pins = [-1, 1].flatMap((sx) =>
      [-1, 1].map((sz) =>
        place(
          cylinder(0.014, 0.014, 14),
          new Matrix4().makeTranslation(
            sx * (CARRIER.width / 2 - 0.05),
            CARRIER.height + 0.007,
            sz * (CARRIER.depth / 2 - 0.05),
          ),
        ),
      ),
    )
    this.carriers = new InstancedMesh(
      merge([
        slab(CARRIER.width, CARRIER.depth, CARRIER.height, 0.032, 0.006),
        ...pins,
      ]),
      kit.carrier,
      MAX_PRODUCTS,
    )
    this.pads = new InstancedMesh(
      new BoxGeometry(1, 1, 1).translate(0, 0.5, 0),
      kit.matte,
      MAX_PRODUCTS,
    )

    this.bodies = {} as Record<ProductKind, InstancedMesh>
    for (const kind of PRODUCT_KINDS) {
      const mesh = new InstancedMesh(
        this.specs[kind].body,
        kit.device,
        MAX_PRODUCTS,
      )
      mesh.setColorAt(0, finishColor.set(0xffffff))
      mesh.instanceColor?.setUsage(DynamicDrawUsage)
      // Thin frames under a soft shadow map alias; the glass covers them anyway.
      mesh.userData.receivesNoShadow = true
      this.bodies[kind] = mesh
      this.counts[kind] = 0
    }

    const quad = new PlaneGeometry(1, 1).rotateX(-Math.PI / 2)
    this.screenData = new InstancedBufferAttribute(
      new Float32Array(MAX_PRODUCTS * 4),
      4,
    ).setUsage(DynamicDrawUsage)
    this.screenFx = new InstancedBufferAttribute(
      new Float32Array(MAX_PRODUCTS * 4),
      4,
    ).setUsage(DynamicDrawUsage)
    quad.setAttribute('aScreen', this.screenData)
    quad.setAttribute('aFx', this.screenFx)
    this.screens = new InstancedMesh(quad, kit.screen, MAX_PRODUCTS)

    for (const mesh of [
      this.carriers,
      this.pads,
      ...Object.values(this.bodies),
      this.screens,
    ]) {
      mesh.instanceMatrix.setUsage(DynamicDrawUsage)
      mesh.frustumCulled = false
      this.group.add(mesh)
    }

    PRODUCT_KINDS.forEach((kind, index) => {
      kit.screenUniforms.uShape.value[index]!.set(...this.specs[kind].shape)
    })
  }

  setScreenAtlas(texture: Texture, cells: Record<ProductKind, ScreenCell>) {
    this.kit.screenUniforms.uAtlas.value = texture
    applyScreenCells(this.kit.screenUniforms, cells)
  }

  setFrameworkAtlas(texture: Texture) {
    const uniforms = this.kit.screenUniforms
    uniforms.uFrameworks.value = texture
    uniforms.uFrameworkGrid.value.set(
      FRAMEWORK_GRID.columns,
      FRAMEWORK_GRID.rows,
    )
    uniforms.uFrameworksReady.value = 1
  }

  update(frame: FrameState) {
    const { line, time, palette, shadows } = frame
    for (const kind of PRODUCT_KINDS) this.counts[kind] = 0
    let count = 0

    // Screens come up with the line's power.
    this.kit.screenUniforms.uPower.value = easeOutCubic(
      introStep(frame.introTime, 0.5, 1.3),
    )

    const first = line.cycle - Math.ceil(GONE_SLOT) - 1
    const last = line.cycle + QUEUE_SLOTS

    for (let index = first; index <= last; index++) {
      if (count >= MAX_PRODUCTS) break
      const slot = getProductSlot(index, line)
      if (slot < -QUEUE_SLOTS - 1.5 || slot > GONE_SLOT) continue
      const kind = getProductKind(index)
      const size = DEVICE_SIZES[kind]
      const x = slotX(slot)

      this.carriers.setMatrixAt(
        count,
        matrix.makeTranslation(x, BED_TOP + RAIL_HEIGHT, TRACK_Z),
      )
      scale.set(size.width + 0.05, PAD_HEIGHT, size.depth + 0.05)
      this.pads.setMatrixAt(
        count,
        matrix.makeTranslation(x, CARRIER_TOP, TRACK_Z).scale(scale),
      )

      matrix.makeTranslation(x, PRODUCT_BASE, TRACK_Z)
      const bodies = this.bodies[kind]
      const bodyIndex = this.counts[kind]++
      bodies.setMatrixAt(bodyIndex, matrix)
      bodies.setColorAt(
        bodyIndex,
        finishColor.copy(palette.finishes[getProductFinish(index)]!),
      )

      // Screen stage: the framework, then one layer per station that worked on it.
      let stage = 0
      let sinceHit = Number.POSITIVE_INFINITY
      for (let station = STATION_COUNT - 1; station >= 0; station--) {
        const hit = getStationHit(station, index)
        if (time >= hit) {
          stage = station + 2
          sinceHit = time - hit
          break
        }
      }
      if (stage === 0 && time >= getInstallHit(index)) {
        stage = 1
        sinceHit = time - getInstallHit(index)
      }
      const reveal = easeInOutCubic(clamp01(sinceHit / SWEEP))
      this.screens.setMatrixAt(
        count,
        partMatrix.multiplyMatrices(matrix, this.specs[kind].screen),
      )
      this.screenData.setXYZW(
        count,
        stage,
        Math.max(stage - 1, 0),
        reveal,
        PRODUCT_KINDS.indexOf(kind),
      )
      // A short lift in brightness as each layer lands; shipped apps stay a touch brighter.
      const flash = stage > 0 ? 0.3 * Math.exp(-sinceHit * 4.5) : 0
      this.screenFx.setXYZW(
        count,
        flash + (stage === STATION_COUNT + 1 ? 0.05 : 0),
        stage === WIPE_STAGE ? 1 : 0,
        getProductFramework(index, kind),
        HUES[Math.floor(hash01(index * 5 + 11) * HUES.length)]!,
      )

      shadows.add(
        x,
        BED_TOP + 0.003,
        TRACK_Z,
        CARRIER.width * 1.12,
        CARRIER.depth * 1.12,
        frame.realShadows ? 0.35 : 0.8,
      )
      count++
    }

    for (const kind of PRODUCT_KINDS) {
      const mesh = this.bodies[kind]
      mesh.count = this.counts[kind]
      mesh.instanceMatrix.needsUpdate = true
      if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true
    }
    for (const mesh of [this.carriers, this.pads, this.screens]) {
      mesh.count = count
      mesh.instanceMatrix.needsUpdate = true
    }
    this.screenData.needsUpdate = true
    this.screenFx.needsUpdate = true
  }

  dispose() {
    for (const mesh of [
      this.carriers,
      this.pads,
      ...Object.values(this.bodies),
      this.screens,
    ]) {
      mesh.geometry.dispose()
      mesh.dispose()
    }
  }
}
