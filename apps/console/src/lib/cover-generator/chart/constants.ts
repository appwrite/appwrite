import type { CoverRenderData } from '@/lib/cover-generator/types'

export const COVER_CHART = {
  pointCount: { min: 2, max: 12, default: 6 },
} as const

export const COVER_CHART_MAX_POINTS = COVER_CHART.pointCount.max

export const COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT = 80

export const COVER_CHART_DEFAULT_LABELS = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
] as const

export const COVER_CHART_DEFAULT_VALUES = [
  42, 58, 71, 88, 96, 112, 98, 120, 105, 130, 118, 145,
] as const

export type CoverChartLabelKey = `label${number}`
export type CoverChartValueKey = `value${number}`

export function getCoverChartLabelKey(index: number): CoverChartLabelKey {
  return `label${index}`
}

export function getCoverChartValueKey(index: number): CoverChartValueKey {
  return `value${index}`
}

export function getCoverChartLabelKeys(count = COVER_CHART_MAX_POINTS): CoverChartLabelKey[] {
  return Array.from({ length: count }, (_, index) => getCoverChartLabelKey(index))
}

export function getCoverChartValueKeys(count = COVER_CHART_MAX_POINTS): CoverChartValueKey[] {
  return Array.from({ length: count }, (_, index) => getCoverChartValueKey(index))
}

export function buildCoverChartDefaultFieldParams(): Record<string, string> {
  const params: Record<string, string> = {}

  for (let index = 0; index < COVER_CHART_MAX_POINTS; index += 1) {
    params[getCoverChartLabelKey(index)] = COVER_CHART_DEFAULT_LABELS[index] ?? ''
    params[getCoverChartValueKey(index)] = String(
      COVER_CHART_DEFAULT_VALUES[index] ?? 0,
    )
  }

  return params
}

export type CoverChartPoint = {
  label: string
  value: number
}

export type CoverChartCommonFields = {
  title?: string
  subtitle?: string
  frameWidthPercent: number
  pointCount: number
  showGrid: boolean
  showValues: boolean
}

function clampPointCount(value: number | undefined): number {
  if (!Number.isFinite(value)) return COVER_CHART.pointCount.default
  return Math.min(
    COVER_CHART.pointCount.max,
    Math.max(COVER_CHART.pointCount.min, Math.round(value!)),
  )
}

function buildChartPointFields(
  template: 'bar-chart' | 'line-chart',
  data: CoverRenderData,
  pointCount: number,
): Record<CoverChartLabelKey | CoverChartValueKey, string | number> {
  const values =
    data.template === template ? (data as Record<string, string | number | undefined>) : null
  const defaults = buildCoverChartDefaultFieldParams()

  const fields = {} as Record<CoverChartLabelKey | CoverChartValueKey, string | number>

  for (let index = 0; index < pointCount; index += 1) {
    const labelKey = getCoverChartLabelKey(index)
    const valueKey = getCoverChartValueKey(index)
    const rawValue = values?.[valueKey]
    const parsedValue =
      typeof rawValue === 'number'
        ? rawValue
        : Number.parseFloat(String(rawValue ?? defaults[valueKey] ?? 0))

    fields[labelKey] =
      (typeof values?.[labelKey] === 'string' ? values[labelKey]?.trim() : '') ||
      defaults[labelKey] ||
      COVER_CHART_DEFAULT_LABELS[index] ||
      ''

    fields[valueKey] = Number.isFinite(parsedValue)
      ? Math.max(0, parsedValue)
      : COVER_CHART_DEFAULT_VALUES[index] ?? 0
  }

  return fields
}

export function normalizeCoverBarChartData(
  data: CoverRenderData,
): Extract<CoverRenderData, { template: 'bar-chart' }> {
  const chartData = data.template === 'bar-chart' ? data : null
  const pointCount = clampPointCount(chartData?.pointCount)

  return {
    theme: data.theme,
    format: data.format,
    width: data.width,
    height: data.height,
    template: 'bar-chart',
    title: chartData?.title?.trim() || undefined,
    subtitle: chartData?.subtitle?.trim() || undefined,
    frameWidthPercent:
      chartData && Number.isFinite(chartData.frameWidthPercent)
        ? chartData.frameWidthPercent
        : COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT,
    pointCount,
    showGrid: chartData?.showGrid == null ? true : Boolean(chartData.showGrid),
    showValues: chartData?.showValues == null ? false : Boolean(chartData.showValues),
    ...buildChartPointFields('bar-chart', data, pointCount),
  }
}

export function normalizeCoverLineChartData(
  data: CoverRenderData,
): Extract<CoverRenderData, { template: 'line-chart' }> {
  const chartData = data.template === 'line-chart' ? data : null
  const pointCount = clampPointCount(chartData?.pointCount)

  return {
    theme: data.theme,
    format: data.format,
    width: data.width,
    height: data.height,
    template: 'line-chart',
    title: chartData?.title?.trim() || undefined,
    subtitle: chartData?.subtitle?.trim() || undefined,
    frameWidthPercent:
      chartData && Number.isFinite(chartData.frameWidthPercent)
        ? chartData.frameWidthPercent
        : COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT,
    pointCount,
    showGrid: chartData?.showGrid == null ? true : Boolean(chartData.showGrid),
    showValues: chartData?.showValues == null ? false : Boolean(chartData.showValues),
    showArea: chartData?.showArea == null ? true : Boolean(chartData.showArea),
    ...buildChartPointFields('line-chart', data, pointCount),
  }
}

export function getCoverChartPoints(
  data: Extract<CoverRenderData, { template: 'bar-chart' | 'line-chart' }>,
): CoverChartPoint[] {
  const normalized =
    data.template === 'bar-chart'
      ? normalizeCoverBarChartData(data)
      : normalizeCoverLineChartData(data)

  return Array.from({ length: normalized.pointCount }, (_, index) => ({
    label: normalized[getCoverChartLabelKey(index)] ?? '',
    value: Number(normalized[getCoverChartValueKey(index)] ?? 0),
  }))
}
