/**
 * Push notification settings shared with Beep, Appwrite's mobile app.
 *
 * They live in the console account's prefs under `beep`. The Notifications
 * worker reads them before every push: muted organizations and projects get
 * none, `resolved: false` drops resolved pushes, and `snoozes` holds back an
 * organization's pushes (or every organization's, under `*`) until an ISO
 * time. The Console inbox and emails are unaffected.
 */
export const BEEP_PREFS_KEY = 'beep'

/** The `snoozes` key that holds back every organization's pushes. */
export const EVERY_ORGANIZATION = '*'

export type BeepPrefs = {
  mutedOrganizationIds: string[]
  mutedProjectIds: string[]
  resolved: boolean
  snoozes: Record<string, string>
}

export type Snooze = {
  /** When pushes come back. */
  until: Date
  /** Whether it is the snooze of every organization rather than of one. */
  everywhere: boolean
}

/** How long a snooze can last. None is open-ended: muting is what stays. */
export const SNOOZE_DURATIONS = [
  { label: '30 minutes', minutes: 30 },
  { label: '1 hour', minutes: 60 },
  { label: '4 hours', minutes: 240 },
  { label: '24 hours', minutes: 1440 },
] as const

const DEFAULTS: BeepPrefs = {
  mutedOrganizationIds: [],
  mutedProjectIds: [],
  resolved: true,
  snoozes: {},
}

function readIds(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((id): id is string => typeof id === 'string' && id !== '')
    : []
}

function isIsoTime(value: string): boolean {
  // Absolute time with an offset, as the server requires.
  const hasOffset =
    value.endsWith('Z') || value.at(-6) === '+' || value.at(-6) === '-'
  return value.includes('T') && hasOffset && !Number.isNaN(Date.parse(value))
}

function readSnoozes(value: unknown): Record<string, string> {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return {}
  }
  return Object.fromEntries(
    Object.entries(value).filter(
      (entry): entry is [string, string] =>
        entry[0] !== '' && typeof entry[1] === 'string' && isIsoTime(entry[1]),
    ),
  )
}

/** Reads `prefs.beep`, treating anything malformed as unset, as the server does. */
export function readBeepPrefs(
  prefs: Record<string, unknown> | undefined | null,
): BeepPrefs {
  const beep = prefs?.[BEEP_PREFS_KEY]
  if (typeof beep !== 'object' || beep === null || Array.isArray(beep)) {
    return DEFAULTS
  }
  const fields = beep as Record<string, unknown>
  return {
    mutedOrganizationIds: readIds(fields.mutedOrganizationIds),
    mutedProjectIds: readIds(fields.mutedProjectIds),
    resolved: fields.resolved !== false,
    snoozes: readSnoozes(fields.snoozes),
  }
}

function running(
  snoozes: Record<string, string>,
  now: Date,
): Record<string, string> {
  return Object.fromEntries(
    Object.entries(snoozes).filter(([, until]) => new Date(until) > now),
  )
}

/**
 * What holds back an organization's pushes at `now`: the later of its own
 * snooze and the one for every organization. Without an organization, only
 * the snooze for every organization counts.
 */
export function snoozeOf(
  beep: BeepPrefs,
  organizationId: string | null,
  now: Date = new Date(),
): Snooze | null {
  let found: Snooze | null = null
  const keys =
    organizationId === null
      ? [EVERY_ORGANIZATION]
      : [organizationId, EVERY_ORGANIZATION]
  for (const key of keys) {
    const value = beep.snoozes[key]
    const until = value === undefined ? null : new Date(value)
    if (
      until !== null &&
      until > now &&
      (found === null || until > found.until)
    ) {
      found = { until, everywhere: key === EVERY_ORGANIZATION }
    }
  }
  return found
}

/** Snoozes `key` (an organization ID or `*`) until `until`, dropping snoozes that ended. */
export function withSnooze(
  beep: BeepPrefs,
  key: string,
  until: Date,
  now: Date = new Date(),
): BeepPrefs {
  return {
    ...beep,
    snoozes: { ...running(beep.snoozes, now), [key]: until.toISOString() },
  }
}

/**
 * Ends what snoozes an organization: its own snooze and the one for every
 * organization. With `*`, ends every snooze.
 */
export function withoutSnooze(
  beep: BeepPrefs,
  key: string,
  now: Date = new Date(),
): BeepPrefs {
  if (key === EVERY_ORGANIZATION) {
    return { ...beep, snoozes: {} }
  }
  const snoozes = Object.fromEntries(
    Object.entries(running(beep.snoozes, now)).filter(
      ([entry]) => entry !== key && entry !== EVERY_ORGANIZATION,
    ),
  )
  return { ...beep, snoozes }
}

function toggle(ids: string[], id: string, include: boolean): string[] {
  const without = ids.filter((item) => item !== id)
  return include ? [...without, id] : without
}

export function withMutedOrganization(
  beep: BeepPrefs,
  organizationId: string,
  muted: boolean,
): BeepPrefs {
  return {
    ...beep,
    mutedOrganizationIds: toggle(
      beep.mutedOrganizationIds,
      organizationId,
      muted,
    ),
  }
}

export function withMutedProject(
  beep: BeepPrefs,
  projectId: string,
  muted: boolean,
): BeepPrefs {
  return {
    ...beep,
    mutedProjectIds: toggle(beep.mutedProjectIds, projectId, muted),
  }
}

/** The account prefs with `beep` replaced, keeping every other key as it is. */
export function mergeBeepPrefs(
  prefs: Record<string, unknown> | undefined | null,
  beep: BeepPrefs,
): Record<string, unknown> {
  return { ...(prefs ?? {}), [BEEP_PREFS_KEY]: beep }
}

/** Whether a prefs value is a plain JSON object (not an array or null). */
export function isPlainObject(
  value: unknown,
): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
