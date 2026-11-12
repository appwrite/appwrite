import type { CoverBrandTheme } from '@/lib/cover-generator/brand-theme'
import { getCoverTheme, type CoverThemeId } from '@/lib/cover-generator/themes'

function withAlpha(color: string, alpha: number): string {
  if (color.startsWith('#') && color.length === 7) {
    const value = Math.round(alpha * 255)
      .toString(16)
      .padStart(2, '0')
    return `${color}${value}`
  }
  return color
}

export type DiagramNodeSurfaceColors = {
  fill: string
  stroke: string
  strokeWidth: number
}

/**
 * Card fill/stroke for diagram elements.
 * Uses brand tokens only. Dark themes lift slightly with muted fill and a soft
 * foreground-tint border so edges read without a heavy zinc outline.
 */
export function getDiagramNodeSurfaceColors(
  brand: CoverBrandTheme,
  themeId: CoverThemeId,
): DiagramNodeSurfaceColors {
  const isDark = getCoverTheme(themeId).family === 'dark'

  if (isDark) {
    return {
      fill: withAlpha(brand.muted, 0.45),
      stroke: withAlpha(brand.foreground, 0.16),
      strokeWidth: 1,
    }
  }

  return {
    fill: withAlpha(brand.background, 0.92),
    stroke: withAlpha(brand.foreground, 0.1),
    strokeWidth: 1,
  }
}

/** Edge label pill chrome, aligned with node surfaces. */
export function getDiagramEdgeLabelSurfaceColors(
  brand: CoverBrandTheme,
  themeId: CoverThemeId,
): { fill: string; stroke: string } {
  const isDark = getCoverTheme(themeId).family === 'dark'

  if (isDark) {
    return {
      fill: withAlpha(brand.background, 0.92),
      stroke: withAlpha(brand.foreground, 0.14),
    }
  }

  return {
    fill: brand.background,
    stroke: withAlpha(brand.foreground, 0.1),
  }
}
