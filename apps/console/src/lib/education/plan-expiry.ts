import {
  getPlanCanonicalFromRecord,
  type BillingPlanRecord,
} from '@/lib/utils/plan-filter'

/** An Education organization is covered for this many months after it is created. */
export const EDUCATION_PLAN_DURATION_MONTHS = 6

/** No Education organization is disabled before this instant, however old it is. */
export const EDUCATION_PLAN_EARLIEST_END_AT = Date.UTC(2026, 10, 1)

/** The curtain starts reminding the team this long before the plan ends. */
export const EDUCATION_PLAN_REMINDER_MS = 5 * 7 * 24 * 60 * 60 * 1000

const DAY_MS = 24 * 60 * 60 * 1000

/**
 * "Remind me later" hides the reminder this long. It is only offered while
 * more time than this remains, so a snooze never runs into the end date.
 */
export const EDUCATION_PLAN_DISMISS_MS = 2 * DAY_MS
const DISMISS_STORAGE_PREFIX = 'console.educationPlanExpiry.dismissedUntil.'

export function educationPlanDismissStorageKey(organizationId: string): string {
  return `${DISMISS_STORAGE_PREFIX}${organizationId}`
}

/**
 * When the Education plan ends for an organization created at `createdAt`:
 * 6 months after creation, but never before November 1, 2026.
 */
export function getEducationPlanEndsAt(
  createdAt: string | null | undefined,
): number | null {
  if (!createdAt) return null
  const created = new Date(createdAt)
  if (Number.isNaN(created.getTime())) return null
  const endsAt = new Date(created)
  endsAt.setUTCMonth(endsAt.getUTCMonth() + EDUCATION_PLAN_DURATION_MONTHS)
  return Math.max(endsAt.getTime(), EDUCATION_PLAN_EARLIEST_END_AT)
}

export function isEducationPlanEnded(
  endsAt: number,
  now = Date.now(),
): boolean {
  return now >= endsAt
}

export function isEducationPlanReminderDue(
  endsAt: number,
  now = Date.now(),
): boolean {
  return now >= endsAt - EDUCATION_PLAN_REMINDER_MS
}

/** Whole days remaining until the plan ends. Zero once it has ended. */
export function daysUntilEducationPlanEnds(
  endsAt: number,
  now = Date.now(),
): number {
  const remaining = endsAt - now
  if (remaining <= 0) return 0
  return Math.ceil(remaining / DAY_MS)
}

/** False in the last days before the plan ends and once it has ended. */
export function canSnoozeEducationPlanReminder(
  endsAt: number,
  now = Date.now(),
): boolean {
  return endsAt - now > EDUCATION_PLAN_DISMISS_MS
}

/**
 * A dismiss hides the reminder for 2 days. In the last days and once the plan
 * ends, a stored dismiss no longer counts.
 */
export function isEducationPlanReminderSnoozed(
  endsAt: number,
  dismissedUntil: number | null,
  now = Date.now(),
): boolean {
  if (!canSnoozeEducationPlanReminder(endsAt, now)) return false
  return dismissedUntil != null && dismissedUntil > now
}

export function readEducationPlanDismissedUntil(
  organizationId: string,
): number | null {
  if (typeof localStorage === 'undefined') return null
  try {
    const raw = localStorage.getItem(
      educationPlanDismissStorageKey(organizationId),
    )
    if (!raw) return null
    const value = Number(raw)
    return Number.isFinite(value) ? value : null
  } catch {
    return null
  }
}

export function writeEducationPlanDismissedUntil(
  organizationId: string,
  dismissedUntil: number,
): void {
  if (typeof localStorage === 'undefined') return
  try {
    localStorage.setItem(
      educationPlanDismissStorageKey(organizationId),
      String(dismissedUntil),
    )
  } catch {
    // Private mode can reject storage. The curtain stays up.
  }
}

type TransitionPlan = BillingPlanRecord & {
  desc?: string
  description?: string
}

/**
 * Plans an Education organization can move to: the upgrade wizard's selectable
 * plans (from `useSelectableBillingPlans`), without the Education plan itself.
 */
export function listEducationTransitionPlans<T extends TransitionPlan>(
  selectablePlans: Record<string, T> | null | undefined,
): Array<[string, T]> {
  if (!selectablePlans) return []
  return Object.entries(selectablePlans)
    .filter((entry): entry is [string, T] => {
      const [planId, plan] = entry
      if (!plan) return false
      return getPlanCanonicalFromRecord(planId, selectablePlans) !== 'education'
    })
    .sort((a, b) => (a[1].order ?? 999) - (b[1].order ?? 999))
}
