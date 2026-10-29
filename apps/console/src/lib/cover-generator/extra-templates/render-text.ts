import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { getCoverScreenshotGlassColors } from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import {
  COVER_CHECKLIST_DEFAULTS,
  COVER_NUMBERED_STEPS_DEFAULTS,
  getCoverChecklistItemKey,
  getCoverStepDescriptionKey,
  getCoverStepTitleKey,
} from '@/lib/cover-generator/extra-templates/constants'
import {
  buildExtraGlassCardSvg,
  buildExtraPillSvg,
  EXTRA_COVER_BODY_FONT,
  EXTRA_COVER_CONTENT_WIDTH,
  EXTRA_COVER_CONTENT_X,
  EXTRA_COVER_TITLE_FONT,
  getExtraCoverBrand,
  renderExtraBodyLines,
  renderExtraEyebrow,
  renderExtraTitleLines,
} from '@/lib/cover-generator/extra-templates/render-shared'
import { clampNumber, escapeXml, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import type {
  CoverAnnouncementData,
  CoverBigTypeData,
  CoverChecklistData,
  CoverNumberedStepsData,
} from '@/lib/cover-generator/types'

const ANNOUNCEMENT_MARGIN_X = 56
const ANNOUNCEMENT_AVAILABLE_WIDTH = COVER_WIDTH - ANNOUNCEMENT_MARGIN_X * 2
const ANNOUNCEMENT_TITLE_FONT_SIZE = 88
const ANNOUNCEMENT_TITLE_STEP = ANNOUNCEMENT_TITLE_FONT_SIZE + 6
/** Measured Aeonik Pro advance at display sizes (matches big-type), plus wrap slack. */
const ANNOUNCEMENT_TITLE_CHAR_RATIO = 0.45
const ANNOUNCEMENT_SUBTITLE_FONT_SIZE = 34
const ANNOUNCEMENT_SUBTITLE_STEP = ANNOUNCEMENT_SUBTITLE_FONT_SIZE + 10
const ANNOUNCEMENT_SUBTITLE_CHAR_RATIO = 0.5
const ANNOUNCEMENT_BADGE_FONT_SIZE = 20
const ANNOUNCEMENT_BADGE_HEIGHT = 48

function announcementMaxChars(
  fontSize: number,
  charRatio: number,
  reservedWidth = 0,
): number {
  return Math.max(
    8,
    Math.floor((ANNOUNCEMENT_AVAILABLE_WIDTH - reservedWidth) / (fontSize * charRatio)),
  )
}

/** Word-wrap that fills each line up to the available character width. */
function wrapAnnouncementLines(
  text: string,
  maxCharsPerLine: number,
  maxLines: number,
): string[] {
  if (maxLines <= 0) return []

  const words = text
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .flatMap((word) => {
      if (word.length <= maxCharsPerLine) return [word]
      const parts: string[] = []
      for (let index = 0; index < word.length; index += maxCharsPerLine) {
        parts.push(word.slice(index, index + maxCharsPerLine))
      }
      return parts
    })
  if (!words.length) return []

  const lines: string[] = []
  let current = words[0] ?? ''

  for (const word of words.slice(1)) {
    const next = `${current} ${word}`
    if (next.length <= maxCharsPerLine) {
      current = next
      continue
    }
    if (lines.length >= maxLines - 1) {
      lines.push(current)
      const last = lines[maxLines - 1] ?? ''
      lines[maxLines - 1] =
        last.length > maxCharsPerLine - 3
          ? `${last.slice(0, Math.max(0, maxCharsPerLine - 3)).trimEnd()}...`
          : `${last.trimEnd()}...`
      return lines.slice(0, maxLines)
    }
    lines.push(current)
    current = word
  }

  if (current && lines.length < maxLines) {
    lines.push(current)
  }
  return lines.slice(0, maxLines)
}

export function renderAnnouncementTemplateSvg(
  data: CoverAnnouncementData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const centerX = COVER_WIDTH / 2

  const titleLines = wrapAnnouncementLines(
    stripCoverTitleSuffix(data.title),
    announcementMaxChars(
      ANNOUNCEMENT_TITLE_FONT_SIZE,
      ANNOUNCEMENT_TITLE_CHAR_RATIO,
      ANNOUNCEMENT_TITLE_FONT_SIZE * 0.5,
    ),
    2,
  )
  const subtitleLines = data.subtitle
    ? wrapAnnouncementLines(
        data.subtitle,
        announcementMaxChars(
          ANNOUNCEMENT_SUBTITLE_FONT_SIZE,
          ANNOUNCEMENT_SUBTITLE_CHAR_RATIO,
        ),
        2,
      )
    : []

  const badgeHeight = data.badge ? ANNOUNCEMENT_BADGE_HEIGHT : 0
  const badgeGap = data.badge ? 36 : 0
  const titleHeight = titleLines.length * ANNOUNCEMENT_TITLE_STEP
  const subtitleGap = subtitleLines.length ? 28 : 0
  const subtitleHeight = subtitleLines.length * ANNOUNCEMENT_SUBTITLE_STEP

  const totalHeight =
    badgeHeight + badgeGap + titleHeight + subtitleGap + subtitleHeight
  let cursorY = Math.round((COVER_HEIGHT - totalHeight) / 2)

  const parts: string[] = []

  if (data.badge) {
    parts.push(
      buildExtraPillSvg({
        x: centerX,
        y: cursorY,
        label: data.badge,
        fontSize: ANNOUNCEMENT_BADGE_FONT_SIZE,
        brand,
        anchor: 'middle',
        tone: 'brand-outline',
        uppercase: true,
        height: ANNOUNCEMENT_BADGE_HEIGHT,
        paddingX: 30,
      }),
    )
    cursorY += badgeHeight + badgeGap
  }

  parts.push(
    renderExtraTitleLines({
      lines: titleLines,
      x: centerX,
      startLayoutY: cursorY,
      fontSize: ANNOUNCEMENT_TITLE_FONT_SIZE,
      brand,
      anchor: 'middle',
      lineGap: 6,
    }),
  )
  cursorY += titleHeight + subtitleGap

  if (subtitleLines.length) {
    parts.push(
      renderExtraBodyLines({
        lines: subtitleLines,
        x: centerX,
        startLayoutY: cursorY,
        fontSize: ANNOUNCEMENT_SUBTITLE_FONT_SIZE,
        brand,
        anchor: 'middle',
        lineGap: 10,
      }),
    )
  }

  return parts.join('\n')
}

const BIG_TYPE_MARGIN_X = 64
const BIG_TYPE_AVAILABLE_WIDTH = COVER_WIDTH - BIG_TYPE_MARGIN_X * 2
const BIG_TYPE_MAX_FONT_SIZE = 480
const BIG_TYPE_MIN_FONT_SIZE = 56
/** Measured Aeonik Pro advance ratio at display sizes, with safety margin. */
const BIG_TYPE_CHAR_RATIO = 0.45
const BIG_TYPE_LINE_HEIGHT_RATIO = 1.02
/** Painted cap/ascender height of Aeonik Pro at display size (not the 0.82 layout heuristic). */
const BIG_TYPE_VISUAL_CAP_RATIO = 0.708

/** Split into one or two lines, balancing word groups around the midpoint. */
function splitBigTypeTitleLines(text: string): string[] {
  const words = text.split(/\s+/).filter(Boolean)
  if (!words.length) return []
  if (words.length === 1 || text.length <= 9) return [text]

  const half = text.length / 2
  let bestIndex = 1
  let bestDistance = Number.POSITIVE_INFINITY
  let cursor = words[0]?.length ?? 0
  for (let index = 1; index < words.length; index += 1) {
    const distance = Math.abs(cursor - half)
    if (distance < bestDistance) {
      bestDistance = distance
      bestIndex = index
    }
    cursor += 1 + (words[index]?.length ?? 0)
  }

  const first = words.slice(0, bestIndex).join(' ')
  const second = words.slice(bestIndex).join(' ')
  return [first, second].filter(Boolean)
}

export function renderBigTypeTemplateSvg(
  data: CoverBigTypeData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const centerX = COVER_WIDTH / 2

  const titleLines = splitBigTypeTitleLines(stripCoverTitleSuffix(data.title))
  const longestLine = Math.max(1, ...titleLines.map((line) => line.length))
  const titleFontSize = Math.round(
    clampNumber(
      BIG_TYPE_AVAILABLE_WIDTH / (longestLine * BIG_TYPE_CHAR_RATIO),
      BIG_TYPE_MIN_FONT_SIZE,
      BIG_TYPE_MAX_FONT_SIZE,
    ),
  )
  const titleLineStep = titleFontSize * BIG_TYPE_LINE_HEIGHT_RATIO
  const glyphHeight = titleFontSize * BIG_TYPE_VISUAL_CAP_RATIO
  const visualHeight =
    titleLines.length === 0
      ? 0
      : (titleLines.length - 1) * titleLineStep + glyphHeight
  const titleFill = data.gradientTitle ? 'url(#cover-title-gradient)' : brand.foreground

  const firstBaseline = Math.round((COVER_HEIGHT - visualHeight) / 2 + glyphHeight)
  const titleSvg = titleLines
    .map((line, index) => {
      const y = Math.round(firstBaseline + index * titleLineStep)
      return `<text class="cover-title" fill="${titleFill}" font-size="${titleFontSize}" x="${centerX}" y="${y}" text-anchor="middle">${escapeXml(line)}</text>`
    })
    .join('\n')

  return titleSvg
}

const CHECKLIST_TITLE_FONT_SIZE = 52
const CHECKLIST_TITLE_STEP = CHECKLIST_TITLE_FONT_SIZE + 8
const CHECKLIST_SUBTITLE_FONT_SIZE = 23
const CHECKLIST_SUBTITLE_STEP = CHECKLIST_SUBTITLE_FONT_SIZE + 8
const CHECKLIST_CARD_X = 648
const CHECKLIST_CARD_PADDING = 30
const CHECKLIST_ROW_HEIGHT = 62
const CHECKLIST_ITEM_FONT_SIZE = 21

function buildChecklistItemSvg(options: {
  x: number
  y: number
  width: number
  label: string
  brand: ReturnType<typeof getExtraCoverBrand>
}): string {
  const { x, y, width, label, brand } = options
  const circleCy = y + CHECKLIST_ROW_HEIGHT / 2
  const circleCx = x + 15
  const text = wrapTextLines(label, Math.max(12, Math.floor((width - 70) / 11)), 1)[0] ?? ''

  return `
    <circle cx="${circleCx}" cy="${circleCy}" r="15" fill="${brand.brandTeal}" fill-opacity="0.14" stroke="${brand.brandTeal}" stroke-opacity="0.45" stroke-width="1.5" />
    <path d="M ${circleCx - 6} ${circleCy + 1} l 4.2 4.2 l 8.4 -9.4" fill="none" stroke="${brand.brandTeal}" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
    <text class="cover-body" fill="${brand.foreground}" font-size="${CHECKLIST_ITEM_FONT_SIZE}" x="${x + 46}" y="${coverSvgTextBaseline(y + (CHECKLIST_ROW_HEIGHT - CHECKLIST_ITEM_FONT_SIZE) / 2, CHECKLIST_ITEM_FONT_SIZE)}">${escapeXml(text)}</text>
  `
}

export function renderChecklistTemplateSvg(
  data: CoverChecklistData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const titleLines = data.title
    ? wrapTextLines(stripCoverTitleSuffix(data.title), 19, 3)
    : []
  const subtitleLines = data.subtitle ? wrapTextLines(data.subtitle, 30, 3) : []

  const itemCount = Math.round(data.itemCount)
  const items = Array.from({ length: itemCount }, (_, index) => {
    const value = data[getCoverChecklistItemKey(index)]?.trim()
    return value || COVER_CHECKLIST_DEFAULTS.items[index] || `Item ${index + 1}`
  })

  const cardX = CHECKLIST_CARD_X
  const cardWidth = EXTRA_COVER_CONTENT_X + EXTRA_COVER_CONTENT_WIDTH - cardX
  const cardHeight = CHECKLIST_CARD_PADDING * 2 + items.length * CHECKLIST_ROW_HEIGHT
  const cardY = Math.round((COVER_HEIGHT - cardHeight) / 2)

  const titleHeight = titleLines.length * CHECKLIST_TITLE_STEP
  const subtitleGap = subtitleLines.length ? 20 : 0
  const subtitleHeight = subtitleLines.length * CHECKLIST_SUBTITLE_STEP
  const textHeight = titleHeight + subtitleGap + subtitleHeight
  const textStartY = Math.round((COVER_HEIGHT - textHeight) / 2)

  return `
    ${renderExtraTitleLines({
      lines: titleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: textStartY,
      fontSize: CHECKLIST_TITLE_FONT_SIZE,
      brand,
    })}
    ${renderExtraBodyLines({
      lines: subtitleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: textStartY + titleHeight + subtitleGap,
      fontSize: CHECKLIST_SUBTITLE_FONT_SIZE,
      brand,
    })}
    ${buildExtraGlassCardSvg({
      x: cardX,
      y: cardY,
      width: cardWidth,
      height: cardHeight,
      radius: 24,
      glass,
    })}
    ${items
      .map((label, index) =>
        buildChecklistItemSvg({
          x: cardX + CHECKLIST_CARD_PADDING,
          y: cardY + CHECKLIST_CARD_PADDING + index * CHECKLIST_ROW_HEIGHT,
          width: cardWidth - CHECKLIST_CARD_PADDING * 2,
          label,
          brand,
        }),
      )
      .join('\n')}
  `
}

const STEPS_TITLE_FONT_SIZE = 46
const STEPS_CARD_HEIGHT = 208
const STEPS_CARD_GAP = 24
const STEPS_CARD_PADDING = 26
const STEPS_NUMBER_FONT_SIZE = 42
const STEPS_NAME_FONT_SIZE = 23
const STEPS_DESCRIPTION_FONT_SIZE = 17

export function renderNumberedStepsTemplateSvg(
  data: CoverNumberedStepsData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const eyebrowLayoutY = 84
  const titleStartY = data.eyebrow ? 84 + 18 + 30 : 96
  const titleLines = wrapTextLines(stripCoverTitleSuffix(data.title), 30, 2)

  const stepCount = Math.round(data.stepCount)
  const cardWidth =
    (EXTRA_COVER_CONTENT_WIDTH - (stepCount - 1) * STEPS_CARD_GAP) / stepCount
  const cardY = COVER_HEIGHT - 88 - STEPS_CARD_HEIGHT

  const cards = Array.from({ length: stepCount }, (_, index) => {
    const x = EXTRA_COVER_CONTENT_X + index * (cardWidth + STEPS_CARD_GAP)
    const number = String(index + 1).padStart(2, '0')
    const stepTitle =
      data[getCoverStepTitleKey(index)]?.trim() ||
      COVER_NUMBERED_STEPS_DEFAULTS.stepTitles[index] ||
      ''
    const stepDescription =
      data[getCoverStepDescriptionKey(index)]?.trim() ||
      COVER_NUMBERED_STEPS_DEFAULTS.stepDescriptions[index] ||
      ''
    const descriptionLines = stepDescription
      ? wrapTextLines(
          stepDescription,
          Math.max(14, Math.floor((cardWidth - STEPS_CARD_PADDING * 2) / 8.6)),
          3,
        )
      : []

    const numberLayoutY = cardY + STEPS_CARD_PADDING
    const nameLayoutY = numberLayoutY + STEPS_NUMBER_FONT_SIZE + 22
    const descriptionLayoutY = nameLayoutY + STEPS_NAME_FONT_SIZE + 14

    return `
      ${buildExtraGlassCardSvg({
        x,
        y: cardY,
        width: cardWidth,
        height: STEPS_CARD_HEIGHT,
        radius: 22,
        glass,
      })}
      <text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${brand.foreground}" font-size="${STEPS_NUMBER_FONT_SIZE}" letter-spacing="-0.02em" x="${x + STEPS_CARD_PADDING}" y="${coverSvgTextBaseline(numberLayoutY, STEPS_NUMBER_FONT_SIZE)}">${number}</text>
      <text font-family="${EXTRA_COVER_BODY_FONT}" fill="${brand.foreground}" font-size="${STEPS_NAME_FONT_SIZE}" font-weight="600" x="${x + STEPS_CARD_PADDING}" y="${coverSvgTextBaseline(nameLayoutY, STEPS_NAME_FONT_SIZE)}">${escapeXml(
        wrapTextLines(
          stepTitle,
          Math.max(10, Math.floor((cardWidth - STEPS_CARD_PADDING * 2) / 12)),
          1,
        )[0] ?? '',
      )}</text>
      ${renderExtraBodyLines({
        lines: descriptionLines,
        x: x + STEPS_CARD_PADDING,
        startLayoutY: descriptionLayoutY,
        fontSize: STEPS_DESCRIPTION_FONT_SIZE,
        brand,
        lineGap: 6,
      })}
    `
  })

  return `
    ${renderExtraEyebrow({
      eyebrow: data.eyebrow,
      x: EXTRA_COVER_CONTENT_X,
      layoutY: eyebrowLayoutY,
      brand,
    })}
    ${renderExtraTitleLines({
      lines: titleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: titleStartY,
      fontSize: STEPS_TITLE_FONT_SIZE,
      brand,
    })}
    ${cards.join('\n')}
  `
}
