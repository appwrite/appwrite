import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { getCoverScreenshotGlassColors } from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import {
  COVER_LOGO_MARQUEE_DEFAULTS,
  getCoverLogoMarqueeIconKey,
  COVER_LOGO_MARQUEE_ICON_COUNT,
} from '@/lib/cover-generator/extra-templates/constants'
import {
  buildExtraGlassCardSvg,
  getExtraCoverBrand,
  prepareExtraCoverImage,
  renderExtraTitleLines,
} from '@/lib/cover-generator/extra-templates/render-shared'
import { escapeXml, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import type { CoverLogoMarqueeData } from '@/lib/cover-generator/types'

const MARQUEE_TITLE_FONT_SIZE = 72
const MARQUEE_SUBTITLE_FONT_SIZE = 26
const MARQUEE_CHIP_SIZE = 168
const MARQUEE_CHIP_RADIUS = 42
const MARQUEE_CHIP_GAP = 32
const MARQUEE_ICON_SIZE = 120
const MARQUEE_ROW_GAP = 32

export async function renderLogoMarqueeTemplateSvg(
  data: CoverLogoMarqueeData,
  themeId: CoverThemeId,
): Promise<string> {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const icons = Array.from({ length: COVER_LOGO_MARQUEE_ICON_COUNT }, (_, index) => {
    const value = data[getCoverLogoMarqueeIconKey(index)]?.trim()
    return value || COVER_LOGO_MARQUEE_DEFAULTS.icons[index] || ''
  })
  const iconHrefs = await Promise.all(
    icons.map((icon) =>
      prepareExtraCoverImage(icon, MARQUEE_ICON_SIZE, themeId, { insetRatio: 0.1 }),
    ),
  )

  const titleLines = data.title
    ? wrapTextLines(stripCoverTitleSuffix(data.title), 26, 2)
    : []
  const subtitle = data.subtitle?.trim()

  const titleHeight = titleLines.length * (MARQUEE_TITLE_FONT_SIZE + 10)
  const subtitleGap = subtitle ? 16 : 0
  const subtitleHeight = subtitle ? MARQUEE_SUBTITLE_FONT_SIZE : 0
  const rowsGap = 44
  const rowsHeight = MARQUEE_CHIP_SIZE * 2 + MARQUEE_ROW_GAP

  const totalHeight =
    titleHeight + subtitleGap + subtitleHeight + rowsGap + rowsHeight
  const startY = Math.round((COVER_HEIGHT - totalHeight) / 2)

  const titleSvg = renderExtraTitleLines({
    lines: titleLines,
    x: COVER_WIDTH / 2,
    startLayoutY: startY,
    fontSize: MARQUEE_TITLE_FONT_SIZE,
    brand,
    anchor: 'middle',
    lineGap: 10,
  })
  const subtitleLayoutY = startY + titleHeight + subtitleGap
  const rowsStartY = subtitleLayoutY + subtitleHeight + rowsGap

  const chipsPerRow = COVER_LOGO_MARQUEE_ICON_COUNT / 2
  const rowWidth =
    chipsPerRow * MARQUEE_CHIP_SIZE + (chipsPerRow - 1) * MARQUEE_CHIP_GAP
  const staggerOffset = (MARQUEE_CHIP_SIZE + MARQUEE_CHIP_GAP) / 2

  const rows = [0, 1].map((row) => {
    const rowX = Math.round(
      COVER_WIDTH / 2 - rowWidth / 2 + (row === 1 ? staggerOffset : 0),
    )
    const rowY = rowsStartY + row * (MARQUEE_CHIP_SIZE + MARQUEE_ROW_GAP)

    return Array.from({ length: chipsPerRow }, (_, column) => {
      const index = row * chipsPerRow + column
      const href = iconHrefs[index]
      const chipX = rowX + column * (MARQUEE_CHIP_SIZE + MARQUEE_CHIP_GAP)
      const iconPadding = (MARQUEE_CHIP_SIZE - MARQUEE_ICON_SIZE) / 2

      return `
        ${buildExtraGlassCardSvg({
          x: chipX,
          y: rowY,
          width: MARQUEE_CHIP_SIZE,
          height: MARQUEE_CHIP_SIZE,
          radius: MARQUEE_CHIP_RADIUS,
          glass,
        })}
        ${
          href
            ? `<image href="${href}" x="${chipX + iconPadding}" y="${rowY + iconPadding}" width="${MARQUEE_ICON_SIZE}" height="${MARQUEE_ICON_SIZE}" />`
            : ''
        }
      `
    }).join('\n')
  })

  return `
    ${titleSvg}
    ${
      subtitle
        ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${MARQUEE_SUBTITLE_FONT_SIZE}" x="${COVER_WIDTH / 2}" y="${coverSvgTextBaseline(subtitleLayoutY, MARQUEE_SUBTITLE_FONT_SIZE)}" text-anchor="middle">${escapeXml(subtitle)}</text>`
        : ''
    }
    ${rows.join('\n')}
  `
}
