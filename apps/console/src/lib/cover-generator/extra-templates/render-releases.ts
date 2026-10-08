import { COVER_HEIGHT } from '@/lib/cover-generator/constants'
import { getCoverScreenshotGlassColors } from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import {
  buildExtraGlassCardSvg,
  EXTRA_COVER_BODY_FONT,
  EXTRA_COVER_TITLE_FONT,
  estimateCoverTextWidth,
  getExtraCoverBrand,
  renderExtraEyebrow,
  renderExtraTitleLines,
} from '@/lib/cover-generator/extra-templates/render-shared'
import { escapeXml, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import type { CoverCountdownData, CoverStatusPillData } from '@/lib/cover-generator/types'

const STATUS_EYEBROW_FONT_SIZE = 18
const STATUS_PILL_FONT_SIZE = 40
const STATUS_PILL_HEIGHT = 104
const STATUS_TITLE_FONT_SIZE = 40
const STATUS_TITLE_STEP = STATUS_TITLE_FONT_SIZE + 8

export function renderStatusPillTemplateSvg(
  data: CoverStatusPillData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const centerX = 600

  const statusText = data.status.trim().toUpperCase()
  const titleLines = wrapTextLines(stripCoverTitleSuffix(data.title), 34, 2)

  const eyebrowHeight = data.eyebrow ? STATUS_EYEBROW_FONT_SIZE + 34 : 0
  const pillWidth = Math.max(
    Math.round(
      estimateCoverTextWidth(statusText, STATUS_PILL_FONT_SIZE, 'bold') +
        statusText.length * STATUS_PILL_FONT_SIZE * 0.08 +
        112,
    ),
    280,
  )
  const titleGap = 46
  const titleHeight = titleLines.length * STATUS_TITLE_STEP
  const totalHeight = eyebrowHeight + STATUS_PILL_HEIGHT + titleGap + titleHeight

  let cursorY = Math.round((COVER_HEIGHT - totalHeight) / 2)

  const parts: string[] = []

  if (data.eyebrow) {
    parts.push(
      renderExtraEyebrow({
        eyebrow: data.eyebrow,
        x: centerX,
        layoutY: cursorY,
        brand,
        fontSize: STATUS_EYEBROW_FONT_SIZE,
        anchor: 'middle',
      }),
    )
    cursorY += eyebrowHeight
  }

  const pillX = centerX - pillWidth / 2
  parts.push(`
    <rect x="${pillX}" y="${cursorY}" width="${pillWidth}" height="${STATUS_PILL_HEIGHT}" rx="${STATUS_PILL_HEIGHT / 2}" ry="${STATUS_PILL_HEIGHT / 2}" fill="${brand.brandCta}" fill-opacity="0.07" stroke="${brand.brandCta}" stroke-width="2.5" />
    <text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${brand.foreground}" font-size="${STATUS_PILL_FONT_SIZE}" font-weight="700" letter-spacing="0.08em" x="${centerX}" y="${coverSvgTextBaseline(cursorY + (STATUS_PILL_HEIGHT - STATUS_PILL_FONT_SIZE) / 2, STATUS_PILL_FONT_SIZE)}" text-anchor="middle">${escapeXml(statusText)}</text>
  `)
  cursorY += STATUS_PILL_HEIGHT + titleGap

  parts.push(
    renderExtraTitleLines({
      lines: titleLines,
      x: centerX,
      startLayoutY: cursorY,
      fontSize: STATUS_TITLE_FONT_SIZE,
      brand,
      anchor: 'middle',
    }),
  )

  return parts.join('\n')
}

const COUNTDOWN_TITLE_FONT_SIZE = 42
const COUNTDOWN_BOX_WIDTH = 156
const COUNTDOWN_BOX_HEIGHT = 168
const COUNTDOWN_BOX_GAP = 20
const COUNTDOWN_NUMBER_FONT_SIZE = 58
const COUNTDOWN_LABEL_FONT_SIZE = 13
const COUNTDOWN_DATE_FONT_SIZE = 22

function buildCountdownBoxSvg(options: {
  x: number
  y: number
  value: number
  label: string
  brand: ReturnType<typeof getExtraCoverBrand>
  glass: ReturnType<typeof getCoverScreenshotGlassColors>
}): string {
  const { x, y, value, label, brand, glass } = options
  const number = String(Math.max(0, Math.round(value))).padStart(2, '0')
  const numberLayoutY = y + 34
  const labelLayoutY = numberLayoutY + COUNTDOWN_NUMBER_FONT_SIZE + 26

  return `
    ${buildExtraGlassCardSvg({
      x,
      y,
      width: COUNTDOWN_BOX_WIDTH,
      height: COUNTDOWN_BOX_HEIGHT,
      radius: 22,
      glass,
    })}
    <text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${brand.foreground}" font-size="${COUNTDOWN_NUMBER_FONT_SIZE}" letter-spacing="-0.02em" x="${x + COUNTDOWN_BOX_WIDTH / 2}" y="${coverSvgTextBaseline(numberLayoutY, COUNTDOWN_NUMBER_FONT_SIZE)}" text-anchor="middle">${number}</text>
    <text font-family="${EXTRA_COVER_BODY_FONT}" fill="${brand.mutedForeground}" font-size="${COUNTDOWN_LABEL_FONT_SIZE}" font-weight="600" letter-spacing="0.2em" x="${x + COUNTDOWN_BOX_WIDTH / 2}" y="${coverSvgTextBaseline(labelLayoutY, COUNTDOWN_LABEL_FONT_SIZE)}" text-anchor="middle">${escapeXml(label.toUpperCase())}</text>
  `
}

export function renderCountdownTemplateSvg(
  data: CoverCountdownData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)
  const centerX = 600

  const titleLines = wrapTextLines(stripCoverTitleSuffix(data.title), 36, 1)
  const eyebrowHeight = data.eyebrow ? 18 + 30 : 0
  const titleHeight = titleLines.length * (COUNTDOWN_TITLE_FONT_SIZE + 8)
  const boxesGap = 48
  const dateGap = data.dateLabel ? 40 : 0
  const dateHeight = data.dateLabel ? COUNTDOWN_DATE_FONT_SIZE : 0

  const totalHeight =
    eyebrowHeight +
    titleHeight +
    boxesGap +
    COUNTDOWN_BOX_HEIGHT +
    dateGap +
    dateHeight
  let cursorY = Math.round((COVER_HEIGHT - totalHeight) / 2)

  const parts: string[] = []

  if (data.eyebrow) {
    parts.push(
      renderExtraEyebrow({
        eyebrow: data.eyebrow,
        x: centerX,
        layoutY: cursorY,
        brand,
        anchor: 'middle',
      }),
    )
    cursorY += eyebrowHeight
  }

  parts.push(
    renderExtraTitleLines({
      lines: titleLines,
      x: centerX,
      startLayoutY: cursorY,
      fontSize: COUNTDOWN_TITLE_FONT_SIZE,
      brand,
      anchor: 'middle',
    }),
  )
  cursorY += titleHeight + boxesGap

  const units: Array<{ value: number; label: string }> = [
    { value: data.days, label: 'Days' },
    { value: data.hours, label: 'Hours' },
    { value: data.minutes, label: 'Minutes' },
    { value: data.seconds, label: 'Seconds' },
  ]
  const rowWidth =
    units.length * COUNTDOWN_BOX_WIDTH + (units.length - 1) * COUNTDOWN_BOX_GAP
  const rowX = Math.round(centerX - rowWidth / 2)

  parts.push(
    units
      .map((unit, index) =>
        buildCountdownBoxSvg({
          x: rowX + index * (COUNTDOWN_BOX_WIDTH + COUNTDOWN_BOX_GAP),
          y: cursorY,
          value: unit.value,
          label: unit.label,
          brand,
          glass,
        }),
      )
      .join('\n'),
  )
  cursorY += COUNTDOWN_BOX_HEIGHT + dateGap

  if (data.dateLabel) {
    parts.push(
      `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${COUNTDOWN_DATE_FONT_SIZE}" x="${centerX}" y="${coverSvgTextBaseline(cursorY, COUNTDOWN_DATE_FONT_SIZE)}" text-anchor="middle">${escapeXml(data.dateLabel)}</text>`,
    )
  }

  return parts.join('\n')
}
