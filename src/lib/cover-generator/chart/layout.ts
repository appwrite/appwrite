import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import { escapeXml, stripCoverTitleSuffix } from '@/lib/cover-generator/text-utils'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

export const COVER_CHART_LAYOUT = {
  cardPaddingX: 24,
  cardPaddingY: 24,
  chartHeight: 280,
  titleFontSize: 42,
  titleLineHeight: 50,
  subtitleFontSize: 24,
  subtitleGap: 12,
  titleCardGap: 28,
  outerRadius: 24,
  borderWidth: COVER_HERO_SCREENSHOT_FRAME.borderWidth,
  plotTop: 16,
  plotBottom: 40,
  plotLeft: 52,
  plotRight: 20,
  axisLabelFontSize: 13,
  valueLabelFontSize: 12,
  gridLineCount: 4,
} as const

export function measureCoverChartTitleBlockHeight(
  title: string | undefined,
  subtitle: string | undefined,
): number {
  if (!title && !subtitle) return 0

  const titleText = title ? stripCoverTitleSuffix(title) : ''
  return (
    (titleText ? COVER_CHART_LAYOUT.titleLineHeight : 0) +
    (titleText && subtitle ? COVER_CHART_LAYOUT.subtitleGap : 0) +
    (subtitle ? COVER_CHART_LAYOUT.subtitleFontSize + 8 : 0)
  )
}

export function buildCoverChartTitleBlock(
  title: string | undefined,
  subtitle: string | undefined,
  centerX: number,
  yOffset: number,
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>,
): string {
  if (!title && !subtitle) return ''

  const titleText = title ? stripCoverTitleSuffix(title) : ''
  const titleY = yOffset
  const subtitleY = titleText
    ? titleY + COVER_CHART_LAYOUT.titleLineHeight + COVER_CHART_LAYOUT.subtitleGap
    : titleY

  return `
    ${
      titleText
        ? `<text class="cover-title" text-anchor="middle" fill="${brand.foreground}" font-size="${COVER_CHART_LAYOUT.titleFontSize}" font-weight="600" x="${centerX}" y="${coverSvgTextBaseline(titleY, COVER_CHART_LAYOUT.titleFontSize)}">${escapeXml(titleText)}</text>`
        : ''
    }
    ${
      subtitle
        ? `<text class="cover-body" text-anchor="middle" fill="${brand.mutedForeground}" font-size="${COVER_CHART_LAYOUT.subtitleFontSize}" x="${centerX}" y="${coverSvgTextBaseline(subtitleY, COVER_CHART_LAYOUT.subtitleFontSize)}">${escapeXml(subtitle)}</text>`
        : ''
    }
  `
}

export function getCoverChartYAxisMax(values: number[]): number {
  const maxValue = Math.max(...values, 1)
  const magnitude = 10 ** Math.floor(Math.log10(maxValue))
  const normalized = maxValue / magnitude

  let niceMax: number
  if (normalized <= 1) niceMax = 1
  else if (normalized <= 2) niceMax = 2
  else if (normalized <= 5) niceMax = 5
  else niceMax = 10

  return niceMax * magnitude
}

export type CoverChartPlotArea = {
  x: number
  y: number
  width: number
  height: number
  yMax: number
}

export function getCoverChartPlotArea(
  cardX: number,
  cardY: number,
  cardWidth: number,
): CoverChartPlotArea {
  const { cardPaddingX, cardPaddingY, chartHeight, plotTop, plotBottom, plotLeft, plotRight } =
    COVER_CHART_LAYOUT

  return {
    x: cardX + cardPaddingX + plotLeft,
    y: cardY + cardPaddingY + plotTop,
    width: cardWidth - cardPaddingX * 2 - plotLeft - plotRight,
    height: chartHeight - plotTop - plotBottom,
    yMax: 0,
  }
}

export function buildCoverChartGridSvg(options: {
  plot: CoverChartPlotArea
  showGrid: boolean
  brand: ReturnType<typeof getCoverBrandThemeForSvgExport>
}): string {
  const { plot, showGrid, brand } = options
  const parts: string[] = []

  for (let index = 0; index <= COVER_CHART_LAYOUT.gridLineCount; index += 1) {
    const ratio = index / COVER_CHART_LAYOUT.gridLineCount
    const y = plot.y + plot.height * (1 - ratio)
    const value = Math.round(plot.yMax * ratio)

    if (showGrid && index > 0) {
      parts.push(
        `<line x1="${plot.x}" y1="${y}" x2="${plot.x + plot.width}" y2="${y}" stroke="${brand.border}" stroke-width="1" opacity="0.55" />`,
      )
    }

    parts.push(
      `<text class="cover-body" text-anchor="end" fill="${brand.mutedForeground}" font-size="${COVER_CHART_LAYOUT.axisLabelFontSize}" x="${plot.x - 10}" y="${coverSvgTextBaseline(y - 4, COVER_CHART_LAYOUT.axisLabelFontSize)}">${value}</text>`,
    )
  }

  parts.push(
    `<line x1="${plot.x}" y1="${plot.y + plot.height}" x2="${plot.x + plot.width}" y2="${plot.y + plot.height}" stroke="${brand.border}" stroke-width="1" opacity="0.75" />`,
  )

  return parts.join('\n')
}

export function buildCoverChartCardShell(options: {
  frameWidthPercent: number
  width: number
  height: number
  title?: string
  subtitle?: string
  themeId: CoverThemeId
  chartContentSvg: string
}): string {
  const { frameWidthPercent, width, height, title, subtitle, themeId, chartContentSvg } = options
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const glass = getCoverScreenshotGlassColors(themeId)

  const frameWidth = getCoverFrameWidthPx(frameWidthPercent, { width, height })
  const frameX = Math.round((COVER_WIDTH - frameWidth) / 2)
  const centerX = COVER_WIDTH / 2
  const cardHeight = COVER_CHART_LAYOUT.cardPaddingY * 2 + COVER_CHART_LAYOUT.chartHeight

  const titleHeight = measureCoverChartTitleBlockHeight(title, subtitle)
  const titleCardGap = titleHeight > 0 ? COVER_CHART_LAYOUT.titleCardGap : 0
  const compositionHeight = titleHeight + titleCardGap + cardHeight
  const compositionY = Math.round((COVER_HEIGHT - compositionHeight) / 2)
  const cardY = compositionY + titleHeight + titleCardGap

  const titleSvg = buildCoverChartTitleBlock(title, subtitle, centerX, compositionY, brand)

  return `
    ${titleSvg}
    <g transform="translate(${frameX} ${cardY})">
      <rect
        width="${frameWidth}"
        height="${cardHeight}"
        rx="${COVER_CHART_LAYOUT.outerRadius}"
        ry="${COVER_CHART_LAYOUT.outerRadius}"
        fill="${glass.shellFill}"
        stroke="${glass.shellBorder}"
        stroke-width="${COVER_CHART_LAYOUT.borderWidth}"
      />
      ${chartContentSvg}
    </g>
  `
}
