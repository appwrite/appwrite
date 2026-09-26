import { filterBillingPlansByLocation } from '@/lib/pricing/start-plan'
import {
  getPlanCanonicalFromRecord,
  type BillingPlanRecord,
} from '@/lib/utils/plan-filter'

/** Education organizations move off the program at this instant. */
export const EDUCATION_PROGRAM_ENDS_AT = Date.UTC(2026, 10, 1)

export const EDUCATION_SUNSET_DISMISS_MS = 24 * 60 * 60 * 1000

const DISMISS_STORAGE_PREFIX = 'console.educationSunset.dismissedUntil.'

export function educationSunsetDismissStorageKey(organizationId: string): string {
  return `${DISMISS_STORAGE_PREFIX}${organizationId}`
}

export function isEducationProgramEnded(now = Date.now()): boolean {
  return now >= EDUCATION_PROGRAM_ENDS_AT
}

/** Whole days remaining until the program ends. Zero once the cutoff has passed. */
export function daysUntilEducationProgramEnds(now = Date.now()): number {
  const remaining = EDUCATION_PROGRAM_ENDS_AT - now
  if (remaining <= 0) return 0
  return Math.ceil(remaining / (24 * 60 * 60 * 1000))
}

/**
 * A dismiss hides the curtain for 24 hours. After the program ends, a stored
 * dismiss no longer counts.
 */
export function isEducationSunsetSnoozed(
  dismissedUntil: number | null,
  now = Date.now(),
): boolean {
  if (isEducationProgramEnded(now)) return false
  return dismissedUntil != null && dismissedUntil > now
}

export function readEducationSunsetDismissedUntil(
  organizationId: string,
): number | null {
  if (typeof localStorage === 'undefined') return null
  try {
    const raw = localStorage.getItem(
      educationSunsetDismissStorageKey(organizationId),
    )
    if (!raw) return null
    const value = Number(raw)
    return Number.isFinite(value) ? value : null
  } catch {
    return null
  }
}

export function writeEducationSunsetDismissedUntil(
  organizationId: string,
  dismissedUntil: number,
): void {
  if (typeof localStorage === 'undefined') return
  try {
    localStorage.setItem(
      educationSunsetDismissStorageKey(organizationId),
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
 * Plans an Education organization can move to. Same country gate as the
 * upgrade wizard, without the Education plan itself.
 */
export function listEducationTransitionPlans<T extends TransitionPlan>(
  plans: Record<string, T> | null | undefined,
  countryCode: string | null | undefined,
): Array<[string, T]> {
  const filtered = filterBillingPlansByLocation(
    plans,
    countryCode,
    null,
  ) as Record<string, T>

  return Object.entries(filtered)
    .filter((entry): entry is [string, T] => {
      const [planId, plan] = entry
      if (!plan) return false
      return getPlanCanonicalFromRecord(planId, filtered) !== 'education'
    })
    .sort((a, b) => (a[1].order ?? 999) - (b[1].order ?? 999))
}
