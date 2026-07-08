import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { COVER_HEIGHT } from '@/lib/cover-generator/constants'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { escapeXml, formatCoverEyebrow, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverSimpleTitleData } from '@/lib/cover-generator/types'
import type { CoverTheme } from '@/lib/cover-generator/constants'

const COVER_CONTENT_X = 96
/** Scaled up from marketing `text-[11px]` for OG export. */
const COVER_EYEBROW_FONT_SIZE = 18
/** Extra space between eyebrow baseline block and title (scaled for OG export). */
const COVER_EYEBROW_TITLE_GAP = 32
const COVER_SUBTITLE_FONT_SIZE = 26
const COVER_SUBTITLE_LINE_STEP = COVER_SUBTITLE_FONT_SIZE + 8
const COVER_SUBTITLE_MAX_CHARS_PER_LINE = 42
const COVER_SUBTITLE_MAX_LINES = 3
const COVER_BOTTOM_PADDING = 80
const COVER_CTA_FONT_SIZE = 22
const COVER_CTA_PILL_HEIGHT = 48
const COVER_CTA_PILL_PADDING_X = 28
const COVER_CTA_RESERVED_HEIGHT = 72

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

function getSimpleTitleMaxSubtitleLines(
  subtitleLayoutY: number,
  hasCta: boolean,
): number {
  const bottomPadding = hasCta
    ? COVER_BOTTOM_PADDING + COVER_CTA_RESERVED_HEIGHT
    : COVER_BOTTOM_PADDING
  const availableHeight = COVER_HEIGHT - bottomPadding - subtitleLayoutY
  return Math.max(
    1,
    Math.min(
      COVER_SUBTITLE_MAX_LINES,
      Math.floor(availableHeight / COVER_SUBTITLE_LINE_STEP),
    ),
  )
}

function estimateCtaPillWidth(label: string): number {
  return Math.max(
    200,
    Math.round(label.length * COVER_CTA_FONT_SIZE * 0.56 + COVER_CTA_PILL_PADDING_X * 2),
  )
}

function renderCtaPill(label: string, brandCta: string): string {
  const pillWidth = estimateCtaPillWidth(label)
  const pillTop = COVER_HEIGHT - COVER_BOTTOM_PADDING - COVER_CTA_PILL_HEIGHT
  const textX = COVER_CONTENT_X + COVER_CTA_PILL_PADDING_X
  const textBaseline = coverSvgTextBaseline(
    pillTop + COVER_CTA_PILL_HEIGHT / 2,
    COVER_CTA_FONT_SIZE,
  )

  return `
    <rect x="${COVER_CONTENT_X}" y="${pillTop}" width="${pillWidth}" height="${COVER_CTA_PILL_HEIGHT}" rx="${COVER_CTA_PILL_HEIGHT / 2}" fill="${brandCta}" />
    <text class="cover-cta" fill="#ffffff" font-size="${COVER_CTA_FONT_SIZE}" font-weight="600" x="${textX}" y="${textBaseline}">${escapeXml(label)}</text>
  `
}

export function renderSimpleTitleTemplateSvg(
  data: CoverSimpleTitleData,
  theme: CoverTheme,
): string {
  const brand = getCoverBrandThemeForSvgExport(theme)
  const titleLines = wrapTextLines(stripCoverTitleSuffix(data.title), 22, 3)
  const titleFontSize = titleLines.some((line) => line.length > 18) ? 72 : 84
  const lineStep = titleFontSize + 8

  const eyebrowText = formatCoverEyebrow(data.eyebrow)
  const eyebrowLayoutY = 196
  const startY = eyebrowText
    ? eyebrowLayoutY + COVER_EYEBROW_FONT_SIZE + COVER_EYEBROW_TITLE_GAP
    : 220

  const firstBaseline = coverSvgTextBaseline(startY, titleFontSize)
  const titleSvg = titleLines.length
    ? `<text class="cover-title" fill="${brand.foreground}" font-size="${titleFontSize}" x="${COVER_CONTENT_X}" y="${firstBaseline}">${renderTitleTspans(titleLines, lineStep, brand.brandCta)}</text>`
    : ''

  const subtitleLayoutY =
    startY + titleLines.length * lineStep + (data.subtitle ? 24 : 0)
  const ctaLabel = data.cta?.trim()
  const subtitleMaxLines = data.subtitle
    ? getSimpleTitleMaxSubtitleLines(subtitleLayoutY, Boolean(ctaLabel))
    : 0
  const subtitleLines = data.subtitle
    ? wrapTextLines(
        data.subtitle.trim(),
        COVER_SUBTITLE_MAX_CHARS_PER_LINE,
        subtitleMaxLines,
      )
    : []

  return `
    ${
      eyebrowText
        ? `<text class="cover-eyebrow" fill="${brand.mutedForeground}" font-size="${COVER_EYEBROW_FONT_SIZE}" font-weight="600" letter-spacing="0.25em" x="${COVER_CONTENT_X}" y="${coverSvgTextBaseline(eyebrowLayoutY, COVER_EYEBROW_FONT_SIZE)}">${escapeXml(eyebrowText)}<tspan fill="${brand.brandCta}">_</tspan></text>`
        : ''
    }
    ${titleSvg}
    ${
      subtitleLines.length
        ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${COVER_SUBTITLE_FONT_SIZE}" x="${COVER_CONTENT_X}" y="${coverSvgTextBaseline(subtitleLayoutY, COVER_SUBTITLE_FONT_SIZE)}">${renderBodyTspans(subtitleLines, COVER_CONTENT_X, COVER_SUBTITLE_LINE_STEP)}</text>`
        : ''
    }
    ${ctaLabel ? renderCtaPill(ctaLabel, brand.brandCta) : ''}
  `
}
