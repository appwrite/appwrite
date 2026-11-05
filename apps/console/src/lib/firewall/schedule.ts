/**
 * Firewall rule schedules: when a rule is active, on the clock of a timezone.
 *
 * The API stores a schedule as `{ mode, days?, interval?, anchor?, startTime?, endTime?, timezone? }`.
 * The form edits a draft that keeps every field, so switching mode does not lose what the user
 * typed; the payload builders send only the fields the chosen mode uses.
 */

export type FirewallScheduleMode =
  | 'daily'
  | 'weekly'
  | 'biweekly'
  | 'weekends'
  | 'everyNDays'

export type FirewallScheduleDraft = {
  /** Off means the rule is always active. */
  enabled: boolean
  mode: FirewallScheduleMode
  /** ISO weekdays, 1 = Monday to 7 = Sunday. */
  days: number[]
  interval: number
  /** YYYY-MM-DD. Biweekly and every-N-days count from it. */
  anchor: string
  /** No time window: the whole of each selected day. */
  allDay: boolean
  /** HH:MM. A start later than the end crosses midnight. */
  startTime: string
  endTime: string
  timezone: string
}

export type FirewallSchedulePayload = {
  mode: FirewallScheduleMode
  days?: number[]
  interval?: number
  anchor?: string
  startTime?: string
  endTime?: string
  timezone?: string
}

export const FIREWALL_SCHEDULE_MODES: {
  value: FirewallScheduleMode
  label: string
}[] = [
  { value: 'daily', label: 'Every day' },
  { value: 'weekly', label: 'Selected days every week' },
  { value: 'biweekly', label: 'Selected days every other week' },
  { value: 'weekends', label: 'Weekends (Sat and Sun)' },
  { value: 'everyNDays', label: 'Every few days' },
]

export const FIREWALL_SCHEDULE_WEEKDAYS: { value: number; label: string }[] = [
  { value: 1, label: 'Mon' },
  { value: 2, label: 'Tue' },
  { value: 3, label: 'Wed' },
  { value: 4, label: 'Thu' },
  { value: 5, label: 'Fri' },
  { value: 6, label: 'Sat' },
  { value: 7, label: 'Sun' },
]

export const FIREWALL_SCHEDULE_INTERVAL_MIN = 2
export const FIREWALL_SCHEDULE_INTERVAL_MAX = 365

const MODES = new Set<string>(FIREWALL_SCHEDULE_MODES.map((m) => m.value))
const isDigits = (value: string) =>
  value.length > 0 && [...value].every((char) => char >= '0' && char <= '9')

/** YYYY-MM-DD. Shape only: the API rejects a date that does not exist. */
function isDate(value: string): boolean {
  const parts = value.split('-')
  return (
    parts.length === 3 &&
    parts[0].length === 4 &&
    parts[1].length === 2 &&
    parts[2].length === 2 &&
    parts.every(isDigits)
  )
}

/** HH:MM on a 24-hour clock. */
function isTime(value: string): boolean {
  const parts = value.split(':')
  return (
    parts.length === 2 &&
    parts[0].length === 2 &&
    parts[1].length === 2 &&
    parts.every(isDigits) &&
    Number(parts[0]) <= 23 &&
    Number(parts[1]) <= 59
  )
}

export function scheduleUsesDays(mode: FirewallScheduleMode): boolean {
  return mode === 'weekly' || mode === 'biweekly'
}

export function scheduleUsesInterval(mode: FirewallScheduleMode): boolean {
  return mode === 'everyNDays'
}

export function scheduleUsesAnchor(mode: FirewallScheduleMode): boolean {
  return mode === 'biweekly' || mode === 'everyNDays'
}

/** Today's date (YYYY-MM-DD) on the clock of `timeZone`. */
export function todayIn(timeZone: string, now: Date = new Date()): string {
  try {
    return new Intl.DateTimeFormat('en-CA', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(now)
  } catch {
    return now.toISOString().slice(0, 10)
  }
}

export function defaultScheduleDraft(
  timezone: string,
  today: string,
): FirewallScheduleDraft {
  return {
    enabled: false,
    mode: 'weekly',
    days: [1, 2, 3, 4, 5],
    interval: 2,
    anchor: today,
    allDay: false,
    startTime: '09:00',
    endTime: '17:00',
    timezone,
  }
}

/**
 * The draft for a stored rule. A stored schedule without a timezone is UTC (the API's default),
 * whatever the browser's zone; `fallbackTimezone` only seeds a rule that has no schedule yet.
 */
export function scheduleDraftFromRule(
  schedule: unknown,
  fallbackTimezone: string,
  today: string,
): FirewallScheduleDraft {
  const draft = defaultScheduleDraft(fallbackTimezone, today)
  if (!schedule || typeof schedule !== 'object') return draft

  const stored = schedule as Record<string, unknown>
  if (typeof stored.mode !== 'string' || !MODES.has(stored.mode)) return draft

  const days = Array.isArray(stored.days)
    ? stored.days.filter((d): d is number => typeof d === 'number')
    : null
  const hasWindow =
    typeof stored.startTime === 'string' && typeof stored.endTime === 'string'

  return {
    enabled: true,
    mode: stored.mode as FirewallScheduleMode,
    days: days && days.length > 0 ? days : draft.days,
    interval:
      typeof stored.interval === 'number' ? stored.interval : draft.interval,
    anchor: typeof stored.anchor === 'string' ? stored.anchor : draft.anchor,
    allDay: !hasWindow,
    startTime: hasWindow ? (stored.startTime as string) : draft.startTime,
    endTime: hasWindow ? (stored.endTime as string) : draft.endTime,
    timezone:
      typeof stored.timezone === 'string' && stored.timezone !== ''
        ? stored.timezone
        : 'UTC',
  }
}

export function isScheduleDraftComplete(draft: FirewallScheduleDraft): boolean {
  if (!draft.enabled) return true
  if (!MODES.has(draft.mode) || draft.timezone.trim() === '') return false
  if (scheduleUsesDays(draft.mode) && draft.days.length === 0) return false
  if (
    scheduleUsesInterval(draft.mode) &&
    (!Number.isInteger(draft.interval) ||
      draft.interval < FIREWALL_SCHEDULE_INTERVAL_MIN ||
      draft.interval > FIREWALL_SCHEDULE_INTERVAL_MAX)
  ) {
    return false
  }
  if (scheduleUsesAnchor(draft.mode) && !isDate(draft.anchor)) return false
  if (draft.allDay) return true

  return (
    isTime(draft.startTime) &&
    isTime(draft.endTime) &&
    draft.startTime !== draft.endTime
  )
}

/** The `schedule` param on create: nothing while the schedule is off. */
export function schedulePayload(
  draft: FirewallScheduleDraft,
): FirewallSchedulePayload | undefined {
  if (!draft.enabled) return undefined

  const payload: FirewallSchedulePayload = { mode: draft.mode }
  if (scheduleUsesDays(draft.mode)) {
    payload.days = [...new Set(draft.days)].sort((a, b) => a - b)
  }
  if (scheduleUsesInterval(draft.mode)) payload.interval = draft.interval
  if (scheduleUsesAnchor(draft.mode)) payload.anchor = draft.anchor
  if (!draft.allDay) {
    payload.startTime = draft.startTime
    payload.endTime = draft.endTime
  }
  payload.timezone = draft.timezone

  return payload
}

/**
 * The `schedule` param on update. Leaving it out keeps the stored schedule, so a rule that had
 * one and is switched off sends an empty object, which removes it. (`null` would not work: most
 * SDKs drop null params, which the API reads as "keep".)
 */
export function scheduleUpdatePayload(
  draft: FirewallScheduleDraft,
  hadSchedule: boolean,
): FirewallSchedulePayload | Record<string, never> | undefined {
  if (draft.enabled) return schedulePayload(draft)

  return hadSchedule ? {} : undefined
}
