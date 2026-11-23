import { describe, expect, test } from 'bun:test'
import { endOfDay, startOfDay } from 'date-fns'
import {
  finalizeDateRangeSelection,
  mergeCalendarRangeWithClocks,
  readDayClock,
  rememberDateRangeClocks,
} from '@/lib/usage/usage-date-range'
import {
  inferRollingPresetByDuration,
  inferUsageDateRangePresetFromStoredRange,
} from '@/lib/usage/usage-date-range-presets'

describe('date range clocks', () => {
  test('a new calendar span keeps the times already chosen', () => {
    const merged = mergeCalendarRangeWithClocks(
      {
        from: new Date(2026, 9, 17),
        to: new Date(2026, 9, 18),
      },
      {
        start: readDayClock(new Date(2026, 0, 1, 9, 15, 0, 0)),
        end: readDayClock(new Date(2026, 0, 1, 17, 45, 30, 0)),
      },
    )

    expect(merged?.from?.getDate()).toBe(17)
    expect(merged?.from?.getHours()).toBe(9)
    expect(merged?.from?.getMinutes()).toBe(15)
    expect(merged?.to?.getDate()).toBe(18)
    expect(merged?.to?.getHours()).toBe(17)
    expect(merged?.to?.getMinutes()).toBe(45)
    expect(merged?.to?.getSeconds()).toBe(30)
  })

  test('a date-only span with no saved clock is an inclusive day', () => {
    const merged = mergeCalendarRangeWithClocks(
      {
        from: new Date(2026, 9, 17),
        to: new Date(2026, 9, 17),
      },
      { start: null, end: null },
    )

    expect(merged?.from?.getTime()).toBe(
      startOfDay(new Date(2026, 9, 17)).getTime(),
    )
    expect(merged?.to?.getTime()).toBe(
      endOfDay(new Date(2026, 9, 17)).getTime(),
    )
  })

  test('the end clock survives while the end day is not chosen yet', () => {
    const clocks = rememberDateRangeClocks(
      {
        start: readDayClock(new Date(2026, 9, 1, 9, 0, 0, 0)),
        end: readDayClock(new Date(2026, 9, 1, 17, 0, 0, 0)),
      },
      { from: new Date(2026, 9, 17, 9, 0, 0, 0) },
    )

    expect(clocks.end?.hour).toBe(17)
  })

  test('an explicit 00:00 end stays 00:00', () => {
    const from = new Date(2026, 9, 17, 0, 0, 0, 0)
    const to = new Date(2026, 9, 18, 0, 0, 0, 0)
    const result = finalizeDateRangeSelection({ from, to }, true)

    expect(result?.to?.getTime()).toBe(to.getTime())
  })

  test('a midnight calendar span still expands when time is off', () => {
    const from = new Date(2026, 9, 17, 0, 0, 0, 0)
    const to = new Date(2026, 9, 18, 0, 0, 0, 0)
    const result = finalizeDateRangeSelection({ from, to }, false)

    expect(result?.from?.getTime()).toBe(startOfDay(from).getTime())
    expect(result?.to?.getTime()).toBe(endOfDay(to).getTime())
  })

  test('an end time before the start time is ordered on apply', () => {
    const result = finalizeDateRangeSelection(
      {
        from: new Date(2026, 9, 17, 18, 0, 0, 0),
        to: new Date(2026, 9, 17, 9, 30, 0, 0),
      },
      true,
    )

    expect(result?.from?.getHours()).toBe(9)
    expect(result?.from?.getMinutes()).toBe(30)
    expect(result?.to?.getHours()).toBe(18)
  })
})

describe('rolling preset inference', () => {
  test('a past one-hour window stays a custom range', () => {
    const range = {
      from: new Date(2020, 0, 2, 14, 0, 0, 0),
      to: new Date(2020, 0, 2, 15, 0, 0, 0),
    }

    expect(inferRollingPresetByDuration(range)).toBeNull()
    expect(inferUsageDateRangePresetFromStoredRange(range)).toBeNull()
  })

  test('a one-hour window ending now matches last hour', () => {
    const to = new Date()
    const from = new Date(to.getTime() - 60 * 60 * 1000)

    expect(inferRollingPresetByDuration({ from, to })?.value).toBe('1h')
  })
})
