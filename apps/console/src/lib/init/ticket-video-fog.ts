import type { TicketVideoPoint } from '@/lib/init/ticket-video-perspective'

/**
 * Init 5's electric fog for the exported ticket video: the hero's peach, pink
 * and violet fog drifting behind the ticket, comets of light sweeping through
 * it, and two comets circling the ticket's edge. Everything is a function of
 * clip progress (0-1) with whole-number cycles, so the clip loops seamlessly.
 */

export type InitTicketVideoFogPalette = {
  /** `--brand-cta` (pink). */
  accent: string
  /** `--network-globe-edge` (violet). */
  violet: string
  /** `--network-globe-region` (peach). */
  peach: string
}

export type InitTicketVideoFog = {
  /** Fog and drifting light trails, drawn behind the ticket. */
  drawBackdrop: (ctx: CanvasRenderingContext2D, progress: number) => void
  /** Comets circling the ticket's projected edge, drawn over it. */
  drawEdge: (
    ctx: CanvasRenderingContext2D,
    quad: [
      TicketVideoPoint,
      TicketVideoPoint,
      TicketVideoPoint,
      TicketVideoPoint,
    ],
    progress: number,
  ) => void
}

type Rgb = [number, number, number]

const TAU = Math.PI * 2

/** Any CSS color (oklch included) to sRGB through a 1x1 canvas. */
function toRgb(color: string, fallback: Rgb): Rgb {
  const probe = document.createElement('canvas')
  probe.width = 1
  probe.height = 1
  const ctx = probe.getContext('2d', { willReadFrequently: true })
  if (!ctx) return fallback
  ctx.fillStyle = `rgb(${fallback.join(' ')})`
  ctx.fillStyle = color
  ctx.fillRect(0, 0, 1, 1)
  const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data
  return [r, g, b]
}

function rgba([r, g, b]: Rgb, alpha: number) {
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

function mix(a: Rgb, b: Rgb, t: number): Rgb {
  return [
    Math.round(a[0] + (b[0] - a[0]) * t),
    Math.round(a[1] + (b[1] - a[1]) * t),
    Math.round(a[2] + (b[2] - a[2]) * t),
  ]
}

/** Violet tail -> pink body -> peach head, along a comet (0 = tail, 1 = head). */
function trailColor(palette: [Rgb, Rgb, Rgb], t: number): Rgb {
  const [violet, accent, peach] = palette
  return t < 0.6
    ? mix(violet, accent, t / 0.6)
    : mix(accent, peach, (t - 0.6) / 0.4)
}

type Blob = {
  color: 0 | 1 | 2
  /** Rest position, as a fraction of the frame. */
  x: number
  y: number
  radius: number
  /** Drift, as a fraction of the frame, and whole cycles per clip. */
  drift: number
  cycles: number
  phase: number
}

// Fog banks sit around the ticket so they show past its edges.
const BLOBS: Blob[] = [
  { color: 1, x: 0.18, y: 0.3, radius: 0.36, drift: 0.05, cycles: 1, phase: 0 },
  {
    color: 0,
    x: 0.84,
    y: 0.24,
    radius: 0.32,
    drift: 0.06,
    cycles: 1,
    phase: 0.3,
  },
  {
    color: 2,
    x: 0.78,
    y: 0.82,
    radius: 0.38,
    drift: 0.05,
    cycles: 2,
    phase: 0.6,
  },
  {
    color: 1,
    x: 0.24,
    y: 0.84,
    radius: 0.3,
    drift: 0.07,
    cycles: 1,
    phase: 0.15,
  },
  {
    color: 0,
    x: 0.5,
    y: 0.08,
    radius: 0.28,
    drift: 0.04,
    cycles: 2,
    phase: 0.45,
  },
  {
    color: 2,
    x: 0.06,
    y: 0.6,
    radius: 0.24,
    drift: 0.05,
    cycles: 1,
    phase: 0.8,
  },
]

type Streak = {
  /** Vertical rest line and wave, as fractions of the frame height. */
  y: number
  amplitude: number
  waves: number
  /** Laps across the frame per clip, and offset along the lap. */
  laps: number
  offset: number
  /** Comet length as a fraction of the frame width. */
  length: number
  direction: 1 | -1
}

const STREAKS: Streak[] = [
  {
    y: 0.2,
    amplitude: 0.08,
    waves: 1.3,
    laps: 3,
    offset: 0,
    length: 0.42,
    direction: 1,
  },
  {
    y: 0.78,
    amplitude: 0.1,
    waves: 1.1,
    laps: 2,
    offset: 0.55,
    length: 0.5,
    direction: -1,
  },
  {
    y: 0.52,
    amplitude: 0.16,
    waves: 0.8,
    laps: 2,
    offset: 0.25,
    length: 0.36,
    direction: 1,
  },
]

const COMET_SEGMENTS = 36

/** Stroke a comet along `points` (tail first), fading in and thinning toward the tail. */
function strokeComet(
  ctx: CanvasRenderingContext2D,
  points: TicketVideoPoint[],
  palette: [Rgb, Rgb, Rgb],
  width: number,
  intensity: number,
) {
  const last = points.length - 1
  for (let pass = 0; pass < 3; pass += 1) {
    // Wide soft glow, then a halo, then the bright core.
    const passWidth = width * [9, 3.5, 1][pass]
    const passAlpha = intensity * [0.06, 0.16, 0.9][pass]
    for (let index = 1; index <= last; index += 1) {
      const t = index / last
      const color =
        pass === 2 && t > 0.92
          ? mix(trailColor(palette, t), [255, 255, 255], 0.5)
          : trailColor(palette, t)
      ctx.strokeStyle = rgba(color, passAlpha * t * t)
      ctx.lineWidth = Math.max(0.5, passWidth * (0.35 + 0.65 * t))
      ctx.beginPath()
      ctx.moveTo(points[index - 1].x, points[index - 1].y)
      ctx.lineTo(points[index].x, points[index].y)
      ctx.stroke()
    }
  }

  // The head: a small hot point with a halo.
  const head = points[last]
  const glow = ctx.createRadialGradient(
    head.x,
    head.y,
    0,
    head.x,
    head.y,
    width * 7,
  )
  glow.addColorStop(0, rgba(mix(palette[2], [255, 255, 255], 0.6), intensity))
  glow.addColorStop(0.25, rgba(palette[1], intensity * 0.35))
  glow.addColorStop(1, rgba(palette[1], 0))
  ctx.fillStyle = glow
  ctx.beginPath()
  ctx.arc(head.x, head.y, width * 7, 0, TAU)
  ctx.fill()
}

/** Points along the ticket's projected outline with rounded corners. */
function roundedOutline(
  quad: [
    TicketVideoPoint,
    TicketVideoPoint,
    TicketVideoPoint,
    TicketVideoPoint,
  ],
  radius: number,
  perCorner: number,
): TicketVideoPoint[] {
  const points: TicketVideoPoint[] = []
  for (let index = 0; index < 4; index += 1) {
    const prev = quad[(index + 3) % 4]
    const corner = quad[index]
    const next = quad[(index + 1) % 4]
    const toPrev = Math.hypot(prev.x - corner.x, prev.y - corner.y) || 1
    const toNext = Math.hypot(next.x - corner.x, next.y - corner.y) || 1
    const inPrev = Math.min(radius, toPrev / 2) / toPrev
    const inNext = Math.min(radius, toNext / 2) / toNext
    const start = {
      x: corner.x + (prev.x - corner.x) * inPrev,
      y: corner.y + (prev.y - corner.y) * inPrev,
    }
    const end = {
      x: corner.x + (next.x - corner.x) * inNext,
      y: corner.y + (next.y - corner.y) * inNext,
    }
    for (let step = 0; step <= perCorner; step += 1) {
      const t = step / perCorner
      const u = 1 - t
      points.push({
        x: u * u * start.x + 2 * u * t * corner.x + t * t * end.x,
        y: u * u * start.y + 2 * u * t * corner.y + t * t * end.y,
      })
    }
  }
  return points
}

/** Point at `distance` along a closed polyline with precomputed cumulative lengths. */
function pointAlong(
  points: TicketVideoPoint[],
  lengths: number[],
  total: number,
  distance: number,
): TicketVideoPoint {
  const d = ((distance % total) + total) % total
  let low = 0
  let high = lengths.length - 1
  while (low < high) {
    const mid = (low + high) >> 1
    if (lengths[mid] < d) low = mid + 1
    else high = mid
  }
  const index = Math.max(1, low)
  const a = points[(index - 1) % points.length]
  const b = points[index % points.length]
  const span = lengths[index] - lengths[index - 1] || 1
  const t = (d - lengths[index - 1]) / span
  return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t }
}

export function createInitTicketVideoFog({
  width,
  height,
  palette,
  isDark,
}: {
  width: number
  height: number
  palette: InitTicketVideoFogPalette
  isDark: boolean
}): InitTicketVideoFog {
  const colors: [Rgb, Rgb, Rgb] = [
    toRgb(palette.violet, [139, 92, 246]),
    toRgb(palette.accent, [253, 54, 110]),
    toRgb(palette.peach, [254, 156, 135]),
  ]
  // Light chrome can't add light, so the fog is a tint there.
  const blend: GlobalCompositeOperation = isDark ? 'lighter' : 'source-over'
  const fogStrength = isDark ? 0.26 : 0.16
  const trailStrength = isDark ? 0.75 : 0.55
  const scale = width / 1920

  const drawBackdrop = (ctx: CanvasRenderingContext2D, progress: number) => {
    ctx.save()
    ctx.globalCompositeOperation = blend

    for (const blob of BLOBS) {
      const angle = TAU * (blob.cycles * progress + blob.phase)
      const cx = (blob.x + Math.cos(angle) * blob.drift) * width
      const cy = (blob.y + Math.sin(angle * 1.3) * blob.drift) * height
      const radius = blob.radius * width * (1 + 0.08 * Math.sin(angle * 2))
      const breathe = 0.75 + 0.25 * Math.sin(angle + blob.phase * TAU)
      const color = colors[blob.color]
      const gradient = ctx.createRadialGradient(cx, cy, 0, cx, cy, radius)
      gradient.addColorStop(0, rgba(color, fogStrength * breathe))
      gradient.addColorStop(0.45, rgba(color, fogStrength * breathe * 0.4))
      gradient.addColorStop(1, rgba(color, 0))
      ctx.fillStyle = gradient
      ctx.fillRect(cx - radius, cy - radius, radius * 2, radius * 2)
    }

    ctx.lineCap = 'round'
    for (const streak of STREAKS) {
      // Each streak crosses the frame `laps` times per clip, entering and
      // leaving off-screen so it never pops in.
      const lap = (streak.laps * progress + streak.offset) % 1
      const span = 1 + streak.length * 2
      const headX = -streak.length + lap * span
      const points: TicketVideoPoint[] = []
      for (let index = 0; index <= COMET_SEGMENTS; index += 1) {
        const fx = headX - streak.length * (1 - index / COMET_SEGMENTS)
        const x = streak.direction === 1 ? fx : 1 - fx
        const y =
          streak.y +
          streak.amplitude * Math.sin(TAU * (streak.waves * fx + progress))
        points.push({ x: x * width, y: y * height })
      }
      strokeComet(ctx, points, colors, 2.2 * scale, trailStrength)
    }

    ctx.restore()
  }

  const drawEdge = (
    ctx: CanvasRenderingContext2D,
    quad: [
      TicketVideoPoint,
      TicketVideoPoint,
      TicketVideoPoint,
      TicketVideoPoint,
    ],
    progress: number,
  ) => {
    const outline = roundedOutline(quad, 40 * scale, 8)
    const lengths = [0]
    for (let index = 1; index <= outline.length; index += 1) {
      const a = outline[index - 1]
      const b = outline[index % outline.length]
      lengths.push(lengths[index - 1] + Math.hypot(b.x - a.x, b.y - a.y))
    }
    const total = lengths[lengths.length - 1]
    if (total < 4) return

    ctx.save()
    ctx.globalCompositeOperation = blend
    ctx.lineCap = 'round'
    // Two comets on opposite sides, three laps per clip.
    for (const offset of [0, 0.5]) {
      const head = (3 * progress + offset) * total
      const tail = total * 0.24
      const points: TicketVideoPoint[] = []
      for (let index = 0; index <= COMET_SEGMENTS; index += 1) {
        points.push(
          pointAlong(
            outline,
            lengths,
            total,
            head - tail * (1 - index / COMET_SEGMENTS),
          ),
        )
      }
      strokeComet(ctx, points, colors, 2 * scale, isDark ? 1 : 0.85)
    }
    ctx.restore()
  }

  return { drawBackdrop, drawEdge }
}
