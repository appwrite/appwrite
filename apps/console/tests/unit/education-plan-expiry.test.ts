import { describe, expect, it } from 'bun:test'
import {
  canSnoozeEducationPlanReminder,
  EDUCATION_PLAN_DISMISS_MS,
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
  it('reads the expiry Cloud computed', () => {
    expect(getEducationPlanEndsAt('2027-02-25T00:00:00.000+00:00')).toBe(
      Date.UTC(2027, 1, 25),
    )
  })

  it('has no end date without one', () => {
    expect(getEducationPlanEndsAt(undefined)).toBeNull()
    expect(getEducationPlanEndsAt(null)).toBeNull()
    expect(getEducationPlanEndsAt('')).toBeNull()
  })

  it('ignores an invalid expiry', () => {
    expect(getEducationPlanEndsAt('not a date')).toBeNull()
  })
})
