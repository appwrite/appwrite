/**
 * Client-side helpers for the project's periodic password change (rotation)
 * policy. Mirrors `PATCH /v1/project/policies/password-rotation`, which the
 * API enforces only on `POST /v1/account/sessions/email`.
 */

import type { Models } from '@appwrite.io/console'
import {
  createTimeUnitPair,
  fromSeconds,
  toSeconds,
  type TimeUnit,
  type TimeUnitPair,
} from '@/lib/utils/time-unit-converter'

/** The API's `Range(86400, 315360000)` on `maxAge`: 1 day. */
export const MIN_PASSWORD_MAX_AGE_SECONDS = 86_400
/** The other end of that range: 10 years. */
export const MAX_PASSWORD_MAX_AGE_SECONDS = 315_360_000
/** What the API stores when `maxAge` was never set: 1 year. */
export const DEFAULT_PASSWORD_MAX_AGE_SECONDS = 31_536_000

/** A maximum age under a day is refused by the API, so sub-day units are left out. */
export const PASSWORD_MAX_AGE_UNITS: TimeUnit[] = [
  'years',
  'months',
  'weeks',
  'days',
]

/**
 * How precisely the value field shows a maximum age converted to another unit:
 * two decimals, since 1 year is 12.17 months and no fewer digits would do.
 */
const UNIT_DISPLAY_DECIMALS = 100

export type PasswordRotationPolicy = {
  /** Require a password reset once the password is older than `maxAge`. */
  enabled: boolean
  /** Maximum password age in seconds. */
  maxAge: number
}

/**
 * The project's rotation policy, or null when the server reports none, which is
 * what hides the card on a server from before the policy existed. `maxAge` only
 * exists on this policy, so it doubles as the type guard.
 */
export function parsePasswordRotationPolicy(
  policy: Models.PolicyList['policies'][number] | undefined,
): PasswordRotationPolicy | null {
  if (!policy || !('maxAge' in policy)) return null
  return {
    enabled: policy.enabled ?? false,
    maxAge: policy.maxAge ?? DEFAULT_PASSWORD_MAX_AGE_SECONDS,
  }
}

/** Keeps a converted value readable instead of a long floating point tail. */
function roundToUnitPrecision(value: number): number {
  return Math.round(value * UNIT_DISPLAY_DECIMALS) / UNIT_DISPLAY_DECIMALS
}

/** The value and unit to show a stored maximum age in (86400s reads as 1 day). */
export function maxAgeUnitPair(seconds: number): TimeUnitPair {
  return createTimeUnitPair(seconds, { units: PASSWORD_MAX_AGE_UNITS })
}

/** The largest whole value this unit can hold without passing 10 years. */
export function maxAgeValueForUnit(unit: TimeUnit): number {
  return Math.floor(fromSeconds(MAX_PASSWORD_MAX_AGE_SECONDS, unit))
}

/**
 * The maximum age as the value field shows it in this unit. The card keeps the
 * age in seconds, so switching unit only changes how the same age is written
 * and two decimals are enough to read it by.
 */
export function maxAgeValueInUnit(seconds: number, unit: TimeUnit): number {
  return roundToUnitPrecision(fromSeconds(seconds, unit))
}

/** The maximum age a typed value means, at the precision the field can show. */
export function maxAgeSecondsFromValue(value: number, unit: TimeUnit): number {
  return toSeconds(roundToUnitPrecision(value), unit)
}

export type PasswordMaxAgeRangeError = 'below-min' | 'above-max' | null

/**
 * Whether the maximum age is outside the range the API accepts. It is only sent
 * while the policy is on, so a value left behind by a disabled policy never
 * blocks the toggle.
 */
export function passwordMaxAgeRangeError(
  enabled: boolean,
  maxAgeSeconds: number,
): PasswordMaxAgeRangeError {
  if (!enabled) return null
  if (maxAgeSeconds < MIN_PASSWORD_MAX_AGE_SECONDS) return 'below-min'
  if (maxAgeSeconds > MAX_PASSWORD_MAX_AGE_SECONDS) return 'above-max'
  return null
}

/**
 * The PATCH body. `maxAge` is left out while the policy is off, and the API
 * keeps options it was not sent, so the stored maximum age survives a toggle.
 */
export function passwordRotationUpdate(
  enabled: boolean,
  maxAgeSeconds: number,
): { enabled: boolean; maxAge?: number } {
  return enabled ? { enabled, maxAge: Math.round(maxAgeSeconds) } : { enabled }
}

/**
 * Whether the draft differs from the stored policy, which is what enables
 * Update. Both ages are in seconds, so switching unit is never an edit; the API
 * stores whole seconds, which is why anything under one counts as rounding.
 */
export function hasPasswordRotationChanges(
  draft: { enabled: boolean; maxAgeSeconds: number },
  stored: PasswordRotationPolicy,
): boolean {
  if (draft.enabled !== stored.enabled) return true
  if (!draft.enabled) return false
  return Math.abs(draft.maxAgeSeconds - stored.maxAge) > 0.5
}
