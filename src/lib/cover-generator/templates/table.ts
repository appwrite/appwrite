import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { getCoverScreenshotGlassColors } from '@/lib/cover-generator/cover-screenshot-frame'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import {
  getCoverTableMatrix,
  normalizeCoverTableData,
} from '@/lib/cover-generator/table/constants'
import {
  buildCoverTableGridSvgFragment,
  buildCoverTableTitleBlockSvg,
  COVER_TABLE_LAYOUT,
  measureCoverTableTitleBlockHeight,
} from '@/lib/cover-generator/table/render-frame'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

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
  const contentX = COVER_TABLE_LAYOUT.cardPaddingX
  const contentWidth = frameWidth - COVER_TABLE_LAYOUT.cardPaddingX * 2

  const tableGrid = buildCoverTableGridSvgFragment({
    x: contentX,
    y: COVER_TABLE_LAYOUT.cardPaddingY,
    width: contentWidth,
    headers: matrix.headers,
    rows: matrix.rows,
    showHeader: normalized.showHeader,
    brand,
    layout: COVER_TABLE_LAYOUT,
    clipId: 'cover-table-grid-clip',
  })

  const cardHeight = COVER_TABLE_LAYOUT.cardPaddingY * 2 + tableGrid.height

  const titleHeight = measureCoverTableTitleBlockHeight(
    normalized.title,
    normalized.subtitle,
    COVER_TABLE_LAYOUT,
  )
  const titleCardGap = titleHeight > 0 ? COVER_TABLE_LAYOUT.titleCardGap : 0
  const compositionHeight = titleHeight + titleCardGap + cardHeight
  const compositionY = Math.round((COVER_HEIGHT - compositionHeight) / 2)
  const cardY = compositionY + titleHeight + titleCardGap

  const titleBlock = buildCoverTableTitleBlockSvg(
    normalized.title,
    normalized.subtitle,
    COVER_WIDTH / 2,
    compositionY,
    brand,
    COVER_TABLE_LAYOUT,
  )

  return `
    ${titleBlock.svg}
    <g transform="translate(${frameX} ${cardY})">
      <rect
        width="${frameWidth}"
        height="${cardHeight}"
        rx="${COVER_TABLE_LAYOUT.outerRadius}"
        ry="${COVER_TABLE_LAYOUT.outerRadius}"
        fill="${glass.shellFill}"
        stroke="${glass.shellBorder}"
        stroke-width="${COVER_TABLE_LAYOUT.borderWidth}"
      />
      <defs>${tableGrid.clipPathDef}</defs>
      ${tableGrid.svg}
    </g>
  `
}
