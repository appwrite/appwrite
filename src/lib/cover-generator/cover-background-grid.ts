import { getCoverBrandLightRgb } from '@/lib/cover-generator/cover-brand-lights'
import { getCoverBrandThemeForSvgExport, getCoverTheme } from '@/lib/cover-generator/themes'
import type { CoverBackgroundGridStyle, CoverThemeId } from '@/lib/cover-generator/themes'

/** Target number of square cells along the shorter canvas edge. */
const COVER_SQUARE_GRID_DIVISIONS = 6
const COVER_SQUARE_PATTERN_FALLBACK_WIDTH = 1200
const COVER_SQUARE_PATTERN_FALLBACK_HEIGHT = 630

const COVER_SQUARE_FILL_ANCHORS: ReadonlyArray<{
  u: number
  v: number
  strength: 0 | 1 | 2
}> = [
  { u: 0.16, v: 0.1, strength: 1 },
  { u: 0.74, v: 0.14, strength: 0 },
  { u: 0.04, v: 0.42, strength: 0 },
  { u: 0.92, v: 0.36, strength: 2 },
  { u: 0.22, v: 0.78, strength: 1 },
  { u: 0.82, v: 0.86, strength: 1 },
  { u: 0.58, v: 0.04, strength: 2 },
  { u: 0.38, v: 0.92, strength: 2 },
]

const COVER_SQUARE_FILL_OPACITY = {
  light: [0.14, 0.08, 0.045],
  /** Matches dark-mono shade wash: strong 0.5, mid 0.2, plus a fainter step. */
  dark: [0.5, 0.28, 0.14],
} as const

export type CoverBackgroundSquareFill = {
  x: number
  y: number
  size: number
  fill: string
}

function rgba([r, g, b]: [number, number, number], alpha: number): string {
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

function getCoverGridStroke(themeId: CoverThemeId): string {
  const theme = getCoverTheme(themeId)
  if (theme.family === 'dark') {
    return 'rgba(63, 67, 70, 0.35)'
  }
  return getCoverBrandThemeForSvgExport(themeId).border
}

function getCoverSquareAccentRgb(themeId: CoverThemeId): [number, number, number] {
  if (getCoverTheme(themeId).family === 'dark') return getCoverBrandLightRgb('mono')
  return getCoverBrandLightRgb('pink')
}

function getCoverSquareGridStroke(themeId: CoverThemeId): string {
  if (getCoverTheme(themeId).family === 'dark') {
    const [r, g, b] = getCoverBrandLightRgb('mono')
    return rgba([r, g, b], 0.55)
  }
  return rgba(getCoverBrandLightRgb('pink'), 0.13)
}

function svgPatternDataUri(svg: string): string {
  return `url("data:image/svg+xml,${encodeURIComponent(svg)}")`
}

function hashCell(col: number, row: number): number {
  let hash = Math.imul(col + 1, 374761393) ^ Math.imul(row + 1, 668265263)
  hash = Math.imul(hash ^ (hash >>> 13), 1274126177)
  return (hash ^ (hash >>> 16)) >>> 0
}

export function getCoverSquareCellSize(width: number, height: number): number {
  const shortSide = Math.min(Math.abs(width), Math.abs(height))
  if (!Number.isFinite(shortSide) || shortSide <= 0) return 105
  return Math.max(8, Math.round(shortSide / COVER_SQUARE_GRID_DIVISIONS))
}

function getCoverSquareFillOpacity(themeId: CoverThemeId, strength: 0 | 1 | 2): number {
  const family = getCoverTheme(themeId).family
  return COVER_SQUARE_FILL_OPACITY[family][strength]
}

export function getCoverBackgroundSquareFills(
  themeId: CoverThemeId,
  width: number,
  height: number,
): CoverBackgroundSquareFill[] {
  const cell = getCoverSquareCellSize(width, height)
  const cols = Math.max(1, Math.ceil(width / cell))
  const rows = Math.max(1, Math.ceil(height / cell))
  const accent = getCoverSquareAccentRgb(themeId)
  const used = new Set<string>()
  const fills: CoverBackgroundSquareFill[] = []

  const addFill = (col: number, row: number, strength: 0 | 1 | 2) => {
    if (col < 0 || row < 0 || col >= cols || row >= rows) return
    const key = `${col}:${row}`
    if (used.has(key)) return
    used.add(key)
    fills.push({
      x: col * cell,
      y: row * cell,
      size: cell,
      fill: rgba(accent, getCoverSquareFillOpacity(themeId, strength)),
    })
  }

  for (const anchor of COVER_SQUARE_FILL_ANCHORS) {
    addFill(Math.round(anchor.u * (cols - 1)), Math.round(anchor.v * (rows - 1)), anchor.strength)
  }

  for (let row = 0; row < rows; row += 1) {
    for (let col = 0; col < cols; col += 1) {
      const nx = (col + 0.5) / cols - 0.5
      const ny = (row + 0.5) / rows - 0.5
      const dist = Math.sqrt(nx * nx * 4 + ny * ny * 4)
      if (dist < 0.38) continue

      const hash = hashCell(col, row)
      if (hash % 71 !== 0) continue

      addFill(col, row, ((hash >>> 8) % 3) as 0 | 1 | 2)
    }
  }

  return fills
}

function getCoverSquarePatternSize(
  width = COVER_SQUARE_PATTERN_FALLBACK_WIDTH,
  height = COVER_SQUARE_PATTERN_FALLBACK_HEIGHT,
): number {
  return getCoverSquareCellSize(width, height)
}

export function buildCoverBackgroundGridSvgPattern(
  themeId: CoverThemeId,
  style: CoverBackgroundGridStyle,
  width = COVER_SQUARE_PATTERN_FALLBACK_WIDTH,
  height = COVER_SQUARE_PATTERN_FALLBACK_HEIGHT,
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
    case 'squares': {
      const cell = getCoverSquarePatternSize(width, height)
      const squareStroke = getCoverSquareGridStroke(themeId)
      return `
        <pattern id="cover-background-grid" width="${cell}" height="${cell}" patternUnits="userSpaceOnUse">
          <path d="M ${cell} 0 L 0 0 0 ${cell}" fill="none" stroke="${squareStroke}" stroke-width="1" />
        </pattern>
      `.trim()
    }
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
  width = COVER_SQUARE_PATTERN_FALLBACK_WIDTH,
  height = COVER_SQUARE_PATTERN_FALLBACK_HEIGHT,
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
    case 'squares': {
      const cell = getCoverSquarePatternSize(width, height)
      const squareStroke = getCoverSquareGridStroke(themeId)
      return {
        backgroundImage: `
          linear-gradient(${squareStroke} 1px, transparent 1px),
          linear-gradient(90deg, ${squareStroke} 1px, transparent 1px)
        `.trim(),
        backgroundSize: `${cell}px ${cell}px`,
      }
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
