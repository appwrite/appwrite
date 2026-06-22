/** OKLCH color-mix for SVG export (matches CSS `color-mix(in oklch, …)`). */

type Oklab = { l: number; a: number; b: number }
type Oklch = { l: number; c: number; h: number }

function clamp01(value: number): number {
  return Math.min(1, Math.max(0, value))
}

function srgbToLinear(channel: number): number {
  return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
}

function linearToSrgb(channel: number): number {
  return channel <= 0.0031308 ? 12.92 * channel : 1.055 * channel ** (1 / 2.4) - 0.055
}

function hexToRgb(hex: string): [number, number, number] {
  const normalized = hex.replace('#', '')
  const full =
    normalized.length === 3
      ? normalized
          .split('')
          .map((char) => char + char)
          .join('')
      : normalized
  return [
    Number.parseInt(full.slice(0, 2), 16) / 255,
    Number.parseInt(full.slice(2, 4), 16) / 255,
    Number.parseInt(full.slice(4, 6), 16) / 255,
  ]
}

function rgbToHex(r: number, g: number, b: number): string {
  const toHex = (value: number) =>
    Math.round(clamp01(value) * 255)
      .toString(16)
      .padStart(2, '0')
  return `#${toHex(r)}${toHex(g)}${toHex(b)}`
}

function parseOklchString(value: string): Oklch | null {
  const match = value
    .trim()
    .match(/^oklch\(\s*([0-9.]+%?)\s+([0-9.]+)\s+([0-9.]+)(?:deg)?\s*\)$/i)
  if (!match) return null

  let l = Number.parseFloat(match[1])
  if (match[1].includes('%')) l /= 100

  return {
    l,
    c: Number.parseFloat(match[2]),
    h: Number.parseFloat(match[3]),
  }
}

/** Resolve CSS hex/oklch colors to hex for SVG export (librsvg does not support oklch). */
export function cssColorToHex(color: string): string {
  const trimmed = color.trim()
  if (trimmed.startsWith('#')) return trimmed

  const oklch = parseOklchString(trimmed)
  if (oklch) {
    const [r, g, b] = oklchToRgb(oklch)
    return rgbToHex(r, g, b)
  }

  return trimmed
}

/** W3C CSS Color 4: sRGB → OKLab */
function rgbToOklab(r: number, g: number, b: number): Oklab {
  const lr = srgbToLinear(r)
  const lg = srgbToLinear(g)
  const lb = srgbToLinear(b)

  const l = Math.cbrt(0.4122214708 * lr + 0.5363325363 * lg + 0.0514459929 * lb)
  const m = Math.cbrt(0.2119034982 * lr + 0.6806995451 * lg + 0.1073969566 * lb)
  const s = Math.cbrt(0.0883024619 * lr + 0.2817188376 * lg + 0.6299787005 * lb)

  return {
    l: 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
    a: 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
    b: 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
  }
}

/** W3C CSS Color 4: OKLab → sRGB */
function oklabToRgb(color: Oklab): [number, number, number] {
  const l = color.l + 0.3963377774 * color.a + 0.2158037573 * color.b
  const m = color.l - 0.1055613458 * color.a - 0.0638541728 * color.b
  const s = color.l - 0.0894841775 * color.a - 1.291485548 * color.b

  const lr = 4.0767416621 * l ** 3 - 3.3077115913 * m ** 3 + 0.2309699292 * s ** 3
  const lg = -1.2684380046 * l ** 3 + 2.6097574011 * m ** 3 - 0.3413193965 * s ** 3
  const lb = -0.0041960863 * l ** 3 - 0.7047186147 * m ** 3 + 1.707614701 * s ** 3

  return [linearToSrgb(lr), linearToSrgb(lg), linearToSrgb(lb)]
}

function oklabToOklch(color: Oklab): Oklch {
  const c = Math.sqrt(color.a * color.a + color.b * color.b)
  let h = (Math.atan2(color.b, color.a) * 180) / Math.PI
  if (h < 0) h += 360
  return { l: color.l, c, h }
}

function oklchToOklab(color: Oklch): Oklab {
  const hueRad = (color.h * Math.PI) / 180
  return {
    l: color.l,
    a: color.c * Math.cos(hueRad),
    b: color.c * Math.sin(hueRad),
  }
}

function rgbToOklch(r: number, g: number, b: number): Oklch {
  return oklabToOklch(rgbToOklab(r, g, b))
}

function oklchToRgb(color: Oklch): [number, number, number] {
  return oklabToRgb(oklchToOklab(color))
}

function interpolateHue(h1: number, h2: number, t: number): number {
  const delta = ((h2 - h1 + 540) % 360) - 180
  return (h1 + delta * t + 360) % 360
}

const OKLCH_CHROMA_EPSILON = 0.01

function mixOklchHue(h1: number, c1: number, h2: number, c2: number, t: number): number {
  const firstIsAchromatic = c1 < OKLCH_CHROMA_EPSILON
  const secondIsAchromatic = c2 < OKLCH_CHROMA_EPSILON

  if (firstIsAchromatic && secondIsAchromatic) return h1
  if (firstIsAchromatic) return h2
  if (secondIsAchromatic) return h1
  return interpolateHue(h1, h2, t)
}

function mixOklch(a: Oklch, b: Oklch, t: number): Oklch {
  return {
    l: a.l * t + b.l * (1 - t),
    c: a.c * t + b.c * (1 - t),
    h: mixOklchHue(a.h, a.c, b.h, b.c, t),
  }
}

/** Mix two hex colors in OKLCH. `firstPercent` is the share of the first color (CSS color-mix). */
export function mixOklchHex(
  first: string,
  second: string,
  firstPercent: number,
): string {
  const t = firstPercent / 100
  const mixed = mixOklch(
    rgbToOklch(...hexToRgb(cssColorToHex(first))),
    rgbToOklch(...hexToRgb(cssColorToHex(second))),
    t,
  )
  const [r, g, b] = oklchToRgb(mixed)
  return rgbToHex(r, g, b)
}
