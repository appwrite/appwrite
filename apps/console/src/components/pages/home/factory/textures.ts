import {
  CanvasTexture,
  ClampToEdgeWrapping,
  LinearFilter,
  LinearMipmapLinearFilter,
  RepeatWrapping,
  SRGBColorSpace,
} from 'three'
import type { FactoryPalette } from './palette'
import { EXTENSION, FASCIA, PRODUCT_KINDS, type ProductKind } from './layout'

type Context = CanvasRenderingContext2D

function createCanvas(width: number, height: number) {
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const context = canvas.getContext('2d')
  if (!context) throw new Error('2D canvas unavailable')
  return { canvas, context }
}

function roundRect(
  context: Context,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
) {
  const radius = Math.max(0, Math.min(r, w / 2, h / 2))
  context.beginPath()
  context.moveTo(x + radius, y)
  context.arcTo(x + w, y, x + w, y + h, radius)
  context.arcTo(x + w, y + h, x, y + h, radius)
  context.arcTo(x, y + h, x, y, radius)
  context.arcTo(x, y, x + w, y, radius)
  context.closePath()
}

function fillRound(
  context: Context,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
  fill: string | CanvasGradient,
) {
  roundRect(context, x, y, w, h, r)
  context.fillStyle = fill
  context.fill()
}

function bar(
  context: Context,
  x: number,
  y: number,
  w: number,
  h: number,
  fill: string,
) {
  fillRound(context, x, y, w, h, h / 2, fill)
}

function circle(
  context: Context,
  x: number,
  y: number,
  r: number,
  fill: string | CanvasGradient,
) {
  context.beginPath()
  context.arc(x, y, r, 0, Math.PI * 2)
  context.fillStyle = fill
  context.fill()
}

function ring(
  context: Context,
  x: number,
  y: number,
  r: number,
  width: number,
  color: string,
  amount = 1,
) {
  context.beginPath()
  context.arc(x, y, r, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * amount)
  context.strokeStyle = color
  context.lineWidth = width
  context.lineCap = 'round'
  context.stroke()
}

function withAlpha(hex: string, alpha: number): string {
  const value = hex.replace('#', '')
  const r = Number.parseInt(value.slice(0, 2), 16)
  const g = Number.parseInt(value.slice(2, 4), 16)
  const b = Number.parseInt(value.slice(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

function finishTexture(texture: CanvasTexture, srgb = true) {
  if (srgb) texture.colorSpace = SRGBColorSpace
  texture.generateMipmaps = true
  texture.minFilter = LinearMipmapLinearFilter
  texture.magFilter = LinearFilter
  texture.needsUpdate = true
  return texture
}

/** World length one repeat of the track texture covers. */
export const TRACK_REPEAT = 0.7

/**
 * The linear motor between the rails: a dark channel with the stator's
 * regular laminations and a brighter guide groove down the middle.
 */
export function createTrackTexture(palette: FactoryPalette): CanvasTexture {
  const { canvas, context } = createCanvas(256, 128)
  context.fillStyle = palette.track
  context.fillRect(0, 0, 256, 128)
  context.fillStyle = palette.trackLine
  for (let x = 0; x < 256; x += 32) context.fillRect(x, 14, 2, 100)
  context.fillRect(0, 12, 256, 2)
  context.fillRect(0, 114, 256, 2)
  context.fillStyle = 'rgba(0, 0, 0, 0.5)'
  context.fillRect(0, 58, 256, 12)
  const texture = finishTexture(new CanvasTexture(canvas))
  texture.wrapS = RepeatWrapping
  texture.wrapT = ClampToEdgeWrapping
  return texture
}

/** Soft round blob for contact shadows under moving things. */
export function createBlobTexture(): CanvasTexture {
  const size = 128
  const { canvas, context } = createCanvas(size, size)
  const gradient = context.createRadialGradient(
    size / 2,
    size / 2,
    0,
    size / 2,
    size / 2,
    size / 2,
  )
  gradient.addColorStop(0, 'rgba(255,255,255,1)')
  gradient.addColorStop(0.35, 'rgba(255,255,255,0.72)')
  gradient.addColorStop(0.7, 'rgba(255,255,255,0.22)')
  gradient.addColorStop(1, 'rgba(255,255,255,0)')
  context.fillStyle = gradient
  context.fillRect(0, 0, size, size)
  return finishTexture(new CanvasTexture(canvas), false)
}

export type ShadowShape = {
  x: number
  z: number
  width: number
  depth: number
  blur: number
  strength: number
}

export const SHADOW_BOUNDS = {
  minX: -26,
  maxX: 26,
  minZ: -6,
  maxZ: 6,
} as const
const SHADOW_PX_PER_UNIT = 24

/** Baked ambient occlusion under everything that never moves. */
export function createShadowTexture(shapes: ShadowShape[]): CanvasTexture {
  const width = Math.round(
    (SHADOW_BOUNDS.maxX - SHADOW_BOUNDS.minX) * SHADOW_PX_PER_UNIT,
  )
  const height = Math.round(
    (SHADOW_BOUNDS.maxZ - SHADOW_BOUNDS.minZ) * SHADOW_PX_PER_UNIT,
  )
  const { canvas, context } = createCanvas(width, height)
  context.fillStyle = '#000'
  context.fillRect(0, 0, width, height)
  context.globalCompositeOperation = 'lighter'
  const offset = width * 4
  const toX = (x: number) => (x - SHADOW_BOUNDS.minX) * SHADOW_PX_PER_UNIT
  const toY = (z: number) => (z - SHADOW_BOUNDS.minZ) * SHADOW_PX_PER_UNIT
  for (const shape of shapes) {
    context.shadowColor = `rgba(255,255,255,${shape.strength})`
    context.shadowBlur = shape.blur * SHADOW_PX_PER_UNIT
    context.shadowOffsetX = offset
    context.fillStyle = '#fff'
    const w = shape.width * SHADOW_PX_PER_UNIT
    const h = shape.depth * SHADOW_PX_PER_UNIT
    roundRect(
      context,
      toX(shape.x) - w / 2 - offset,
      toY(shape.z) - h / 2,
      w,
      h,
      Math.min(w, h) * 0.2,
    )
    context.fill()
  }
  return finishTexture(new CanvasTexture(canvas), false)
}

/** The LCD each station's name is shown on, in world units. */
export const NAMEPLATE_SIZE = {
  width: FASCIA.width - 0.05,
  height: FASCIA.height - 0.05,
} as const
const PLATE_WIDTH = 1536
/** Icon ink on the displays: the Console's neutral icon grey. */
const LCD_ICON = '#c4c6d7'
const PLATE_HEIGHT = Math.round(
  (PLATE_WIDTH * NAMEPLATE_SIZE.height) / NAMEPLATE_SIZE.width,
)

function prepareIconSvg(svg: string, color: string): string {
  const document = new DOMParser().parseFromString(svg, 'image/svg+xml')
  const root = document.documentElement
  root.setAttribute('xmlns', 'http://www.w3.org/2000/svg')
  root.setAttribute('width', '128')
  root.setAttribute('height', '128')
  root.setAttribute('stroke', color)
  root.removeAttribute('class')
  return new XMLSerializer().serializeToString(root)
}

function loadImage(src: string): Promise<HTMLImageElement | null> {
  return new Promise((resolve) => {
    const image = new Image()
    image.decoding = 'async'
    image.onload = () => resolve(image)
    image.onerror = () => resolve(null)
    image.src = src
  })
}

/** The page's brand font stack (Inter for languages Aeonik does not cover). */
function brandFontStack(): string {
  const value = getComputedStyle(document.documentElement)
    .getPropertyValue('--font-aeonik-pro')
    .trim()
  return value || "'Aeonik Pro', Inter, system-ui, sans-serif"
}

/**
 * The station displays: one backlit LCD per row with the product icon and
 * the (translated) product name centered together, in the neutral ink of
 * the framework installer's display. Rows follow `names`; the last is the
 * network gate.
 */
export function createNameplateAtlas(
  icons: readonly string[],
  names: readonly string[],
  rtl: boolean,
): { texture: CanvasTexture; ready: Promise<void> } {
  const { canvas, context } = createCanvas(
    PLATE_WIDTH,
    PLATE_HEIGHT * names.length,
  )
  const texture = finishTexture(new CanvasTexture(canvas))
  const fonts = brandFontStack()

  const ready = Promise.all([
    document.fonts?.load(`500 100px ${fonts}`).catch(() => undefined),
    ...icons.map((svg) =>
      loadImage(
        `data:image/svg+xml;charset=utf-8,${encodeURIComponent(
          prepareIconSvg(svg, LCD_ICON),
        )}`,
      ),
    ),
  ]).then(([, ...images]) => {
    const height = PLATE_HEIGHT
    const icon = Math.round(height * 0.5)
    const left = Math.round(height * 0.3)
    const gap = Math.round(height * 0.2)
    names.forEach((name, index) => {
      const top = index * height
      context.save()
      context.translate(0, top)
      context.beginPath()
      context.rect(0, 0, PLATE_WIDTH, height)
      context.clip()
      context.fillStyle = '#0b0c0e'
      context.fillRect(0, 0, PLATE_WIDTH, height)
      // Backlight: brighter in the middle, like an edge-lit panel.
      const backlight = context.createRadialGradient(
        PLATE_WIDTH * 0.32,
        height * 0.5,
        0,
        PLATE_WIDTH * 0.32,
        height * 0.5,
        PLATE_WIDTH * 0.7,
      )
      backlight.addColorStop(0, 'rgba(255,255,255,0.07)')
      backlight.addColorStop(1, 'rgba(255,255,255,0)')
      context.fillStyle = backlight
      context.fillRect(0, 0, PLATE_WIDTH, height)

      // Icon and name centered together as one group.
      let size = Math.round(height * 0.38)
      const available = PLATE_WIDTH - left * 2 - icon - gap
      context.font = `500 ${size}px ${fonts}`
      while (context.measureText(name).width > available && size > 40) {
        size -= 4
        context.font = `500 ${size}px ${fonts}`
      }
      const textWidth = context.measureText(name).width
      const start = (PLATE_WIDTH - (icon + gap + textWidth)) / 2
      const iconX = rtl ? start + textWidth + gap : start
      const textX = rtl ? start : start + icon + gap
      const image = images[index] as HTMLImageElement | null
      if (image)
        context.drawImage(image, iconX, (height - icon) / 2, icon, icon)
      context.fillStyle = '#f2f3f6'
      context.textBaseline = 'middle'
      context.direction = rtl ? 'rtl' : 'ltr'
      context.textAlign = 'left'
      context.fillText(name, textX, height / 2 + size * 0.04)
      context.restore()
    })
    texture.needsUpdate = true
  })

  return { texture, ready }
}

/** Appwrite logomark paths (viewBox 0 0 25 22), from `public/logo.svg`. */
export const APPWRITE_MARK = [
  'M24.4429 16.4322V21.9096H10.7519C6.76318 21.9096 3.28044 19.7067 1.4171 16.4322C1.14622 15.9561 0.909137 15.4567 0.710264 14.9383C0.319864 13.9225 0.0744552 12.8325 0 11.6952V10.2143C0.0161646 9.96089 0.0416361 9.70942 0.0749451 9.46095C0.143032 8.95105 0.245898 8.45211 0.381093 7.96711C1.66006 3.36909 5.81877 0 10.7519 0C15.6851 0 19.8433 3.36909 21.1223 7.96711H15.2682C14.3072 6.4683 12.6437 5.4774 10.7519 5.4774C8.86017 5.4774 7.19668 6.4683 6.23562 7.96711C5.9427 8.42274 5.71542 8.92516 5.56651 9.46095C5.43425 9.93599 5.36371 10.4369 5.36371 10.9548C5.36371 12.5248 6.01324 13.94 7.05463 14.9383C8.01961 15.865 9.32061 16.4322 10.7519 16.4322H24.4429Z',
  'M24.4429 9.46094V14.9383H14.4492C15.4906 13.94 16.1401 12.5248 16.1401 10.9548C16.1401 10.4369 16.0696 9.93598 15.9373 9.46094H24.4429Z',
]

/** The unfolded screen: one half on each side of the hinge. */
export const DESCRIPTION_SCREEN = {
  /** Screen width on each half. */
  leaf: EXTENSION.leaf - EXTENSION.border - 0.002,
  /** Half the crease at the hinge: a hairline, so text runs across it. */
  crease: 0.002,
  height: EXTENSION.height - 2 * EXTENSION.border,
} as const
/** Edge to edge, unfolded: both halves' screens and the crease between them. */
export const DESCRIPTION_WIDTH =
  2 * (DESCRIPTION_SCREEN.crease + DESCRIPTION_SCREEN.leaf)
const DESCRIPTION_CELL = {
  width: 1024,
  height: Math.round((1024 * DESCRIPTION_SCREEN.height) / DESCRIPTION_WIDTH),
}
/** Two columns of cells keep the atlas within every GPU's size limit. */
const DESCRIPTION_COLUMNS = 2

/** Where station `index`'s screen sits in the description atlas (uv space). */
export function getDescriptionCell(index: number, count: number) {
  const rows = Math.ceil(count / DESCRIPTION_COLUMNS)
  const column = index % DESCRIPTION_COLUMNS
  const row = Math.floor(index / DESCRIPTION_COLUMNS)
  return {
    u: column / DESCRIPTION_COLUMNS,
    // Row 0 is at the canvas top, which is v = 1.
    v: 1 - (row + 1) / rows,
    du: 1 / DESCRIPTION_COLUMNS,
    dv: 1 / rows,
  }
}

/** Breaks `text` into lines that fit `width`; by word, or by character for CJK. */
function wrap(context: Context, text: string, width: number): string[] {
  const spaced = text.includes(' ')
  const units = spaced ? text.split(' ') : Array.from(text)
  const lines: string[] = []
  let line = ''
  for (const unit of units) {
    const next = line ? (spaced ? `${line} ${unit}` : line + unit) : unit
    if (line && context.measureText(next).width > width) {
      lines.push(line)
      line = unit
    } else line = next
  }
  if (line) lines.push(line)
  return lines
}

/**
 * What each station's unfolded screen says: the product icon and name, then
 * what the product does, centered on a backlit LCD like the nameplates.
 */
export function createDescriptionAtlas(
  icons: readonly string[],
  names: readonly string[],
  descriptions: readonly string[],
  rtl: boolean,
): { texture: CanvasTexture; ready: Promise<void> } {
  const { width, height } = DESCRIPTION_CELL
  const rows = Math.ceil(names.length / DESCRIPTION_COLUMNS)
  const { canvas, context } = createCanvas(
    width * DESCRIPTION_COLUMNS,
    height * rows,
  )
  const texture = finishTexture(new CanvasTexture(canvas))
  const fonts = brandFontStack()

  const ready = Promise.all([
    document.fonts?.load(`500 100px ${fonts}`).catch(() => undefined),
    ...icons.map((svg) =>
      loadImage(
        `data:image/svg+xml;charset=utf-8,${encodeURIComponent(
          prepareIconSvg(svg, LCD_ICON),
        )}`,
      ),
    ),
  ]).then(([, ...images]) => {
    names.forEach((name, index) => {
      const x = (index % DESCRIPTION_COLUMNS) * width
      const y = Math.floor(index / DESCRIPTION_COLUMNS) * height
      context.save()
      context.translate(x, y)
      context.beginPath()
      context.rect(0, 0, width, height)
      context.clip()
      // An Appwrite slide: the dark base, one soft brand light per bottom corner.
      context.fillStyle = '#19191c'
      context.fillRect(0, 0, width, height)
      const light = (left: number, rgb: string, strength: number) => {
        const glow = context.createRadialGradient(
          left,
          height * 1.15,
          0,
          left,
          height * 1.15,
          width * 0.62,
        )
        glow.addColorStop(0, `rgba(${rgb}, ${strength})`)
        glow.addColorStop(0.45, `rgba(${rgb}, ${strength * 0.35})`)
        glow.addColorStop(1, `rgba(${rgb}, 0)`)
        context.fillStyle = glow
        context.fillRect(0, 0, width, height)
      }
      light(-width * 0.08, '253, 54, 110', 0.34)
      light(width * 1.08, '124, 103, 254', 0.3)

      const pad = width * 0.085
      const startX = rtl ? width - pad : pad
      const measure = width - pad * 2
      context.direction = rtl ? 'rtl' : 'ltr'
      context.textAlign = rtl ? 'right' : 'left'
      context.textBaseline = 'middle'

      // Eyebrow: the product's icon and name, small and muted.
      const eyebrow = Math.round(height * 0.068)
      const icon = Math.round(eyebrow * 1.15)
      const eyebrowY = pad * 0.95
      const image = images[index] as HTMLImageElement | null
      if (image) {
        context.globalAlpha = 0.75
        context.drawImage(
          image,
          rtl ? startX - icon : startX,
          eyebrowY - icon / 2,
          icon,
          icon,
        )
        context.globalAlpha = 1
      }
      context.font = `500 ${eyebrow}px ${fonts}`
      context.fillStyle = 'rgba(255, 255, 255, 0.62)'
      context.fillText(
        name,
        rtl ? startX - icon - eyebrow * 0.6 : startX + icon + eyebrow * 0.6,
        eyebrowY,
      )

      // Headline: what the product does, in the brand face, with the pink underscore.
      const description = descriptions[index] ?? ''
      let size = Math.round(height * 0.118)
      let lines: string[] = []
      for (; size >= 28; size -= 2) {
        context.font = `400 ${size}px ${fonts}`
        lines = wrap(context, description, measure * 0.9)
        if (lines.length <= 3) break
      }
      const leading = size * 1.16
      const headlineTop =
        height * 0.5 - (lines.length * leading) / 2 + leading * 0.1
      context.fillStyle = '#f4f4f6'
      lines.forEach((line, row) => {
        const y = headlineTop + leading * (row + 0.5)
        context.fillText(line, startX, y)
        if (row === lines.length - 1) {
          const end = context.measureText(line).width
          context.fillStyle = '#fd366e'
          context.fillText('_', rtl ? startX - end : startX + end, y)
        }
      })

      // The Appwrite mark, bottom corner.
      const mark = height * 0.075
      const scale = mark / 22
      context.save()
      context.globalAlpha = 0.55
      context.translate(
        rtl ? startX - mark * (25 / 22) : startX,
        height - pad * 0.8 - mark,
      )
      context.scale(scale, scale)
      context.fillStyle = '#ffffff'
      for (const path of APPWRITE_MARK) context.fill(new Path2D(path))
      context.restore()
      context.restore()
    })
    texture.needsUpdate = true
  })

  return { texture, ready }
}

export type ScreenCell = {
  u: number
  v: number
  du: number
  dv: number
  stride: number
}

/**
 * App UIs: neutral surfaces carry the layout, and each app has its own
 * brand colors for imagery, charts and actions. The shader also turns the
 * hue per product, so no two apps on the line look the same.
 */
type ScreenColors = {
  dark: boolean
  background: string
  surface: string
  line: string
  muted: string
  strong: string
  text: string
  raised: string
  /** Primary brand color (actions, highlights). */
  accent: string
  accent2: string
  accent3: string
}

type Brand = Pick<ScreenColors, 'accent' | 'accent2' | 'accent3'>

const BRANDS: Record<ProductKind, Brand> = {
  phone: { accent: '#7c5cff', accent2: '#ff4f8b', accent3: '#ffb547' },
  tablet: { accent: '#ff6a3d', accent2: '#ffb547', accent3: '#20c4b4' },
  desktop: { accent: '#3d8bff', accent2: '#22c7b8', accent3: '#a78bfa' },
  display: { accent: '#fd366e', accent2: '#7c67fe', accent3: '#fe9567' },
  watch: { accent: '#ff375f', accent2: '#a3e635', accent3: '#22d3ee' },
}

const ONLINE = '#2fc28a'

const DARK_UI: Omit<ScreenColors, keyof Brand> = {
  dark: true,
  background: '#121215',
  surface: '#1e1e23',
  line: '#2d2d34',
  muted: '#585962',
  strong: '#9c9da8',
  text: '#ededf2',
  raised: '#2b2b32',
}

const LIGHT_UI: Omit<ScreenColors, keyof Brand> = {
  dark: false,
  background: '#ffffff',
  surface: '#f1f1f4',
  line: '#e0e0e6',
  muted: '#b3b4be',
  strong: '#6d6e7a',
  text: '#1c1c22',
  raised: '#ffffff',
}

/** Each format ships its own look, so the line reads as many different apps. */
const SCREEN_THEMES: Record<ProductKind, 'dark' | 'light'> = {
  phone: 'dark',
  tablet: 'light',
  desktop: 'dark',
  display: 'light',
  watch: 'dark',
}

const SCREEN_SIZES: Record<ProductKind, { w: number; h: number }> = {
  phone: { w: 92, h: 190 },
  tablet: { w: 140, h: 186 },
  desktop: { w: 208, h: 132 },
  display: { w: 208, h: 132 },
  watch: { w: 104, h: 120 },
}

export const SCREEN_STAGES = 8
const GUTTER = 4

/** Image placeholder in the app's colors: a sky, a sun and two hills. */
function photo(
  context: Context,
  x: number,
  y: number,
  w: number,
  h: number,
  colors: ScreenColors,
  seed: number,
) {
  const skies: [string, string][] = [
    [colors.accent, colors.accent2],
    [colors.accent2, colors.accent3],
    [colors.accent3, colors.accent],
  ]
  const [from, to] = skies[seed % skies.length]!
  const gradient = context.createLinearGradient(x, y, x + w * 0.6, y + h)
  gradient.addColorStop(0, from)
  gradient.addColorStop(1, to)
  context.save()
  roundRect(context, x, y, w, h, Math.min(6, h / 4))
  context.clip()
  context.fillStyle = gradient
  context.fillRect(x, y, w, h)
  const shift = (seed % 3) * 0.16
  circle(
    context,
    x + w * (0.74 - shift),
    y + h * 0.34,
    Math.min(w, h) * 0.1,
    'rgba(255,255,255,0.85)',
  )
  context.fillStyle = 'rgba(0,0,0,0.16)'
  context.beginPath()
  context.moveTo(x, y + h)
  context.lineTo(x + w * (0.3 + shift * 0.5), y + h * 0.48)
  context.lineTo(x + w * (0.62 + shift * 0.5), y + h)
  context.closePath()
  context.fill()
  context.fillStyle = 'rgba(0,0,0,0.26)'
  context.beginPath()
  context.moveTo(x + w * 0.38, y + h)
  context.lineTo(x + w * (0.72 - shift * 0.4), y + h * 0.62)
  context.lineTo(x + w, y + h)
  context.closePath()
  context.fill()
  context.restore()
}

function lockChip(
  context: Context,
  x: number,
  y: number,
  size: number,
  fill: string,
) {
  fillRound(context, x, y, size, size, size * 0.3, fill)
  const s = size / 12
  fillRound(context, x + 3.5 * s, y + 5.5 * s, 5 * s, 4 * s, s, '#ffffff')
  context.beginPath()
  context.arc(x + 6 * s, y + 5.5 * s, 1.7 * s, Math.PI, 0)
  context.strokeStyle = '#ffffff'
  context.lineWidth = 1.2 * s
  context.stroke()
}

function shieldChip(
  context: Context,
  x: number,
  y: number,
  size: number,
  colors: ScreenColors,
) {
  fillRound(
    context,
    x,
    y,
    size,
    size,
    size * 0.3,
    withAlpha(colors.accent3, 0.25),
  )
  const s = size / 12
  context.beginPath()
  context.moveTo(x + 6 * s, y + 2.2 * s)
  context.lineTo(x + 9.6 * s, y + 3.6 * s)
  context.lineTo(x + 9.2 * s, y + 7 * s)
  context.quadraticCurveTo(x + 8.2 * s, y + 9.4 * s, x + 6 * s, y + 10.2 * s)
  context.quadraticCurveTo(x + 3.8 * s, y + 9.4 * s, x + 2.8 * s, y + 7 * s)
  context.lineTo(x + 2.4 * s, y + 3.6 * s)
  context.closePath()
  context.fillStyle = colors.accent3
  context.fill()
}

/** Bars in the app's colors, the latest one in its primary. */
function chartBars(
  context: Context,
  x: number,
  y: number,
  w: number,
  h: number,
  colors: ScreenColors,
  count: number,
) {
  const gap = w / count
  for (let index = 0; index < count; index++) {
    const value = 0.35 + 0.6 * Math.abs(Math.sin(index * 1.7 + 0.6))
    const barH = h * value
    fillRound(
      context,
      x + index * gap + gap * 0.2,
      y + h - barH,
      gap * 0.6,
      barH,
      1.5,
      index === count - 1
        ? colors.accent
        : index % 2
          ? colors.accent2
          : withAlpha(colors.accent2, 0.55),
    )
  }
}

function lineChart(
  context: Context,
  x: number,
  y: number,
  w: number,
  h: number,
  color: string,
  phase: number,
  fill = true,
) {
  context.beginPath()
  const steps = 14
  for (let index = 0; index <= steps; index++) {
    const px = x + (w * index) / steps
    const py =
      y +
      h *
        (0.55 -
          0.35 * Math.sin(index * 0.8 + phase) * (0.6 + index / steps / 2))
    if (index === 0) context.moveTo(px, py)
    else context.lineTo(px, py)
  }
  context.strokeStyle = color
  context.lineWidth = 1.6
  context.lineJoin = 'round'
  context.stroke()
  if (!fill) return
  context.lineTo(x + w, y + h)
  context.lineTo(x, y + h)
  context.closePath()
  const shade = context.createLinearGradient(0, y, 0, y + h)
  shade.addColorStop(0, withAlpha(color, 0.22))
  shade.addColorStop(1, withAlpha(color, 0))
  context.fillStyle = shade
  context.fill()
}

function avatar(
  context: Context,
  x: number,
  y: number,
  r: number,
  colors: ScreenColors,
  seed: number,
) {
  const tones = [colors.accent, colors.accent2, colors.accent3]
  circle(context, x, y, r, tones[seed % tones.length]!)
}

function presence(
  context: Context,
  x: number,
  y: number,
  r: number,
  colors: ScreenColors,
) {
  circle(context, x, y, r + 1.2, colors.background)
  circle(context, x, y, r, ONLINE)
}

/** A raised card (toasts, popovers). */
function raised(
  context: Context,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
  colors: ScreenColors,
) {
  context.save()
  context.shadowColor = 'rgba(0,0,0,0.28)'
  context.shadowBlur = 6
  context.shadowOffsetY = 1
  fillRound(context, x, y, w, h, r, colors.raised)
  context.restore()
}

function drawPhone(
  context: Context,
  w: number,
  h: number,
  stage: number,
  colors: ScreenColors,
) {
  context.fillStyle = colors.background
  context.fillRect(0, 0, w, h)
  bar(context, 9, 6, 14, 3, colors.text)
  bar(context, 66, 6, 6, 3, colors.muted)
  bar(context, 74, 6, 10, 3, colors.muted)

  avatar(context, 15, 24, 7, colors, 0)
  bar(context, 26, 18, 30, 4, colors.text)
  bar(context, 26, 26, 20, 3, colors.muted)
  lockChip(context, 74, 17, 12, colors.accent)

  if (stage >= 3) photo(context, 7, 37, 78, 42, colors, 0)
  else fillRound(context, 7, 37, 78, 42, 6, colors.surface)

  if (stage >= 4) {
    fillRound(context, 7, 84, 78, 30, 6, colors.surface)
    chartBars(context, 11, 88, 70, 22, colors, 9)
  }

  if (stage >= 2) {
    for (let row = 0; row < 3; row++) {
      const y = 120 + row * 16
      avatar(context, 15, y + 6, 5, colors, row + 1)
      bar(context, 25, y + 1.5, 36 - row * 4, 3, colors.text)
      bar(context, 25, y + 7.5, 26 + row * 5, 2.5, colors.muted)
      bar(context, 72, y + 2, 12, 2.5, colors.line)
      if (stage >= 5 && row !== 1) presence(context, 18.5, y + 9.5, 1.8, colors)
    }
  }

  if (stage >= 5) {
    presence(context, 20, 29, 2.4, colors)
    fillRound(context, 44, 166, 26, 9, 4.5, colors.surface)
    for (let index = 0; index < 3; index++) {
      circle(context, 51 + index * 6, 170.5, 1.5, colors.accent)
    }
  }

  if (stage >= 6) {
    raised(context, 5, 3, 82, 17, 6, colors)
    circle(context, 13, 11.5, 4, colors.accent)
    bar(context, 21, 8, 34, 3, colors.text)
    bar(context, 21, 13, 48, 2.5, colors.muted)
  }

  if (stage >= 7) shieldChip(context, 60, 17, 12, colors)

  if (stage >= 8) {
    fillRound(context, 0, 176, w, 14, 0, colors.surface)
    for (let index = 0; index < 4; index++) {
      circle(
        context,
        14 + index * 21,
        183,
        2.6,
        index === 0 ? colors.accent : colors.muted,
      )
    }
    circle(context, 77, 160, 8, colors.accent)
    bar(context, 73, 159, 8, 2, '#ffffff')
    bar(context, 76, 156, 2, 8, '#ffffff')
  }
}

function drawTablet(
  context: Context,
  w: number,
  h: number,
  stage: number,
  colors: ScreenColors,
) {
  context.fillStyle = colors.background
  context.fillRect(0, 0, w, h)
  avatar(context, 12, 12, 6, colors, 0)
  fillRound(context, 24, 7, 64, 10, 5, colors.surface)
  bar(context, 30, 10.5, 30, 3, colors.muted)
  lockChip(context, 122, 6, 12, colors.accent)

  if (stage >= 2) {
    for (let row = 0; row < 7; row++) {
      const y = 28 + row * 13
      fillRound(
        context,
        6,
        y,
        34,
        9,
        3,
        row === 0 ? withAlpha(colors.accent, 0.18) : colors.surface,
      )
      bar(
        context,
        10,
        y + 3,
        18 - (row % 3) * 3,
        3,
        row === 0 ? colors.accent : colors.muted,
      )
    }
  }

  for (let row = 0; row < 3; row++) {
    for (let column = 0; column < 3; column++) {
      const x = 46 + column * 30
      const y = 26 + row * 31
      if (stage >= 3) photo(context, x, y, 27, 28, colors, row * 3 + column)
      else fillRound(context, x, y, 27, 28, 5, colors.surface)
    }
  }

  if (stage >= 4) {
    fillRound(context, 46, 122, 87, 26, 6, colors.surface)
    bar(context, 51, 127, 30, 3, colors.text)
    bar(context, 51, 134, 76, 4, colors.line)
    bar(context, 51, 134, 52, 4, colors.accent)
    bar(context, 51, 141, 76, 4, colors.line)
    bar(context, 51, 141, 30, 4, colors.accent3)
  }

  if (stage >= 5) {
    for (let index = 0; index < 3; index++) {
      avatar(context, 96 + index * 7, 12, 4.5, colors, index + 1)
    }
    presence(context, 113, 15, 1.8, colors)
  }

  if (stage >= 6) {
    raised(context, 66, 156, 68, 22, 6, colors)
    circle(context, 75, 167, 4, colors.accent)
    bar(context, 83, 162, 36, 3, colors.text)
    bar(context, 83, 168, 44, 2.5, colors.muted)
  }

  if (stage >= 7) shieldChip(context, 106, 6, 12, colors)

  if (stage >= 8) {
    fillRound(context, 6, 158, 54, 18, 6, colors.accent)
    bar(context, 16, 165.5, 34, 3, '#ffffff')
  }
}

function drawDesktop(
  context: Context,
  w: number,
  h: number,
  stage: number,
  colors: ScreenColors,
) {
  context.fillStyle = colors.background
  context.fillRect(0, 0, w, h)
  fillRound(context, 0, 0, w, 14, 0, colors.surface)
  circle(context, 9, 7, 3.5, colors.accent)
  bar(context, 16, 5.5, 20, 3, colors.text)
  avatar(context, 198, 7, 4, colors, 0)
  lockChip(context, 180, 2, 10, colors.accent)

  fillRound(context, 0, 14, 40, h - 14, 0, colors.surface)
  for (let row = 0; row < 6; row++) {
    const active = stage >= 8 && row === 1
    if (active)
      fillRound(
        context,
        4,
        20 + row * 12,
        32,
        9,
        3,
        withAlpha(colors.accent, 0.22),
      )
    bar(
      context,
      8,
      23 + row * 12,
      18 - (row % 2) * 5,
      3,
      active ? colors.accent : colors.muted,
    )
  }

  for (let index = 0; index < 4; index++) {
    const x = 46 + index * 39.5
    if (stage >= 3) photo(context, x, 20, 36, 15, colors, index)
    else fillRound(context, x, 20, 36, 15, 4, colors.surface)
  }

  fillRound(context, 46, 39, 156, 36, 5, colors.surface)
  if (stage >= 4) {
    bar(context, 51, 43, 26, 3, colors.text)
    lineChart(context, 50, 49, 148, 23, colors.accent2, 0.2)
  }
  if (stage >= 5) {
    lineChart(context, 50, 52, 148, 20, colors.accent, 2.4, false)
    circle(context, 192, 45, 2.5, ONLINE)
    bar(context, 172, 43.5, 16, 3, colors.muted)
  }

  if (stage >= 2) {
    bar(context, 48, 81, 150, 3, colors.muted)
    for (let row = 0; row < 4; row++) {
      const y = 89 + row * 10
      bar(context, 48, y, 32, 3, colors.text)
      bar(context, 88, y, 40, 3, colors.line)
      bar(context, 136, y, 26, 3, colors.line)
      fillRound(
        context,
        172,
        y - 1,
        24,
        5,
        2.5,
        withAlpha(row % 2 ? colors.accent3 : colors.accent2, 0.45),
      )
    }
  }

  if (stage >= 6) {
    raised(context, 138, 18, 64, 17, 5, colors)
    circle(context, 146, 26.5, 3.5, colors.accent)
    bar(context, 153, 22.5, 30, 3, colors.text)
    bar(context, 153, 28, 40, 2.5, colors.muted)
  }

  if (stage >= 7) shieldChip(context, 166, 2, 10, colors)
}

function drawDisplay(
  context: Context,
  w: number,
  h: number,
  stage: number,
  colors: ScreenColors,
) {
  context.fillStyle = colors.background
  context.fillRect(0, 0, w, h)
  fillRound(context, 0, 0, w, 13, 0, colors.surface)
  for (let dot = 0; dot < 3; dot++)
    circle(context, 7 + dot * 6, 6.5, 2, colors.muted)
  fillRound(context, 44, 3, 112, 7, 3.5, colors.background)
  bar(context, 54, 5.5, 46, 2, colors.muted)
  fillRound(context, 170, 3, 30, 7, 3.5, withAlpha(colors.accent, 0.16))
  bar(context, 176, 5.5, 18, 2, colors.accent)

  // Hero: a band with the headline; Storage fills in the artwork.
  if (stage >= 3) {
    const hero = context.createLinearGradient(6, 17, 202, 59)
    hero.addColorStop(0, colors.accent2)
    hero.addColorStop(0.6, colors.accent)
    hero.addColorStop(1, colors.accent3)
    fillRound(context, 6, 17, 196, 42, 6, hero)
  } else {
    fillRound(context, 6, 17, 196, 42, 6, colors.surface)
  }
  if (stage >= 3) {
    context.save()
    roundRect(context, 6, 17, 196, 42, 6)
    context.clip()
    circle(context, 172, 30, 26, 'rgba(255,255,255,0.14)')
    circle(context, 150, 62, 30, 'rgba(255,255,255,0.1)')
    context.restore()
    bar(context, 16, 26, 80, 6, '#ffffff')
    bar(context, 16, 36, 56, 4, 'rgba(255,255,255,0.55)')
  }

  if (stage >= 2) {
    for (let index = 0; index < 4; index++) {
      const x = 6 + index * 49.5
      fillRound(context, x, 64, 46, 54, 5, colors.surface)
      if (stage >= 3) photo(context, x + 3, 67, 40, 26, colors, index + 1)
      else fillRound(context, x + 3, 67, 40, 26, 4, colors.line)
      bar(context, x + 4, 98, 28, 3, colors.text)
      bar(context, x + 4, 104, 20, 2.5, colors.muted)
      if (stage >= 4) bar(context, x + 30, 108, 12, 4, colors.accent)
    }
  }

  if (stage >= 5) {
    fillRound(context, 148, 21, 48, 10, 5, 'rgba(255,255,255,0.92)')
    circle(context, 155, 26, 2.2, ONLINE)
    bar(context, 160, 24.5, 30, 3, '#2d2d35')
  }

  if (stage >= 6) {
    circle(context, 192, 120, 8, colors.accent)
    fillRound(context, 188, 117, 8, 6, 2, '#ffffff')
    raised(context, 136, 104, 46, 12, 5, colors)
    bar(context, 141, 108.5, 34, 3, colors.text)
  }

  if (stage >= 7) lockChip(context, 46, 3.5, 6, colors.accent3)

  if (stage >= 8) {
    fillRound(context, 16, 45, 40, 9, 4.5, colors.accent)
    bar(context, 22, 48, 28, 3, '#ffffff')
    fillRound(context, 0, 122, w, 10, 0, colors.surface)
    bar(context, 8, 125.5, 30, 3, colors.muted)
    bar(context, 44, 125.5, 22, 3, colors.muted)
  }
}

function drawWatch(
  context: Context,
  w: number,
  h: number,
  stage: number,
  colors: ScreenColors,
) {
  context.fillStyle = '#000000'
  context.fillRect(0, 0, w, h)
  bar(context, 34, 10, 36, 7, '#ffffff')
  lockChip(context, 10, 9, 10, colors.accent)

  const cx = w / 2
  const cy = 62
  const amount = stage >= 8 ? 1 : 0.62
  if (stage >= 2) {
    ring(context, cx, cy, 30, 6, withAlpha(colors.accent, 0.25))
    ring(context, cx, cy, 30, 6, colors.accent, amount)
    ring(context, cx, cy, 22, 6, withAlpha(colors.accent2, 0.25))
    ring(context, cx, cy, 22, 6, colors.accent2, amount * 0.85)
    ring(context, cx, cy, 14, 6, withAlpha(colors.accent3, 0.25))
    ring(context, cx, cy, 14, 6, colors.accent3, amount * 0.7)
  }

  if (stage >= 3) circle(context, 14, 34, 4, colors.accent3)

  if (stage >= 4) {
    bar(context, 22, 100, 34, 5, '#ffffff')
    bar(context, 60, 100, 22, 5, colors.accent2)
  }

  if (stage >= 5) {
    context.beginPath()
    const points = [0, 0, -4, 6, -9, 3, 0, 0, 0]
    points.forEach((offset, index) => {
      const x = 18 + index * 8.5
      const y = 112 + offset
      if (index === 0) context.moveTo(x, y)
      else context.lineTo(x, y)
    })
    context.strokeStyle = ONLINE
    context.lineWidth = 2
    context.stroke()
  }

  if (stage >= 6) circle(context, 90, 13, 4, colors.accent)
  if (stage >= 7) shieldChip(context, 86, 30, 11, colors)
}

const DRAWERS: Record<ProductKind, typeof drawPhone> = {
  phone: drawPhone,
  tablet: drawTablet,
  desktop: drawDesktop,
  display: drawDisplay,
  watch: drawWatch,
}

/**
 * Every product UI at every stage in one atlas: row per kind, column per
 * stage. Stage 1 is what Auth adds; stage 8 is the deployed app.
 */
export function createScreenAtlas(
  /** Pixel density of the UIs; higher stays sharp on large, high-DPR screens. */
  scale = 1,
): {
  texture: CanvasTexture
  cells: Record<ProductKind, ScreenCell>
} {
  const sizeOf = (kind: ProductKind) => ({
    w: Math.round(SCREEN_SIZES[kind].w * scale),
    h: Math.round(SCREEN_SIZES[kind].h * scale),
  })
  const width = Math.max(
    ...PRODUCT_KINDS.map((kind) => (sizeOf(kind).w + GUTTER) * SCREEN_STAGES),
  )
  const height = PRODUCT_KINDS.reduce(
    (sum, kind) => sum + sizeOf(kind).h + GUTTER,
    0,
  )
  const { canvas, context } = createCanvas(width, height)

  const cells = {} as Record<ProductKind, ScreenCell>
  let y = 0
  for (const kind of PRODUCT_KINDS) {
    const size = sizeOf(kind)
    const logical = SCREEN_SIZES[kind]
    const colors: ScreenColors = {
      ...(SCREEN_THEMES[kind] === 'dark' ? DARK_UI : LIGHT_UI),
      ...BRANDS[kind],
    }
    for (let stage = 1; stage <= SCREEN_STAGES; stage++) {
      const x = (stage - 1) * (size.w + GUTTER)
      context.save()
      context.translate(x, y)
      context.beginPath()
      context.rect(0, 0, size.w, size.h)
      context.clip()
      context.scale(size.w / logical.w, size.h / logical.h)
      DRAWERS[kind](context, logical.w, logical.h, stage, colors)
      context.restore()
    }
    cells[kind] = {
      u: 0,
      // Canvas rows run top-down, texture v runs bottom-up.
      v: 1 - (y + size.h) / height,
      du: size.w / width,
      dv: size.h / height,
      stride: (size.w + GUTTER) / width,
    }
    y += size.h + GUTTER
  }

  const texture = finishTexture(new CanvasTexture(canvas))
  texture.flipY = true
  return { texture, cells }
}
