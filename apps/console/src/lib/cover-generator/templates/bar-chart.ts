import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import {
  getCoverChartPoints,
  normalizeCoverBarChartData,
} from '@/lib/cover-generator/chart/constants'
import {
  buildCoverChartCardShell,
  buildCoverChartGridSvg,
  COVER_CHART_LAYOUT,
  getCoverChartPlotArea,
  getCoverChartYAxisMax,
} from '@/lib/cover-generator/chart/layout'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { escapeXml } from '@/lib/cover-generator/text-utils'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

function buildBarChartContentSvg(
  data: Extract<CoverRenderData, { template: 'bar-chart' }>,
  themeId: CoverThemeId,
): string {
  const normalized = normalizeCoverBarChartData(data)
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const points = getCoverChartPoints(normalized)
  const frameWidth = getCoverFrameWidthPx(normalized.frameWidthPercent, {
    width: normalized.width,
    height: normalized.height,
  })

  const yMax = getCoverChartYAxisMax(points.map((point) => point.value))
  const plot = getCoverChartPlotArea(0, 0, frameWidth)
  plot.yMax = yMax

  const slotWidth = plot.width / points.length
  const barWidth = Math.min(48, slotWidth * 0.58)
  const parts: string[] = [buildCoverChartGridSvg({ plot, showGrid: normalized.showGrid, brand })]

  points.forEach((point, index) => {
    const barHeight = (point.value / yMax) * plot.height
    const x = plot.x + index * slotWidth + (slotWidth - barWidth) / 2
    const y = plot.y + plot.height - barHeight
    const labelX = plot.x + index * slotWidth + slotWidth / 2

    parts.push(
      `<rect x="${x}" y="${y}" width="${barWidth}" height="${barHeight}" rx="6" ry="6" fill="${brand.brandCta}" opacity="0.92" />`,
    )

    if (normalized.showValues && point.value > 0) {
      parts.push(
        `<text class="cover-body" text-anchor="middle" fill="${brand.foreground}" font-size="${COVER_CHART_LAYOUT.valueLabelFontSize}" font-weight="600" x="${labelX}" y="${coverSvgTextBaseline(y - 8, COVER_CHART_LAYOUT.valueLabelFontSize)}">${escapeXml(String(Math.round(point.value)))}</text>`,
      )
    }

    parts.push(
      `<text class="cover-body" text-anchor="middle" fill="${brand.mutedForeground}" font-size="${COVER_CHART_LAYOUT.axisLabelFontSize}" x="${labelX}" y="${coverSvgTextBaseline(plot.y + plot.height + 22, COVER_CHART_LAYOUT.axisLabelFontSize)}">${escapeXml(point.label)}</text>`,
    )
  })

  return parts.join('\n')
}

export function renderBarChartTemplateSvg(
  data: Extract<CoverRenderData, { template: 'bar-chart' }>,
  themeId: CoverThemeId,
): string {
  const normalized = normalizeCoverBarChartData(data)

  return buildCoverChartCardShell({
    frameWidthPercent: normalized.frameWidthPercent,
    width: normalized.width,
    height: normalized.height,
    title: normalized.title,
    subtitle: normalized.subtitle,
    themeId,
    chartContentSvg: buildBarChartContentSvg(normalized, themeId),
  })
}
