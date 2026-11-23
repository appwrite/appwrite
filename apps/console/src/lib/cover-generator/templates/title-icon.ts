import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { prepareCoverIconDataUri } from '@/lib/cover-generator/brand-background'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import {
  clampNumber,
  escapeXml,
  stripCoverTitleSuffix,
  wrapTextLines,
} from '@/lib/cover-generator/text-utils'
import type { CoverTitleIconData } from '@/lib/cover-generator/types'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import { getCoverTheme } from '@/lib/cover-generator/themes'

const ROW_CENTER_Y = COVER_HEIGHT / 2
const TITLE_CHAR_WIDTH_RATIO = 0.58
const ICON_PLACEHOLDER_RADIUS = 12
/** Extra space between the icon slot and the title so the lockup does not feel cramped. */
const ICON_TITLE_GAP_RATIO = 0.45
/** Nudge the icon up slightly so it optically centers with the title cap height. */
const ICON_OPTICAL_Y_OFFSET_RATIO = -0.04

function getTitleIconLockupFontSize(title: string): number {
  const text = stripCoverTitleSuffix(title)
  const length = Math.max(text.length, 1)

  if (length <= 5) return 112
  if (length <= 10) return 96
  if (length <= 16) return 80
  if (length <= 22) return 68
  return 56
}

function getTitleLineStep(fontSize: number): number {
  return fontSize + 6
}

function estimateTitleWidth(lines: string[], fontSize: number): number {
  const longestLine = lines.reduce((max, line) => Math.max(max, line.length), 0)
  return Math.max(longestLine, 1) * fontSize * TITLE_CHAR_WIDTH_RATIO
}

function getTitleStartLayoutY(
  lineCount: number,
  fontSize: number,
  lineStep: number,
): number {
  if (lineCount <= 0) return ROW_CENTER_Y

  const blockHeight = fontSize + (lineCount - 1) * lineStep
  return ROW_CENTER_Y - blockHeight / 2
}

export async function renderTitleIconTemplateSvg(
  data: CoverTitleIconData,
  themeId: CoverThemeId,
): Promise<string> {
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const themeFamily = getCoverTheme(themeId).family
  const titleText = data.title ? stripCoverTitleSuffix(data.title) : ''
  const titleFontSize = titleText ? getTitleIconLockupFontSize(titleText) : 96
  const titleLineStep = getTitleLineStep(titleFontSize)
  const titleLines = titleText ? wrapTextLines(titleText, 18, 2) : []

  const iconSize = clampNumber(data.iconSize, 40, 128)
  const iconTitleGap = Math.round(titleFontSize * ICON_TITLE_GAP_RATIO)
  const iconHref = await prepareCoverIconDataUri(data.icon, iconSize, {
    themeFamily,
    themeId,
    contentAlign: 'left',
  })

  const titleWidth = estimateTitleWidth(titleLines, titleFontSize)
  const rowWidth =
    iconSize + (titleLines.length > 0 ? iconTitleGap + titleWidth : 0)
  const rowStartX = (COVER_WIDTH - rowWidth) / 2
  const iconX = rowStartX
  const iconY =
    ROW_CENTER_Y -
    iconSize / 2 +
    Math.round(iconSize * ICON_OPTICAL_Y_OFFSET_RATIO)
  const titleX = iconX + iconSize + iconTitleGap
  const titleStartY = getTitleStartLayoutY(
    titleLines.length,
    titleFontSize,
    titleLineStep,
  )

  const iconMarkup = iconHref
    ? `<image href="${iconHref}" x="${iconX}" y="${iconY}" width="${iconSize}" height="${iconSize}" />`
    : `<rect x="${iconX}" y="${iconY}" width="${iconSize}" height="${iconSize}" rx="${ICON_PLACEHOLDER_RADIUS}" fill="${brand.border}" opacity="0.35" />`

  const titleContent = titleLines
    .map((line, index) => {
      const layoutY = titleStartY + index * titleLineStep
      const isLast = index === titleLines.length - 1
      return `<text class="cover-title" fill="${brand.foreground}" font-size="${titleFontSize}" x="${titleX}" y="${coverSvgTextBaseline(layoutY, titleFontSize)}">${escapeXml(line)}${
        isLast ? `<tspan fill="${brand.brandCta}">_</tspan>` : ''
      }</text>`
    })
    .join('')

  return `
    ${iconMarkup}
    ${titleContent}
  `
}
