/** Normalize any browser color string to `rgb(r, g, b)` for canvas / Three.js. */
function normalizeComputedColorToRgb(computed: string, fallback: string): string {
  if (!computed || computed === 'rgba(0, 0, 0, 0)') return fallback

  const rgbMatch = computed.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i)
  if (rgbMatch) {
    return `rgb(${rgbMatch[1]}, ${rgbMatch[2]}, ${rgbMatch[3]})`
  }

  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  if (!ctx) return fallback

  try {
    ctx.fillStyle = computed
    const normalized = ctx.fillStyle
    if (normalized.startsWith('#')) {
      const hex = normalized.slice(1)
      const full =
        hex.length === 3
          ? `${hex[0]}${hex[0]}${hex[1]}${hex[1]}${hex[2]}${hex[2]}`
          : hex
      const r = Number.parseInt(full.slice(0, 2), 16)
      const g = Number.parseInt(full.slice(2, 4), 16)
      const b = Number.parseInt(full.slice(4, 6), 16)
      if (![r, g, b].some(Number.isNaN)) {
        return `rgb(${r}, ${g}, ${b})`
      }
    }

    const normalizedMatch = normalized.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i)
    if (normalizedMatch) {
      return `rgb(${normalizedMatch[1]}, ${normalizedMatch[2]}, ${normalizedMatch[3]})`
    }
  } catch {
    // Fall through to fallback.
  }

  return fallback
}

/** Resolve any CSS color expression (var, color-mix, etc.) to computed `rgb(...)`. */
export function getCssColorExpression(expression: string, fallback: string): string {
  if (typeof document === 'undefined') return fallback

  const probe = document.createElement('span')
  probe.style.color = expression
  probe.style.display = 'none'
  document.documentElement.appendChild(probe)

  const computed = getComputedStyle(probe).color
  probe.remove()

  return normalizeComputedColorToRgb(computed, fallback)
}

/** Resolve a CSS custom property to a computed `rgb(...)` string for canvas / Three.js. */
export function getCssThemeColor(variable: string, fallback: string): string {
  return getCssColorExpression(`var(${variable})`, fallback)
}

/** Resolve a CSS color expression to `#rrggbb` for Three.js / three-globe. */
export function cssColorToHex(expression: string, fallback = '#888888'): string {
  return rgbToHex(getCssColorExpression(expression, fallback), fallback)
}

/** Build rgba from an rgb/hex string and alpha (0–1). */
export function withAlpha(color: string, alpha: number): string {
  if (color.startsWith('#')) {
    const hex = color.replace('#', '')
    const normalized =
      hex.length === 3
        ? `${hex[0]}${hex[0]}${hex[1]}${hex[1]}${hex[2]}${hex[2]}`
        : hex
    const r = Number.parseInt(normalized.slice(0, 2), 16)
    const g = Number.parseInt(normalized.slice(2, 4), 16)
    const b = Number.parseInt(normalized.slice(4, 6), 16)
    if (!Number.isNaN(r) && !Number.isNaN(g) && !Number.isNaN(b)) {
      return `rgba(${r}, ${g}, ${b}, ${alpha})`
    }
  }

  const match = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i)
  if (!match) return color
  return `rgba(${match[1]}, ${match[2]}, ${match[3]}, ${alpha})`
}

/** Convert computed `rgb(...)` / `#hex` to `#rrggbb` for Three.js / three-globe. */
export function rgbToHex(rgbColor: string, fallback = '#888888'): string {
  if (rgbColor.startsWith('#')) {
    return rgbColor.length === 4
      ? `#${rgbColor[1]}${rgbColor[1]}${rgbColor[2]}${rgbColor[2]}${rgbColor[3]}${rgbColor[3]}`
      : rgbColor
  }

  const match = rgbColor.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i)
  if (!match) return fallback

  const toHex = (value: string) => Number(value).toString(16).padStart(2, '0')
  return `#${toHex(match[1])}${toHex(match[2])}${toHex(match[3])}`
}
