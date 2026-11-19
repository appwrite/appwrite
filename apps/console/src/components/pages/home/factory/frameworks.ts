import {
  CanvasTexture,
  LinearFilter,
  LinearMipmapLinearFilter,
  SRGBColorSpace,
} from 'three'
import { getFrameworkIconFile } from '@/lib/frameworks/icons'
import type { FactoryPalette } from './palette'
import { hash01, type ProductKind } from './layout'

/**
 * The frameworks apps are built with. The installer at the head of the line
 * puts one into every product before Auth; which one depends on the kind of
 * device, the way a team picks a stack for its platform.
 */
export const FRAMEWORKS = [
  { id: 'nextjs', name: 'Next.js', color: '#ffffff' },
  { id: 'svelte', name: 'SvelteKit', color: '#ff3e00' },
  { id: 'nuxt', name: 'Nuxt', color: '#00dc82' },
  { id: 'astro', name: 'Astro', color: '#bc52ee' },
  { id: 'angular', name: 'Angular', color: '#e23237' },
  { id: 'vue', name: 'Vue', color: '#42b883' },
  { id: 'react', name: 'React', color: '#61dafb' },
  { id: 'flutter', name: 'Flutter', color: '#47c5fb' },
  { id: 'react-native', name: 'React Native', color: '#61dafb' },
  { id: 'swift', name: 'Swift', color: '#f05138' },
  { id: 'kotlin', name: 'Kotlin', color: '#7f52ff' },
] as const

export type FrameworkId = (typeof FRAMEWORKS)[number]['id']

const BY_KIND: Record<ProductKind, readonly FrameworkId[]> = {
  phone: ['flutter', 'react-native', 'swift', 'kotlin'],
  watch: ['swift', 'flutter'],
  tablet: ['flutter', 'react-native', 'swift'],
  laptop: ['nextjs', 'svelte', 'react', 'vue', 'swift'],
  desktop: ['nextjs', 'svelte', 'nuxt', 'angular', 'vue', 'react'],
  display: ['nextjs', 'astro', 'nuxt', 'svelte', 'vue'],
  mini: ['nextjs', 'nuxt', 'svelte', 'astro'],
}

/** Index into `FRAMEWORKS` for product `index` of kind `kind`. */
export function getProductFramework(index: number, kind: ProductKind): number {
  const options = BY_KIND[kind]
  const id = options[Math.floor(hash01(index * 7 + 3) * options.length)]!
  return FRAMEWORKS.findIndex((framework) => framework.id === id)
}

function finish(texture: CanvasTexture) {
  texture.colorSpace = SRGBColorSpace
  texture.generateMipmaps = true
  texture.minFilter = LinearMipmapLinearFilter
  texture.magFilter = LinearFilter
  texture.needsUpdate = true
  return texture
}

/** Size framework marks are rasterized at, so they stay sharp on the displays. */
const ICON_RASTER = 512

/** Loads one mark the Console ships in `/icons`, rasterized large; null when missing. */
export async function loadIcon(file: string): Promise<HTMLImageElement | null> {
  try {
    const response = await fetch(`/icons/${file}`)
    if (!response.ok) return null
    const svg = new DOMParser().parseFromString(
      await response.text(),
      'image/svg+xml',
    ).documentElement
    svg.setAttribute('width', String(ICON_RASTER))
    svg.setAttribute('height', String(ICON_RASTER))
    const source = new XMLSerializer().serializeToString(svg)
    return await new Promise<HTMLImageElement | null>((resolve) => {
      const image = new Image()
      image.decoding = 'async'
      image.onload = () => resolve(image)
      image.onerror = () => resolve(null)
      image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(source)}`
    })
  } catch {
    return null
  }
}

/**
 * Loads every framework mark the Console uses (via the shared framework
 * icon map). Missing icons resolve to null.
 */
export function loadFrameworkIcons(): Promise<(HTMLImageElement | null)[]> {
  return Promise.all(
    FRAMEWORKS.map((framework) => {
      const file = getFrameworkIconFile(framework.id)
      return file ? loadIcon(file) : Promise.resolve(null)
    }),
  )
}

/** Draws `image` contained in a `size` square at (x, y). */
function drawContained(
  context: CanvasRenderingContext2D,
  image: HTMLImageElement,
  x: number,
  y: number,
  size: number,
) {
  const ratio = image.naturalWidth / Math.max(image.naturalHeight, 1) || 1
  const width = ratio >= 1 ? size : size * ratio
  const height = ratio >= 1 ? size / ratio : size
  context.drawImage(
    image,
    x + (size - width) / 2,
    y + (size - height) / 2,
    width,
    height,
  )
}

const FRAMEWORK_CELL = 512
/** The boot-screen atlas is a grid, so it stays within every GPU's texture size limit. */
export const FRAMEWORK_GRID = {
  columns: 4,
  rows: Math.ceil(FRAMEWORKS.length / 4),
}

/** Every framework mark in a grid, for the boot screen products show once installed. */
export function createFrameworkAtlas(
  icons: readonly (HTMLImageElement | null)[],
): CanvasTexture {
  const canvas = document.createElement('canvas')
  canvas.width = FRAMEWORK_CELL * FRAMEWORK_GRID.columns
  canvas.height = FRAMEWORK_CELL * FRAMEWORK_GRID.rows
  const context = canvas.getContext('2d')
  if (context) {
    icons.forEach((icon, index) => {
      if (!icon) return
      const column = index % FRAMEWORK_GRID.columns
      const row = Math.floor(index / FRAMEWORK_GRID.columns)
      drawContained(
        context,
        icon,
        column * FRAMEWORK_CELL + 40,
        row * FRAMEWORK_CELL + 40,
        FRAMEWORK_CELL - 80,
      )
    })
  }
  return finish(new CanvasTexture(canvas))
}

const LCD = { width: 2048, height: 448, segments: 12 } as const

/**
 * The installer's display: a backlit panel with the framework being
 * installed, its name and a segmented progress bar. Redrawn only when what
 * it shows changes (a new framework or one more segment).
 */
export class InstallerDisplay {
  readonly texture: CanvasTexture
  private readonly canvas: HTMLCanvasElement
  private readonly context: CanvasRenderingContext2D | null
  private icons: readonly (HTMLImageElement | null)[] = []
  private shown = ''
  private font = "'Aeonik Pro', Inter, system-ui, sans-serif"

  constructor(private palette: FactoryPalette) {
    this.canvas = document.createElement('canvas')
    this.canvas.width = LCD.width
    this.canvas.height = LCD.height
    this.context = this.canvas.getContext('2d')
    this.texture = finish(new CanvasTexture(this.canvas))
    const value = getComputedStyle(document.documentElement)
      .getPropertyValue('--font-aeonik-pro')
      .trim()
    if (value) this.font = value
  }

  setIcons(icons: readonly (HTMLImageElement | null)[]) {
    this.icons = icons
    this.shown = ''
  }

  setPalette(palette: FactoryPalette) {
    this.palette = palette
    this.shown = ''
  }

  /**
   * `progress` 0..1; `power` 0..1 while the line boots; `content` 0..1 fades
   * the framework in and out the way liquid crystal does, slow at the ends.
   */
  update(framework: number, progress: number, power: number, content: number) {
    const lit = Math.round(progress * LCD.segments)
    const level = Math.round(power * 8)
    const fade = Math.round(content * 12)
    const key = `${framework}:${lit}:${level}:${fade}`
    if (key === this.shown || !this.context) return
    this.shown = key
    this.draw(this.context, framework, lit, level / 8, fade / 12)
    this.texture.needsUpdate = true
  }

  private draw(
    context: CanvasRenderingContext2D,
    index: number,
    lit: number,
    power: number,
    content: number,
  ) {
    const { width, height, segments } = LCD
    const framework = FRAMEWORKS[index] ?? FRAMEWORKS[0]
    const dark = this.palette.dark
    context.globalAlpha = 1
    context.fillStyle = dark ? '#0b0c0e' : '#15161a'
    context.fillRect(0, 0, width, height)
    context.globalAlpha = power

    // Backlight: brighter in the middle, like an edge-lit panel.
    const backlight = context.createRadialGradient(
      width * 0.32,
      height * 0.5,
      0,
      width * 0.32,
      height * 0.5,
      width * 0.7,
    )
    backlight.addColorStop(0, 'rgba(255,255,255,0.07)')
    backlight.addColorStop(1, 'rgba(255,255,255,0)')
    context.fillStyle = backlight
    context.fillRect(0, 0, width, height)
    // Liquid crystal: the picture fades through the backlight rather than cutting.
    context.globalAlpha = power * content

    const icon = this.icons[index]
    const size = height * 0.62
    const left = height * 0.22
    if (icon) drawContained(context, icon, left, (height - size) / 2, size)

    const textX = left + size + height * 0.2
    context.fillStyle = '#f2f3f6'
    context.textBaseline = 'alphabetic'
    context.textAlign = 'left'
    context.direction = 'ltr'
    let fontSize = Math.round(height * 0.32)
    context.font = `500 ${fontSize}px ${this.font}`
    const available = width - textX - height * 0.2
    while (
      context.measureText(framework.name).width > available &&
      fontSize > 60
    ) {
      fontSize -= 2
      context.font = `500 ${fontSize}px ${this.font}`
    }
    context.fillText(framework.name, textX, height * 0.5)

    // Segmented progress bar in the framework's color.
    const barTop = height * 0.62
    const barHeight = height * 0.12
    const gap = 12
    const segment = (available - gap * (segments - 1)) / segments
    for (let n = 0; n < segments; n++) {
      context.fillStyle = n < lit ? framework.color : 'rgba(255,255,255,0.1)'
      context.fillRect(textX + n * (segment + gap), barTop, segment, barHeight)
    }

    context.globalAlpha = 1
  }

  dispose() {
    this.texture.dispose()
  }
}

/** Formats the shipped counter in the page's language. */
export function createCountFormatter(): Intl.NumberFormat {
  const locale =
    (typeof document !== 'undefined' && document.documentElement.lang) ||
    undefined
  try {
    return new Intl.NumberFormat(locale)
  } catch {
    return new Intl.NumberFormat()
  }
}

const COUNTER_SIZE = { width: 1024, height: 588 } as const

/** The display beside the rack: how many projects have shipped to the Appwrite Network. */
export class CounterDisplay {
  readonly texture: CanvasTexture
  private readonly canvas: HTMLCanvasElement
  private readonly context: CanvasRenderingContext2D | null
  private readonly format = createCountFormatter()
  private shown = ''
  private font = "'Aeonik Pro', Inter, system-ui, sans-serif"

  constructor(
    private palette: FactoryPalette,
    private label: string,
  ) {
    this.canvas = document.createElement('canvas')
    this.canvas.width = COUNTER_SIZE.width
    this.canvas.height = COUNTER_SIZE.height
    this.context = this.canvas.getContext('2d')
    this.texture = finish(new CanvasTexture(this.canvas))
    const value = getComputedStyle(document.documentElement)
      .getPropertyValue('--font-aeonik-pro')
      .trim()
    if (value) this.font = value
  }

  setPalette(palette: FactoryPalette) {
    this.palette = palette
    this.shown = ''
  }

  setLabel(label: string) {
    this.label = label
    this.shown = ''
  }

  /** `power` 0..1 while the line boots. */
  update(count: number, power: number) {
    const level = Math.round(power * 8)
    const key = `${count}:${level}`
    if (key === this.shown || !this.context) return
    this.shown = key
    this.draw(this.context, count, level / 8)
    this.texture.needsUpdate = true
  }

  private draw(
    context: CanvasRenderingContext2D,
    count: number,
    power: number,
  ) {
    const { width, height } = COUNTER_SIZE
    const pink = this.palette.toneHex.pink
    context.globalAlpha = 1
    context.fillStyle = '#0d0d10'
    context.fillRect(0, 0, width, height)
    context.globalAlpha = power
    const glow = context.createRadialGradient(
      width * 0.2,
      height * 0.1,
      0,
      width * 0.2,
      height * 0.1,
      width * 0.9,
    )
    glow.addColorStop(0, 'rgba(253,54,110,0.16)')
    glow.addColorStop(1, 'rgba(253,54,110,0)')
    context.fillStyle = glow
    context.fillRect(0, 0, width, height)

    const pad = 72
    context.textAlign = 'left'
    context.textBaseline = 'alphabetic'
    const rtl =
      typeof document !== 'undefined' && document.documentElement.dir === 'rtl'
    context.direction = rtl ? 'rtl' : 'ltr'
    const x = rtl ? width - pad : pad
    context.textAlign = rtl ? 'right' : 'left'

    // Live dot and label.
    const dotX = rtl ? width - pad - 16 : pad + 16
    context.fillStyle = pink
    context.beginPath()
    context.arc(dotX, 132, 16, 0, Math.PI * 2)
    context.fill()
    context.fillStyle = 'rgba(255,255,255,0.78)'
    let labelSize = 84
    context.font = `500 ${labelSize}px ${this.font}`
    while (
      context.measureText(this.label).width > width - pad * 2 - 56 &&
      labelSize > 40
    ) {
      labelSize -= 4
      context.font = `500 ${labelSize}px ${this.font}`
    }
    context.fillText(this.label, rtl ? x - 56 : x + 56, 132 + labelSize * 0.36)

    context.fillStyle = '#ffffff'
    let size = 220
    const text = this.format.format(count)
    context.font = `500 ${size}px ${this.font}`
    while (context.measureText(text).width > width - pad * 2 && size > 80) {
      size -= 6
      context.font = `500 ${size}px ${this.font}`
    }
    context.fillText(text, x, height - 120)

    context.fillStyle = 'rgba(255,255,255,0.14)'
    context.fillRect(pad, height - 72, width - pad * 2, 3)
    context.globalAlpha = 1
  }

  dispose() {
    this.texture.dispose()
  }
}
