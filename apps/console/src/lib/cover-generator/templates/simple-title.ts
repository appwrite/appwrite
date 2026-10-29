import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import {
  COVER_CONTENT_X,
  getCoverSimpleTitleMinTextTop,
  isCoverBrandWordmarkTitle,
  prepareCoverLogotypeDataUri,
  renderCoverLogotypeSvg,
  COVER_SIMPLE_TITLE_LOGOTYPE_HEIGHT,
} from '@/lib/cover-generator/cover-logotype'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { getCoverContentLayoutTransform } from '@/lib/cover-generator/cover-layout-scale'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { COVER_EYEBROW_LETTER_SPACING, escapeXml, formatCoverEyebrow, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverTheme } from '@/lib/cover-generator/constants'
const COVER_EYEBROW_FONT_SIZE = 22
const COVER_EYEBROW_TITLE_GAP = 20
const COVER_TITLE_MAX_CHARS_PER_LINE = 18
const COVER_TITLE_MAX_LINES = 3
const COVER_TITLE_SUBTITLE_GAP = 16
const COVER_SUBTITLE_FONT_SIZE = 32
const COVER_SUBTITLE_LINE_STEP = COVER_SUBTITLE_FONT_SIZE + 10
/** Inter regular advance at subtitle size; keep a matching right inset. */
const COVER_SUBTITLE_CHAR_ADVANCE = 0.44
const COVER_SUBTITLE_MAX_CHARS_PER_LINE = Math.max(
  40,
  Math.floor(
    (COVER_WIDTH - COVER_CONTENT_X * 2) /
      (COVER_SUBTITLE_FONT_SIZE * COVER_SUBTITLE_CHAR_ADVANCE),
  ),
)
const COVER_SUBTITLE_MAX_LINES = 2
const COVER_CTA_FONT_SIZE = 22
const COVER_CTA_PILL_HEIGHT = 44
const COVER_CTA_PILL_PADDING_X = 20
const COVER_CTA_CHAR_ADVANCE = 0.5
const COVER_CTA_GAP = 20

function renderTitleTspans(
  lines: string[],
  lineStep: number,
  brandCta: string,
): string {
  return lines
    .map((line, index) => {
      const isLast = index === lines.length - 1
      const underscore = isLast ? `<tspan fill="${brandCta}">_</tspan>` : ''
      const content = `${escapeXml(line)}${underscore}`

      if (index === 0) {
        return `<tspan>${content}</tspan>`
      }

      return `<tspan x="${COVER_CONTENT_X}" dy="${lineStep}">${content}</tspan>`
    })
    .join('')
}

function renderBodyTspans(
  lines: string[],
  x: number,
  lineStep: number,
): string {
  return lines
    .map((line, index) => {
      const content = escapeXml(line)
      if (index === 0) {
        return `<tspan>${content}</tspan>`
      }
      return `<tspan x="${x}" dy="${lineStep}">${content}</tspan>`
    })
    .join('')
}

function getSimpleTitleFontSize(lines: string[]): number {
  if (lines.length >= 3) return 92
  if (lines.length === 2) return 108
  const longest = Math.max(0, ...lines.map((line) => line.length))
  return longest > 16 ? 120 : 144
}

function textBlockHeight(lineCount: number, fontSize: number, lineStep: number): number {
  if (lineCount <= 0) return 0
  return (lineCount - 1) * lineStep + fontSize
}

/**
 * Artboard bottom inset so the *output* bottom gap matches the output left gap.
 * OG 1200×630 letterboxes the 16:9 artboard horizontally (extra left/right),
 * which is why a matching artboard padding looked huge on the bottom.
 */
function getSimpleTitleBottomPadding(canvasWidth: number, canvasHeight: number): number {
  const { scale, translateX } = getCoverContentLayoutTransform(canvasWidth, canvasHeight)
  return Math.round(translateX / scale + COVER_CONTENT_X)
}

function estimateCtaPillWidth(label: string): number {
  const textWidth = Math.ceil(label.length * COVER_CTA_FONT_SIZE * COVER_CTA_CHAR_ADVANCE)
  return Math.max(
    COVER_CTA_PILL_HEIGHT,
    textWidth + COVER_CTA_PILL_PADDING_X * 2,
  )
}

function renderCtaPill(label: string, brandCta: string, pillTop: number): string {
  const pillWidth = estimateCtaPillWidth(label)
  const textBaseline = coverSvgTextBaseline(
    pillTop + (COVER_CTA_PILL_HEIGHT - COVER_CTA_FONT_SIZE) / 2,
    COVER_CTA_FONT_SIZE,
  )

  return `
    <rect x="${COVER_CONTENT_X}" y="${pillTop}" width="${pillWidth}" height="${COVER_CTA_PILL_HEIGHT}" rx="${COVER_CTA_PILL_HEIGHT / 2}" fill="${brandCta}" />
    <text class="cover-cta" fill="#ffffff" font-size="${COVER_CTA_FONT_SIZE}" font-weight="600" x="${COVER_CONTENT_X + pillWidth / 2}" y="${textBaseline}" text-anchor="middle">${escapeXml(label)}</text>
  `
}

export async function renderSimpleTitleTemplateSvg(
  data: Extract<CoverRenderData, { template: 'simple-title' }>,
  theme: CoverTheme,
): Promise<string> {
  const brand = getCoverBrandThemeForSvgExport(theme)
  const bottomPadding = getSimpleTitleBottomPadding(data.width, data.height)
  const logotype = await prepareCoverLogotypeDataUri(
    theme,
    COVER_SIMPLE_TITLE_LOGOTYPE_HEIGHT,
  )
  const logotypeSvg = logotype ? renderCoverLogotypeSvg(logotype) : ''
  const skipBrandTitle = Boolean(logotype) && isCoverBrandWordmarkTitle(data.title)
  const titleLines = skipBrandTitle
    ? []
    : wrapTextLines(
        stripCoverTitleSuffix(data.title),
        COVER_TITLE_MAX_CHARS_PER_LINE,
        COVER_TITLE_MAX_LINES,
      )
  const titleFontSize = getSimpleTitleFontSize(titleLines)
  const lineStep = titleFontSize + 6
  const eyebrowText = formatCoverEyebrow(data.eyebrow)
  const ctaLabel = data.cta?.trim()
  const subtitleLines = data.subtitle
    ? wrapTextLines(
        data.subtitle.trim(),
        COVER_SUBTITLE_MAX_CHARS_PER_LINE,
        COVER_SUBTITLE_MAX_LINES,
      )
    : []

  let cursorBottom = COVER_HEIGHT - bottomPadding
  let ctaSvg = ''

  if (ctaLabel) {
    const pillTop = cursorBottom - COVER_CTA_PILL_HEIGHT
    ctaSvg = renderCtaPill(ctaLabel, brand.brandCta, pillTop)
    cursorBottom = pillTop - COVER_CTA_GAP
  }

  const subtitleLayoutY = subtitleLines.length
    ? cursorBottom -
      textBlockHeight(
        subtitleLines.length,
        COVER_SUBTITLE_FONT_SIZE,
        COVER_SUBTITLE_LINE_STEP,
      )
    : 0
  if (subtitleLines.length) {
    cursorBottom = subtitleLayoutY - COVER_TITLE_SUBTITLE_GAP
  }

  const minTextTop = getCoverSimpleTitleMinTextTop()
  let startY =
    cursorBottom - textBlockHeight(titleLines.length, titleFontSize, lineStep)
  if (titleLines.length > 0 && startY < minTextTop) {
    startY = minTextTop
  }
  const eyebrowLayoutY = eyebrowText
    ? Math.max(
        minTextTop - COVER_EYEBROW_FONT_SIZE,
        startY - COVER_EYEBROW_TITLE_GAP - COVER_EYEBROW_FONT_SIZE,
      )
    : 0

  const firstBaseline = coverSvgTextBaseline(startY, titleFontSize)
  const titleSvg = titleLines.length
    ? `<text class="cover-title" fill="${brand.foreground}" font-size="${titleFontSize}" letter-spacing="0" x="${COVER_CONTENT_X}" y="${firstBaseline}">${renderTitleTspans(titleLines, lineStep, brand.brandCta)}</text>`
    : ''

  return `
    ${logotypeSvg}
    ${
      eyebrowText
        ? `<text class="cover-eyebrow" fill="${brand.mutedForeground}" font-size="${COVER_EYEBROW_FONT_SIZE}" font-weight="600" letter-spacing="${COVER_EYEBROW_LETTER_SPACING}" x="${COVER_CONTENT_X}" y="${coverSvgTextBaseline(eyebrowLayoutY, COVER_EYEBROW_FONT_SIZE)}">${escapeXml(eyebrowText)}<tspan fill="${brand.brandCta}">_</tspan></text>`
        : ''
    }
    ${titleSvg}
    ${
      subtitleLines.length
        ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${COVER_SUBTITLE_FONT_SIZE}" x="${COVER_CONTENT_X}" y="${coverSvgTextBaseline(subtitleLayoutY, COVER_SUBTITLE_FONT_SIZE)}">${renderBodyTspans(subtitleLines, COVER_CONTENT_X, COVER_SUBTITLE_LINE_STEP)}</text>`
        : ''
    }
    ${ctaSvg}
  `
}
