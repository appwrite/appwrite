import { isHtmlDarkChrome, isResolvedThemeDarkChrome } from '@/lib/html-theme'
import type { Init5ScenePalette } from './scene-types'

/**
 * The Init 5 light's colors, read from the console theme. Shared by the hero
 * and the site banner so both storms burn in the same tones.
 */

export function isDarkChrome(resolvedTheme: string | undefined) {
  if (resolvedTheme === 'light') return false
  if (resolvedTheme === 'dark') return true
  if (!resolvedTheme) return isHtmlDarkChrome()
  if (isResolvedThemeDarkChrome(resolvedTheme)) return true
  return isHtmlDarkChrome()
}

let colorProbe: CanvasRenderingContext2D | null = null

/** Any CSS color (hex, rgb, oklch, color-mix...) to `#rrggbb` via a 1px canvas. */
function cssColorToHex(value: string, fallback: string) {
  if (typeof document === 'undefined') return fallback
  if (!colorProbe) {
    const canvas = document.createElement('canvas')
    canvas.width = 1
    canvas.height = 1
    colorProbe = canvas.getContext('2d', { willReadFrequently: true })
  }
  const ctx = colorProbe
  if (!ctx || !value) return fallback
  ctx.clearRect(0, 0, 1, 1)
  ctx.fillStyle = fallback
  ctx.fillStyle = value
  ctx.fillRect(0, 0, 1, 1)
  const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data
  return `#${[r, g, b].map((channel) => channel.toString(16).padStart(2, '0')).join('')}`
}

export function readPalette(
  element: HTMLElement,
  isDark: boolean,
): Init5ScenePalette {
  const style = getComputedStyle(element)
  const read = (name: string, fallback: string) =>
    cssColorToHex(style.getPropertyValue(name).trim(), fallback)
  return {
    isDark,
    background: read('--background', isDark ? '#19191c' : '#ffffff'),
    foreground: read('--foreground', isDark ? '#fafafa' : '#18181b'),
    accent: read('--brand-cta', '#fd366e'),
    violet: read('--network-globe-edge', isDark ? '#9b8aff' : '#7c67fe'),
    peach: read('--network-globe-region', isDark ? '#ffb088' : '#fe9567'),
  }
}
