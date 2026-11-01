import { describe, expect, test } from 'bun:test'
import { endOfDay, startOfDay } from 'date-fns'
import {
  getUsageChartIntervalDisabledReasonDetails,
  resolveUsageChartIntervalForRange,
  resolveUsageChartIntervalWithin,
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

  test('a 15 minute range drops 15m to 1m', () => {
    expect(resolveUsageChartIntervalForRange('15m', rangeMinutes(15))).toBe(
      '1m',
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

describe('usage chart interval shorter than the range', () => {
  const today = {
    from: startOfDay(new Date(2026, 9, 29)),
    to: endOfDay(new Date(2026, 9, 29)),
  }
  const analyticsPlan = { usageLogsIntervals: ['1h', '1d'] }

  test('1d is disabled for a single day', () => {
    expect(getUsageChartIntervalDisabledReasonDetails('1d', today)).toEqual({
      kind: 'coarse',
    })
    expect(
      getUsageChartIntervalDisabledReasonDetails('1d', rangeMinutes(24 * 60)),
    ).toEqual({ kind: 'coarse' })
    expect(
      getUsageChartIntervalDisabledReasonDetails('1h', today),
    ).toBeUndefined()
  })

  test('1d over a single day resolves to 1h', () => {
    expect(resolveUsageChartIntervalForRange('1d', today)).toBe('1h')
    expect(resolveUsageChartIntervalForRange('1d', rangeMinutes(24 * 60))).toBe(
      '1h',
    )
    expect(resolveUsageChartIntervalForRange('1d', today, analyticsPlan)).toBe(
      '1h',
    )
  })

  test('1d stays available for two days', () => {
    expect(
      getUsageChartIntervalDisabledReasonDetails('1d', rangeMinutes(48 * 60)),
    ).toBeUndefined()
    expect(resolveUsageChartIntervalForRange('1d', rangeMinutes(48 * 60))).toBe(
      '1d',
    )
  })

  test('the last hour drops 1h to 15m', () => {
    expect(resolveUsageChartIntervalForRange('1h', rangeMinutes(60))).toBe(
      '15m',
    )
    expect(
      getUsageChartIntervalDisabledReasonDetails('1h', rangeMinutes(60)),
    ).toEqual({ kind: 'coarse' })
  })

  test('the finest allowed interval is never too coarse', () => {
    expect(
      getUsageChartIntervalDisabledReasonDetails('1h', rangeMinutes(60), [
        '1h',
        '1d',
      ]),
    ).toBeUndefined()
    expect(
      resolveUsageChartIntervalForRange('1d', rangeMinutes(60), analyticsPlan),
    ).toBe('1h')
  })
})

describe('usage chart interval within a fixed set', () => {
  const hourlyAndDaily = ['1h', '1d'] as const

  test('a single day reads hourly', () => {
    expect(
      resolveUsageChartIntervalWithin(
        '1d',
        rangeMinutes(24 * 60),
        hourlyAndDaily,
      ),
    ).toBe('1h')
  })

  test('the last hour keeps 1h, the finest interval in the set', () => {
    expect(
      resolveUsageChartIntervalWithin('1h', rangeMinutes(60), hourlyAndDaily),
    ).toBe('1h')
    expect(
      resolveUsageChartIntervalWithin('1d', rangeMinutes(10), hourlyAndDaily),
    ).toBe('1h')
  })

  test('ranges over 31 days read daily', () => {
    expect(
      resolveUsageChartIntervalWithin(
        '1h',
        rangeMinutes(40 * 24 * 60),
        hourlyAndDaily,
      ),
    ).toBe('1d')
  })
})
