import { describe, expect, test } from 'bun:test'
import {
  getUsageChartIntervalDisabledReasonDetails,
  resolveUsageChartIntervalForRange,
} from '@/lib/usage/chart-interval'

function rangeMinutes(minutes: number) {
  const from = new Date(2026, 9, 17, 18, 0, 0, 0)
  return {
    from,
    to: new Date(from.getTime() + minutes * 60 * 1000),
  }
}

describe('usage chart interval for short ranges', () => {
  test('a range under 15 minutes drops 15m to 1m', () => {
    expect(resolveUsageChartIntervalForRange('15m', rangeMinutes(10))).toBe(
      '1m',
    )
  })

  test('a range under 15 minutes drops coarser intervals to 1m', () => {
    expect(resolveUsageChartIntervalForRange('1h', rangeMinutes(8))).toBe('1m')
    expect(resolveUsageChartIntervalForRange('1d', rangeMinutes(1))).toBe('1m')
  })

  test('a 15 minute range keeps 15m', () => {
    expect(resolveUsageChartIntervalForRange('15m', rangeMinutes(15))).toBe(
      '15m',
    )
  })

  test('a wider range does not refine 15m down to 1m', () => {
    expect(resolveUsageChartIntervalForRange('15m', rangeMinutes(20))).toBe(
      '15m',
    )
    expect(
      resolveUsageChartIntervalForRange('15m', rangeMinutes(24 * 60)),
    ).toBe('15m')
  })

  test('saving a short range can keep the coarser interval', () => {
    expect(
      resolveUsageChartIntervalForRange('15m', rangeMinutes(10), undefined, {
        refine: false,
      }),
    ).toBe('15m')
  })

  test('a plan without 1m keeps 15m on a short range', () => {
    expect(
      resolveUsageChartIntervalForRange('15m', rangeMinutes(10), {
        usageLogsIntervals: ['15m', '1h', '1d'],
      }),
    ).toBe('15m')
  })

  test('coarser intervals are disabled while the range needs 1m', () => {
    const range = rangeMinutes(10)
    expect(getUsageChartIntervalDisabledReasonDetails('15m', range)).toEqual({
      kind: 'short',
    })
    expect(
      getUsageChartIntervalDisabledReasonDetails('1m', range),
    ).toBeUndefined()
    expect(
      getUsageChartIntervalDisabledReasonDetails('15m', range, [
        '15m',
        '1h',
        '1d',
      ]),
    ).toBeUndefined()
  })
})
