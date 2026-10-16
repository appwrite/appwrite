import { describe, expect, test } from 'bun:test'
import {
  formatInterval,
  getFunctionIntervalMinimum,
  getFunctionScheduleMode,
  isCronAllowed,
  isCronScheduleAllowed,
  isIntervalAllowed,
  splitInterval,
} from '@/lib/function-interval'

const english = (text: string) => text

describe('splitInterval', () => {
  test('uses the largest whole unit', () => {
    expect(splitInterval(45)).toEqual({ value: 45, unit: 'minutes' })
    expect(splitInterval(360)).toEqual({ value: 6, unit: 'hours' })
    expect(splitInterval(2880)).toEqual({ value: 2, unit: 'days' })
    expect(splitInterval(90)).toEqual({ value: 90, unit: 'minutes' })
  })
})

describe('formatInterval', () => {
  test('names single units', () => {
    expect(formatInterval(1, english)).toBe('Every minute')
    expect(formatInterval(60, english)).toBe('Every hour')
    expect(formatInterval(1440, english)).toBe('Every day')
  })

  test('counts multiple units', () => {
    expect(formatInterval(5, english)).toBe('Every 5 minutes')
    expect(formatInterval(360, english)).toBe('Every 6 hours')
    expect(formatInterval(10080, english)).toBe('Every 7 days')
  })

  test('translates before filling in the count', () => {
    const japanese = (text: string) =>
      text === 'Every {count} hours' ? '{count} 時間ごと' : text
    expect(formatInterval(720, japanese)).toBe('12 時間ごと')
  })

  test('treats zero as disabled', () => {
    expect(formatInterval(0, english)).toBe('Disabled')
  })
})

describe('getFunctionIntervalMinimum', () => {
  test('reads the plan minimum', () => {
    expect(getFunctionIntervalMinimum({ functionsIntervalMinimum: 60 })).toBe(
      60,
    )
    expect(getFunctionIntervalMinimum({ functionsIntervalMinimum: 60n })).toBe(
      60,
    )
  })

  test('allows every interval without a plan minimum', () => {
    expect(getFunctionIntervalMinimum({ functionsIntervalMinimum: 0 })).toBe(0)
    expect(getFunctionIntervalMinimum({})).toBe(0)
    expect(getFunctionIntervalMinimum(undefined)).toBe(0)
  })
})

describe('isIntervalAllowed', () => {
  test('rejects intervals below the plan minimum', () => {
    expect(isIntervalAllowed(30, 60, 0)).toBe(false)
    expect(isIntervalAllowed(60, 60, 0)).toBe(true)
    expect(isIntervalAllowed(5, 0, 0)).toBe(true)
  })

  test('keeps the stored interval after a downgrade', () => {
    expect(isIntervalAllowed(5, 60, 5)).toBe(true)
    expect(isIntervalAllowed(15, 60, 5)).toBe(false)
  })

  test('rejects empty and fractional intervals', () => {
    expect(isIntervalAllowed(0, 0, 0)).toBe(false)
    expect(isIntervalAllowed(1.5, 0, 0)).toBe(false)
  })
})

describe('getFunctionScheduleMode', () => {
  test('derives the mode from the stored function', () => {
    expect(getFunctionScheduleMode({ interval: 60, schedule: '' })).toBe(
      'interval',
    )
    expect(
      getFunctionScheduleMode({ interval: 0, schedule: '0 * * * *' }),
    ).toBe('cron')
    expect(getFunctionScheduleMode({ schedule: '' })).toBe('none')
  })
})

describe('isCronAllowed', () => {
  test('refuses plans that turn cron off', () => {
    expect(isCronAllowed({ functionsAllowCron: false }, true)).toBe(false)
  })

  test('allows plans that turn cron on or predate the flag', () => {
    expect(isCronAllowed({ functionsAllowCron: true }, true)).toBe(true)
    expect(isCronAllowed({ functionsIntervalMinimum: 0 }, true)).toBe(true)
  })

  test('refuses while the plan is still loading', () => {
    expect(isCronAllowed(undefined, true)).toBe(false)
    expect(isCronAllowed(null, true)).toBe(false)
  })

  test('refuses the fallback plan a failed request leaves on a billing console', () => {
    expect(isCronAllowed({ $id: 'self-hosted' }, true)).toBe(false)
  })

  test('allows the self-hosted plan on a self-hosted console', () => {
    expect(isCronAllowed({ $id: 'self-hosted' }, false)).toBe(true)
  })
})

describe('isCronScheduleAllowed', () => {
  test('keeps the stored cron saveable after a downgrade', () => {
    expect(isCronScheduleAllowed('0 * * * *', false, '0 * * * *')).toBe(true)
  })

  test('refuses a new or changed cron when the plan does not allow cron', () => {
    expect(isCronScheduleAllowed('0 * * * *', false, '')).toBe(false)
    expect(isCronScheduleAllowed('*/5 * * * *', false, '0 * * * *')).toBe(false)
  })

  test('requires an expression', () => {
    expect(isCronScheduleAllowed(' ', true, '')).toBe(false)
    expect(isCronScheduleAllowed('*/5 * * * *', true, '')).toBe(true)
  })
})
