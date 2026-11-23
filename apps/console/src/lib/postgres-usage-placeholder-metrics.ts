export type MockUsageMetricSeriesPoint = {
  timestamp: number
  value: number
  secondaryValue?: number
}

export const POSTGRES_USAGE_PLACEHOLDER_NOTE =
  'Sample data only. Instance metrics will be provided by the usage service.'

const DEFAULT_POINT_COUNT = 48

/**
 * Deterministic mock time series for Postgres monitor placeholders until the
 * usage service exposes instance-level CPU, memory, disk I/O, and network stats.
 */
export function buildMockUsageMetricSeries(
  fromMs: number,
  toMs: number,
  phase: number,
  base: number,
  amplitude: number,
  options?: {
    pointCount?: number
    refreshEpoch?: number
    secondaryBase?: number
    secondaryAmplitude?: number
  },
): MockUsageMetricSeriesPoint[] {
  const pointCount = options?.pointCount ?? DEFAULT_POINT_COUNT
  const refreshEpoch = options?.refreshEpoch ?? 0
  const span = Math.max(toMs - fromMs, 60_000)

  return Array.from({ length: pointCount }, (_, index) => {
    const t = index / Math.max(pointCount - 1, 1)
    const timestamp = Math.round(fromMs + span * t)
    const wave =
      Math.sin(phase + refreshEpoch * 0.01 + t * Math.PI * 2) * amplitude
    const jitter =
      Math.sin(phase * 1.7 + refreshEpoch * 0.02 + index * 0.9) *
      amplitude *
      0.15
    const value = Math.max(0, base + wave + jitter)

    let secondaryValue: number | undefined
    if (options?.secondaryBase != null && options?.secondaryAmplitude != null) {
      const secondaryWave =
        Math.sin(phase * 0.8 + refreshEpoch * 0.015 + t * Math.PI * 1.6) *
        options.secondaryAmplitude
      secondaryValue = Math.max(
        0,
        options.secondaryBase + secondaryWave + jitter * 0.6,
      )
    }

    return {
      timestamp,
      value,
      secondaryValue,
    }
  })
}

export function latestMockSeriesValue(
  series: MockUsageMetricSeriesPoint[],
): number | null {
  const last = series[series.length - 1]
  return last && Number.isFinite(last.value) ? last.value : null
}
