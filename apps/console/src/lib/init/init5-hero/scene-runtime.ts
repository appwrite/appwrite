import * as THREE from 'three'
import type {
  Init5SceneController,
  Init5SceneLayout,
  Init5SceneOptions,
  Init5ScenePalette,
  Init5SceneQuality,
} from './scene-types'

export const CAMERA_DISTANCE = 8
const CAMERA_FOV = 30
/** Reduced motion renders the settled state: every intro timeline reads as finished. */
export const SETTLED_TIME = 1_000

export type SceneRuntime = {
  renderer: THREE.WebGLRenderer
  scene: THREE.Scene
  camera: THREE.PerspectiveCamera
  /** CSS pixels. */
  width: number
  height: number
  pixelRatio: number
  /** World units visible on the z = 0 plane. */
  viewWidth: number
  viewHeight: number
  /** World placement of the glyph root: stage units are scaled by `scale`. */
  anchor: { x: number; y: number; scale: number }
  /** CSS pixels per stage unit on the glyph plane. */
  pixelsPerUnit: number
  palette: Init5ScenePalette
  quality: Init5SceneQuality
  reducedMotion: boolean
  /** Seconds since the intro (re)started. */
  time: number
  /** Monotonic seconds, never reset (idle loops). */
  clock: number
  pointer: {
    /** Smoothed pointer in NDC (-1..1, y up). */
    ndc: THREE.Vector2
    /** Latest raw pointer in NDC. */
    target: THREE.Vector2
    /** Eases to 1 while the pointer is over the hero, back to 0 after it leaves. */
    presence: number
    inside: boolean
  }
  /** Smoothed scroll-away progress (0-1). */
  scroll: number
  /** Project a pointer (default: smoothed) onto `object`'s local z = 0 plane. */
  pointerOnPlane: (
    object: THREE.Object3D,
    target: THREE.Vector3,
    ndc?: THREE.Vector2,
  ) => boolean
  requestRender: () => void
}

export type SceneVariant = {
  update: (runtime: SceneRuntime, dt: number) => void
  onPalette?: (runtime: SceneRuntime) => void
  onResize?: (runtime: SceneRuntime) => void
  onPulse?: (runtime: SceneRuntime, ndc: THREE.Vector2) => void
  onReplay?: (runtime: SceneRuntime) => void
  dispose: () => void
}

export type SceneRuntimeConfig = {
  renderer?: THREE.WebGLRendererParameters
  /** Device pixel ratio cap at high quality. */
  maxPixelRatio?: number
  /** Called once the renderer exists, before the variant is built. */
  configureRenderer?: (renderer: THREE.WebGLRenderer) => void
}

/** Colors for custom shaders, kept in display (sRGB) space. */
export function displayColor(hex: string, target = new THREE.Color()) {
  return target.setStyle(hex, THREE.LinearSRGBColorSpace)
}

export function clamp01(value: number) {
  return Math.min(1, Math.max(0, value))
}

export function easeOutExpo(t: number) {
  return t >= 1 ? 1 : 1 - 2 ** (-10 * t)
}

export function easeOutCubic(t: number) {
  return 1 - (1 - t) ** 3
}

export function easeInOutCubic(t: number) {
  return t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2
}

/** Frame-rate independent exponential smoothing factor. */
export function damp(lambda: number, dt: number) {
  return 1 - Math.exp(-lambda * dt)
}

export function createSceneController(
  options: Init5SceneOptions,
  build: (runtime: SceneRuntime) => SceneVariant,
  config: SceneRuntimeConfig = {},
): Init5SceneController {
  const { canvas } = options
  const renderer = new THREE.WebGLRenderer({
    canvas,
    antialias: false,
    alpha: true,
    premultipliedAlpha: true,
    powerPreference: 'high-performance',
    ...config.renderer,
  })
  const maxPixelRatio =
    options.quality === 'low' ? 1.25 : (config.maxPixelRatio ?? 2)
  const pixelRatio = Math.min(window.devicePixelRatio || 1, maxPixelRatio)
  renderer.setPixelRatio(pixelRatio)
  renderer.setSize(
    Math.max(1, options.width),
    Math.max(1, options.height),
    false,
  )
  renderer.setClearColor(0x000000, 0)
  config.configureRenderer?.(renderer)

  const scene = new THREE.Scene()
  const camera = new THREE.PerspectiveCamera(
    CAMERA_FOV,
    Math.max(1, options.width) / Math.max(1, options.height),
    0.1,
    80,
  )
  camera.position.set(0, 0, CAMERA_DISTANCE)

  const raycaster = new THREE.Raycaster()
  const plane = new THREE.Plane()
  const planeNormal = new THREE.Vector3()
  const planePoint = new THREE.Vector3()
  const worldQuaternion = new THREE.Quaternion()
  const hit = new THREE.Vector3()

  let layout: Init5SceneLayout = options.layout
  let scrollTarget = 0
  let rafId = 0
  let running = false
  let lastNow = 0
  let ready = false
  let disposed = false
  let frozen = false
  let wantsActive = false
  let pendingRender = 0
  const minFrameMs = options.quality === 'low' ? 1000 / 30 : 0

  const runtime: SceneRuntime = {
    renderer,
    scene,
    camera,
    width: Math.max(1, options.width),
    height: Math.max(1, options.height),
    pixelRatio,
    viewWidth: 1,
    viewHeight: 1,
    anchor: { x: 0, y: 0, scale: 1 },
    pixelsPerUnit: 1,
    palette: options.palette,
    quality: options.quality,
    reducedMotion: options.reducedMotion,
    time: options.reducedMotion ? SETTLED_TIME : 0,
    clock: options.reducedMotion ? 12 : 0,
    pointer: {
      ndc: new THREE.Vector2(0, 0),
      target: new THREE.Vector2(0, 0),
      presence: 0,
      inside: false,
    },
    scroll: 0,
    pointerOnPlane: (object, target, ndc) => {
      object.updateWorldMatrix(true, false)
      object.getWorldQuaternion(worldQuaternion)
      object.getWorldPosition(planePoint)
      planeNormal.set(0, 0, 1).applyQuaternion(worldQuaternion)
      plane.setFromNormalAndCoplanarPoint(planeNormal, planePoint)
      raycaster.setFromCamera(ndc ?? runtime.pointer.ndc, camera)
      if (!raycaster.ray.intersectPlane(plane, hit)) return false
      target.copy(hit)
      object.worldToLocal(target)
      return true
    },
    requestRender: () => {
      if (running || disposed || pendingRender) return
      pendingRender = requestAnimationFrame(() => {
        pendingRender = 0
        step(0)
      })
    },
  }

  const applyLayout = () => {
    const viewHeight =
      2 * CAMERA_DISTANCE * Math.tan(THREE.MathUtils.degToRad(CAMERA_FOV / 2))
    const viewWidth = viewHeight * (runtime.width / runtime.height)
    runtime.viewHeight = viewHeight
    runtime.viewWidth = viewWidth
    runtime.anchor.x = (layout.cx - 0.5) * viewWidth
    runtime.anchor.y = (0.5 - layout.cy) * viewHeight
    runtime.anchor.scale = Math.max(0.01, layout.height * viewHeight)
    runtime.pixelsPerUnit = Math.max(1, layout.height * runtime.height)
  }
  applyLayout()

  const variant = build(runtime)

  function step(dt: number) {
    if (disposed) return
    if (!runtime.reducedMotion) {
      runtime.time += dt
      runtime.clock += dt
      runtime.pointer.ndc.lerp(runtime.pointer.target, damp(8, dt))
      runtime.pointer.presence +=
        ((runtime.pointer.inside ? 1 : 0) - runtime.pointer.presence) *
        damp(3.5, dt)
      runtime.scroll += (scrollTarget - runtime.scroll) * damp(9, dt)
    }
    variant.update(runtime, dt)
    renderer.render(scene, camera)
    if (!ready) {
      ready = true
      options.onReady?.()
    }
  }

  const frame = (now: number) => {
    if (!running) return
    rafId = requestAnimationFrame(frame)
    if (lastNow === 0) lastNow = now
    const elapsed = now - lastNow
    if (minFrameMs && elapsed < minFrameMs - 1) return
    lastNow = now
    step(Math.min(0.05, elapsed / 1000))
  }

  const start = () => {
    if (running || disposed) return
    running = true
    lastNow = 0
    rafId = requestAnimationFrame(frame)
  }

  const stop = () => {
    running = false
    cancelAnimationFrame(rafId)
  }

  const handleContextLost = (event: Event) => {
    event.preventDefault()
    stop()
  }
  const handleContextRestored = () => {
    variant.onPalette?.(runtime)
    runtime.requestRender()
  }
  canvas.addEventListener('webglcontextlost', handleContextLost)
  canvas.addEventListener('webglcontextrestored', handleContextRestored)

  const toNdc = (x: number, y: number, target: THREE.Vector2) =>
    target.set(x * 2 - 1, -(y * 2 - 1))

  const controller: Init5SceneController = {
    setPointer: (x, y) => {
      if (x === null || y === null) {
        runtime.pointer.inside = false
        return
      }
      toNdc(x, y, runtime.pointer.target)
      if (!runtime.pointer.inside && runtime.pointer.presence < 0.02) {
        // Entering from outside: start the smoothed pointer where the cursor is.
        runtime.pointer.ndc.copy(runtime.pointer.target)
      }
      runtime.pointer.inside = true
    },
    pulse: (x, y) => {
      if (runtime.reducedMotion) return
      variant.onPulse?.(runtime, toNdc(x, y, new THREE.Vector2()))
    },
    setScrollProgress: (progress) => {
      scrollTarget = runtime.reducedMotion ? 0 : clamp01(progress)
    },
    setPalette: (palette) => {
      runtime.palette = palette
      variant.onPalette?.(runtime)
      runtime.requestRender()
    },
    setLayout: (next) => {
      layout = next
      applyLayout()
      variant.onResize?.(runtime)
      runtime.requestRender()
    },
    resize: (width, height) => {
      runtime.width = Math.max(1, width)
      runtime.height = Math.max(1, height)
      renderer.setSize(runtime.width, runtime.height, false)
      camera.aspect = runtime.width / runtime.height
      camera.updateProjectionMatrix()
      applyLayout()
      variant.onResize?.(runtime)
      runtime.requestRender()
    },
    setActive: (active) => {
      wantsActive = active
      if (runtime.reducedMotion || frozen) {
        if (active) runtime.requestRender()
        return
      }
      if (active) start()
      else stop()
    },
    replayIntro: () => {
      if (runtime.reducedMotion) return
      runtime.time = 0
      variant.onReplay?.(runtime)
    },
    seek: (seconds) => {
      if (seconds === null) {
        frozen = false
        if (wantsActive) start()
        return
      }
      frozen = true
      stop()
      runtime.time = 0
      runtime.clock = 0
      variant.onReplay?.(runtime)
      // Step in small increments so smoothed values (rotation, sweeps) settle naturally.
      const stepSize = 1 / 60
      let remaining = Math.max(0, seconds)
      while (remaining > stepSize) {
        runtime.time += stepSize
        runtime.clock += stepSize
        variant.update(runtime, stepSize)
        remaining -= stepSize
      }
      runtime.time += remaining
      runtime.clock += remaining
      variant.update(runtime, remaining)
      renderer.render(scene, camera)
    },
    dispose: () => {
      if (disposed) return
      disposed = true
      stop()
      cancelAnimationFrame(pendingRender)
      canvas.removeEventListener('webglcontextlost', handleContextLost)
      canvas.removeEventListener('webglcontextrestored', handleContextRestored)
      variant.dispose()
      renderer.dispose()
      renderer.forceContextLoss()
    },
  }

  if (import.meta.env.DEV) {
    // Console access for tuning: `__init5Hero.__runtime.scene`.
    Object.defineProperty(controller, '__runtime', { value: runtime })
  }

  return controller
}
