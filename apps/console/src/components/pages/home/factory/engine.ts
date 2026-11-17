import {
  Box3,
  DirectionalLight,
  Fog,
  HemisphereLight,
  LinearMipmapLinearFilter,
  MathUtils,
  Mesh,
  MeshStandardMaterial,
  type Object3D,
  NeutralToneMapping,
  PCFShadowMap,
  PerspectiveCamera,
  PlaneGeometry,
  Raycaster,
  SRGBColorSpace,
  Scene,
  ShadowMaterial,
  Vector2,
  Vector3,
  WebGLRenderTarget,
  WebGLRenderer,
  type Texture,
} from 'three'
import { GlowLayer, ShadowLayer } from './effects'
import { createFrameworkAtlas, loadFrameworkIcons } from './frameworks'
import type { DisplayFit, FrameState } from './frame'
import { applyKitPalette, createKit, disposeKit, type FactoryKit } from './kit'
import {
  BED_BACK,
  BED_FRONT,
  CYCLE,
  EXTENSION,
  MOVE,
  STATION_COUNT,
  TOWER_TOP,
  easeInOutCubic,
  easeOutCubic,
  getLineState,
  range,
  stationX,
} from './layout'
import {
  createFactoryPalette,
  readPageBackground,
  type FactoryPalette,
} from './palette'
import { ProductLine } from './products'
import { StationLine } from './stations'
import { createStudioEnvironment } from './studio'
import {
  createBlobTexture,
  createDescriptionAtlas,
  createNameplateAtlas,
  createScreenAtlas,
  createShadowTexture,
  createTrackTexture,
} from './textures'
import { FactoryWorld, getStaticShadowShapes } from './world'

export type FactoryEngineOptions = {
  canvas: HTMLCanvasElement
  /** Lucide SVG markup per station in `STATION_IDS` order, then the network gate's. */
  icons: string[]
  /** Translated product names, printed on each column, then the gate's sign. */
  names: string[]
  /** Translated product taglines, shown when a station unfolds. */
  descriptions: string[]
  /** Translated label of the shipped counter. */
  counterLabel: string
  /** Right-to-left page: nameplates put the icon on the right. */
  rtl: boolean
  dark: boolean
  reducedMotion: boolean
  onReady?: () => void
  onHover?: (station: number) => void
  /** WebGL failed or the context was lost for good. */
  onError?: () => void
}

/**
 * `overlay`: the scene fills the hero behind copy on the start side, so the
 * line runs low and recedes toward the core on the far side.
 * `band`: the scene sits in its own block under the copy.
 */
export type FactoryComposition = 'overlay' | 'band'

/** A rectangle on the stage, in CSS pixels from its top left. */
export type CopyArea = {
  left: number
  top: number
  right: number
  bottom: number
}

type Framing = {
  /** World width to fit across the frame at the target. */
  span: number
  /** World height to fit. */
  tall: number
  elevation: number
  azimuth: number
  target: [number, number, number]
  /** Lens shift as a fraction of the frame (positive moves the scene right / down). */
  shiftX: number
  shiftY: number
  /** Narrow screens pan along the line instead of fitting all of it. */
  track: boolean
}

const FRAMINGS: Record<'overlay' | 'band' | 'narrow', Framing> = {
  overlay: {
    span: 21.5,
    tall: 7.4,
    elevation: MathUtils.degToRad(29),
    azimuth: MathUtils.degToRad(-17),
    target: [1.8, 1.0, -2.0],
    shiftX: 0.06,
    shiftY: 0.11,
    track: false,
  },
  band: {
    span: 21,
    tall: 6.6,
    elevation: MathUtils.degToRad(29),
    azimuth: MathUtils.degToRad(-17),
    target: [1.8, 1.0, -2.0],
    shiftX: 0,
    shiftY: 0.04,
    track: false,
  },
  narrow: {
    span: 6.6,
    tall: 6.4,
    elevation: MathUtils.degToRad(28),
    azimuth: MathUtils.degToRad(-16),
    target: [1.2, 1.0, -1.6],
    shiftX: 0,
    shiftY: 0.02,
    track: true,
  },
}

/** Factory clock when the scene boots: a line already full of products. */
const START_TIME = 30 * CYCLE + MOVE + 0.02
/** Still frame for reduced motion: several machines mid-action. */
const STILL_TIME = 30 * CYCLE + MOVE + 0.5
/** Seconds the line takes to power up before it starts moving. */
const INTRO_END = 2.4
const FOV = 30
const FRAME_INTERVAL = 1000 / 60

const nextFrame = () =>
  new Promise<void>((resolve) => {
    if (typeof requestAnimationFrame === 'function')
      requestAnimationFrame(() => resolve())
    else setTimeout(resolve, 16)
  })

export class FactoryEngine {
  private readonly renderer: WebGLRenderer
  private readonly scene = new Scene()
  private readonly camera = new PerspectiveCamera(FOV, 2, 0.5, 120)
  /** The camera mirrored in the floor, for the reflection pass. */
  private readonly mirror = new PerspectiveCamera()
  private readonly target = new Vector3(1.5, 0.9, -0.3)
  private readonly raycaster = new Raycaster()
  private readonly hemi = new HemisphereLight()
  private readonly key = new DirectionalLight()
  private readonly rim = new DirectionalLight()
  private readonly stationBoxes: Box3[] = []
  private readonly displayBoxes: [Box3, Box3][] = []
  private readonly scratch = new Vector3()
  private readonly bufferSize = new Vector2()
  private palette: FactoryPalette
  private kit: FactoryKit | null = null
  private world: FactoryWorld | null = null
  private stations: StationLine | null = null
  private products: ProductLine | null = null
  private glows: GlowLayer | null = null
  private shadows: ShadowLayer | null = null
  private textures: Texture[] = []
  private screenAtlas: Texture | null = null
  private nameplateAtlas: Texture | null = null
  private descriptionAtlas: Texture | null = null
  private frameworkAtlas: Texture | null = null
  /** Objects the floor should not mirror (things lying on it). */
  private readonly unreflected: Object3D[] = []
  private readonly environments = new Map<boolean, Texture>()
  private frameState: FrameState | null = null
  private copyArea: CopyArea | null = null
  private readonly fits: DisplayFit[] = Array.from(
    { length: STATION_COUNT },
    () => ({ lift: 1, scale: 1 }),
  )
  private reflection: WebGLRenderTarget | null = null
  private floor: Mesh | null = null
  private shadowCatcher: Mesh | null = null

  private width = 1
  private height = 1
  private quality = 1
  private dpr = 1
  private distance = 20
  private active = false
  private disposed = false
  private ready = false
  private rendered = false
  private rafId = 0
  private lastNow = 0
  private lastRender = 0
  private time = START_TIME
  private introTime = 0
  private slowWindows = 0
  private fastWindows = 0
  private sampleCount = 0
  private readonly samples = new Float32Array(60)
  private pointer = new Vector2(0, 0)
  private pointerTarget = new Vector2(0, 0)
  private readonly hoverPointer = new Vector2()
  private pointerInside = false
  private hovered = -1
  private needsHoverTest = false
  private mobile = false
  private composition: FactoryComposition = 'band'
  private framing: Framing = FRAMINGS.band
  /** Shadow maps and the mirrored floor: only where there is GPU to spare. */
  private readonly studio: boolean
  private readonly anisotropy: number
  private readonly pixelBudget: number

  constructor(private readonly options: FactoryEngineOptions) {
    this.renderer = new WebGLRenderer({
      canvas: options.canvas,
      antialias: true,
      alpha: true,
      stencil: false,
      powerPreference: 'default',
    })
    this.renderer.setClearColor(0x000000, 0)
    this.renderer.outputColorSpace = SRGBColorSpace
    this.renderer.toneMapping = NeutralToneMapping
    this.renderer.toneMappingExposure = 1
    // Desktop-class GPUs (fine pointer, 4+ cores) get cast shadows and a polished floor.
    this.studio =
      typeof window !== 'undefined' &&
      window.matchMedia('(pointer: fine)').matches &&
      (navigator.hardwareConcurrency ?? 4) >= 4
    this.renderer.shadowMap.enabled = this.studio
    this.renderer.shadowMap.type = PCFShadowMap
    // Native 2x on desktop-class GPUs; phones and low-core devices get a tighter budget.
    this.pixelBudget = this.studio ? 7.4e6 : 2.2e6
    // Screens and print sit at a steep angle; anisotropic filtering keeps them crisp.
    this.anisotropy = Math.min(8, this.renderer.capabilities.getMaxAnisotropy())
    this.palette = createFactoryPalette(
      options.dark,
      readPageBackground(options.dark),
    )
    options.canvas.addEventListener('webglcontextlost', this.handleContextLost)
    if (options.reducedMotion) {
      this.introTime = INTRO_END + 2
      this.time = STILL_TIME
    }
  }

  private handleContextLost = (event: Event) => {
    event.preventDefault()
    this.stop()
    this.options.onError?.()
  }

  /** Builds the scene in small steps so no single task blocks input. */
  async init() {
    const palette = this.palette
    const shadow = createShadowTexture(getStaticShadowShapes())
    const blob = createBlobTexture()
    const track = this.crisp(createTrackTexture(palette))
    this.textures.push(shadow, blob)
    await nextFrame()
    if (this.disposed) return

    const kit = createKit(palette, { shadow, blob, track })
    this.kit = kit
    const plates = this.createNameplates()
    this.nameplateAtlas = this.crisp(plates.texture)
    const descriptions = this.createDescriptions()
    this.descriptionAtlas = this.crisp(descriptions.texture)
    this.world = new FactoryWorld(
      kit,
      plates.texture,
      descriptions.texture,
      this.options.counterLabel,
    )
    this.floor = this.world.group.getObjectByName('floor') as Mesh
    this.scene.add(this.world.group)
    await nextFrame()
    if (this.disposed) return

    this.stations = new StationLine(kit)
    this.scene.add(this.stations.group)
    await nextFrame()
    if (this.disposed) return

    this.products = new ProductLine(kit)
    const atlas = createScreenAtlas(this.atlasScale)
    this.screenAtlas = this.crisp(atlas.texture)
    this.products.setScreenAtlas(atlas.texture, atlas.cells)
    this.scene.add(this.products.group)
    this.glows = new GlowLayer(kit.glow)
    this.shadows = new ShadowLayer(kit.blob)
    this.scene.add(this.glows.points, this.shadows.mesh)
    void loadFrameworkIcons().then((icons) => {
      if (this.disposed || !this.world || !this.products) return
      this.world.setFrameworkIcons(icons)
      this.frameworkAtlas = this.crisp(createFrameworkAtlas(icons))
      this.products.setFrameworkAtlas(this.frameworkAtlas)
      if (this.ready && !this.isLooping()) this.renderFrame(0)
    })
    this.scene.traverse((object) => {
      if (object.userData.noReflection) this.unreflected.push(object)
    })
    await nextFrame()
    if (this.disposed) return

    // Key from the camera's side and above; rim from behind to edge the tops.
    this.key.position.set(-5, 13, 8)
    this.key.target.position.set(1.5, 0, -0.6)
    this.rim.position.set(10, 8, -11)
    this.rim.target.position.set(1.5, 0.8, -0.6)
    this.scene.add(
      this.hemi,
      this.key,
      this.key.target,
      this.rim,
      this.rim.target,
    )
    if (this.studio) {
      this.setupShadows()
      this.setupReflection()
    }
    this.applyLighting()

    for (let index = 0; index < STATION_COUNT; index++) {
      const x = stationX(index)
      this.stationBoxes.push(
        new Box3(
          new Vector3(x - 0.74, 0.1, BED_BACK),
          new Vector3(x + 0.74, TOWER_TOP + 0.1, BED_FRONT),
        ),
      )
      const reach = EXTENSION.leaf
      const screenBottom = TOWER_TOP + EXTENSION.lift
      this.displayBoxes.push([
        // The mast, as wide as the column, then the screen, wings and all.
        new Box3(
          new Vector3(x - 0.6, TOWER_TOP, EXTENSION.z - 0.3),
          new Vector3(x + 0.6, screenBottom, EXTENSION.z + 0.3),
        ),
        new Box3(
          new Vector3(x - reach, screenBottom, EXTENSION.z - 0.3),
          new Vector3(
            x + reach,
            screenBottom + EXTENSION.height + 0.1,
            EXTENSION.z + 0.3,
          ),
        ),
      ])
    }

    this.applyResolution()
    this.frameCamera()
    this.updateCamera(0)
    try {
      await this.renderer.compileAsync(this.scene, this.camera)
    } catch {
      // Older drivers without parallel compile still compile on first render.
    }
    await Promise.all([plates.ready, descriptions.ready])
    if (this.disposed) return
    this.ready = true
    this.renderFrame(0)
    this.options.onReady?.()
    if (this.active && !this.options.reducedMotion) this.start()
  }

  /**
   * One soft directional shadow over the line. The shadow camera is rolled
   * so its long side runs along the line, which lets a 4:1 map cover it.
   */
  private setupShadows() {
    const shadow = this.key.shadow
    this.key.castShadow = true
    shadow.mapSize.set(4096, 2048)
    shadow.radius = 3
    shadow.bias = -0.0004
    shadow.normalBias = 0.02
    const forward = this.key.target.position
      .clone()
      .sub(this.key.position)
      .normalize()
    const along = new Vector3(1, 0, 0)
      .addScaledVector(forward, -forward.x)
      .normalize()
    const camera = shadow.camera
    camera.up.copy(along).cross(forward)
    camera.left = -11.5
    camera.right = 11.5
    camera.top = 6
    camera.bottom = -2.6
    camera.near = 8
    camera.far = 27
    camera.updateProjectionMatrix()
    this.scene.traverse((object) => {
      if (!(object instanceof Mesh)) return
      if (
        object.material instanceof MeshStandardMaterial &&
        !object.material.transparent
      ) {
        object.castShadow = !object.userData.castsNoShadow
        object.receiveShadow = !object.userData.receivesNoShadow
      }
    })
    this.shadowCatcher = new Mesh(
      new PlaneGeometry(70, 30).rotateX(-Math.PI / 2),
      new ShadowMaterial({ transparent: true, depthWrite: false }),
    )
    this.shadowCatcher.position.set(1, 0.002, -1)
    this.shadowCatcher.receiveShadow = true
    this.shadowCatcher.renderOrder = 1
    this.scene.add(this.shadowCatcher)
  }

  /** A half-resolution target the floor samples to mirror the machine. */
  private setupReflection() {
    this.reflection = new WebGLRenderTarget(2, 2, {
      colorSpace: SRGBColorSpace,
      generateMipmaps: true,
      minFilter: LinearMipmapLinearFilter,
    })
    const floor = this.kit?.floor
    if (!floor) return
    floor.defines = { ...floor.defines, USE_REFLECTION: '' }
    floor.uniforms.uReflection!.value = this.reflection.texture
    floor.needsUpdate = true
  }

  /**
   * Renders the scene from the camera's mirror image under the floor. Points
   * on the floor project to the same height and the opposite side in both
   * views, which is all the floor shader needs to look the reflection up.
   */
  private renderReflection() {
    const reflection = this.reflection
    const floor = this.floor
    if (!reflection || !floor) return
    const { renderer, camera, mirror } = this
    mirror.position.set(
      camera.position.x,
      -camera.position.y,
      camera.position.z,
    )
    mirror.up.set(0, -1, 0)
    mirror.lookAt(this.target.x, -this.target.y, this.target.z)
    mirror.updateMatrixWorld()
    mirror.projectionMatrix.copy(camera.projectionMatrix)
    // The mirrored view is flipped left to right, and so is its lens shift.
    mirror.projectionMatrix.elements[8]! *= -1
    mirror.projectionMatrixInverse.copy(mirror.projectionMatrix).invert()

    const hidden = [
      floor,
      this.shadowCatcher,
      this.shadows?.mesh,
      ...this.unreflected,
    ]
    for (const object of hidden) if (object) object.visible = false
    // Shadows were drawn for the last frame; one frame of lag is invisible here.
    renderer.shadowMap.autoUpdate = !this.rendered
    renderer.setRenderTarget(reflection)
    renderer.clear()
    renderer.render(this.scene, mirror)
    renderer.setRenderTarget(null)
    renderer.shadowMap.autoUpdate = true
    for (const object of hidden) if (object) object.visible = true
  }

  /** Reprints the nameplates (language or direction changed). */
  /** Reprints the nameplates and description screens (language or direction changed). */
  setNames(names: string[], descriptions: string[], rtl: boolean) {
    if (
      names.join('|') === this.options.names.join('|') &&
      descriptions.join('|') === this.options.descriptions.join('|') &&
      rtl === this.options.rtl
    )
      return
    this.options.names = names
    this.options.descriptions = descriptions
    this.options.rtl = rtl
    if (this.kit) this.printNameplates()
  }

  /** Relabels the shipped counter (language changed). */
  setCounterLabel(label: string) {
    this.options.counterLabel = label
    this.world?.setCounterLabel(label)
    if (this.ready && !this.isLooping()) this.renderFrame(0)
  }

  private createNameplates() {
    return createNameplateAtlas(
      this.options.icons,
      this.options.names,
      this.options.rtl,
    )
  }

  private createDescriptions() {
    return createDescriptionAtlas(
      this.options.icons,
      this.options.names.slice(0, STATION_COUNT),
      this.options.descriptions,
      this.options.rtl,
    )
  }

  private printNameplates() {
    const kit = this.kit
    if (!kit) return
    const plates = this.createNameplates()
    this.nameplateAtlas?.dispose()
    this.nameplateAtlas = this.crisp(plates.texture)
    kit.nameplate.uniforms.uMap!.value = plates.texture
    const descriptions = this.createDescriptions()
    this.descriptionAtlas?.dispose()
    this.descriptionAtlas = this.crisp(descriptions.texture)
    this.world?.setDescriptionTexture(descriptions.texture)
    void Promise.all([plates.ready, descriptions.ready]).then(() => {
      if (this.ready && !this.isLooping()) this.renderFrame(0)
    })
  }

  private get atlasScale() {
    return this.studio ? 1.5 : 1
  }

  private crisp<T extends Texture>(texture: T): T {
    texture.anisotropy = this.anisotropy
    return texture
  }

  private applyLighting() {
    const palette = this.palette
    let environment = this.environments.get(palette.dark)
    if (!environment) {
      environment = createStudioEnvironment(this.renderer, palette.dark)
      this.environments.set(palette.dark, environment)
    }
    this.scene.environment = environment
    this.scene.environmentIntensity = palette.envIntensity
    if (this.shadowCatcher) {
      ;(this.shadowCatcher.material as ShadowMaterial).opacity =
        palette.shadowOpacity
    }
    this.key.shadow.intensity = palette.dark ? 0.85 : 0.7
    this.scene.fog = new Fog(palette.background, 23, 40)
    this.hemi.color.copy(palette.hemiSky)
    this.hemi.groundColor.copy(palette.hemiGround)
    this.hemi.intensity = palette.hemiIntensity
    this.key.intensity = palette.keyIntensity
    this.rim.intensity = palette.rimIntensity
  }

  setSize(width: number, height: number) {
    this.width = Math.max(1, Math.round(width))
    this.height = Math.max(1, Math.round(height))
    this.mobile = this.width / this.height < 1.25
    this.applyResolution()
    this.frameCamera()
    if (this.ready && !this.isLooping()) this.renderFrame(0)
  }

  /**
   * The part of the stage (CSS pixels) covered by page copy, which the
   * unfolded displays must stay clear of; null when nothing is laid over it.
   */
  setCopyArea(area: CopyArea | null) {
    this.copyArea = area
    if (this.ready && !this.isLooping()) this.renderFrame(0)
  }

  /**
   * Keeps every unfolded display clear of the copy: lower the mast first,
   * and if the display still reaches the copy, open it smaller.
   */
  private fitDisplays() {
    const area = this.copyArea
    for (let index = 0; index < STATION_COUNT; index++) {
      const fit = this.fits[index]!
      fit.lift = 1
      fit.scale = 1
      if (!area || this.isClear(index, 1, 1, area)) continue
      let low = 0
      let high = 1
      if (this.isClear(index, 0, 1, area)) {
        for (let step = 0; step < 8; step++) {
          const middle = (low + high) / 2
          if (this.isClear(index, middle, 1, area)) low = middle
          else high = middle
        }
        fit.lift = low
        continue
      }
      fit.lift = 0
      low = 0.5
      for (let step = 0; step < 8; step++) {
        const middle = (low + high) / 2
        if (this.isClear(index, 0, middle, area)) low = middle
        else high = middle
      }
      fit.scale = low
    }
  }

  /** Whether station `index`'s open display, so lifted and scaled, stays off the copy. */
  private isClear(index: number, lift: number, scale: number, area: CopyArea) {
    const x = stationX(index)
    const base = TOWER_TOP - 0.01 + lift * EXTENSION.lift
    const top = base + EXTENSION.height * scale
    const reach = EXTENSION.leaf * scale
    let left = Number.POSITIVE_INFINITY
    let right = Number.NEGATIVE_INFINITY
    let highest = Number.POSITIVE_INFINITY
    for (const [cornerX, cornerY] of [
      [x - reach, top],
      [x + reach, top],
      [x - reach, base],
      [x + reach, base],
    ] as const) {
      this.scratch.set(cornerX, cornerY, EXTENSION.z).project(this.camera)
      const screenX = ((this.scratch.x + 1) / 2) * this.width
      left = Math.min(left, screenX)
      right = Math.max(right, screenX)
      highest = Math.min(highest, ((1 - this.scratch.y) / 2) * this.height)
    }
    const margin = 16
    return (
      right < area.left - margin ||
      left > area.right + margin ||
      highest > area.bottom + margin
    )
  }

  setComposition(composition: FactoryComposition) {
    this.composition = composition
    this.frameCamera()
    if (this.ready && !this.isLooping()) this.renderFrame(0)
  }

  private applyResolution() {
    const deviceRatio =
      typeof window === 'undefined' ? 1 : window.devicePixelRatio || 1
    const budgetRatio = Math.sqrt(this.pixelBudget / (this.width * this.height))
    const ratio = Math.min(deviceRatio, 2, budgetRatio) * this.quality
    this.dpr = Math.max(0.75, Math.min(ratio, 2))
    this.renderer.setPixelRatio(this.dpr)
    this.renderer.setSize(this.width, this.height, false)
    const buffer = this.renderer.getDrawingBufferSize(this.bufferSize)
    if (this.reflection) {
      this.reflection.setSize(
        Math.max(2, Math.round(buffer.x / 2)),
        Math.max(2, Math.round(buffer.y / 2)),
      )
    }
    if (this.kit) {
      const scale =
        (this.height * this.dpr) / (2 * Math.tan(MathUtils.degToRad(FOV) / 2))
      this.kit.glow.uniforms.uScale!.value = scale
      this.kit.floor.uniforms.uResolution!.value.copy(buffer)
    }
  }

  private frameCamera() {
    const aspect = this.width / this.height
    this.camera.aspect = aspect
    this.framing = this.mobile ? FRAMINGS.narrow : FRAMINGS[this.composition]
    const framing = this.framing
    const vertical = MathUtils.degToRad(FOV)
    const horizontal = 2 * Math.atan(Math.tan(vertical / 2) * aspect)
    const byWidth = framing.span / 2 / Math.tan(horizontal / 2)
    const byHeight = framing.tall / 2 / Math.tan(vertical / 2)
    this.distance = Math.max(byWidth, byHeight)
    if (framing.shiftX || framing.shiftY) {
      this.camera.setViewOffset(
        this.width,
        this.height,
        -framing.shiftX * this.width,
        -framing.shiftY * this.height,
        this.width,
        this.height,
      )
    } else {
      this.camera.clearViewOffset()
    }
    this.camera.updateProjectionMatrix()
  }

  private updateCamera(dt: number) {
    const ease = Math.min(1, dt * 2.6)
    this.pointer.x += (this.pointerTarget.x - this.pointer.x) * ease
    this.pointer.y += (this.pointerTarget.y - this.pointer.y) * ease
    // A slow push-in while the line powers up, then a barely moving camera.
    const settle = 1 - easeOutCubic(range(this.introTime, 0, INTRO_END + 0.6))
    const framing = this.framing
    const drift = this.options.reducedMotion
      ? 0
      : Math.sin(this.time * 0.06) * MathUtils.degToRad(0.8)
    const azimuth =
      framing.azimuth +
      this.pointer.x * MathUtils.degToRad(2.4) +
      drift +
      settle * MathUtils.degToRad(-4)
    const elevation =
      framing.elevation +
      this.pointer.y * MathUtils.degToRad(1.4) +
      settle * MathUtils.degToRad(2)
    const distance = this.distance * (1 + settle * 0.07)

    const [targetX, targetY, targetZ] = framing.target
    this.target.set(targetX, targetY, targetZ)
    if (framing.track && !this.options.reducedMotion) {
      this.target.x = Math.sin((this.time - START_TIME) * 0.07) * 8.4 + targetX
    }
    this.camera.position.set(
      this.target.x + Math.sin(azimuth) * Math.cos(elevation) * distance,
      this.target.y + Math.sin(elevation) * distance,
      this.target.z + Math.cos(azimuth) * Math.cos(elevation) * distance,
    )
    this.camera.lookAt(this.target)
  }

  private isLooping() {
    return this.rafId !== 0
  }

  setActive(active: boolean) {
    this.active = active
    if (!this.ready || this.options.reducedMotion) return
    if (active) this.start()
    else this.stop()
  }

  private start() {
    if (this.rafId || this.disposed) return
    this.lastNow = performance.now()
    this.lastRender = 0
    this.rafId = requestAnimationFrame(this.tick)
  }

  private stop() {
    if (this.rafId) cancelAnimationFrame(this.rafId)
    this.rafId = 0
  }

  private tick = (now: number) => {
    if (this.disposed) return
    this.rafId = requestAnimationFrame(this.tick)
    // Cap at 60 fps: high refresh screens get the same scene for half the work.
    if (this.lastRender && now - this.lastRender < FRAME_INTERVAL - 2.5) return
    const dt = Math.min((now - this.lastNow) / 1000, 0.05)
    this.trackPerformance(now - (this.lastRender || now))
    this.lastNow = now
    this.lastRender = now
    this.renderFrame(dt)
  }

  /**
   * Lowers resolution only after sustained slow frames (three windows of 60
   * frames whose median runs below ~38 fps) and restores it when frames run
   * clean again. Hitches, background tabs and unfocused windows never count.
   */
  private trackPerformance(interval: number) {
    if (this.introTime < INTRO_END || interval <= 0 || interval > 120) return
    if (
      typeof document !== 'undefined' &&
      (document.hidden || !document.hasFocus())
    )
      return
    this.samples[this.sampleCount++] = interval
    if (this.sampleCount < this.samples.length) return
    this.sampleCount = 0
    const median = [...this.samples].sort((a, b) => a - b)[
      this.samples.length >> 1
    ]!
    if (median > 26) {
      this.slowWindows++
      this.fastWindows = 0
      if (this.slowWindows >= 3 && this.quality > 0.6) {
        this.quality = Math.max(0.6, this.quality * 0.85)
        this.slowWindows = 0
        this.applyResolution()
      }
    } else if (median < 18) {
      this.fastWindows++
      this.slowWindows = 0
      if (this.fastWindows >= 3 && this.quality < 1) {
        this.quality = Math.min(1, this.quality * 1.12)
        this.fastWindows = 0
        this.applyResolution()
      }
    }
  }

  private renderFrame(dt: number) {
    const { kit, world, stations, products, glows, shadows } = this
    if (!kit || !world || !stations || !products || !glows || !shadows) return

    if (!this.options.reducedMotion) {
      this.introTime += dt
      // The line holds still while it powers up, then eases into its rhythm.
      const speed = easeInOutCubic(range(this.introTime, 1.5, INTRO_END))
      this.time += dt * speed
    }

    const frame: FrameState = this.frameState ?? {
      time: this.time,
      dt,
      introTime: this.introTime,
      line: getLineState(this.time),
      palette: this.palette,
      glows,
      shadows,
      highlight: -1,
      levels: new Array(STATION_COUNT).fill(0),
      realShadows: this.studio,
      fits: this.fits,
    }
    this.frameState = frame
    frame.time = this.time
    frame.dt = dt
    frame.introTime = this.introTime
    frame.line = getLineState(this.time)
    frame.palette = this.palette
    frame.highlight = this.hovered
    this.fitDisplays()

    glows.begin()
    shadows.begin()
    stations.update(frame)
    world.update(frame)
    products.update(frame)
    glows.end()
    shadows.end()

    this.updateCamera(dt)
    this.renderReflection()
    this.renderer.render(this.scene, this.camera)
    this.rendered = true
    if (this.needsHoverTest) this.testHover()
  }

  /** Pointer in CSS pixels relative to the canvas, or null when it leaves. */
  setPointer(x: number | null, y: number | null) {
    if (x === null || y === null || this.options.reducedMotion) {
      this.pointerInside = false
      this.pointerTarget.set(0, 0)
      if (this.hovered !== -1) this.setHovered(-1)
      return
    }
    this.pointerInside = true
    const nx = (x / this.width) * 2 - 1
    const ny = -(y / this.height) * 2 + 1
    this.pointerTarget.set(nx, ny)
    this.hoverPointer.set(nx, ny)
    this.needsHoverTest = true
    if (!this.isLooping() && this.ready) this.renderFrame(0)
  }

  private testHover() {
    this.needsHoverTest = false
    if (!this.pointerInside) return
    this.raycaster.setFromCamera(this.hoverPointer, this.camera)
    let best = -1
    let bestDistance = Number.POSITIVE_INFINITY
    const test = (box: Box3, index: number) => {
      const hit = this.raycaster.ray.intersectBox(box, this.scratch)
      if (!hit) return
      const distance = hit.distanceTo(this.raycaster.ray.origin)
      if (distance < bestDistance) {
        bestDistance = distance
        best = index
      }
    }
    this.stationBoxes.forEach(test)
    // An open station also answers for its unfolded display (only the display,
    // so the machines beside it stay reachable).
    if (this.hovered >= 0) {
      for (const box of this.displayBoxes[this.hovered]!)
        test(box, this.hovered)
    }
    if (best !== this.hovered) this.setHovered(best)
  }

  private setHovered(index: number) {
    this.hovered = index
    this.options.onHover?.(index)
  }

  /** Highlight from outside (a focused or hovered label). */
  setHighlight(index: number) {
    if (index === this.hovered) return
    this.hovered = index
    if (this.ready && !this.isLooping()) this.renderFrame(0)
  }

  setTheme(dark: boolean) {
    const kit = this.kit
    const palette = createFactoryPalette(dark, readPageBackground(dark))
    this.palette = palette
    if (!kit || !this.products) return
    applyKitPalette(kit, palette)
    this.world?.setPalette(palette)
    this.applyLighting()

    const track = this.crisp(createTrackTexture(palette))
    const previous = kit.track.map
    if (previous) {
      track.repeat.copy(previous.repeat)
      previous.dispose()
    }
    kit.track.map = track
    kit.track.needsUpdate = true

    if (this.ready && !this.isLooping()) this.renderFrame(0)
  }

  dispose() {
    this.disposed = true
    this.stop()
    this.options.canvas.removeEventListener(
      'webglcontextlost',
      this.handleContextLost,
    )
    this.world?.dispose()
    this.stations?.dispose()
    this.products?.dispose()
    this.glows?.points.geometry.dispose()
    this.shadows?.mesh.geometry.dispose()
    if (this.kit) {
      this.kit.track.map?.dispose()
      disposeKit(this.kit)
    }
    for (const texture of this.textures) texture.dispose()
    this.screenAtlas?.dispose()
    this.nameplateAtlas?.dispose()
    this.descriptionAtlas?.dispose()
    this.frameworkAtlas?.dispose()
    for (const environment of this.environments.values()) environment.dispose()
    this.reflection?.dispose()
    if (this.shadowCatcher) {
      this.shadowCatcher.geometry.dispose()
      ;(this.shadowCatcher.material as ShadowMaterial).dispose()
    }
    this.renderer.dispose()
    this.renderer.forceContextLoss()
  }
}

export function createFactoryEngine(options: FactoryEngineOptions) {
  const engine = new FactoryEngine(options)
  void engine.init().catch(() => options.onError?.())
  return engine
}
