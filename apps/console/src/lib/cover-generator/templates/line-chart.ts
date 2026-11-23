import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import {
  getCoverChartPoints,
  normalizeCoverLineChartData,
} from '@/lib/cover-generator/chart/constants'
import { buildCoverLineChartPlotSvg } from '@/lib/cover-generator/chart/line'
import {
  buildCoverChartCardShell,
  getCoverChartPlotArea,
  getCoverChartYAxisMax,
} from '@/lib/cover-generator/chart/layout'
import { getCoverFrameWidthPx } from '@/lib/cover-generator/cover-frame-width'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

function buildLineChartContentSvg(
  data: Extract<CoverRenderData, { template: 'line-chart' }>,
  themeId: CoverThemeId,
): string {
  const normalized = normalizeCoverLineChartData(data)
  const brand = getCoverBrandThemeForSvgExport(themeId)
  const points = getCoverChartPoints(normalized)
  const frameWidth = getCoverFrameWidthPx(normalized.frameWidthPercent, {
    width: normalized.width,
    height: normalized.height,
  })

  const yMax = getCoverChartYAxisMax(points.map((point) => point.value))
  const plot = getCoverChartPlotArea(0, 0, frameWidth)
  plot.yMax = yMax

  return buildCoverLineChartPlotSvg({
    points,
    plot,
    brand,
    showGrid: normalized.showGrid,
    showArea: normalized.showArea,
    showValues: normalized.showValues,
  })
}

export function renderLineChartTemplateSvg(
  data: Extract<CoverRenderData, { template: 'line-chart' }>,
  themeId: CoverThemeId,
): string {
  const normalized = normalizeCoverLineChartData(data)

  return buildCoverChartCardShell({
    frameWidthPercent: normalized.frameWidthPercent,
    width: normalized.width,
    height: normalized.height,
    title: normalized.title,
    subtitle: normalized.subtitle,
    themeId,
    chartContentSvg: buildLineChartContentSvg(normalized, themeId),
  })
}
