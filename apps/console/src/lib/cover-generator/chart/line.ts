import type { CoverBrandTheme } from '@/lib/cover-generator/brand-theme'
import {
  buildCoverChartGridSvg,
  COVER_CHART_LAYOUT,
  type CoverChartPlotArea,
} from '@/lib/cover-generator/chart/layout'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { escapeXml } from '@/lib/cover-generator/text-utils'

const DEFAULT_LINE_CHART_AREA_GRADIENT_ID = 'cover-line-chart-area-gradient'

function buildLinePoints(
  points: Array<{ label: string; value: number }>,
  plot: CoverChartPlotArea,
): Array<{ x: number; y: number; label: string; value: number }> {
  const slotWidth = plot.width / Math.max(points.length - 1, 1)

  return points.map((point, index) => {
    const x =
      points.length === 1 ? plot.x + plot.width / 2 : plot.x + index * slotWidth
    const y = plot.y + plot.height - (point.value / plot.yMax) * plot.height

    return { x, y, label: point.label, value: point.value }
  })
}

export function buildCoverLineChartPlotSvg(options: {
  points: Array<{ label: string; value: number }>
  plot: CoverChartPlotArea
  brand: CoverBrandTheme
  showGrid: boolean
  showArea: boolean
  showValues: boolean
  areaGradientId?: string
}): string {
  const {
    points,
    plot,
    brand,
    showGrid,
    showArea,
    showValues,
    areaGradientId = DEFAULT_LINE_CHART_AREA_GRADIENT_ID,
  } = options

  const linePoints = buildLinePoints(points, plot)
  const polylinePoints = linePoints.map((point) => `${point.x},${point.y}`).join(' ')
  const parts: string[] = [
    `<defs>
      <linearGradient id="${areaGradientId}" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="${brand.brandCta}" stop-opacity="0.35" />
        <stop offset="100%" stop-color="${brand.brandCta}" stop-opacity="0" />
      </linearGradient>
    </defs>`,
    buildCoverChartGridSvg({ plot, showGrid, brand }),
  ]

  if (showArea && linePoints.length > 1) {
    const baselineY = plot.y + plot.height
    const areaPath = [
      `M ${linePoints[0]!.x} ${baselineY}`,
      ...linePoints.map((point) => `L ${point.x} ${point.y}`),
      `L ${linePoints[linePoints.length - 1]!.x} ${baselineY}`,
      'Z',
    ].join(' ')

    parts.push(`<path d="${areaPath}" fill="url(#${areaGradientId})" />`)
  }

  if (linePoints.length > 1) {
    parts.push(
      `<polyline points="${polylinePoints}" fill="none" stroke="${brand.brandCta}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />`,
    )
  }

  linePoints.forEach((point) => {
    parts.push(
      `<circle cx="${point.x}" cy="${point.y}" r="5" fill="${brand.background}" stroke="${brand.brandCta}" stroke-width="2.5" />`,
    )

    if (showValues && point.value > 0) {
      parts.push(
        `<text class="cover-body" text-anchor="middle" fill="${brand.foreground}" font-size="${COVER_CHART_LAYOUT.valueLabelFontSize}" font-weight="600" x="${point.x}" y="${coverSvgTextBaseline(point.y - 12, COVER_CHART_LAYOUT.valueLabelFontSize)}">${escapeXml(String(Math.round(point.value)))}</text>`,
      )
    }

    if (point.label) {
      parts.push(
        `<text class="cover-body" text-anchor="middle" fill="${brand.mutedForeground}" font-size="${COVER_CHART_LAYOUT.axisLabelFontSize}" x="${point.x}" y="${coverSvgTextBaseline(plot.y + plot.height + 22, COVER_CHART_LAYOUT.axisLabelFontSize)}">${escapeXml(point.label)}</text>`,
      )
    }
  })

  return parts.join('\n')
}
