import type { CoverThemeDefinition, CoverThemeId } from '@/lib/cover-generator/themes'
import { getCoverTheme } from '@/lib/cover-generator/themes'
import { getCoverTitleGradientSvgStopsForTheme } from '@/lib/cover-generator/cover-title-gradient-stops'

/** Matches marketing `.text-gradient-brand` in `src/styles.css`. */
export const COVER_TITLE_GRADIENT_ANGLE_DEG = 145

export type CoverTitleGradientStop = {
  offset: string
  color: string
}

/**
 * Resolved hex stops for SVG export (sharp/librsvg do not support color-mix in stop-color).
 * Computed from theme tokens with OKLCH mixing to match `.text-gradient-brand`.
 */
export function getCoverTitleGradientSvgStops(
  themeId: CoverThemeId,
): CoverTitleGradientStop[] {
  return getCoverTitleGradientSvgStopsForTheme(themeId)
}

/** CSS angle → SVG objectBoundingBox line (matches browser linear-gradient direction). */
export function getCoverTitleGradientSvgLine(angleDeg = COVER_TITLE_GRADIENT_ANGLE_DEG) {
  const angleRad = ((angleDeg - 90) * Math.PI) / 180
  return {
    x1: 0.5 - Math.cos(angleRad) * 0.5,
    y1: 0.5 - Math.sin(angleRad) * 0.5,
    x2: 0.5 + Math.cos(angleRad) * 0.5,
    y2: 0.5 + Math.sin(angleRad) * 0.5,
  }
}

export type CoverTitleGradientBounds = {
  x: number
  y: number
  width: number
  height: number
}

/** CSS linear-gradient line half-length for a box (matches browser coverage). */
export function getCoverTitleGradientLineHalfLength(
  width: number,
  height: number,
  angleDeg = COVER_TITLE_GRADIENT_ANGLE_DEG,
): number {
  const angleRad = ((angleDeg - 90) * Math.PI) / 180
  return (
    Math.abs((width / 2) * Math.sin(angleRad)) +
    Math.abs((height / 2) * Math.cos(angleRad))
  )
}

/** User-space gradient on the title block box (same as homepage `h1.text-gradient-brand`). */
export function getCoverTitleGradientUserSpaceLine(
  bounds: CoverTitleGradientBounds,
  angleDeg = COVER_TITLE_GRADIENT_ANGLE_DEG,
) {
  const angleRad = ((angleDeg - 90) * Math.PI) / 180
  const cx = bounds.x + bounds.width / 2
  const cy = bounds.y + bounds.height / 2
  const length = getCoverTitleGradientLineHalfLength(
    bounds.width,
    bounds.height,
    angleDeg,
  )

  return {
    x1: cx - Math.cos(angleRad) * length,
    y1: cy - Math.sin(angleRad) * length,
    x2: cx + Math.cos(angleRad) * length,
    y2: cy + Math.sin(angleRad) * length,
  }
}

/** CSS background for preview - uses color-mix like the marketing hero. */
export function getCoverTitleGradientBackgroundImage(
  theme: CoverThemeDefinition,
): string {
  const { brandCta, foreground, background, family } = theme

  if (family === 'dark') {
    return `linear-gradient(${COVER_TITLE_GRADIENT_ANGLE_DEG}deg, color-mix(in oklch, ${brandCta} 55%, ${foreground}) 0%, color-mix(in oklch, ${brandCta} 88%, ${foreground}) 28%, ${foreground} 62%)`
  }

  return `linear-gradient(${COVER_TITLE_GRADIENT_ANGLE_DEG}deg, color-mix(in oklch, ${brandCta} 45%, ${background}) 0%, color-mix(in oklch, ${brandCta} 72%, ${foreground}) 18%, ${foreground} 46%)`
}

export function buildCoverTitleGradientSvgDef(
  themeId: CoverThemeId,
  userSpaceBounds?: CoverTitleGradientBounds,
): string {
  const stops = getCoverTitleGradientSvgStops(themeId)
    .map(
      (stop) =>
        `<stop offset="${stop.offset}" stop-color="${stop.color}" />`,
    )
    .join('\n        ')

  if (userSpaceBounds) {
    const line = getCoverTitleGradientUserSpaceLine(userSpaceBounds)
    return `
      <linearGradient id="cover-title-gradient" gradientUnits="userSpaceOnUse" x1="${line.x1}" y1="${line.y1}" x2="${line.x2}" y2="${line.y2}">
        ${stops}
      </linearGradient>
  `
  }

  const line = getCoverTitleGradientSvgLine()
  return `
      <linearGradient id="cover-title-gradient" gradientUnits="objectBoundingBox" x1="${line.x1}" y1="${line.y1}" x2="${line.x2}" y2="${line.y2}">
        ${stops}
      </linearGradient>
  `
}

export function getCoverTitleGradientStyle(themeId: CoverThemeId): {
  backgroundImage: string
  WebkitBackgroundClip: 'text'
  backgroundClip: 'text'
  color: 'transparent'
  WebkitTextFillColor: 'transparent'
  paddingInlineEnd: string
} {
  return {
    backgroundImage: getCoverTitleGradientBackgroundImage(getCoverTheme(themeId)),
    WebkitBackgroundClip: 'text',
    backgroundClip: 'text',
    color: 'transparent',
    WebkitTextFillColor: 'transparent',
    // background-clip:text trims glyph overhang on the last character (worse with negative tracking).
    paddingInlineEnd: '0.075em',
  }
}
