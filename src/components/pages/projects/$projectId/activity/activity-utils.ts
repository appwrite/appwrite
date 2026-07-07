/**
 * Shared helpers for the project Activity view and detail drawer.
 */

import type { Models } from '@appwrite.io/console'
import type { CountryLookups } from '@/lib/locale/country-lookups'

/** Older audit rows may still expose split country fields. */
type ActivityEventCountryFields = Models.ActivityEvent & {
  countryCode?: string
  countryName?: string
}

/** Pretty-printed full audit row (same shape as the API / drawer raw JSON). */
export function formatActivityEventJson(event: Models.ActivityEvent): string {
  return JSON.stringify(event, null, 2)
}

/** ISO-3166-1 alpha-2 code from `country` (current API) or legacy `countryCode`. */
export function getActivityCountryCode(
  event: Models.ActivityEvent,
): string | null {
  const legacyCode = (event as ActivityEventCountryFields).countryCode?.trim()
  if (legacyCode && legacyCode !== '--') {
    return legacyCode.toLowerCase()
  }

  const country = event.country?.trim()
  if (!country || country === '--') return null
  if (/^[a-z]{2}$/i.test(country)) return country.toLowerCase()

  return null
}

/** Human-readable country label for table cells and the activity drawer. */
export function getActivityCountryDisplayName(
  event: Models.ActivityEvent,
  lookups?: CountryLookups | null,
): string | null {
  const legacyName = (event as ActivityEventCountryFields).countryName?.trim()
  if (legacyName) return legacyName

  const code = getActivityCountryCode(event)
  if (code && lookups) {
    const name = lookups.codeToName.get(code.toUpperCase())
    if (name) return name
  }

  const country = event.country?.trim()
  if (country && !/^[a-z]{2}$/i.test(country)) return country

  if (code) return code.toUpperCase()

  return null
}

export function isRegularUserType(actorType: string | undefined | null): boolean {
  const normalized = (actorType ?? '').toLowerCase()
  /** `user` is the supported actor; `users` may appear on older audit rows. */
  return normalized === 'user' || normalized === 'users'
}

/**
 * Whether this actor has a real human email worth surfacing as the secondary
 * line under their name. End-users do (their auth email); admins do (their
 * console account email). API keys and system actors don't - their `actorEmail`
 * is often a synthetic service address, so we fall back to the actor id for those.
 */
export function hasHumanEmail(actorType: string | undefined | null): boolean {
  const normalized = (actorType ?? '').toLowerCase()
  return isRegularUserType(actorType) || normalized === 'admin'
}

/**
 * Label + color used in the dedicated "Type" column so each row's actor type
 * (end-user, admin, API key, system) is identifiable at a glance.
 */
export function userTypeBadge(
  actorType: string | undefined | null,
): { label: string; tone: string } {
  const normalized = (actorType ?? '').toLowerCase()

  if (isRegularUserType(actorType)) {
    return {
      label: 'User',
      tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    }
  }
  if (normalized === 'admin') {
    return {
      label: 'Admin',
      tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    }
  }
  if (normalized === 'guest') {
    return {
      label: 'Guest',
      tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    }
  }
  if (normalized === 'keyproject') {
    return {
      label: 'Project key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  if (normalized === 'keyaccount') {
    return {
      label: 'Account key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  if (normalized === 'keyorganization') {
    return {
      label: 'Org key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  if (normalized.startsWith('key')) {
    return {
      label: 'API key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  return {
    label: 'System',
    tone: 'bg-slate-500/10 text-slate-600 dark:text-slate-400',
  }
}
