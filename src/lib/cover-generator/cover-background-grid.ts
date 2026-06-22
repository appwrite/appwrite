import { getCoverBrandThemeForSvgExport, getCoverTheme } from '@/lib/cover-generator/themes'
import type { CoverBackgroundGridStyle, CoverThemeId } from '@/lib/cover-generator/themes'

function getCoverGridStroke(themeId: CoverThemeId): string {
  const theme = getCoverTheme(themeId)
  if (theme.family === 'dark') {
    return 'rgba(63, 67, 70, 0.35)'
  }
  return getCoverBrandThemeForSvgExport(themeId).border
}

function svgPatternDataUri(svg: string): string {
  return `url("data:image/svg+xml,${encodeURIComponent(svg)}")`
}

export function buildCoverBackgroundGridSvgPattern(
  themeId: CoverThemeId,
  style: CoverBackgroundGridStyle,
): string {
  const stroke = getCoverGridStroke(themeId)
  const fill = getCoverGridStroke(themeId)

  switch (style) {
    case 'dots':
      return `
        <pattern id="cover-background-grid" width="18" height="18" patternUnits="userSpaceOnUse">
          <circle cx="1" cy="1" r="1" fill="${fill}" />
        </pattern>
      `.trim()
    case 'grid':
      return `
        <pattern id="cover-background-grid" width="24" height="24" patternUnits="userSpaceOnUse">
          <path d="M 24 0 L 0 0 0 24" fill="none" stroke="${stroke}" stroke-width="0.75" />
        </pattern>
      `.trim()
    case 'diagonal':
      return `
        <pattern id="cover-background-grid" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
          <line x1="0" y1="0" x2="0" y2="10" stroke="${stroke}" stroke-width="0.75" />
        </pattern>
      `.trim()
    case 'none':
      return ''
  }
}

export function getCoverBackgroundGridCssStyle(
  themeId: CoverThemeId,
  style: CoverBackgroundGridStyle,
): { backgroundImage: string; backgroundSize: string } | null {
  if (style === 'none') return null

  const stroke = getCoverGridStroke(themeId)

  switch (style) {
    case 'dots':
      return {
        backgroundImage: `radial-gradient(circle, ${stroke} 1px, transparent 1px)`,
        backgroundSize: '18px 18px',
      }
    case 'grid':
      return {
        backgroundImage: `
          linear-gradient(${stroke} 0.75px, transparent 0.75px),
          linear-gradient(90deg, ${stroke} 0.75px, transparent 0.75px)
        `.trim(),
        backgroundSize: '24px 24px',
      }
    case 'diagonal':
      return {
        backgroundImage: svgPatternDataUri(
          `<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><line x1="0" y1="10" x2="10" y2="0" stroke="${stroke}" stroke-width="0.75"/></svg>`,
        ),
        backgroundSize: '10px 10px',
      }
  }
}

/** @deprecated Use buildCoverBackgroundGridSvgPattern with theme backgroundGrid style. */
export function buildCoverDotGridSvgPattern(themeId: CoverThemeId): string {
  return buildCoverBackgroundGridSvgPattern(themeId, getCoverTheme(themeId).backgroundGrid)
}

/** @deprecated Use getCoverBackgroundGridCssStyle with theme backgroundGrid style. */
export function getCoverDottedBackgroundStyle(themeId: CoverThemeId): {
  backgroundImage: string
  backgroundSize: string
} {
  return getCoverBackgroundGridCssStyle(themeId, getCoverTheme(themeId).backgroundGrid)
}
