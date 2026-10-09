import { describe, expect, test } from 'bun:test'
import { subHours, subMinutes } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import {
  getUsageChartIntervalDisabledReasonDetails,
  isUsageChartIntervalTooCoarseForRange,
  isUsageChartIntervalTooFineForRange,
  isUsageChartIntervalValidForRange,
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'

function rangeEndingNow(from: Date): DateRange {
  return { from, to: new Date() }
}

describe('chart interval vs date range', () => {
  test('rejects intervals that are not smaller than the range', () => {
    const lastHour = rangeEndingNow(subHours(new Date(), 1))

    expect(isUsageChartIntervalTooCoarseForRange('1m', lastHour)).toBe(false)
    expect(isUsageChartIntervalTooCoarseForRange('15m', lastHour)).toBe(false)
    expect(isUsageChartIntervalTooCoarseForRange('1h', lastHour)).toBe(true)
    expect(isUsageChartIntervalTooCoarseForRange('1d', lastHour)).toBe(true)
    expect(isUsageChartIntervalValidForRange('15m', lastHour)).toBe(true)
    expect(isUsageChartIntervalValidForRange('1h', lastHour)).toBe(false)
  })

  test('still rejects intervals that are too fine for a wide range', () => {
    const lastWeek = rangeEndingNow(subHours(new Date(), 24 * 7))

    expect(isUsageChartIntervalTooFineForRange('1m', lastWeek)).toBe(true)
    expect(isUsageChartIntervalTooFineForRange('1h', lastWeek)).toBe(false)
    expect(isUsageChartIntervalValidForRange('1m', lastWeek)).toBe(false)
    expect(isUsageChartIntervalValidForRange('1h', lastWeek)).toBe(true)
    expect(isUsageChartIntervalValidForRange('1d', lastWeek)).toBe(true)
  })

  test('steps down to a smaller interval when the range shrinks', () => {
    const lastHour = rangeEndingNow(subHours(new Date(), 1))

    expect(resolveUsageChartIntervalForRange('1d', lastHour)).toBe('15m')
    expect(resolveUsageChartIntervalForRange('1h', lastHour)).toBe('15m')
    expect(resolveUsageChartIntervalForRange('15m', lastHour)).toBe('15m')
    expect(resolveUsageChartIntervalForRange('1m', lastHour)).toBe('1m')
  })

  test('steps up to a coarser interval when the range widens', () => {
    const lastWeek = rangeEndingNow(subHours(new Date(), 24 * 7))

    expect(resolveUsageChartIntervalForRange('1m', lastWeek)).toBe('1h')
    expect(resolveUsageChartIntervalForRange('15m', lastWeek)).toBe('1h')
    expect(resolveUsageChartIntervalForRange('1h', lastWeek)).toBe('1h')
  })

  test('disables bigger intervals when a smaller choice exists', () => {
    const lastHour = rangeEndingNow(subHours(new Date(), 1))
    const all: UsageChartInterval[] = ['1m', '15m', '1h', '1d']

    expect(
      getUsageChartIntervalDisabledReasonDetails('1m', lastHour, all),
    ).toBeUndefined()
    expect(
      getUsageChartIntervalDisabledReasonDetails('15m', lastHour, all),
    ).toBeUndefined()
    expect(
      getUsageChartIntervalDisabledReasonDetails('1h', lastHour, all),
    ).toEqual({ kind: 'largerThanRange' })
    expect(
      getUsageChartIntervalDisabledReasonDetails('1d', lastHour, all),
    ).toEqual({ kind: 'largerThanRange' })
  })

  test('keeps the smallest interval enabled when nothing smaller exists', () => {
    const lastThirtyMinutes = rangeEndingNow(subMinutes(new Date(), 30))
    const hourlyOnly: UsageChartInterval[] = ['1h', '1d']

    expect(
      getUsageChartIntervalDisabledReasonDetails(
        '1h',
        lastThirtyMinutes,
        hourlyOnly,
      ),
    ).toBeUndefined()
    expect(
      getUsageChartIntervalDisabledReasonDetails(
        '1d',
        lastThirtyMinutes,
        hourlyOnly,
      ),
    ).toEqual({ kind: 'largerThanRange' })
    expect(
      resolveUsageChartIntervalForRange('1d', lastThirtyMinutes, {
        usageLogsIntervals: hourlyOnly,
      }),
    ).toBe('1h')
  })
})
