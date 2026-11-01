import { describe, expect, it } from 'bun:test'
import {
  canSnoozeEducationPlanReminder,
  EDUCATION_PLAN_DISMISS_MS,
  EDUCATION_PLAN_EARLIEST_END_AT,
  getEducationPlanEndsAt,
  isEducationPlanReminderSnoozed,
} from '@/lib/education/plan-expiry'

const DAY_MS = 24 * 60 * 60 * 1000

describe('education plan reminder snooze', () => {
  const now = Date.UTC(2026, 10, 1)

  it('hides the reminder for one day', () => {
    expect(EDUCATION_PLAN_DISMISS_MS).toBe(DAY_MS)
  })

  it('allows Remind me later on the last day', () => {
    expect(canSnoozeEducationPlanReminder(now + 12 * 60 * 60 * 1000, now)).toBe(
      true,
    )
  })

  it('blocks Remind me later once the plan has ended', () => {
    expect(canSnoozeEducationPlanReminder(now, now)).toBe(false)
    expect(canSnoozeEducationPlanReminder(now - 1, now)).toBe(false)
  })

  it('shows the reminder again after a one-day dismiss', () => {
    const endsAt = now + 5 * DAY_MS
    const dismissedUntil = now + EDUCATION_PLAN_DISMISS_MS

    expect(isEducationPlanReminderSnoozed(endsAt, dismissedUntil, now)).toBe(
      true,
    )
    expect(
      isEducationPlanReminderSnoozed(endsAt, dismissedUntil, dismissedUntil),
    ).toBe(false)
  })

  it('ignores a stored dismiss after the plan ends', () => {
    expect(
      isEducationPlanReminderSnoozed(now, now + EDUCATION_PLAN_DISMISS_MS, now),
    ).toBe(false)
  })
})

describe('education plan end date', () => {
  const createdAt = '2026-08-15T00:00:00.000+00:00'
  const sixMonths = Date.UTC(2027, 1, 15)

  it('ends six months after creation without an extension', () => {
    expect(getEducationPlanEndsAt(createdAt)).toBe(sixMonths)
    expect(getEducationPlanEndsAt(createdAt, '')).toBe(sixMonths)
  })

  it('ends at the extension when it is later', () => {
    expect(
      getEducationPlanEndsAt(createdAt, '2027-03-01T00:00:00.000+00:00'),
    ).toBe(Date.UTC(2027, 2, 1))
  })

  it('ignores an extension earlier than six months or November 1, 2026', () => {
    expect(
      getEducationPlanEndsAt(createdAt, '2027-01-01T00:00:00.000+00:00'),
    ).toBe(sixMonths)
    expect(
      getEducationPlanEndsAt(
        '2025-01-01T00:00:00.000+00:00',
        '2026-10-01T00:00:00.000+00:00',
      ),
    ).toBe(EDUCATION_PLAN_EARLIEST_END_AT)
  })

  it('ignores an invalid extension', () => {
    expect(getEducationPlanEndsAt(createdAt, 'not a date')).toBe(sixMonths)
  })
})
