import { memo, useEffect, useLayoutEffect, useRef, type RefObject } from 'react'
import { cn } from '@/lib/utils'

export type SphereColorMode =
  | 'brand'
  | 'blue'
  | 'green'
  | 'purple'
  | 'amber'
  | 'cyan'

export type SphereShapeMode =
  | 'sphere'
  | 'torus'
  | 'disc'
  | 'ring'
  | 'cube'
  | 'helix'

export const SPHERE_SIZE_SCALE_DEFAULT = 1
export const SPHERE_SIZE_SCALE_MIN = 0.125
export const SPHERE_SIZE_SCALE_MAX = 2
export const SPHERE_SIZE_SCALE_STEP = 0.05

export function scaleSphereSize(
  baseSize: number,
  scale: number = SPHERE_SIZE_SCALE_DEFAULT,
): number {
  const clamped = Math.min(
    SPHERE_SIZE_SCALE_MAX,
    Math.max(SPHERE_SIZE_SCALE_MIN, scale),
  )
  return Math.round(baseSize * clamped)
}

type ColorPalette = {
  primary: Rgb
  dark: Rgb
  light: Rgb
}

type ThinkingBubbleProps = {
  /** Canvas width and height in pixels */
  size?: number
  className?: string
  /** Static activity fallback when activityRef is not provided */
  activity?: number
  /** Live activity value updated outside React render (preferred) */
  activityRef?: RefObject<number>
  /** Pointer interaction (tilt, click ripples) */
  interactive?: boolean
  /** Particle color palette */
  colorMode?: SphereColorMode
  /** Particle layout shape */
  shapeMode?: SphereShapeMode
  /** Explicit particle count; omit for size-based default */
  particleCount?: number | null
  /** Center within the parent layout box (default true) */
  centered?: boolean
}

type Rgb = [number, number, number]

type Particle = {
  theta: number
  phi: number
  /** 0–1 radial position within the sphere volume */
  radiusFactor: number
  brightness: number
  phase: number
  color: Rgb
  targetColor: Rgb
}

type ClickRipple = {
  x: number
  y: number
  time: number
}

const COLOR_PALETTES: Record<SphereColorMode, ColorPalette> = {
  brand: {
    primary: [253, 54, 110],
    dark: [25, 25, 29],
    light: [237, 237, 240],
  },
  blue: {
    primary: [59, 130, 246],
    dark: [15, 23, 42],
    light: [219, 234, 254],
  },
  green: {
    primary: [34, 197, 94],
    dark: [20, 83, 45],
    light: [220, 252, 231],
  },
  purple: {
    primary: [168, 85, 247],
    dark: [46, 16, 101],
    light: [243, 232, 255],
  },
  amber: {
    primary: [245, 158, 11],
    dark: [69, 26, 3],
    light: [254, 243, 199],
  },
  cyan: {
    primary: [6, 182, 212],
    dark: [8, 51, 68],
    light: [207, 250, 254],
  },
}

function particleCountForSize(size: number): number {
  // Scale roughly with area so composer/turn bubbles stay light; hero stays dense.
  const clamped = Math.max(16, Math.min(280, size))
  const normalized = (clamped - 16) / (220 - 16)
  const curved = Math.pow(Math.min(1, Math.max(0, normalized)), 1.45)
  return Math.round(72 + curved * 1928)
}

export const SPHERE_PARTICLE_COUNT_MIN = 50
export const SPHERE_PARTICLE_COUNT_MAX = 4000
export const SPHERE_PARTICLE_COUNT_STEP = 25

export function defaultParticleCountForSize(size: number): number {
  return particleCountForSize(size)
}

function resolveParticleCount(
  size: number,
  explicit?: number | null,
): number {
  if (explicit != null && explicit > 0) {
    return Math.min(
      SPHERE_PARTICLE_COUNT_MAX,
      Math.max(SPHERE_PARTICLE_COUNT_MIN, Math.round(explicit)),
    )
  }
  return particleCountForSize(size)
}

function mixRgb(a: Rgb, b: Rgb, t: number): Rgb {
  return [
    Math.round(a[0] + (b[0] - a[0]) * t),
    Math.round(a[1] + (b[1] - a[1]) * t),
    Math.round(a[2] + (b[2] - a[2]) * t),
  ]
}

function lerpRgb(a: Rgb, b: Rgb, t: number): Rgb {
  return [
    a[0] + (b[0] - a[0]) * t,
    a[1] + (b[1] - a[1]) * t,
    a[2] + (b[2] - a[2]) * t,
  ]
}

/** ~85% primary, ~8% light highlights, ~7% dark depth */
function pickParticleColor(palette: ColorPalette): Rgb {
  const roll = Math.random()

  if (roll < 0.85) {
    const variant = Math.random()
    if (variant < 0.12) {
      return mixRgb(palette.primary, palette.dark, 0.18)
    }
    if (variant < 0.2) {
      return mixRgb(palette.primary, palette.light, 0.15)
    }
    return palette.primary
  }

  if (roll < 0.93) {
    return mixRgb(palette.primary, palette.light, 0.35 + Math.random() * 0.45)
  }

  return mixRgb(palette.primary, palette.dark, 0.4 + Math.random() * 0.35)
}

function createParticleLayout(shapeMode: SphereShapeMode): Pick<
  Particle,
  'theta' | 'phi' | 'radiusFactor'
> {
  switch (shapeMode) {
    case 'torus':
      return {
        theta: Math.random() * Math.PI * 2,
        phi: Math.random() * Math.PI * 2,
        radiusFactor: 1,
      }
    case 'disc':
      return {
        theta: Math.random() * Math.PI * 2,
        phi: (Math.random() - 0.5) * 0.35,
        radiusFactor: Math.sqrt(Math.random()),
      }
    case 'ring':
      return {
        theta: Math.random() * Math.PI * 2,
        phi: (Math.random() - 0.5) * 0.4,
        radiusFactor: Math.random(),
      }
    case 'cube':
      return {
        theta: Math.random() * 2 - 1,
        phi: Math.random() * 2 - 1,
        radiusFactor: Math.random() * 2 - 1,
      }
    case 'helix':
      return {
        theta: Math.random(),
        phi: Math.random() * Math.PI * 2,
        radiusFactor: 0.55 + Math.random() * 0.45,
      }
    case 'sphere':
    default:
      return {
        theta: Math.random() * Math.PI * 2,
        phi: Math.acos(2 * Math.random() - 1),
        radiusFactor: Math.cbrt(Math.random()),
      }
  }
}

function particleLocalPosition(
  particle: Particle,
  shapeMode: SphereShapeMode,
  radius: number,
  jitter: number,
): { x: number; y: number; z: number } {
  const angle = particle.theta + jitter

  switch (shapeMode) {
    case 'torus': {
      const major = radius * 0.58
      const minor = radius * 0.26
      const tube = minor * Math.cos(particle.phi)
      return {
        x: (major + tube) * Math.cos(angle),
        y: (major + tube) * Math.sin(angle),
        z: minor * Math.sin(particle.phi),
      }
    }
    case 'disc': {
      const r = radius * particle.radiusFactor
      return {
        x: r * Math.cos(angle),
        y: r * Math.sin(angle),
        z: radius * particle.phi * 0.35,
      }
    }
    case 'ring': {
      const inner = radius * 0.62
      const outer = radius * 0.98
      const r = inner + particle.radiusFactor * (outer - inner)
      return {
        x: r * Math.cos(angle),
        y: r * Math.sin(angle),
        z: radius * particle.phi * 0.3,
      }
    }
    case 'cube':
      return {
        x: radius * particle.theta,
        y: radius * particle.phi,
        z: radius * particle.radiusFactor,
      }
    case 'helix': {
      const turns = 2.75
      const helixAngle = particle.theta * turns * Math.PI * 2
      const core = radius * 0.34
      const tube = radius * 0.11 * particle.radiusFactor
      const radial = core + tube * Math.cos(particle.phi)
      return {
        x: radial * Math.cos(helixAngle),
        y: (particle.theta * 2 - 1) * radius * 1.05,
        z: radial * Math.sin(helixAngle) + tube * Math.sin(particle.phi) * 0.35,
      }
    }
    case 'sphere':
    default: {
      const r = radius * particle.radiusFactor
      return {
        x: r * Math.sin(particle.phi) * Math.cos(angle),
        y: r * Math.sin(particle.phi) * Math.sin(angle),
        z: r * Math.cos(particle.phi),
      }
    }
  }
}

function remapParticleLayouts(
  particles: Particle[],
  shapeMode: SphereShapeMode,
) {
  for (const particle of particles) {
    const layout = createParticleLayout(shapeMode)
    particle.theta = layout.theta
    particle.phi = layout.phi
    particle.radiusFactor = layout.radiusFactor
  }
}

/** Uniform distribution throughout a solid sphere volume */
function createParticles(
  count: number,
  colorMode: SphereColorMode,
  shapeMode: SphereShapeMode,
): Particle[] {
  const palette = COLOR_PALETTES[colorMode]
  return Array.from({ length: count }, () => {
    const color = pickParticleColor(palette)
    const layout = createParticleLayout(shapeMode)
    return {
      ...layout,
      brightness: 0.35 + Math.random() * 0.65,
      phase: Math.random() * Math.PI * 2,
      color,
      targetColor: color,
    }
  })
}

function ensureParticlesUpTo(
  particles: Particle[],
  targetCount: number,
  colorMode: SphereColorMode,
  shapeMode: SphereShapeMode,
) {
  const neededCount = Math.min(
    SPHERE_PARTICLE_COUNT_MAX,
    Math.max(SPHERE_PARTICLE_COUNT_MIN, targetCount),
  )
  if (particles.length >= neededCount) return

  const palette = COLOR_PALETTES[colorMode]
  while (particles.length < neededCount) {
    const color = pickParticleColor(palette)
    particles.push({
      ...createParticleLayout(shapeMode),
      brightness: 0.35 + Math.random() * 0.65,
      phase: Math.random() * Math.PI * 2,
      color,
      targetColor: color,
    })
  }
}

function syncCanvas(
  ctx: CanvasRenderingContext2D,
  canvas: HTMLCanvasElement,
  renderSize: number,
  dpr: number,
) {
  const pixelSize = Math.max(1, Math.ceil(renderSize * dpr))
  if (canvas.width !== pixelSize || canvas.height !== pixelSize) {
    canvas.width = pixelSize
    canvas.height = pixelSize
  }
  canvas.style.width = `${renderSize}px`
  canvas.style.height = `${renderSize}px`
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
}

function rotateY(x: number, y: number, z: number, angle: number) {
  const cos = Math.cos(angle)
  const sin = Math.sin(angle)
  return {
    x: x * cos + z * sin,
    y,
    z: -x * sin + z * cos,
  }
}

function rotateX(x: number, y: number, z: number, angle: number) {
  const cos = Math.cos(angle)
  const sin = Math.sin(angle)
  return {
    x,
    y: y * cos - z * sin,
    z: y * sin + z * cos,
  }
}

export const ThinkingBubble = memo(function ThinkingBubble({
  size = 220,
  className,
  activity = 0.15,
  activityRef,
  interactive = true,
  colorMode = 'brand',
  shapeMode = 'sphere',
  particleCount,
  centered = true,
}: ThinkingBubbleProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const initialParticleCount = resolveParticleCount(size, particleCount)
  const particlesRef = useRef<Particle[]>(
    createParticles(initialParticleCount, colorMode, shapeMode),
  )
  const frameRef = useRef<number | null>(null)
  const reducedMotionRef = useRef(false)
  const staticActivityRef = useRef(activity)
  const smoothedActivityRef = useRef(activity)
  const externalActivityRef = activityRef
  const interactiveRef = useRef(interactive)
  const targetSizeRef = useRef(size)
  const displaySizeRef = useRef(size)
  const colorModeRef = useRef(colorMode)
  const shapeModeRef = useRef(shapeMode)
  const particleCountRef = useRef<number | null | undefined>(particleCount)
  const targetParticleCountRef = useRef(initialParticleCount)
  const displayParticleCountRef = useRef(initialParticleCount)

  const targetMouseRef = useRef({ x: 0, y: 0, active: false })
  const currentMouseRef = useRef({ x: 0, y: 0 })
  const clickRipplesRef = useRef<ClickRipple[]>([])
  const clickPulseRef = useRef(0)

  useEffect(() => {
    staticActivityRef.current = activity
  }, [activity])

  useEffect(() => {
    interactiveRef.current = interactive
  }, [interactive])

  useLayoutEffect(() => {
    const container = containerRef.current
    const canvas = canvasRef.current
    if (!container || !canvas) return

    const ctx = canvas.getContext('2d', { alpha: true })
    if (!ctx) return

    const dpr = Math.min(window.devicePixelRatio || 1, 2)
    const layoutSize = displaySizeRef.current
    container.style.width = `${layoutSize}px`
    container.style.height = `${layoutSize}px`
    syncCanvas(ctx, canvas, layoutSize, dpr)
  }, [size])

  useEffect(() => {
    targetSizeRef.current = size
    targetParticleCountRef.current = resolveParticleCount(
      size,
      particleCountRef.current,
    )
    ensureParticlesUpTo(
      particlesRef.current,
      targetParticleCountRef.current,
      colorModeRef.current,
      shapeModeRef.current,
    )
  }, [size])

  useEffect(() => {
    particleCountRef.current = particleCount
    targetParticleCountRef.current = resolveParticleCount(
      targetSizeRef.current,
      particleCount,
    )
    ensureParticlesUpTo(
      particlesRef.current,
      targetParticleCountRef.current,
      colorModeRef.current,
      shapeModeRef.current,
    )
  }, [particleCount])

  useEffect(() => {
    colorModeRef.current = colorMode
    const palette = COLOR_PALETTES[colorMode]
    for (const particle of particlesRef.current) {
      particle.targetColor = pickParticleColor(palette)
    }
  }, [colorMode])

  useEffect(() => {
    shapeModeRef.current = shapeMode
    remapParticleLayouts(particlesRef.current, shapeMode)
  }, [shapeMode])

  useEffect(() => {
    reducedMotionRef.current = window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches

    const container = containerRef.current
    const canvas = canvasRef.current
    if (!container || !canvas) return

    const ctx = canvas.getContext('2d', { alpha: true })
    if (!ctx) return

    const dpr = Math.min(window.devicePixelRatio || 1, 2)
    syncCanvas(ctx, canvas, displaySizeRef.current, dpr)
    container.style.width = `${displaySizeRef.current}px`
    container.style.height = `${displaySizeRef.current}px`

    const handlePointerMove = (event: PointerEvent) => {
      if (!interactiveRef.current) return
      const rect = container.getBoundingClientRect()
      const x = ((event.clientX - rect.left) / rect.width) * 2 - 1
      const y = ((event.clientY - rect.top) / rect.height) * 2 - 1
      targetMouseRef.current = {
        x: Math.max(-1, Math.min(1, x)),
        y: Math.max(-1, Math.min(1, y)),
        active: true,
      }
    }

    const handlePointerLeave = () => {
      if (!interactiveRef.current) return
      targetMouseRef.current = { x: 0, y: 0, active: false }
    }

    const handlePointerDown = (event: PointerEvent) => {
      if (!interactiveRef.current) return
      const rect = container.getBoundingClientRect()
      clickRipplesRef.current.push({
        x: event.clientX - rect.left,
        y: event.clientY - rect.top,
        time: performance.now(),
      })
      if (clickRipplesRef.current.length > 6) {
        clickRipplesRef.current.shift()
      }
      clickPulseRef.current = 1
    }

    container.addEventListener('pointermove', handlePointerMove)
    container.addEventListener('pointerleave', handlePointerLeave)
    container.addEventListener('pointerdown', handlePointerDown)

    let startTime = performance.now()
    let lastFrameTime = performance.now()
    let rotationY = 0.4
    let rotationX = 0.4
    let angularVelocityY = 0.15
    let angularVelocityX = 0.1

    const draw = (timestamp: number) => {
      const elapsed = (timestamp - startTime) / 1000
      const dt = Math.min(0.05, (timestamp - lastFrameTime) / 1000)
      lastFrameTime = timestamp
      const motionScale = reducedMotionRef.current ? 0.15 : 1

      const targetSize = targetSizeRef.current
      const displaySize = displaySizeRef.current
      const sizeDelta = targetSize - displaySize
      if (reducedMotionRef.current) {
        displaySizeRef.current = targetSize
      } else if (Math.abs(sizeDelta) > 0.25) {
        displaySizeRef.current =
          displaySize + sizeDelta * (1 - Math.exp(-10 * dt))
      } else {
        displaySizeRef.current = targetSize
      }
      const renderSize = displaySizeRef.current

      container.style.width = `${renderSize}px`
      container.style.height = `${renderSize}px`
      syncCanvas(ctx, canvas, renderSize, dpr)

      const targetActivity =
        externalActivityRef?.current ?? staticActivityRef.current
      const smoothed = smoothedActivityRef.current
      const activityLevel =
        smoothed +
        (targetActivity - smoothed) * (1 - Math.exp(-12 * dt))
      smoothedActivityRef.current = activityLevel

      const targetMouse = targetMouseRef.current
      const currentMouse = currentMouseRef.current
      const lerp = targetMouse.active ? 0.1 : 0.04
      currentMouse.x += (targetMouse.x - currentMouse.x) * lerp
      currentMouse.y += (targetMouse.y - currentMouse.y) * lerp

      clickPulseRef.current *= 0.92
      if (clickPulseRef.current < 0.01) clickPulseRef.current = 0

      const mouseInfluence = interactiveRef.current
        ? Math.hypot(currentMouse.x, currentMouse.y)
        : 0
      const clickBoost = clickPulseRef.current * 0.06

      ctx.clearRect(0, 0, renderSize, renderSize)

      const targetParticleCount = targetParticleCountRef.current
      let displayParticleCount = displayParticleCountRef.current
      const particleCountDelta = targetParticleCount - displayParticleCount
      if (reducedMotionRef.current) {
        displayParticleCount = targetParticleCount
      } else if (Math.abs(particleCountDelta) > 0.5) {
        displayParticleCount +=
          particleCountDelta * (1 - Math.exp(-12 * dt))
      } else {
        displayParticleCount = targetParticleCount
      }
      displayParticleCountRef.current = displayParticleCount
      const activeParticleCount = Math.min(
        particlesRef.current.length,
        Math.max(
          0,
          Math.round(displayParticleCount),
        ),
      )
      ensureParticlesUpTo(
        particlesRef.current,
        Math.max(activeParticleCount, targetParticleCount),
        colorModeRef.current,
        shapeModeRef.current,
      )

      const colorLerp = reducedMotionRef.current ? 1 : 1 - Math.exp(-8 * dt)
      for (let index = 0; index < particlesRef.current.length; index += 1) {
        const particle = particlesRef.current[index]
        particle.color = lerpRgb(
          particle.color,
          particle.targetColor,
          colorLerp,
        )
      }

      const center = renderSize / 2
      const sphereRadius = renderSize * 0.34

      const spinDrive = activityLevel ** 1.2
      const targetAngularY =
        (0.1 + spinDrive * 1.25) * motionScale + mouseInfluence * 0.25
      const targetAngularX = (0.06 + spinDrive * 0.78) * motionScale

      angularVelocityY +=
        (targetAngularY - angularVelocityY) * (1 - Math.exp(-10 * dt))
      angularVelocityX +=
        (targetAngularX - angularVelocityX) * (1 - Math.exp(-10 * dt))

      rotationY += angularVelocityY * dt
      rotationX += angularVelocityX * dt

      const pulse =
        1 + Math.sin(elapsed * 1.2 * motionScale) * 0.04 + clickBoost
      const rotY = rotationY + (interactiveRef.current ? currentMouse.x * 1.1 : 0)
      const rotX = rotationX + (interactiveRef.current ? currentMouse.y * 0.85 : 0)

      const leanX = interactiveRef.current ? currentMouse.x * renderSize * 0.07 : 0
      const leanY = interactiveRef.current ? currentMouse.y * renderSize * 0.07 : 0

      clickRipplesRef.current = clickRipplesRef.current.filter(
        (ripple) => timestamp - ripple.time < 900,
      )

      const projected: Array<{
        sx: number
        sy: number
        depth: number
        brightness: number
        dotSize: number
        color: Rgb
      }> = []

      for (let index = 0; index < activeParticleCount; index += 1) {
        const particle = particlesRef.current[index]
        const jitter =
          Math.sin(elapsed * 2.4 * motionScale + particle.phase) *
          sphereRadius *
          0.004
        const radius = sphereRadius * pulse
        const { x: localX, y: localY, z: localZ } = particleLocalPosition(
          particle,
          shapeModeRef.current,
          radius,
          jitter,
        )

        const afterY = rotateY(localX, localY, localZ, rotY)
        const afterX = rotateX(afterY.x, afterY.y, afterY.z, rotX)

        const perspective = 1 / (1 + afterX.z / (renderSize * 1.8))
        let sx = center + afterX.x * perspective + leanX
        let sy = center + afterX.y * perspective + leanY

        for (const ripple of clickRipplesRef.current) {
          const age = (timestamp - ripple.time) / 900
          const dx = sx - ripple.x
          const dy = sy - ripple.y
          const dist = Math.hypot(dx, dy)
          const waveRadius = age * renderSize * 0.55
          const waveWidth = renderSize * 0.14
          const waveStrength =
            (1 - age) * Math.exp(-Math.abs(dist - waveRadius) / waveWidth)
          if (waveStrength > 0.01) {
            const push = waveStrength * renderSize * 0.035
            const invDist = dist > 0.001 ? 1 / dist : 0
            sx += dx * invDist * push
            sy += dy * invDist * push
          }
        }

        const mouseFacing = interactiveRef.current
          ? 1 +
            (currentMouse.x * (sx - center) + currentMouse.y * (sy - center)) /
              (renderSize * 0.35)
          : 1
        const facingBoost = Math.max(0.75, Math.min(1.2, mouseFacing))

        projected.push({
          sx,
          sy,
          depth: afterX.z,
          brightness:
            particle.brightness * (0.55 + perspective * 0.55) * facingBoost,
          dotSize: (0.55 + particle.brightness * 0.75) * perspective,
          color: particle.color,
        })
      }

      projected.sort((a, b) => a.depth - b.depth)

      for (const point of projected) {
        const [r, g, b] = point.color
        const alpha = Math.min(0.72, point.brightness * 0.65)

        ctx.beginPath()
        ctx.arc(point.sx, point.sy, point.dotSize, 0, Math.PI * 2)
        ctx.fillStyle = `rgba(${Math.round(r)}, ${Math.round(g)}, ${Math.round(b)}, ${alpha})`
        ctx.fill()
      }

      frameRef.current = requestAnimationFrame(draw)
    }

    frameRef.current = requestAnimationFrame(draw)

    return () => {
      container.removeEventListener('pointermove', handlePointerMove)
      container.removeEventListener('pointerleave', handlePointerLeave)
      container.removeEventListener('pointerdown', handlePointerDown)
      if (frameRef.current != null) {
        cancelAnimationFrame(frameRef.current)
      }
    }
  }, [externalActivityRef])

  return (
    <div
      className={cn(
        'relative flex shrink-0 items-center justify-center',
        centered && 'mx-auto',
        className,
      )}
      style={{ width: size, height: size }}
      aria-hidden
    >
      <div
        ref={containerRef}
        className={cn('relative select-none', interactive && 'cursor-pointer')}
      >
        <canvas ref={canvasRef} className="block size-full" />
      </div>
    </div>
  )
})
