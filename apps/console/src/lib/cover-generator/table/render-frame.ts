import { buildCoverExportFontStyleBlock } from '@/lib/cover-generator/cover-export-font-styles'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import { COVER_TABLE_DEFAULT_FRAME_WIDTH_PERCENT } from '@/lib/cover-generator/table/constants'
import { escapeXml, stripCoverTitleSuffix } from '@/lib/cover-generator/text-utils'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

export const COVER_TABLE_LAYOUT = {
  cardPaddingX: 24,
  cardPaddingY: 24,
  titleFontSize: 42,
  titleLineHeight: 50,
  subtitleFontSize: 24,
  subtitleGap: 12,
  titleCardGap: 28,
  rowHeight: 58,
  headerFontSize: 16,
  cellFontSize: 17,
  innerRadius: 14,
  outerRadius: 24,
  borderWidth: COVER_HERO_SCREENSHOT_FRAME.borderWidth,
} as const

export const COVER_TABLE_REFERENCE_FRAME_WIDTH = getCoverFrameWidthPx(
  COVER_TABLE_DEFAULT_FRAME_WIDTH_PERCENT,
  { width: COVER_WIDTH, height: COVER_HEIGHT },
)

export type ScaledCoverTableLayout = {
  cardPaddingX: number
  cardPaddingY: number
  titleFontSize: number
  titleLineHeight: number
  subtitleFontSize: number
  subtitleGap: number
  titleCardGap: number
  rowHeight: number
  headerFontSize: number
  cellFontSize: number
  innerRadius: number
  outerRadius: number
  borderWidth: number
}

export function getScaledCoverTableLayout(frameWidth: number): ScaledCoverTableLayout {
  const scale = frameWidth / COVER_TABLE_REFERENCE_FRAME_WIDTH

  return {
    cardPaddingX: COVER_TABLE_LAYOUT.cardPaddingX * scale,
    cardPaddingY: COVER_TABLE_LAYOUT.cardPaddingY * scale,
    titleFontSize: COVER_TABLE_LAYOUT.titleFontSize * scale,
    titleLineHeight: COVER_TABLE_LAYOUT.titleLineHeight * scale,
    subtitleFontSize: COVER_TABLE_LAYOUT.subtitleFontSize * scale,
    subtitleGap: COVER_TABLE_LAYOUT.subtitleGap * scale,
    titleCardGap: COVER_TABLE_LAYOUT.titleCardGap * scale,
    rowHeight: COVER_TABLE_LAYOUT.rowHeight * scale,
    headerFontSize: COVER_TABLE_LAYOUT.headerFontSize * scale,
    cellFontSize: COVER_TABLE_LAYOUT.cellFontSize * scale,
    innerRadius: COVER_TABLE_LAYOUT.innerRadius * scale,
    outerRadius: COVER_TABLE_LAYOUT.outerRadius * scale,
    borderWidth: Math.max(1, COVER_TABLE_LAYOUT.borderWidth * scale),
  }
}

function truncateCellText(value: string, maxLength: number): string {
  const trimmed = value.trim()
  if (trimmed.length <= maxLength) return trimmed
  return `${trimmed.slice(0, Math.max(0, maxLength - 1))}…`
}

function coverTableRowTextY(rowY: number, layout: ScaledCoverTableLayout, fontSize: number): number {
  return Math.round(rowY + (layout.rowHeight - fontSize) / 2)
}

export function measureCoverTableTitleBlockHeight(
  title: string | undefined,
  subtitle: string | undefined,
  layout: ScaledCoverTableLayout = COVER_TABLE_LAYOUT,
): number {
  if (!title && !subtitle) return 0

  const titleText = title ? stripCoverTitleSuffix(title) : ''
  return (
    (titleText ? layout.titleLineHeight : 0) +
    (titleText && subtitle ? layout.subtitleGap : 0) +
    (subtitle ? layout.subtitleFontSize + 8 : 0)
  )
}

export function buildCoverTableTitleBlockSvg(
  title: string | undefined,
  subtitle: string | undefined,
  centerX: number,
  yOffset: number,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
  layout: ScaledCoverTableLayout,
): { svg: string; height: number } {
  if (!title && !subtitle) {
    return { svg: '', height: 0 }
  }

  const titleText = title ? stripCoverTitleSuffix(title) : ''
  const titleY = yOffset
  const subtitleY = titleText
    ? titleY + layout.titleLineHeight + layout.subtitleGap
    : titleY

  const svg = `
    ${
      titleText
        ? `<text class="cover-title" text-anchor="middle" fill="${brand.foreground}" font-size="${layout.titleFontSize}" font-weight="600" x="${centerX}" y="${coverSvgTextBaseline(titleY, layout.titleFontSize)}">${escapeXml(titleText)}</text>`
        : ''
    }
    ${
      subtitle
        ? `<text class="cover-body" text-anchor="middle" fill="${brand.mutedForeground}" font-size="${layout.subtitleFontSize}" x="${centerX}" y="${coverSvgTextBaseline(subtitleY, layout.subtitleFontSize)}">${escapeXml(subtitle)}</text>`
        : ''
    }
  `

  return {
    svg,
    height: measureCoverTableTitleBlockHeight(title, subtitle, layout),
  }
}

function buildRoundedTopRectPath(
  x: number,
  y: number,
  width: number,
  height: number,
  radius: number,
): string {
  const r = Math.min(radius, width / 2, height)
  return [
    `M ${x + r} ${y}`,
    `H ${x + width - r}`,
    `Q ${x + width} ${y} ${x + width} ${y + r}`,
    `V ${y + height}`,
    `H ${x}`,
    `V ${y + r}`,
    `Q ${x} ${y} ${x + r} ${y}`,
    'Z',
  ].join(' ')
}

export function buildCoverTableGridSvgFragment(options: {
  x: number
  y: number
  width: number
  headers: string[]
  rows: string[][]
  showHeader: boolean
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>
  layout: ScaledCoverTableLayout
  clipId: string
}): { svg: string; clipPathDef: string; height: number } {
  const { x, y, width, headers, rows, showHeader, brand, layout, clipId } = options
  const columnCount = headers.length
  if (columnCount === 0) return { svg: '', clipPathDef: '', height: 0 }

  const colWidth = width / columnCount
  const visibleRowCount = (showHeader ? 1 : 0) + rows.length
  const height = visibleRowCount * layout.rowHeight
  const maxChars = Math.max(8, Math.floor(colWidth / 8))
  const radius = Math.max(0.5, layout.innerRadius)
  const maskId = clipId

  const clippedParts: string[] = [
    `<rect x="${x}" y="${y}" width="${width}" height="${height}" fill="${brand.background}" opacity="0.4" />`,
  ]

  let rowIndex = 0

  if (showHeader) {
    const rowY = y + rowIndex * layout.rowHeight
    clippedParts.push(
      `<path d="${buildRoundedTopRectPath(x, rowY, width, layout.rowHeight, radius)}" fill="${brand.border}" opacity="0.45" />`,
    )

    headers.forEach((header, col) => {
      const cellCenterX = x + col * colWidth + colWidth / 2
      clippedParts.push(
        `<text class="cover-body" text-anchor="middle" fill="${brand.foreground}" font-size="${layout.headerFontSize}" font-weight="600" letter-spacing="0.04em" x="${cellCenterX}" y="${coverSvgTextBaseline(coverTableRowTextY(rowY, layout, layout.headerFontSize), layout.headerFontSize)}">${escapeXml(truncateCellText(header, maxChars).toUpperCase())}</text>`,
      )
    })

    rowIndex += 1
  }

  rows.forEach((row) => {
    const rowY = y + rowIndex * layout.rowHeight

    if (rowIndex > (showHeader ? 1 : 0)) {
      clippedParts.push(
        `<line x1="${x}" y1="${rowY}" x2="${x + width}" y2="${rowY}" stroke="${brand.border}" stroke-width="1" />`,
      )
    }

    row.forEach((cell, col) => {
      const cellCenterX = x + col * colWidth + colWidth / 2
      const isFirstCol = col === 0
      clippedParts.push(
        `<text class="cover-body" text-anchor="middle" fill="${isFirstCol ? brand.foreground : brand.mutedForeground}" font-size="${layout.cellFontSize}" font-weight="${isFirstCol ? 600 : 400}" x="${cellCenterX}" y="${coverSvgTextBaseline(coverTableRowTextY(rowY, layout, layout.cellFontSize), layout.cellFontSize)}">${escapeXml(truncateCellText(cell, maxChars))}</text>`,
      )

      if (col > 0) {
        const lineX = x + col * colWidth
        clippedParts.push(
          `<line x1="${lineX}" y1="${rowY}" x2="${lineX}" y2="${rowY + layout.rowHeight}" stroke="${brand.border}" stroke-width="1" opacity="0.65" />`,
        )
      }
    })

    rowIndex += 1
  })

  const maskDef = `
    <mask id="${maskId}" maskUnits="userSpaceOnUse">
      <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="${radius}" ry="${radius}" fill="white" />
    </mask>
  `

  return {
    svg: `
      <g mask="url(#${maskId})">
        ${clippedParts.join('\n')}
      </g>
      <rect
        x="${x}"
        y="${y}"
        width="${width}"
        height="${height}"
        rx="${radius}"
        ry="${radius}"
        fill="none"
        stroke="${brand.border}"
        stroke-width="1"
      />
    `,
    clipPathDef: maskDef,
    height,
  }
}

export type CoverTableFrameCompositionOptions = {
  themeId: CoverThemeId
  frameWidth: number
  title?: string
  subtitle?: string
  headers: string[]
  rows: string[][]
  showHeader?: boolean
  clipIdPrefix: string
  titleCenterX?: number
}

export function buildCoverTableFrameComposition(
  options: CoverTableFrameCompositionOptions,
): { svg: string; defs: string; width: number; height: number; strokePad: number } {
  const {
    themeId,
    frameWidth,
    title,
    subtitle,
    headers,
    rows,
    showHeader = true,
    clipIdPrefix,
    titleCenterX = frameWidth / 2,
  } = options

  const brand = getCoverBrandThemeForSvgExport(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)
  const layout = getScaledCoverTableLayout(frameWidth)
  const contentX = layout.cardPaddingX
  const contentWidth = frameWidth - layout.cardPaddingX * 2
  const gridClipId = `${clipIdPrefix}-grid`
  const gridRowCount = (showHeader ? 1 : 0) + rows.length
  const gridHeight = gridRowCount * layout.rowHeight

  const cardHeight = layout.cardPaddingY * 2 + gridHeight
  const titleHeight = measureCoverTableTitleBlockHeight(title, subtitle, layout)
  const titleCardGap = titleHeight > 0 ? layout.titleCardGap : 0
  const totalHeight = titleHeight + titleCardGap + cardHeight
  const cardY = titleHeight + titleCardGap
  const gridAbsoluteY = cardY + layout.cardPaddingY

  const titleBlock = buildCoverTableTitleBlockSvg(
    title,
    subtitle,
    titleCenterX,
    0,
    brand,
    layout,
  )

  const outerRadius = Math.max(0.5, layout.outerRadius)
  const tableGridAbsolute = buildCoverTableGridSvgFragment({
    x: contentX,
    y: gridAbsoluteY,
    width: contentWidth,
    headers,
    rows,
    showHeader,
    brand,
    layout,
    clipId: gridClipId,
  })

  const svg = `
    ${titleBlock.svg}
    <rect
      x="0"
      y="${cardY}"
      width="${frameWidth}"
      height="${cardHeight}"
      rx="${outerRadius}"
      ry="${outerRadius}"
      fill="${glass.shellFill}"
      stroke="${glass.shellBorder}"
      stroke-width="${layout.borderWidth}"
    />
    ${tableGridAbsolute.svg}
  `

  const defs = tableGridAbsolute.clipPathDef

  return { svg, defs, width: frameWidth, height: totalHeight, strokePad: layout.borderWidth }
}

export function buildCoverTableFrameSvgString(
  options: CoverTableFrameCompositionOptions & {
    containerHeight?: number
  },
): string {
  const composition = buildCoverTableFrameComposition(options)
  const fontStyles = buildCoverExportFontStyleBlock('')
  const pad = Math.ceil(composition.strokePad + 1)
  const viewWidth = composition.width + pad * 2
  const viewHeight = composition.height + pad * 2

  return `
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="${-pad} ${-pad} ${viewWidth} ${viewHeight}" width="100%" height="100%" overflow="visible" preserveAspectRatio="xMidYMid meet">
      ${fontStyles}
      <defs>${composition.defs}</defs>
      ${composition.svg}
    </svg>
  `.trim()
}
