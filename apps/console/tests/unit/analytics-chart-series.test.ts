import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { buildAnalyticsChartPoints } from '@/components/pages/projects/$projectId/analytics/_components/chart-series'

// Built from local dates so the daily bucket grid does not shift by timezone.
const range = {
  startAt: new Date(2026, 9, 1).toISOString(),
  endAt: new Date(2026, 9, 3, 23, 59, 59).toISOString(),
}

function bucket(
  date: string,
  pageviews: number | null,
): Models.AnalyticsMetric {
  return { date, visitors: 1, sessions: 1, events: 7, pageviews }
}

describe('analytics chart series pageviews', () => {
  test('carries a bucket pageview count through', () => {
    const points = buildAnalyticsChartPoints(
      [bucket('2026-10-01', 12)],
      range,
      '1d',
    )
    expect(points[0].pageviews).toBe(12)
  })

  test('zero-fills the buckets the API omits', () => {
    const points = buildAnalyticsChartPoints(
      [bucket('2026-10-01', 12), bucket('2026-10-03', 5)],
      range,
      '1d',
    )
    expect(points.map((point) => point.pageviews)).toEqual([12, 0, 5])
  })

  test('reads a null count as zero', () => {
    const points = buildAnalyticsChartPoints(
      [bucket('2026-10-01', null)],
      range,
      '1d',
    )
    expect(points[0].pageviews).toBe(0)
  })
})
