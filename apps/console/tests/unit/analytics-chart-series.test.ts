import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import {
  buildAnalyticsChartPoints,
  groupAnnotationsByBucket,
} from '@/components/pages/projects/$projectId/analytics/_components/chart-series'

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

describe('analytics chart annotations', () => {
  const points = buildAnalyticsChartPoints([], range, '1d')
  const note = (id: string, date: Date) => ({
    $id: id,
    date: date.toISOString(),
  })

  test('places a note on the bucket its date falls in', () => {
    const groups = groupAnnotationsByBucket(points, [
      note('a', new Date(2026, 9, 2, 15, 30)),
    ])
    expect([...groups.keys()]).toEqual([1])
  })

  test('shares one marker between notes in the same bucket', () => {
    const groups = groupAnnotationsByBucket(points, [
      note('a', new Date(2026, 9, 3, 1)),
      note('b', new Date(2026, 9, 3, 22)),
    ])
    expect(groups.get(2)?.map((item) => item.$id)).toEqual(['a', 'b'])
  })

  test('drops notes outside the grid', () => {
    const groups = groupAnnotationsByBucket(points, [
      note('before', new Date(2026, 8, 30, 23)),
      note('after', new Date(2026, 9, 4, 0, 30)),
    ])
    expect(groups.size).toBe(0)
  })
})
