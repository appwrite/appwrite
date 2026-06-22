import { getTitleFill } from '@/lib/cover-generator/brand-background'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import type { CoverTitleGradientBounds } from '@/lib/cover-generator/cover-title-gradient'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import type { CoverMilestoneFields } from '@/lib/cover-generator/milestone/constants'
import { escapeXml, formatCoverEyebrow, stripCoverTitleSuffix, wrapTextLines, clampNumber } from '@/lib/cover-generator/text-utils'
import type { CoverTheme } from '@/lib/cover-generator/constants'

const COVER_CONTENT_X = 96
const COVER_EYEBROW_FONT_SIZE = 18
const COVER_EYEBROW_TITLE_GAP = 28
const COVER_STAT_LABEL_FONT_SIZE = 22
const COVER_TITLE_FONT_SIZE = 58
const COVER_SUBTITLE_FONT_SIZE = 26
const COVER_TITLE_LINE_STEP = COVER_TITLE_FONT_SIZE + 8

export type MilestoneTemplateSvgResult = {
  content: string
  titleGradientBounds?: CoverTitleGradientBounds
}

export function getMilestoneStatFontSize(stat: string): number {
  const length = stat.trim().length
  if (length <= 3) return 168
  if (length <= 5) return 140
  if (length <= 7) return 118
  return 96
}

/** Larger stat sizing for split and centered milestone layouts. */
export function getMilestoneCenteredStatFontSize(stat: string): number {
  const length = stat.trim().length
  if (length <= 3) return 212
  if (length <= 5) return 176
  if (length <= 7) return 148
  return 120
}

/** Hero stat sizing for the centered milestone layout only. */
function getMilestoneCenteredHeroStatFontSize(stat: string): number {
  const length = stat.trim().length
  if (length <= 3) return 252
  if (length <= 5) return 212
  if (length <= 7) return 176
  return 144
}

const COVER_MILESTONE_CENTERED_STAT_LABEL_FONT_SIZE = 32
const COVER_MILESTONE_CENTERED_STAT_LABEL_GAP = 10
const COVER_MILESTONE_CENTERED_STAT_TITLE_GAP = 52
const COVER_MILESTONE_CENTERED_BOTTOM_PADDING = 12
const COVER_MILESTONE_SPLIT_STAT_LABEL_FONT_SIZE = 32
const COVER_MILESTONE_CENTERED_TITLE_MAX_WIDTH = COVER_WIDTH - COVER_CONTENT_X * 2
const COVER_MILESTONE_SPLIT_CENTER_X = COVER_WIDTH * 0.75

function getMilestoneCenteredTitleFontSize(title: string): number {
  const text = stripCoverTitleSuffix(title)
  const length = Math.max(text.length, 1)
  const computed = Math.floor(COVER_MILESTONE_CENTERED_TITLE_MAX_WIDTH / (length * 0.62))
  return clampNumber(computed, 28, 46)
}

function buildStatGradientBounds(
  x: number,
  y: number,
  stat: string,
  fontSize: number,
): CoverTitleGradientBounds {
  const width = Math.min(COVER_WIDTH - x - COVER_CONTENT_X, stat.length * fontSize * 0.62)
  return {
    x,
    y,
    width: Math.max(width, 120),
    height: fontSize,
  }
}

function renderEyebrow(
  eyebrow: string | undefined,
  x: number,
  layoutY: number,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
): string {
  const eyebrowText = formatCoverEyebrow(eyebrow)
  if (!eyebrowText) return ''

  return `<text class="cover-eyebrow" fill="${brand.mutedForeground}" font-size="${COVER_EYEBROW_FONT_SIZE}" font-weight="600" letter-spacing="0.25em" x="${x}" y="${coverSvgTextBaseline(layoutY, COVER_EYEBROW_FONT_SIZE)}">${escapeXml(eyebrowText)}<tspan fill="${brand.brandCta}">_</tspan></text>`
}

function renderTitleBlock(
  title: string,
  subtitle: string | undefined,
  x: number,
  startY: number,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
  maxChars = 22,
): string {
  const titleLines = wrapTextLines(stripCoverTitleSuffix(title), maxChars, 3)
  const titleSvg = titleLines
    .map((line, index) => {
      const layoutY = startY + index * COVER_TITLE_LINE_STEP
      return `<text class="cover-title" fill="${brand.foreground}" font-size="${COVER_TITLE_FONT_SIZE}" x="${x}" y="${coverSvgTextBaseline(layoutY, COVER_TITLE_FONT_SIZE)}">${escapeXml(line)}${
        index === titleLines.length - 1 ? `<tspan fill="${brand.brandCta}">_</tspan>` : ''
      }</text>`
    })
    .join('')

  const subtitleLayoutY =
    startY + titleLines.length * COVER_TITLE_LINE_STEP + (subtitle ? 22 : 0)

  return `
    ${titleSvg}
    ${
      subtitle
        ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${COVER_SUBTITLE_FONT_SIZE}" x="${x}" y="${coverSvgTextBaseline(subtitleLayoutY, COVER_SUBTITLE_FONT_SIZE)}">${escapeXml(subtitle)}</text>`
        : ''
    }
  `
}

function renderStatBlock(params: {
  stat: string
  statLabel?: string
  x: number
  statLayoutY: number
  statFill: string
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>
  textAnchor?: 'start' | 'middle'
  statFontSize?: number
  statLabelFontSize?: number
  statLabelGap?: number
}): { svg: string; fontSize: number } {
  const fontSize = params.statFontSize ?? getMilestoneStatFontSize(params.stat)
  const statLabelFontSize = params.statLabelFontSize ?? COVER_STAT_LABEL_FONT_SIZE
  const statLabelGap = params.statLabelGap ?? 16
  const anchor = params.textAnchor ?? 'start'
  const statBaseline = coverSvgTextBaseline(params.statLayoutY, fontSize)
  const labelLayoutY = params.statLayoutY + fontSize + statLabelGap

  const svg = `
    <text class="cover-title" fill="${params.statFill}" font-size="${fontSize}" x="${params.x}" y="${statBaseline}" text-anchor="${anchor}">${escapeXml(params.stat.trim())}</text>
    ${
      params.statLabel
        ? `<text class="cover-body" fill="${params.brand.mutedForeground}" font-size="${statLabelFontSize}" x="${params.x}" y="${coverSvgTextBaseline(labelLayoutY, statLabelFontSize)}" text-anchor="${anchor}">${escapeXml(params.statLabel)}</text>`
        : ''
    }
  `

  return { svg, fontSize }
}

export function renderMilestoneSplitTemplateSvg(
  data: CoverMilestoneFields,
  theme: CoverTheme,
): MilestoneTemplateSvgResult {
  const brand = getCoverBrandThemeForSvgExport(theme)
  const statFill = getTitleFill(brand, data.gradientStat)
  const eyebrowLayoutY = 188
  const copyStartY = data.eyebrow
    ? eyebrowLayoutY + COVER_EYEBROW_FONT_SIZE + COVER_EYEBROW_TITLE_GAP
    : 210
  const statFontSize = getMilestoneCenteredStatFontSize(data.stat)
  const statLabelFontSize = COVER_MILESTONE_SPLIT_STAT_LABEL_FONT_SIZE
  const statLabelGap = 20
  const statBlockHeight =
    statFontSize + (data.statLabel ? statLabelFontSize + statLabelGap : 0)
  const statLayoutY = Math.round((COVER_HEIGHT - statBlockHeight) / 2)

  const statBlock = renderStatBlock({
    stat: data.stat,
    statLabel: data.statLabel,
    x: COVER_MILESTONE_SPLIT_CENTER_X,
    statLayoutY,
    statFill,
    brand,
    textAnchor: 'middle',
    statFontSize,
    statLabelFontSize,
    statLabelGap,
  })

  const gradientWidth = Math.min(
    COVER_WIDTH / 2 - COVER_CONTENT_X,
    data.stat.length * statBlock.fontSize * 0.62,
  )
  const titleGradientBounds = data.gradientStat
    ? buildStatGradientBounds(
        COVER_MILESTONE_SPLIT_CENTER_X - gradientWidth / 2,
        statLayoutY,
        data.stat,
        statBlock.fontSize,
      )
    : undefined

  return {
    titleGradientBounds,
    content: `
      ${renderEyebrow(data.eyebrow, COVER_CONTENT_X, eyebrowLayoutY, brand)}
      ${renderTitleBlock(data.title, data.subtitle, COVER_CONTENT_X, copyStartY, brand, 20)}
      ${statBlock.svg}
    `,
  }
}

export function renderMilestoneCenteredTemplateSvg(
  data: CoverMilestoneFields,
  theme: CoverTheme,
): MilestoneTemplateSvgResult {
  const brand = getCoverBrandThemeForSvgExport(theme)
  const statFill = getTitleFill(brand, data.gradientStat)
  const centerX = COVER_WIDTH / 2
  const statFontSize = getMilestoneCenteredHeroStatFontSize(data.stat)
  const statLabelFontSize = COVER_MILESTONE_CENTERED_STAT_LABEL_FONT_SIZE
  const statLabelGap = COVER_MILESTONE_CENTERED_STAT_LABEL_GAP
  const eyebrowText = formatCoverEyebrow(data.eyebrow)
  const titleLine =
    wrapTextLines(stripCoverTitleSuffix(data.title), 120, 1)[0] ??
    stripCoverTitleSuffix(data.title)
  const titleFontSize = getMilestoneCenteredTitleFontSize(titleLine)
  const titleLineStep = titleFontSize + 8

  const eyebrowBlockHeight = eyebrowText ? COVER_EYEBROW_FONT_SIZE + 28 : 0
  const statBlockHeight =
    statFontSize + (data.statLabel ? statLabelFontSize + statLabelGap : 0)
  const titleBlockHeight = titleLineStep
  const subtitleBlockHeight = data.subtitle ? COVER_SUBTITLE_FONT_SIZE + 20 : 0
  const totalHeight =
    eyebrowBlockHeight +
    statBlockHeight +
    COVER_MILESTONE_CENTERED_STAT_TITLE_GAP +
    titleBlockHeight +
    subtitleBlockHeight +
    COVER_MILESTONE_CENTERED_BOTTOM_PADDING

  let cursorY = Math.round((630 - totalHeight) / 2)

  const parts: string[] = []

  if (eyebrowText) {
    parts.push(
      `<text class="cover-eyebrow" fill="${brand.mutedForeground}" font-size="${COVER_EYEBROW_FONT_SIZE}" font-weight="600" letter-spacing="0.25em" x="${centerX}" y="${coverSvgTextBaseline(cursorY, COVER_EYEBROW_FONT_SIZE)}" text-anchor="middle">${escapeXml(eyebrowText)}<tspan fill="${brand.brandCta}">_</tspan></text>`,
    )
    cursorY += eyebrowBlockHeight
  }

  const statLayoutY = cursorY
  parts.push(
    renderStatBlock({
      stat: data.stat,
      statLabel: data.statLabel,
      x: centerX,
      statLayoutY,
      statFill,
      brand,
      textAnchor: 'middle',
      statFontSize,
      statLabelFontSize,
      statLabelGap,
    }).svg,
  )
  cursorY += statBlockHeight + COVER_MILESTONE_CENTERED_STAT_TITLE_GAP

  parts.push(
    `<text class="cover-title" fill="${brand.foreground}" font-size="${titleFontSize}" x="${centerX}" y="${coverSvgTextBaseline(cursorY, titleFontSize)}" text-anchor="middle">${escapeXml(titleLine)}<tspan fill="${brand.brandCta}">_</tspan></text>`,
  )
  cursorY += titleBlockHeight + (data.subtitle ? 20 : 0)

  if (data.subtitle) {
    parts.push(
      `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${COVER_SUBTITLE_FONT_SIZE}" x="${centerX}" y="${coverSvgTextBaseline(cursorY, COVER_SUBTITLE_FONT_SIZE)}" text-anchor="middle">${escapeXml(data.subtitle)}</text>`,
    )
  }

  const titleGradientBounds = data.gradientStat
    ? buildStatGradientBounds(centerX - 280, statLayoutY, data.stat, statFontSize)
    : undefined

  return {
    titleGradientBounds,
    content: parts.join(''),
  }
}
