export type Init5HeroPoint = { x: number; y: number }

export type Init5HeroSampleRegion = {
  /** Center X in normalized 0–1 canvas space */
  cx: number
  /** Center Y in normalized 0–1 canvas space */
  cy: number
  /** Width of digit as fraction of canvas width */
  width: number
}

const SAMPLE_CANVAS_WIDTH = 360
const SAMPLE_CANVAS_HEIGHT = 480

function shuffleInPlace<T>(items: T[]) {
  for (let i = items.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[items[i], items[j]] = [items[j]!, items[i]!]
  }
}

/**
 * Sample points along a bold "5" glyph for particle target positions.
 * Coordinates are normalized 0–1 relative to the hero canvas.
 */
export function sampleInit5HeroDigitPoints(
  targetCount: number,
  region: Init5HeroSampleRegion,
): Init5HeroPoint[] {
  if (typeof document === 'undefined' || targetCount <= 0) return []

  const canvas = document.createElement('canvas')
  canvas.width = SAMPLE_CANVAS_WIDTH
  canvas.height = SAMPLE_CANVAS_HEIGHT
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  if (!ctx) return []

  ctx.clearRect(0, 0, SAMPLE_CANVAS_WIDTH, SAMPLE_CANVAS_HEIGHT)
  ctx.fillStyle = '#ffffff'
  ctx.font =
    '800 380px "Inter", ui-sans-serif, system-ui, -apple-system, sans-serif'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText('5', SAMPLE_CANVAS_WIDTH / 2, SAMPLE_CANVAS_HEIGHT / 2 + 10)

  const { data, width, height } = ctx.getImageData(
    0,
    0,
    SAMPLE_CANVAS_WIDTH,
    SAMPLE_CANVAS_HEIGHT,
  )

  const filled: Init5HeroPoint[] = []
  const step = 2
  for (let y = 0; y < height; y += step) {
    for (let x = 0; x < width; x += step) {
      const alpha = data[(y * width + x) * 4 + 3] ?? 0
      if (alpha > 120) {
        filled.push({ x: x / width, y: y / height })
      }
    }
  }

  if (filled.length === 0) return []

  shuffleInPlace(filled)

  const points: Init5HeroPoint[] = []
  for (let i = 0; i < targetCount; i++) {
    const source = filled[i % filled.length]!
    const jitterX = (Math.random() - 0.5) * 0.008
    const jitterY = (Math.random() - 0.5) * 0.008
    const localX = Math.min(1, Math.max(0, source.x + jitterX))
    const localY = Math.min(1, Math.max(0, source.y + jitterY))

    points.push(mapLocalDigitPointToRegion(localX, localY, region))
  }

  return points
}

export function mapLocalDigitPointToRegion(
  localX: number,
  localY: number,
  region: Init5HeroSampleRegion,
): Init5HeroPoint {
  return {
    x: region.cx + (localX - 0.5) * region.width,
    y: region.cy + (localY - 0.5) * region.width * 1.28,
  }
}

export function randomInit5HeroScatterPoint(): Init5HeroPoint {
  const edge = Math.random()
  if (edge < 0.25) {
    return { x: Math.random(), y: 0.02 + Math.random() * 0.08 }
  }
  if (edge < 0.5) {
    return { x: Math.random(), y: 0.9 + Math.random() * 0.08 }
  }
  if (edge < 0.75) {
    return { x: 0.02 + Math.random() * 0.08, y: Math.random() }
  }
  return { x: 0.9 + Math.random() * 0.08, y: Math.random() }
}

export function easeOutCubic(t: number): number {
  return 1 - (1 - t) ** 3
}

export function easeInOutCubic(t: number): number {
  return t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2
}

export function easeOutExpo(t: number): number {
  return t >= 1 ? 1 : 1 - 2 ** (-10 * t)
}
