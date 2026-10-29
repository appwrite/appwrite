import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { getCoverBrandLightRgb } from '@/lib/cover-generator/cover-brand-lights'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import { getCoverScreenshotGlassColors } from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import {
  COVER_CODE_DIFF_MAX_LINES,
} from '@/lib/cover-generator/extra-templates/constants'
import {
  buildExtraGlassCardSvg,
  EXTRA_COVER_BODY_FONT,
  EXTRA_COVER_CONTENT_X,
  EXTRA_COVER_MONO_FONT,
  estimateCoverTextWidth,
  getExtraCoverBrand,
  renderExtraTitleLines,
  type ExtraCoverBrand,
} from '@/lib/cover-generator/extra-templates/render-shared'
import { escapeXml, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import type {
  CoverApiEndpointData,
  CoverApiEndpointMethod,
  CoverCodeDiffData,
} from '@/lib/cover-generator/types'

function rgba([r, g, b]: [number, number, number], alpha: number): string {
  return `rgba(${r},${g},${b},${alpha})`
}

const API_METHOD_TONES: Record<CoverApiEndpointMethod, 'teal' | 'purple' | 'orange' | 'pink'> = {
  GET: 'teal',
  POST: 'purple',
  PUT: 'orange',
  PATCH: 'pink',
  DELETE: 'pink',
}

const API_TITLE_FONT_SIZE = 48
const API_TITLE_STEP = API_TITLE_FONT_SIZE + 8
const API_SUBTITLE_FONT_SIZE = 24
const API_BAR_HEIGHT = 92
const API_METHOD_FONT_SIZE = 19
const API_PATH_FONT_SIZE = 23
const API_STATUS_FONT_SIZE = 19

export function renderApiEndpointTemplateSvg(
  data: CoverApiEndpointData & { width: number; height: number },
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const titleLines = data.title
    ? wrapTextLines(stripCoverTitleSuffix(data.title), 30, 2)
    : []
  const subtitle = data.subtitle?.trim()

  const titleHeight = titleLines.length * API_TITLE_STEP
  const subtitleGap = subtitle ? 20 : 0
  const subtitleHeight = subtitle ? API_SUBTITLE_FONT_SIZE + 8 : 0
  const barGap = 56
  const totalHeight = titleHeight + subtitleGap + subtitleHeight + barGap + API_BAR_HEIGHT
  const startY = Math.round((COVER_HEIGHT - totalHeight) / 2)

  const titleSvg = renderExtraTitleLines({
    lines: titleLines,
    x: EXTRA_COVER_CONTENT_X,
    startLayoutY: startY,
    fontSize: API_TITLE_FONT_SIZE,
    brand,
  })
  const subtitleLayoutY = startY + titleHeight + subtitleGap
  const barY = subtitleLayoutY + subtitleHeight + barGap

  const barWidth = getCoverFrameWidthPx(data.frameWidthPercent, {
    width: data.width,
    height: data.height,
  })
  const barX = Math.round((COVER_WIDTH - barWidth) / 2)

  const tone = API_METHOD_TONES[data.method]
  const toneRgb = getCoverBrandLightRgb(tone)
  const methodChipHeight = 44
  const methodChipWidth = Math.max(
    Math.round(
      estimateCoverTextWidth(data.method, API_METHOD_FONT_SIZE, 'bold') + 40,
    ),
    64,
  )
  const methodChipX = barX + 24
  const methodChipY = barY + (API_BAR_HEIGHT - methodChipHeight) / 2

  const pathX = methodChipX + methodChipWidth + 24
  const statusText = data.status?.trim()
  const statusWidth = statusText
    ? estimateCoverTextWidth(statusText, API_STATUS_FONT_SIZE) + 8
    : 0
  const maxPathChars = Math.max(
    12,
    Math.floor(
      (barX + barWidth - 24 - statusWidth - 16 - pathX) /
        (API_PATH_FONT_SIZE * MONO_CHAR_WIDTH_RATIO),
    ),
  )
  const pathText = wrapTextLines(data.path, maxPathChars, 1)[0] ?? ''

  return `
    ${titleSvg}
    ${
      subtitle
        ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${API_SUBTITLE_FONT_SIZE}" x="${EXTRA_COVER_CONTENT_X}" y="${coverSvgTextBaseline(subtitleLayoutY, API_SUBTITLE_FONT_SIZE)}">${escapeXml(subtitle)}</text>`
        : ''
    }
    ${buildExtraGlassCardSvg({
      x: barX,
      y: barY,
      width: barWidth,
      height: API_BAR_HEIGHT,
      radius: 20,
      glass,
    })}
    <rect x="${methodChipX}" y="${methodChipY}" width="${methodChipWidth}" height="${methodChipHeight}" rx="12" ry="12" fill="${rgba(toneRgb, 0.14)}" stroke="${rgba(toneRgb, 0.45)}" stroke-width="1.5" />
    <text font-family="${EXTRA_COVER_BODY_FONT}" fill="${rgba(toneRgb, 1)}" font-size="${API_METHOD_FONT_SIZE}" font-weight="700" letter-spacing="0.06em" x="${methodChipX + methodChipWidth / 2}" y="${coverSvgTextBaseline(methodChipY + (methodChipHeight - API_METHOD_FONT_SIZE) / 2, API_METHOD_FONT_SIZE)}" text-anchor="middle">${escapeXml(data.method)}</text>
    <text class="cover-code" fill="${brand.foreground}" font-size="${API_PATH_FONT_SIZE}" x="${pathX}" y="${coverSvgTextBaseline(barY + (API_BAR_HEIGHT - API_PATH_FONT_SIZE) / 2, API_PATH_FONT_SIZE)}">${escapeXml(pathText)}</text>
    ${
      statusText
        ? `<text font-family="${EXTRA_COVER_MONO_FONT}" fill="${rgba(getCoverBrandLightRgb('teal'), 1)}" font-size="${API_STATUS_FONT_SIZE}" x="${barX + barWidth - 24}" y="${coverSvgTextBaseline(barY + (API_BAR_HEIGHT - API_STATUS_FONT_SIZE) / 2, API_STATUS_FONT_SIZE)}" text-anchor="end">${escapeXml(statusText)}</text>`
        : ''
    }
  `
}

const DIFF_TITLE_FONT_SIZE = 44
const DIFF_HEADER_HEIGHT = 52
const DIFF_LINE_HEIGHT = 34
const DIFF_LINE_FONT_SIZE = 19
const DIFF_PADDING_X = 24
const DIFF_PADDING_BOTTOM = 22
const DIFF_META_FONT_SIZE = 16
/** librsvg mono fallback advance ratio is wider than typical 0.6 - measure conservatively. */
const MONO_CHAR_WIDTH_RATIO = 0.78

/** Clip overlong diff lines with an ellipsis so they never overflow the window. */
function truncateDiffLine(line: string, maxChars: number): string {
  if (line.length <= maxChars) return line
  return `${line.slice(0, Math.max(1, maxChars - 1)).trimEnd()}…`
}

type DiffLineKind = 'added' | 'removed' | 'context'

function getDiffLineKind(line: string): DiffLineKind {
  if (line.startsWith('+')) return 'added'
  if (line.startsWith('-')) return 'removed'
  return 'context'
}

function buildDiffLineSvg(options: {
  line: string
  kind: DiffLineKind
  x: number
  y: number
  width: number
  brand: ExtraCoverBrand
}): string {
  const { line, kind, x, y, width, brand } = options
  const teal = getCoverBrandLightRgb('teal')
  const pink = getCoverBrandLightRgb('pink')

  const background =
    kind === 'added'
      ? `<rect x="${x}" y="${y}" width="${width}" height="${DIFF_LINE_HEIGHT}" fill="${rgba(teal, 0.09)}" />`
      : kind === 'removed'
        ? `<rect x="${x}" y="${y}" width="${width}" height="${DIFF_LINE_HEIGHT}" fill="${rgba(pink, 0.09)}" />`
        : ''
  const fill =
    kind === 'added'
      ? rgba(teal, 1)
      : kind === 'removed'
        ? rgba(pink, 1)
        : brand.mutedForeground

  return `
    ${background}
    <text class="cover-code" fill="${fill}" font-size="${DIFF_LINE_FONT_SIZE}" x="${x + 12}" y="${coverSvgTextBaseline(y + (DIFF_LINE_HEIGHT - DIFF_LINE_FONT_SIZE) / 2, DIFF_LINE_FONT_SIZE)}">${escapeXml(line)}</text>
  `
}

export function renderCodeDiffTemplateSvg(
  data: CoverCodeDiffData & { width: number; height: number },
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const titleLines = data.title
    ? wrapTextLines(stripCoverTitleSuffix(data.title), 34, 2)
    : []

  const allLines = data.code.split('\n').filter((line) => line.trim().length > 0)
  const clipped = allLines.length > COVER_CODE_DIFF_MAX_LINES
  const lines = clipped ? allLines.slice(0, COVER_CODE_DIFF_MAX_LINES) : allLines
  const additions = allLines.filter((line) => getDiffLineKind(line) === 'added').length
  const removals = allLines.filter((line) => getDiffLineKind(line) === 'removed').length

  const frameWidth = getCoverFrameWidthPx(data.frameWidthPercent, {
    width: data.width,
    height: data.height,
  })
  const frameX = Math.round((COVER_WIDTH - frameWidth) / 2)

  const titleHeight = titleLines.length * (DIFF_TITLE_FONT_SIZE + 8)
  const titleGap = titleLines.length ? 36 : 0
  const windowBodyHeight =
    (lines.length + (clipped ? 1 : 0)) * DIFF_LINE_HEIGHT
  const windowHeight =
    DIFF_HEADER_HEIGHT + 16 + windowBodyHeight + DIFF_PADDING_BOTTOM
  const totalHeight = titleHeight + titleGap + windowHeight
  const startY = Math.round((COVER_HEIGHT - totalHeight) / 2)
  const windowY = startY + titleHeight + titleGap

  const dotY = windowY + DIFF_HEADER_HEIGHT / 2
  const dots = Array.from({ length: 3 }, (_, index) => {
    const cx = frameX + DIFF_PADDING_X + 6 + index * 16
    return `<circle cx="${cx}" cy="${dotY}" r="5" fill="${glass.chromeDotFill}" />`
  }).join('')

  const teal = getCoverBrandLightRgb('teal')
  const pink = getCoverBrandLightRgb('pink')
  const fileNameX = frameX + DIFF_PADDING_X + 56

  const linesStartY = windowY + DIFF_HEADER_HEIGHT + 8
  const maxLineChars = Math.max(
    8,
    Math.floor(
      (frameWidth - DIFF_PADDING_X * 2 - 8) /
        (DIFF_LINE_FONT_SIZE * MONO_CHAR_WIDTH_RATIO),
    ),
  )
  const linesSvg = lines
    .map((rawLine, index) =>
      buildDiffLineSvg({
        line: truncateDiffLine(rawLine, maxLineChars),
        kind: getDiffLineKind(rawLine),
        x: frameX + DIFF_PADDING_X - 8,
        y: linesStartY + index * DIFF_LINE_HEIGHT,
        width: frameWidth - DIFF_PADDING_X * 2 + 16,
        brand,
      }),
    )
    .join('\n')
  const ellipsisSvg = clipped
    ? `<text class="cover-code" fill="${brand.mutedForeground}" font-size="${DIFF_LINE_FONT_SIZE}" x="${frameX + DIFF_PADDING_X + 4}" y="${coverSvgTextBaseline(linesStartY + lines.length * DIFF_LINE_HEIGHT + (DIFF_LINE_HEIGHT - DIFF_LINE_FONT_SIZE) / 2, DIFF_LINE_FONT_SIZE)}">…</text>`
    : ''

  return `
    ${renderExtraTitleLines({
      lines: titleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: startY,
      fontSize: DIFF_TITLE_FONT_SIZE,
      brand,
    })}
    ${buildExtraGlassCardSvg({
      x: frameX,
      y: windowY,
      width: frameWidth,
      height: windowHeight,
      radius: 20,
      glass,
    })}
    ${dots}
    <text font-family="${EXTRA_COVER_MONO_FONT}" fill="${brand.mutedForeground}" font-size="${DIFF_META_FONT_SIZE + 2}" x="${fileNameX}" y="${coverSvgTextBaseline(windowY + (DIFF_HEADER_HEIGHT - DIFF_META_FONT_SIZE - 2) / 2, DIFF_META_FONT_SIZE + 2)}">${escapeXml(data.fileName)}</text>
    <text font-family="${EXTRA_COVER_MONO_FONT}" font-size="${DIFF_META_FONT_SIZE}" x="${frameX + frameWidth - DIFF_PADDING_X}" y="${coverSvgTextBaseline(windowY + (DIFF_HEADER_HEIGHT - DIFF_META_FONT_SIZE) / 2, DIFF_META_FONT_SIZE)}" text-anchor="end"><tspan fill="${rgba(teal, 1)}">+${additions}</tspan><tspan fill="${brand.mutedForeground}">  </tspan><tspan fill="${rgba(pink, 1)}">-${removals}</tspan></text>
    <line x1="${frameX}" y1="${windowY + DIFF_HEADER_HEIGHT}" x2="${frameX + frameWidth}" y2="${windowY + DIFF_HEADER_HEIGHT}" stroke="${glass.shellBorder}" stroke-width="1.5" />
    ${linesSvg}
    ${ellipsisSvg}
  `
}
