const DEFAULT_ACCENT_HSL = { h: 344, s: 98, l: 62 }

function rgbToHsl(r: number, g: number, b: number) {
  const rn = r / 255
  const gn = g / 255
  const bn = b / 255
  const max = Math.max(rn, gn, bn)
  const min = Math.min(rn, gn, bn)
  const delta = max - min
  let h = 0
  let s = 0
  const l = (max + min) / 2

  if (delta !== 0) {
    s = delta / (1 - Math.abs(2 * l - 1))
    switch (max) {
      case rn:
        h = ((gn - bn) / delta + (gn < bn ? 6 : 0)) * 60
        break
      case gn:
        h = ((bn - rn) / delta + 2) * 60
        break
      default:
        h = ((rn - gn) / delta + 4) * 60
        break
    }
  }

  return {
    h: Math.round(h),
    s: Math.round(s * 100),
    l: Math.round(l * 100),
  }
}

/** Resolve any CSS color (including custom properties) to a computed `rgb(...)`. */
export function resolveCssColor(
  color: string,
  context?: HTMLElement | null,
): string {
  if (typeof document === 'undefined') return color

  const probe = document.createElement('span')
  probe.style.color = color
  probe.style.display = 'none'
  const parent = context ?? document.documentElement
  parent.appendChild(probe)
  const resolved = getComputedStyle(probe).color
  probe.remove()
  return resolved
}

export type ParsedAccentHsl = { h: number; s: number; l: number }

export function parseCssAccentToHsl(
  color: string,
  context?: HTMLElement | null,
): ParsedAccentHsl {
  const resolved = resolveCssColor(color, context)
  const match = resolved.match(
    /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/i,
  )
  if (!match) return DEFAULT_ACCENT_HSL

  const r = Number(match[1])
  const g = Number(match[2])
  const b = Number(match[3])
  if ([r, g, b].some((channel) => Number.isNaN(channel))) {
    return DEFAULT_ACCENT_HSL
  }

  return rgbToHsl(r, g, b)
}

export function accentHslToCss({ h, s, l }: ParsedAccentHsl) {
  return `hsl(${h}, ${s}%, ${l}%)`
}
