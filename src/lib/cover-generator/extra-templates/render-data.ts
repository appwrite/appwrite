import { getCoverBadgeColor, getCoverSuccessColor } from '@/lib/cover-generator/brand-theme'
import {
  COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT,
  COVER_CHART_DEFAULT_LABELS,
} from '@/lib/cover-generator/chart/constants'
import { buildCoverLineChartPlotSvg } from '@/lib/cover-generator/chart/line'
import {
  buildCoverChartCardShell,
  getCoverChartPlotArea,
  getCoverChartYAxisMax,
} from '@/lib/cover-generator/chart/layout'
import { COVER_HEIGHT } from '@/lib/cover-generator/constants'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import { getCoverScreenshotGlassColors } from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import {
  COVER_METRIC_DELTA_DEFAULTS,
  COVER_METRIC_DELTA_TREND_POINTS,
  COVER_STATS_GRID_DEFAULTS,
  getCoverMetricTrendKey,
  getCoverStatLabelKey,
  getCoverStatValueKey,
} from '@/lib/cover-generator/extra-templates/constants'
import {
  buildExtraGlassCardSvg,
  EXTRA_COVER_CONTENT_WIDTH,
  EXTRA_COVER_CONTENT_X,
  EXTRA_COVER_TITLE_FONT,
  estimateCoverTextWidth,
  getExtraCoverBrand,
  renderExtraBodyLines,
  renderExtraTitleLines,
} from '@/lib/cover-generator/extra-templates/render-shared'
import { COVER_EYEBROW_LETTER_SPACING, escapeXml, stripCoverTitleSuffix, wrapTextLines } from '@/lib/cover-generator/text-utils'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import type {
  CoverDonutChartData,
  CoverMetricDeltaData,
  CoverProgressBarData,
  CoverStatsGridData,
} from '@/lib/cover-generator/types'

const STATS_TITLE_FONT_SIZE = 44
const STATS_CARD_HEIGHT = 188
const STATS_CARD_GAP = 24
const STATS_CARD_PADDING = 28
const STATS_VALUE_FONT_SIZE = 52
const STATS_LABEL_FONT_SIZE = 19

export function renderStatsGridTemplateSvg(
  data: CoverStatsGridData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const titleLines = data.title
    ? wrapTextLines(stripCoverTitleSuffix(data.title), 36, 2)
    : []
  const titleHeight = titleLines.length * (STATS_TITLE_FONT_SIZE + 8)
  const cardsGap = titleLines.length ? 52 : 0
  const totalHeight = titleHeight + cardsGap + STATS_CARD_HEIGHT
  const startY = Math.round((COVER_HEIGHT - totalHeight) / 2)
  const cardsY = startY + titleHeight + cardsGap

  const statCount = Math.round(data.statCount)
  const cardWidth =
    (EXTRA_COVER_CONTENT_WIDTH - (statCount - 1) * STATS_CARD_GAP) / statCount
  const valueFill = brand.foreground

  const cards = Array.from({ length: statCount }, (_, index) => {
    const x = EXTRA_COVER_CONTENT_X + index * (cardWidth + STATS_CARD_GAP)
    const value =
      data[getCoverStatValueKey(index)]?.trim() ||
      COVER_STATS_GRID_DEFAULTS.values[index] ||
      ''
    const label =
      data[getCoverStatLabelKey(index)]?.trim() ||
      COVER_STATS_GRID_DEFAULTS.labels[index] ||
      ''
    const maxValueChars = Math.max(
      4,
      Math.floor((cardWidth - STATS_CARD_PADDING * 2) / (STATS_VALUE_FONT_SIZE * 0.6)),
    )
    const valueText = wrapTextLines(value, maxValueChars, 1)[0] ?? ''
    const valueLayoutY = cardsY + STATS_CARD_PADDING + 12
    const labelLayoutY = valueLayoutY + STATS_VALUE_FONT_SIZE + 22

    return `
      ${buildExtraGlassCardSvg({
        x,
        y: cardsY,
        width: cardWidth,
        height: STATS_CARD_HEIGHT,
        radius: 22,
        glass,
      })}
      <text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${valueFill}" font-size="${STATS_VALUE_FONT_SIZE}" letter-spacing="-0.02em" x="${x + STATS_CARD_PADDING}" y="${coverSvgTextBaseline(valueLayoutY, STATS_VALUE_FONT_SIZE)}">${escapeXml(valueText)}</text>
      <text class="cover-body" fill="${brand.mutedForeground}" font-size="${STATS_LABEL_FONT_SIZE}" x="${x + STATS_CARD_PADDING}" y="${coverSvgTextBaseline(labelLayoutY, STATS_LABEL_FONT_SIZE)}">${escapeXml(
        wrapTextLines(
          label,
          Math.max(6, Math.floor((cardWidth - STATS_CARD_PADDING * 2) / 9.5)),
          2,
        )[0] ?? '',
      )}</text>
    `
  })

  return `
    ${renderExtraTitleLines({
      lines: titleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: startY,
      fontSize: STATS_TITLE_FONT_SIZE,
      brand,
    })}
    ${cards.join('\n')}
  `
}

const METRIC_LABEL_FONT_SIZE = 18
const METRIC_VALUE_FONT_SIZE = 118
const METRIC_DELTA_FONT_SIZE = 36
const METRIC_LABEL_GAP = 16
const METRIC_DELTA_GAP = 20
const METRIC_DELTA_AREA_GRADIENT_ID = 'cover-metric-delta-area-gradient'

export function renderMetricDeltaTemplateSvg(
  data: CoverMetricDeltaData & { width: number; height: number },
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)

  const labelText = data.label?.trim().toUpperCase()
  const valueText = wrapTextLines(data.value.trim(), 10, 1)[0] ?? data.value.trim()
  const deltaLabel = data.delta?.trim() || ''
  const deltaFill =
    data.deltaTone === 'down' ? brand.brandCta : getCoverSuccessColor(themeId)

  const labelHeight = labelText ? METRIC_LABEL_FONT_SIZE : 0
  const labelGap = labelText ? METRIC_LABEL_GAP : 0
  const valueLayoutY = labelHeight + labelGap
  const headerHeight = valueLayoutY + METRIC_VALUE_FONT_SIZE

  const valueWidth = estimateCoverTextWidth(valueText, METRIC_VALUE_FONT_SIZE, 'regular')
  const deltaX = valueWidth + METRIC_DELTA_GAP
  const valueBaseline = coverSvgTextBaseline(valueLayoutY, METRIC_VALUE_FONT_SIZE)

  const headerSvg = `
    ${
      labelText
        ? `<text class="cover-eyebrow" fill="${brand.mutedForeground}" font-size="${METRIC_LABEL_FONT_SIZE}" font-weight="600" letter-spacing="${COVER_EYEBROW_LETTER_SPACING}" x="0" y="${coverSvgTextBaseline(0, METRIC_LABEL_FONT_SIZE)}">${escapeXml(labelText)}</text>`
        : ''
    }
    <text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${brand.foreground}" font-size="${METRIC_VALUE_FONT_SIZE}" letter-spacing="-0.02em" x="0" y="${valueBaseline}">${escapeXml(valueText)}</text>
    ${
      deltaLabel
        ? `<text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${deltaFill}" font-size="${METRIC_DELTA_FONT_SIZE}" font-weight="600" letter-spacing="-0.02em" x="${deltaX}" y="${valueBaseline}">${escapeXml(deltaLabel)}</text>`
        : ''
    }
  `

  const points = Array.from({ length: COVER_METRIC_DELTA_TREND_POINTS }, (_, index) => {
    const raw = data[getCoverMetricTrendKey(index)]
    const value = typeof raw === 'number' && Number.isFinite(raw) ? raw : undefined
    return {
      label: COVER_CHART_DEFAULT_LABELS[index] ?? '',
      value: Math.min(
        100,
        Math.max(0, value ?? COVER_METRIC_DELTA_DEFAULTS.trend[index] ?? 50),
      ),
    }
  })

  const frameWidthPercent = COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT
  const frameWidth = getCoverFrameWidthPx(frameWidthPercent, {
    width: data.width,
    height: data.height,
  })
  const yMax = getCoverChartYAxisMax(points.map((point) => point.value))
  const plot = getCoverChartPlotArea(0, 0, frameWidth)
  plot.yMax = yMax

  return buildCoverChartCardShell({
    frameWidthPercent,
    width: data.width,
    height: data.height,
    themeId,
    headerSvg,
    headerHeight,
    chartContentSvg: buildCoverLineChartPlotSvg({
      points,
      plot,
      brand,
      showGrid: true,
      showArea: true,
      showValues: false,
      areaGradientId: METRIC_DELTA_AREA_GRADIENT_ID,
    }),
  })
}

const DONUT_TITLE_FONT_SIZE = 60
const DONUT_TITLE_STEP = DONUT_TITLE_FONT_SIZE + 10
const DONUT_SUBTITLE_FONT_SIZE = 28
const DONUT_RADIUS = 132
const DONUT_STROKE = 32
const DONUT_PERCENT_FONT_SIZE = 56
const DONUT_CENTER_LABEL_FONT_SIZE = 19

export function renderDonutChartTemplateSvg(
  data: CoverDonutChartData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)

  const titleLines = data.title
    ? wrapTextLines(stripCoverTitleSuffix(data.title), 18, 3)
    : []
  const subtitleLines = data.subtitle ? wrapTextLines(data.subtitle, 26, 2) : []

  const titleHeight = titleLines.length * DONUT_TITLE_STEP
  const subtitleGap = subtitleLines.length ? 20 : 0
  const subtitleHeight = subtitleLines.length * (DONUT_SUBTITLE_FONT_SIZE + 8)
  const textHeight = titleHeight + subtitleGap + subtitleHeight
  const textStartY = Math.round((COVER_HEIGHT - textHeight) / 2)

  const donutCx = 1200 - 96 - DONUT_RADIUS - 24
  const donutCy = COVER_HEIGHT / 2
  const circumference = 2 * Math.PI * DONUT_RADIUS
  const percent = Math.min(100, Math.max(0, data.percent))
  const arcLength = (percent / 100) * circumference

  const percentText = `${Math.round(percent)}%`
  const centerLabel = data.centerLabel?.trim()
  const percentLayoutY = centerLabel
    ? donutCy - DONUT_PERCENT_FONT_SIZE / 2 - 4
    : donutCy - DONUT_PERCENT_FONT_SIZE / 2
  const centerLabelLayoutY = donutCy + DONUT_PERCENT_FONT_SIZE / 2 + 12

  return `
    ${renderExtraTitleLines({
      lines: titleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: textStartY,
      fontSize: DONUT_TITLE_FONT_SIZE,
      brand,
    })}
    ${renderExtraBodyLines({
      lines: subtitleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: textStartY + titleHeight + subtitleGap,
      fontSize: DONUT_SUBTITLE_FONT_SIZE,
      brand,
    })}
    <circle cx="${donutCx}" cy="${donutCy}" r="${DONUT_RADIUS}" fill="none" stroke="${brand.border}" stroke-width="${DONUT_STROKE}" opacity="0.6" />
    <circle
      cx="${donutCx}"
      cy="${donutCy}"
      r="${DONUT_RADIUS}"
      fill="none"
      stroke="${getCoverBadgeColor(themeId, data.color)}"
      stroke-width="${DONUT_STROKE}"
      stroke-linecap="round"
      stroke-dasharray="${arcLength} ${circumference}"
      transform="rotate(-90 ${donutCx} ${donutCy})"
    />
    <text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${brand.foreground}" font-size="${DONUT_PERCENT_FONT_SIZE}" letter-spacing="-0.02em" x="${donutCx}" y="${coverSvgTextBaseline(percentLayoutY, DONUT_PERCENT_FONT_SIZE)}" text-anchor="middle">${escapeXml(percentText)}</text>
    ${
      centerLabel
        ? `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${DONUT_CENTER_LABEL_FONT_SIZE}" x="${donutCx}" y="${coverSvgTextBaseline(centerLabelLayoutY, DONUT_CENTER_LABEL_FONT_SIZE)}" text-anchor="middle">${escapeXml(centerLabel)}</text>`
        : ''
    }
  `
}

const PROGRESS_TITLE_FONT_SIZE = 60
const PROGRESS_VALUE_FONT_SIZE = 132
const PROGRESS_BAR_HEIGHT = 30
const PROGRESS_LABEL_FONT_SIZE = 28

export function renderProgressBarTemplateSvg(
  data: CoverProgressBarData,
  themeId: CoverThemeId,
): string {
  const brand = getExtraCoverBrand(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const percent = Math.min(100, Math.max(0, data.percent))
  const percentText = `${Math.round(percent)}%`
  const titleLines = data.title
    ? wrapTextLines(stripCoverTitleSuffix(data.title), 28, 2)
    : []
  const label = data.label?.trim()
  const barFill = getCoverBadgeColor(themeId, data.color)

  const titleHeight = titleLines.length * (PROGRESS_TITLE_FONT_SIZE + 10)
  const valueGap = 24
  const valueHeight = PROGRESS_VALUE_FONT_SIZE
  const barGap = 48
  const labelGap = label ? 26 : 0
  const labelHeight = label ? PROGRESS_LABEL_FONT_SIZE : 0

  const totalHeight =
    titleHeight + valueGap + valueHeight + barGap + PROGRESS_BAR_HEIGHT + labelGap + labelHeight
  let cursorY = Math.round((COVER_HEIGHT - totalHeight) / 2)

  const parts: string[] = []

  parts.push(
    renderExtraTitleLines({
      lines: titleLines,
      x: EXTRA_COVER_CONTENT_X,
      startLayoutY: cursorY,
      fontSize: PROGRESS_TITLE_FONT_SIZE,
      brand,
    }),
  )
  cursorY += titleHeight + valueGap

  parts.push(
    `<text font-family="${EXTRA_COVER_TITLE_FONT}" fill="${brand.foreground}" font-size="${PROGRESS_VALUE_FONT_SIZE}" letter-spacing="-0.02em" x="${EXTRA_COVER_CONTENT_X}" y="${coverSvgTextBaseline(cursorY, PROGRESS_VALUE_FONT_SIZE)}">${escapeXml(percentText)}</text>`,
  )
  cursorY += valueHeight + barGap

  const fillWidth = Math.max(
    PROGRESS_BAR_HEIGHT,
    Math.round((EXTRA_COVER_CONTENT_WIDTH * percent) / 100),
  )
  parts.push(`
    <rect x="${EXTRA_COVER_CONTENT_X}" y="${cursorY}" width="${EXTRA_COVER_CONTENT_WIDTH}" height="${PROGRESS_BAR_HEIGHT}" rx="${PROGRESS_BAR_HEIGHT / 2}" ry="${PROGRESS_BAR_HEIGHT / 2}" fill="${glass.shellFill}" stroke="${glass.shellBorder}" stroke-width="1.5" />
    <rect x="${EXTRA_COVER_CONTENT_X}" y="${cursorY}" width="${fillWidth}" height="${PROGRESS_BAR_HEIGHT}" rx="${PROGRESS_BAR_HEIGHT / 2}" ry="${PROGRESS_BAR_HEIGHT / 2}" fill="${barFill}" />
  `)
  cursorY += PROGRESS_BAR_HEIGHT + labelGap

  if (label) {
    parts.push(
      `<text class="cover-body" fill="${brand.mutedForeground}" font-size="${PROGRESS_LABEL_FONT_SIZE}" x="${EXTRA_COVER_CONTENT_X}" y="${coverSvgTextBaseline(cursorY, PROGRESS_LABEL_FONT_SIZE)}">${escapeXml(label)}</text>`,
    )
  }

  return parts.join('\n')
}
