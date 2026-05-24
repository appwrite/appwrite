import { memo, useEffect, useRef, type RefObject } from 'react'
import { cn } from '@/lib/utils'

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
}

type ClickRipple = {
  x: number
  y: number
  time: number
}

/** Appwrite brand palette */
const BRAND = {
  primary: [253, 54, 110] as Rgb,
  dark: [25, 25, 29] as Rgb,
  light: [237, 237, 240] as Rgb,
}

function particleCountForSize(size: number): number {
  if (size <= 40) return 700
  if (size <= 72) return 1100
  if (size <= 120) return 1600
  return 2000
}

function mixRgb(a: Rgb, b: Rgb, t: number): Rgb {
  return [
    Math.round(a[0] + (b[0] - a[0]) * t),
    Math.round(a[1] + (b[1] - a[1]) * t),
    Math.round(a[2] + (b[2] - a[2]) * t),
  ]
}

/** ~85% primary, ~8% light highlights, ~7% dark depth */
function pickParticleColor(): Rgb {
  const roll = Math.random()

  if (roll < 0.85) {
    const variant = Math.random()
    if (variant < 0.12) {
      return mixRgb(BRAND.primary, BRAND.dark, 0.18)
    }
    if (variant < 0.2) {
      return mixRgb(BRAND.primary, BRAND.light, 0.15)
    }
    return BRAND.primary
  }

  if (roll < 0.93) {
    return mixRgb(BRAND.primary, BRAND.light, 0.35 + Math.random() * 0.45)
  }

  return mixRgb(BRAND.primary, BRAND.dark, 0.4 + Math.random() * 0.35)
}

/** Uniform distribution throughout a solid sphere volume */
function createParticles(count: number): Particle[] {
  return Array.from({ length: count }, () => ({
    theta: Math.random() * Math.PI * 2,
    phi: Math.acos(2 * Math.random() - 1),
    radiusFactor: Math.cbrt(Math.random()),
    brightness: 0.35 + Math.random() * 0.65,
    phase: Math.random() * Math.PI * 2,
    color: pickParticleColor(),
  }))
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
}: ThinkingBubbleProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const particlesRef = useRef<Particle[]>(
    createParticles(particleCountForSize(size)),
  )
  const frameRef = useRef<number | null>(null)
  const reducedMotionRef = useRef(false)
  const staticActivityRef = useRef(activity)
  const smoothedActivityRef = useRef(activity)
  const externalActivityRef = activityRef
  const interactiveRef = useRef(interactive)

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

  useEffect(() => {
    particlesRef.current = createParticles(particleCountForSize(size))
  }, [size])

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
    canvas.width = size * dpr
    canvas.height = size * dpr
    canvas.style.width = `${size}px`
    canvas.style.height = `${size}px`
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

    const center = size / 2
    const sphereRadius = size * 0.34

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

      ctx.clearRect(0, 0, size, size)

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

      const leanX = interactiveRef.current ? currentMouse.x * size * 0.07 : 0
      const leanY = interactiveRef.current ? currentMouse.y * size * 0.07 : 0

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

      for (const particle of particlesRef.current) {
        const jitter =
          Math.sin(elapsed * 2.4 * motionScale + particle.phase) *
          sphereRadius *
          0.004
        const radius = sphereRadius * particle.radiusFactor * pulse

        const localX =
          radius * Math.sin(particle.phi) * Math.cos(particle.theta + jitter)
        const localY =
          radius * Math.sin(particle.phi) * Math.sin(particle.theta + jitter)
        const localZ = radius * Math.cos(particle.phi)

        const afterY = rotateY(localX, localY, localZ, rotY)
        const afterX = rotateX(afterY.x, afterY.y, afterY.z, rotX)

        const perspective = 1 / (1 + afterX.z / (size * 1.8))
        let sx = center + afterX.x * perspective + leanX
        let sy = center + afterX.y * perspective + leanY

        for (const ripple of clickRipplesRef.current) {
          const age = (timestamp - ripple.time) / 900
          const dx = sx - ripple.x
          const dy = sy - ripple.y
          const dist = Math.hypot(dx, dy)
          const waveRadius = age * size * 0.55
          const waveWidth = size * 0.14
          const waveStrength =
            (1 - age) * Math.exp(-Math.abs(dist - waveRadius) / waveWidth)
          if (waveStrength > 0.01) {
            const push = waveStrength * size * 0.035
            const invDist = dist > 0.001 ? 1 / dist : 0
            sx += dx * invDist * push
            sy += dy * invDist * push
          }
        }

        const mouseFacing = interactiveRef.current
          ? 1 +
            (currentMouse.x * (sx - center) + currentMouse.y * (sy - center)) /
              (size * 0.35)
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
        ctx.fillStyle = `rgba(${r}, ${g}, ${b}, ${alpha})`
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
  }, [size, externalActivityRef])

  return (
    <div
      ref={containerRef}
      className={cn(
        'relative select-none',
        interactive && 'cursor-pointer',
        className,
      )}
      style={{ width: size, height: size }}
      aria-hidden
    >
      <canvas ref={canvasRef} className="block" />
    </div>
  )
})
