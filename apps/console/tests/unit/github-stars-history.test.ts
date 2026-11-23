import { describe, expect, test } from 'bun:test'
import {
  buildCumulativeStarHistory,
  downsampleStarHistoryMonthly,
  starHistoryGrowthSince,
  toIsoDateUtc,
} from '@/lib/marketing/github-stars-history'

describe('buildCumulativeStarHistory', () => {
  test('walks newest-first weekly additions back from the live total', () => {
    const asOf = new Date('2026-10-08T12:00:00.000Z')
    const points = buildCumulativeStarHistory(
      100,
      [
        { week: Date.parse('2026-10-04T00:00:00.000Z') / 1000, total: 10 },
        { week: Date.parse('2026-09-27T00:00:00.000Z') / 1000, total: 20 },
      ],
      asOf,
    )

    expect(points).toEqual([
      { date: '2026-09-27', stars: 70 },
      { date: '2026-10-04', stars: 90 },
      { date: '2026-10-08', stars: 100 },
    ])
  })

  test('clamps at zero when weekly totals exceed the live count', () => {
    const points = buildCumulativeStarHistory(
      5,
      [{ week: Date.parse('2026-10-04T00:00:00.000Z') / 1000, total: 12 }],
      new Date('2026-10-08T12:00:00.000Z'),
    )

    expect(points[0]?.stars).toBe(0)
    expect(points.at(-1)?.stars).toBe(5)
  })
})

describe('downsampleStarHistoryMonthly', () => {
  test('keeps the last sample in each month', () => {
    expect(
      downsampleStarHistoryMonthly([
        { date: '2026-09-06', stars: 10 },
        { date: '2026-09-27', stars: 18 },
        { date: '2026-10-04', stars: 22 },
        { date: '2026-10-08', stars: 25 },
      ]),
    ).toEqual([
      { date: '2026-09-27', stars: 18 },
      { date: '2026-10-08', stars: 25 },
    ])
  })

  test('returns an empty list for empty input', () => {
    expect(downsampleStarHistoryMonthly([])).toEqual([])
  })
})

describe('starHistoryGrowthSince', () => {
  test('uses the last sample on or before the cutoff', () => {
    expect(
      starHistoryGrowthSince(
        [
          { date: '2025-09-28', stars: 52000 },
          { date: '2025-10-08', stars: 52200 },
          { date: '2026-09-27', stars: 57447 },
          { date: '2026-10-08', stars: 57597 },
        ],
        365,
      ),
    ).toBe(5397)
  })

  test('returns null without a baseline sample', () => {
    expect(
      starHistoryGrowthSince(
        [
          { date: '2026-09-27', stars: 57447 },
          { date: '2026-10-08', stars: 57597 },
        ],
        365,
      ),
    ).toBeNull()
  })
})

describe('toIsoDateUtc', () => {
  test('formats the UTC calendar day', () => {
    expect(toIsoDateUtc(new Date('2026-10-08T23:15:00.000Z'))).toBe(
      '2026-10-08',
    )
  })
})
