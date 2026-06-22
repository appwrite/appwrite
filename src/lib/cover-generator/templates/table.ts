import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import {
  getCoverTableMatrix,
  normalizeCoverTableData,
} from '@/lib/cover-generator/table/constants'
import { escapeXml, stripCoverTitleSuffix } from '@/lib/cover-generator/text-utils'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

const TABLE_CLIP_ID = 'cover-table-grid-clip'

const TABLE_LAYOUT = {
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

function truncateCellText(value: string, maxLength: number): string {
  const trimmed = value.trim()
  if (trimmed.length <= maxLength) return trimmed
  return `${trimmed.slice(0, Math.max(0, maxLength - 1))}…`
}

function coverTableRowTextY(rowY: number, fontSize: number): number {
  return Math.round(rowY + (TABLE_LAYOUT.rowHeight - fontSize) / 2)
}

function measureTableTitleBlockHeight(
  title: string | undefined,
  subtitle: string | undefined,
): number {
  if (!title && !subtitle) return 0

  const titleText = title ? stripCoverTitleSuffix(title) : ''
  return (
    (titleText ? TABLE_LAYOUT.titleLineHeight : 0) +
    (titleText && subtitle ? TABLE_LAYOUT.subtitleGap : 0) +
    (subtitle ? TABLE_LAYOUT.subtitleFontSize + 8 : 0)
  )
}

function buildTableTitleBlock(
  title: string | undefined,
  subtitle: string | undefined,
  centerX: number,
  yOffset: number,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
): { svg: string; height: number } {
  if (!title && !subtitle) {
    return { svg: '', height: 0 }
  }

  const titleText = title ? stripCoverTitleSuffix(title) : ''
  const titleY = yOffset
  const subtitleY = titleText
    ? titleY + TABLE_LAYOUT.titleLineHeight + TABLE_LAYOUT.subtitleGap
    : titleY

  const svg = `
    ${
      titleText
        ? `<text class="cover-title" text-anchor="middle" fill="${brand.foreground}" font-size="${TABLE_LAYOUT.titleFontSize}" font-weight="600" x="${centerX}" y="${coverSvgTextBaseline(titleY, TABLE_LAYOUT.titleFontSize)}">${escapeXml(titleText)}</text>`
        : ''
    }
    ${
      subtitle
        ? `<text class="cover-body" text-anchor="middle" fill="${brand.mutedForeground}" font-size="${TABLE_LAYOUT.subtitleFontSize}" x="${centerX}" y="${coverSvgTextBaseline(subtitleY, TABLE_LAYOUT.subtitleFontSize)}">${escapeXml(subtitle)}</text>`
        : ''
    }
  `

  const height = measureTableTitleBlockHeight(title, subtitle)

  return { svg, height }
}

function buildTableGridSvg(options: {
  x: number
  y: number
  width: number
  headers: string[]
  rows: string[][]
  showHeader: boolean
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>
}): { svg: string; height: number } {
  const { x, y, width, headers, rows, showHeader, brand } = options
  const columnCount = headers.length
  if (columnCount === 0) return { svg: '', height: 0 }

  const colWidth = width / columnCount
  const visibleRowCount = (showHeader ? 1 : 0) + rows.length
  const height = visibleRowCount * TABLE_LAYOUT.rowHeight
  const maxChars = Math.max(8, Math.floor(colWidth / 8))
  const radius = TABLE_LAYOUT.innerRadius

  const clippedParts: string[] = [
    `<rect x="${x}" y="${y}" width="${width}" height="${height}" fill="${brand.background}" opacity="0.4" />`,
  ]

  let rowIndex = 0

  if (showHeader) {
    const rowY = y + rowIndex * TABLE_LAYOUT.rowHeight
    clippedParts.push(
      `<rect x="${x}" y="${rowY}" width="${width}" height="${TABLE_LAYOUT.rowHeight}" fill="${brand.border}" opacity="0.45" />`,
    )

    headers.forEach((header, col) => {
      const cellCenterX = x + col * colWidth + colWidth / 2
      clippedParts.push(
        `<text class="cover-body" text-anchor="middle" fill="${brand.foreground}" font-size="${TABLE_LAYOUT.headerFontSize}" font-weight="600" letter-spacing="0.04em" x="${cellCenterX}" y="${coverSvgTextBaseline(coverTableRowTextY(rowY, TABLE_LAYOUT.headerFontSize), TABLE_LAYOUT.headerFontSize)}">${escapeXml(truncateCellText(header, maxChars).toUpperCase())}</text>`,
      )
    })

    rowIndex += 1
  }

  rows.forEach((row) => {
    const rowY = y + rowIndex * TABLE_LAYOUT.rowHeight

    if (rowIndex > (showHeader ? 1 : 0)) {
      clippedParts.push(
        `<line x1="${x}" y1="${rowY}" x2="${x + width}" y2="${rowY}" stroke="${brand.border}" stroke-width="1" />`,
      )
    }

    row.forEach((cell, col) => {
      const cellCenterX = x + col * colWidth + colWidth / 2
      const isFirstCol = col === 0
      clippedParts.push(
        `<text class="cover-body" text-anchor="middle" fill="${isFirstCol ? brand.foreground : brand.mutedForeground}" font-size="${TABLE_LAYOUT.cellFontSize}" font-weight="${isFirstCol ? 600 : 400}" x="${cellCenterX}" y="${coverSvgTextBaseline(coverTableRowTextY(rowY, TABLE_LAYOUT.cellFontSize), TABLE_LAYOUT.cellFontSize)}">${escapeXml(truncateCellText(cell, maxChars))}</text>`,
      )

      if (col > 0) {
        const lineX = x + col * colWidth
        clippedParts.push(
          `<line x1="${lineX}" y1="${rowY}" x2="${lineX}" y2="${rowY + TABLE_LAYOUT.rowHeight}" stroke="${brand.border}" stroke-width="1" opacity="0.65" />`,
        )
      }
    })

    rowIndex += 1
  })

  return {
    svg: `
      <defs>
        <clipPath id="${TABLE_CLIP_ID}">
          <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="${radius}" ry="${radius}" />
        </clipPath>
      </defs>
      <g clip-path="url(#${TABLE_CLIP_ID})">
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
    height,
  }
}

export function renderTableTemplateSvg(
  data: Extract<CoverRenderData, { template: 'table' }>,
  themeId: CoverThemeId,
): string {
  const normalized = normalizeCoverTableData(data)
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)
  const matrix = getCoverTableMatrix(normalized)

  const frameWidth = getCoverFrameWidthPx(normalized.frameWidthPercent, {
    width: normalized.width,
    height: normalized.height,
  })
  const frameX = Math.round((COVER_WIDTH - frameWidth) / 2)
  const contentX = TABLE_LAYOUT.cardPaddingX
  const contentWidth = frameWidth - TABLE_LAYOUT.cardPaddingX * 2
  const centerX = COVER_WIDTH / 2

  const tableGrid = buildTableGridSvg({
    x: contentX,
    y: TABLE_LAYOUT.cardPaddingY,
    width: contentWidth,
    headers: matrix.headers,
    rows: matrix.rows,
    showHeader: normalized.showHeader,
    brand,
  })

  const cardHeight = TABLE_LAYOUT.cardPaddingY * 2 + tableGrid.height

  const titleHeight = measureTableTitleBlockHeight(
    normalized.title,
    normalized.subtitle,
  )
  const titleCardGap = titleHeight > 0 ? TABLE_LAYOUT.titleCardGap : 0
  const compositionHeight = titleHeight + titleCardGap + cardHeight
  const compositionY = Math.round((COVER_HEIGHT - compositionHeight) / 2)
  const cardY = compositionY + titleHeight + titleCardGap

  const titleBlock = buildTableTitleBlock(
    normalized.title,
    normalized.subtitle,
    centerX,
    compositionY,
    brand,
  )

  return `
    ${titleBlock.svg}
    <g transform="translate(${frameX} ${cardY})">
      <rect
        width="${frameWidth}"
        height="${cardHeight}"
        rx="${TABLE_LAYOUT.outerRadius}"
        ry="${TABLE_LAYOUT.outerRadius}"
        fill="${glass.shellFill}"
        stroke="${glass.shellBorder}"
        stroke-width="${TABLE_LAYOUT.borderWidth}"
      />
      ${tableGrid.svg}
    </g>
  `
}
