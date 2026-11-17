import { afterEach, describe, expect, test } from 'bun:test'
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
    const groups = groupAnnotationsByBucket(
      points,
      [note('a', new Date(2026, 9, 2, 15, 30))],
      '1d',
    )
    expect([...groups.keys()]).toEqual([1])
  })

  test('shares one marker between notes in the same bucket', () => {
    const groups = groupAnnotationsByBucket(
      points,
      [note('a', new Date(2026, 9, 3, 1)), note('b', new Date(2026, 9, 3, 22))],
      '1d',
    )
    expect(groups.get(2)?.map((item) => item.$id)).toEqual(['a', 'b'])
  })

  test('drops notes outside the grid', () => {
    const groups = groupAnnotationsByBucket(
      points,
      [
        note('before', new Date(2026, 8, 30, 23)),
        note('after', new Date(2026, 9, 4, 0, 30)),
      ],
      '1d',
    )
    expect(groups.size).toBe(0)
  })

  test('ends a one-bucket grid at the bucket, not later', () => {
    const grid = buildAnalyticsChartPoints(
      [],
      {
        startAt: new Date(2026, 9, 1, 10).toISOString(),
        endAt: new Date(2026, 9, 1, 10, 59).toISOString(),
      },
      '1h',
    )
    const groups = groupAnnotationsByBucket(
      grid,
      [
        note('inside', new Date(2026, 9, 1, 10, 45)),
        note('later', new Date(2026, 11, 1)),
      ],
      '1h',
    )
    expect(grid).toHaveLength(1)
    expect(groups.get(0)?.map((item) => item.$id)).toEqual(['inside'])
  })
})

// Last: changing the zone mid-file would shift the local-date grids above.
describe('analytics chart annotations across DST', () => {
  const timeZone = process.env.TZ
  afterEach(() => {
    if (timeZone === undefined) delete process.env.TZ
    else process.env.TZ = timeZone
  })

  const note = (id: string, date: Date) => ({
    $id: id,
    date: date.toISOString(),
  })

  test('keeps the last hour of a day after a 23-hour DST day', () => {
    process.env.TZ = 'Europe/Berlin'
    const grid = buildAnalyticsChartPoints(
      [],
      {
        startAt: new Date(2026, 2, 29).toISOString(),
        endAt: new Date(2026, 2, 30, 23, 59, 59).toISOString(),
      },
      '1d',
    )
    const groups = groupAnnotationsByBucket(
      grid,
      [note('late', new Date(2026, 2, 30, 23, 30))],
      '1d',
    )
    expect([...groups.keys()]).toEqual([1])
  })
})
