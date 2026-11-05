import { describe, expect, test } from 'bun:test'
import {
  defaultScheduleDraft,
  isScheduleDraftComplete,
  scheduleDraftFromRule,
  schedulePayload,
  scheduleUpdatePayload,
  todayIn,
  type FirewallScheduleDraft,
  type FirewallSchedulePayload,
} from '@/lib/firewall/schedule'

const TODAY = '2026-11-04'

function draft(
  overrides: Partial<FirewallScheduleDraft> = {},
): FirewallScheduleDraft {
  return {
    ...defaultScheduleDraft('Asia/Kolkata', TODAY),
    enabled: true,
    ...overrides,
  }
}

describe('defaultScheduleDraft', () => {
  test('starts off, on weekday office hours in the given zone, anchored today', () => {
    expect(defaultScheduleDraft('Europe/Berlin', TODAY)).toEqual({
      enabled: false,
      mode: 'weekly',
      days: [1, 2, 3, 4, 5],
      interval: 2,
      anchor: TODAY,
      allDay: false,
      startTime: '09:00',
      endTime: '17:00',
      timezone: 'Europe/Berlin',
    })
  })
})

describe('schedulePayload', () => {
  test('sends nothing while the schedule is off', () => {
    expect(schedulePayload(draft({ enabled: false }))).toBeUndefined()
  })

  test('sends only the fields each mode uses', () => {
    expect(schedulePayload(draft({ mode: 'daily' }))).toEqual({
      mode: 'daily',
      startTime: '09:00',
      endTime: '17:00',
      timezone: 'Asia/Kolkata',
    })
    expect(schedulePayload(draft({ mode: 'weekly', days: [6, 5] }))).toEqual({
      mode: 'weekly',
      days: [5, 6],
      startTime: '09:00',
      endTime: '17:00',
      timezone: 'Asia/Kolkata',
    })
    expect(
      schedulePayload(draft({ mode: 'biweekly', days: [1, 3], allDay: true })),
    ).toEqual({
      mode: 'biweekly',
      days: [1, 3],
      anchor: TODAY,
      timezone: 'Asia/Kolkata',
    })
    expect(schedulePayload(draft({ mode: 'weekends', allDay: true }))).toEqual({
      mode: 'weekends',
      timezone: 'Asia/Kolkata',
    })
    expect(schedulePayload(draft({ mode: 'everyNDays', interval: 3 }))).toEqual(
      {
        mode: 'everyNDays',
        interval: 3,
        anchor: TODAY,
        startTime: '09:00',
        endTime: '17:00',
        timezone: 'Asia/Kolkata',
      },
    )
  })

  test('keeps a window that crosses midnight as entered', () => {
    expect(
      schedulePayload(
        draft({ mode: 'weekends', startTime: '22:00', endTime: '06:00' }),
      ),
    ).toEqual({
      mode: 'weekends',
      startTime: '22:00',
      endTime: '06:00',
      timezone: 'Asia/Kolkata',
    })
  })
})

describe('scheduleUpdatePayload', () => {
  test('removes a stored schedule with an empty object when switched off', () => {
    expect(scheduleUpdatePayload(draft({ enabled: false }), true)).toEqual({})
  })

  test('sends nothing when there was no schedule and it stays off', () => {
    expect(
      scheduleUpdatePayload(draft({ enabled: false }), false),
    ).toBeUndefined()
  })

  test('sends the schedule when it is on', () => {
    expect(scheduleUpdatePayload(draft({ mode: 'daily' }), true)).toEqual({
      mode: 'daily',
      startTime: '09:00',
      endTime: '17:00',
      timezone: 'Asia/Kolkata',
    })
  })
})

describe('scheduleDraftFromRule', () => {
  test('a rule without a schedule gives the default draft, switched off', () => {
    expect(scheduleDraftFromRule(undefined, 'UTC', TODAY)).toEqual(
      defaultScheduleDraft('UTC', TODAY),
    )
    expect(scheduleDraftFromRule(null, 'UTC', TODAY).enabled).toBe(false)
  })

  test('round-trips every mode through the payload', () => {
    const stored: FirewallSchedulePayload[] = [
      {
        mode: 'weekly',
        days: [5, 6],
        startTime: '22:00',
        endTime: '06:00',
        timezone: 'Asia/Kolkata',
      },
      {
        mode: 'biweekly',
        days: [1, 3],
        anchor: '2026-11-02',
        timezone: 'Australia/Sydney',
      },
      {
        mode: 'everyNDays',
        interval: 3,
        anchor: '2026-11-02',
        startTime: '09:00',
        endTime: '17:00',
        timezone: 'Europe/Berlin',
      },
      { mode: 'weekends', timezone: 'Asia/Kolkata' },
      {
        mode: 'daily',
        startTime: '18:00',
        endTime: '00:00',
        timezone: 'Europe/Berlin',
      },
    ]

    for (const schedule of stored) {
      const restored = scheduleDraftFromRule(schedule, 'UTC', TODAY)
      expect(restored.enabled).toBe(true)
      expect(schedulePayload(restored)).toEqual(schedule)
    }
  })

  test('a stored schedule without a timezone is UTC, not the browser zone', () => {
    const restored = scheduleDraftFromRule(
      { mode: 'weekends' },
      'Asia/Kolkata',
      TODAY,
    )
    expect(restored.timezone).toBe('UTC')
    expect(restored.allDay).toBe(true)
  })

  test('fields a mode does not use keep their defaults', () => {
    const restored = scheduleDraftFromRule(
      { mode: 'daily', timezone: 'UTC' },
      'UTC',
      TODAY,
    )
    expect(restored.days).toEqual([1, 2, 3, 4, 5])
    expect(restored.anchor).toBe(TODAY)
    expect(restored.interval).toBe(2)
  })
})

describe('isScheduleDraftComplete', () => {
  test('a schedule that is off is always complete', () => {
    expect(isScheduleDraftComplete(draft({ enabled: false, days: [] }))).toBe(
      true,
    )
  })

  test('weekly and biweekly need at least one day', () => {
    expect(isScheduleDraftComplete(draft({ mode: 'weekly', days: [] }))).toBe(
      false,
    )
    expect(isScheduleDraftComplete(draft({ mode: 'biweekly', days: [] }))).toBe(
      false,
    )
    expect(isScheduleDraftComplete(draft({ mode: 'daily', days: [] }))).toBe(
      true,
    )
  })

  test('every N days needs an interval from 2 to 365', () => {
    for (const [interval, ok] of [
      [1, false],
      [2, true],
      [365, true],
      [366, false],
    ] as const) {
      expect(
        isScheduleDraftComplete(draft({ mode: 'everyNDays', interval })),
      ).toBe(ok)
    }
  })

  test('anchored modes need a YYYY-MM-DD date', () => {
    expect(
      isScheduleDraftComplete(draft({ mode: 'biweekly', anchor: '' })),
    ).toBe(false)
    expect(
      isScheduleDraftComplete(
        draft({ mode: 'everyNDays', anchor: '2026-11-4' }),
      ),
    ).toBe(false)
    expect(isScheduleDraftComplete(draft({ mode: 'weekly', anchor: '' }))).toBe(
      true,
    )
  })

  test('a window needs two different valid times, unless it is all day', () => {
    expect(
      isScheduleDraftComplete(draft({ startTime: '09:00', endTime: '09:00' })),
    ).toBe(false)
    expect(
      isScheduleDraftComplete(draft({ startTime: '', endTime: '17:00' })),
    ).toBe(false)
    expect(
      isScheduleDraftComplete(draft({ startTime: '22:00', endTime: '06:00' })),
    ).toBe(true)
    expect(
      isScheduleDraftComplete(
        draft({ allDay: true, startTime: '', endTime: '' }),
      ),
    ).toBe(true)
  })

  test('dates must be exactly YYYY-MM-DD', () => {
    for (const anchor of [
      '2026-11-04T00:00',
      '20261104',
      '2026-1a-04',
      ' 2026-11-04',
      '2026-11-04 ',
      '2026-11-04-01',
    ]) {
      expect(isScheduleDraftComplete(draft({ mode: 'biweekly', anchor }))).toBe(
        false,
      )
    }
    expect(
      isScheduleDraftComplete(
        draft({ mode: 'biweekly', anchor: '2028-02-29' }),
      ),
    ).toBe(true)
  })

  test('times must be HH:MM on a 24-hour clock', () => {
    for (const startTime of [
      '24:00',
      '23:60',
      '9:00',
      '09:00:00',
      '0a:00',
      ' 09:00',
      '09:00 ',
      '09-00',
    ]) {
      expect(
        isScheduleDraftComplete(draft({ startTime, endTime: '10:00' })),
      ).toBe(false)
    }
    for (const startTime of ['00:00', '23:59', '19:30']) {
      expect(
        isScheduleDraftComplete(draft({ startTime, endTime: '10:00' })),
      ).toBe(true)
    }
  })

  test('needs a timezone', () => {
    expect(isScheduleDraftComplete(draft({ timezone: '' }))).toBe(false)
  })
})

describe('todayIn', () => {
  // 20:00 UTC on 4 Nov is already 5 Nov in Kolkata and still 4 Nov in Los Angeles.
  const now = new Date('2026-11-04T20:00:00Z')

  test("gives the date on the zone's clock", () => {
    expect(todayIn('UTC', now)).toBe('2026-11-04')
    expect(todayIn('Asia/Kolkata', now)).toBe('2026-11-05')
    expect(todayIn('America/Los_Angeles', now)).toBe('2026-11-04')
  })

  test('falls back to the UTC date for a zone the browser does not know', () => {
    expect(todayIn('Mars/Olympus_Mons', now)).toBe('2026-11-04')
  })
})
