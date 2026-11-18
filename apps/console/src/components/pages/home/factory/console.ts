import {
  CanvasTexture,
  LinearFilter,
  LinearMipmapLinearFilter,
  SRGBColorSpace,
  Vector2,
  type WebGLRenderer,
} from 'three'
import { hash01 } from './layout'
import type { FactoryPalette } from './palette'

/**
 * The giant screen beside the server aisle shows the real Appwrite Console:
 * the same project overview capture the homepage hero uses, with its
 * bandwidth chart replotted live (same axes, same series colors), so the
 * screen is exact and still alive.
 */

type Theme = 'dark' | 'light'

const SOURCES: Record<Theme, string> = {
  dark: '/images/heroes/console-app-dark.avif?v=20260729',
  light: '/images/heroes/console-app-light.avif?v=20260729',
}

/** The capture is 1920 x 1234; the canvas keeps its shape. */
const CANVAS = { width: 1600, height: 1028 } as const
/** Seconds between redraws of the chart. */
const STEP = 1 / 12

/** The chart's plot area in the capture (canvas pixels): 0 MB at the bottom, 12.7 MB at the top. */
const PLOT = { left: 367, right: 1081, top: 359, bottom: 591 } as const
/** What gets repainted each frame: the plot plus a margin for the old lines' antialiasing. */
const COVER = { left: 361, top: 350, right: 1089, bottom: 598 } as const
/** An empty point of the chart card, to read its background from. */
const BACKGROUND_PROBE = { x: 456, y: 430 } as const
const MAX = 12.7

/** The series colors as the Console draws them (sampled from the capture). */
const SERIES: Record<Theme, { outbound: string; inbound: string }> = {
  dark: { outbound: '#ff0088', inbound: '#00bd7f' },
  light: { outbound: '#cc006a', inbound: '#009587' },
}

/** Samples on screen at once, and seconds between new ones. */
const POINTS = 46
const EVERY = 0.75

/** Outbound bandwidth in MB at sample `index`: a 3 to 6 MB base, with the odd spike and dip. */
function outbound(index: number): number {
  const spike = hash01(index * 3 + 1)
  const dip = hash01(index * 5 + 2)
  let value =
    4.3 + 1.5 * Math.sin(index * 0.33) + 0.9 * Math.sin(index * 0.87 + 1.2)
  if (spike > 0.9) value += 4.5 + 3 * hash01(index * 7 + 3)
  if (dip > 0.94) value = 0.3
  return Math.min(Math.max(value, 0.2), 12.4)
}

/** Inbound bandwidth in MB: low and steady. */
function inbound(index: number): number {
  return 0.45 + 0.25 * Math.sin(index * 0.41) + 0.35 * hash01(index * 11 + 4)
}

export class ConsoleDisplay {
  readonly texture: CanvasTexture
  private readonly canvas: HTMLCanvasElement
  private readonly context: CanvasRenderingContext2D | null
  /** The chart alone: all that changes between redraws, uploaded on its own. */
  private readonly chart: CanvasTexture
  private readonly chartContext: CanvasRenderingContext2D | null
  /** Where the chart lands in the texture (rows count up from the bottom). */
  private readonly chartPosition = new Vector2(
    COVER.left,
    CANVAS.height - COVER.bottom,
  )
  private readonly images: Partial<Record<Theme, HTMLImageElement>> = {}
  private theme: Theme
  private background = '#1b1a1f'
  /** The capture currently painted on the canvas, if any. */
  private painted: Theme | null = null
  /** The theme of the blank screen shown while the capture loads. */
  private blank: Theme | null = null
  private lastDraw = Number.NEGATIVE_INFINITY
  /** The whole screen needs uploading (first paint, theme change). */
  private repainted = false
  /** Only the chart changed since the last upload. */
  private replotted = false

  constructor(palette: FactoryPalette) {
    this.theme = palette.dark ? 'dark' : 'light'
    this.canvas = document.createElement('canvas')
    this.canvas.width = CANVAS.width
    this.canvas.height = CANVAS.height
    this.context = this.canvas.getContext('2d', { willReadFrequently: false })
    this.texture = new CanvasTexture(this.canvas)
    this.texture.colorSpace = SRGBColorSpace
    this.texture.generateMipmaps = true
    this.texture.minFilter = LinearMipmapLinearFilter
    this.texture.magFilter = LinearFilter
    const chart = document.createElement('canvas')
    chart.width = COVER.right - COVER.left
    chart.height = COVER.bottom - COVER.top
    this.chartContext = chart.getContext('2d')
    this.chart = new CanvasTexture(chart)
    this.load(this.theme)
  }

  setPalette(palette: FactoryPalette) {
    this.theme = palette.dark ? 'dark' : 'light'
    this.load(this.theme)
    this.lastDraw = Number.NEGATIVE_INFINITY
  }

  private load(theme: Theme) {
    if (this.images[theme]) return
    const image = new Image()
    image.decoding = 'async'
    image.onload = () => {
      this.images[theme] = image
      this.lastDraw = Number.NEGATIVE_INFINITY
    }
    image.src = SOURCES[theme]
  }

  update(time: number) {
    const context = this.context
    const chart = this.chartContext
    if (!context || !chart || Math.abs(time - this.lastDraw) < STEP) return
    this.lastDraw = time
    const image = this.images[this.theme]
    if (!image) {
      // A blank screen until the capture loads, painted once per theme.
      if (this.blank === this.theme) return
      context.fillStyle = this.theme === 'dark' ? '#19181d' : '#ffffff'
      context.fillRect(0, 0, CANVAS.width, CANVAS.height)
      this.blank = this.theme
      this.painted = null
      this.repainted = true
      return
    }
    this.blank = null
    if (this.painted !== this.theme) {
      context.drawImage(image, 0, 0, CANVAS.width, CANVAS.height)
      const [r = 0, g = 0, b = 0] = context.getImageData(
        BACKGROUND_PROBE.x,
        BACKGROUND_PROBE.y,
        1,
        1,
      ).data
      this.background = `rgb(${r}, ${g}, ${b})`
      this.painted = this.theme
      this.repainted = true
    }
    chart.save()
    chart.translate(-COVER.left, -COVER.top)
    this.drawChart(chart, time)
    chart.restore()
    // Keep the full canvas current for the next whole upload.
    context.drawImage(
      this.chart.image as HTMLCanvasElement,
      COVER.left,
      COVER.top,
    )
    this.replotted = true
  }

  /**
   * Sends this frame's changes to the GPU: the whole screen after a repaint,
   * otherwise just the chart's rectangle (a tenth of the pixels).
   */
  upload(renderer: WebGLRenderer) {
    if (this.repainted) {
      this.texture.needsUpdate = true
      this.repainted = false
      this.replotted = false
    } else if (this.replotted) {
      renderer.copyTextureToTexture(
        this.chart,
        this.texture,
        null,
        this.chartPosition,
      )
      this.replotted = false
    }
  }

  /** Replots the bandwidth chart, scrolling left as new samples arrive. */
  private drawChart(context: CanvasRenderingContext2D, time: number) {
    const colors = SERIES[this.theme]
    context.fillStyle = this.background
    context.fillRect(
      COVER.left,
      COVER.top,
      COVER.right - COVER.left,
      COVER.bottom - COVER.top,
    )
    const offset = Math.floor(time / EVERY)
    const shift = time / EVERY - offset
    const step = (PLOT.right - PLOT.left) / (POINTS - 1)
    const toY = (value: number) =>
      PLOT.bottom - (value / MAX) * (PLOT.bottom - PLOT.top)
    const series = (sample: (index: number) => number) => {
      const points: [number, number][] = []
      for (let index = -1; index <= POINTS; index++) {
        points.push([
          PLOT.left + (index - shift) * step,
          toY(sample(index + offset)),
        ])
      }
      return points
    }
    const trace = (points: [number, number][]) => {
      context.moveTo(points[0]![0], points[0]![1])
      for (let index = 1; index < points.length; index++) {
        const [x, y] = points[index]!
        const [px, py] = points[index - 1]!
        const middle = (x + px) / 2
        context.bezierCurveTo(middle, py, middle, y, x, y)
      }
    }
    context.save()
    context.beginPath()
    context.rect(
      PLOT.left,
      PLOT.top - 4,
      PLOT.right - PLOT.left,
      PLOT.bottom - PLOT.top + 6,
    )
    context.clip()
    for (const [points, color, fill] of [
      [series(outbound), colors.outbound, 0.2],
      [series(inbound), colors.inbound, 0.1],
    ] as const) {
      const gradient = context.createLinearGradient(0, PLOT.top, 0, PLOT.bottom)
      gradient.addColorStop(
        0,
        `${color}${Math.round(fill * 255)
          .toString(16)
          .padStart(2, '0')}`,
      )
      gradient.addColorStop(1, `${color}00`)
      context.beginPath()
      trace(points)
      context.lineTo(points.at(-1)![0], PLOT.bottom)
      context.lineTo(points[0]![0], PLOT.bottom)
      context.closePath()
      context.fillStyle = gradient
      context.fill()
      context.beginPath()
      trace(points)
      context.strokeStyle = color
      context.lineWidth = 2.6
      context.lineJoin = 'round'
      context.stroke()
    }
    context.restore()
  }

  dispose() {
    this.texture.dispose()
    this.chart.dispose()
  }
}
