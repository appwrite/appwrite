export const INIT_PRESENCE_ACTIVITY_ON_INIT = 'On Init'
export const INIT_PRESENCE_ACTIVITY_LEFT = 'Left Init'
export const INIT_PRESENCE_ACTIVITY_OFFLINE = 'Offline'

/** Soft cap for sidebar display; only compresses when clearly over budget. */
export const INIT_PRESENCE_ACTIVITY_MAX_CHARS = 32

/** Hype statuses shown before Init week starts (currentDay <= 0). */
export const INIT_PRE_EVENT_HYPE_ACTIVITIES = [
  'Counting down to Init',
  'Getting hyped for launch week',
  'Exploring the schedule',
  'Claiming a ticket',
  'Customizing a ticket',
  'Ready for five days of launches',
  'Waiting for kickoff',
  'Saving a front-row seat',
  'Browsing what is coming',
  'Psyched for Init',
  'Plotting the week ahead',
  'Marking the calendar',
  'Init week loading',
  'First launch drops soon',
] as const

const LOCKED_DAY_ACTIVITY_TEMPLATES = [
  (day: number) => `Waiting for day ${day}`,
  (day: number) => `Anticipating day ${day}`,
  (day: number) => `Counting down to day ${day}`,
  (day: number) => `Day ${day} launch soon`,
  (day: number) => `Saving room for day ${day}`,
  (day: number) => `Curious about day ${day}`,
] as const

function hashPresenceSeed(seed: string): number {
  let hash = 0
  for (let index = 0; index < seed.length; index += 1) {
    hash = (hash << 5) - hash + seed.charCodeAt(index)
    hash |= 0
  }
  return Math.abs(hash)
}

export function pickInitPresenceActivity(
  activities: readonly string[],
  seed: string,
): string {
  if (activities.length === 0) return INIT_PRESENCE_ACTIVITY_ON_INIT
  return activities[hashPresenceSeed(seed) % activities.length]!
}

function getInitLockedDayActivityPool(day: number): string[] {
  return LOCKED_DAY_ACTIVITY_TEMPLATES.map((template) => template(day))
}

export function getAllInitWaitingActivities(maxDay = 5): string[] {
  const activities = new Set<string>(INIT_PRE_EVENT_HYPE_ACTIVITIES)

  for (let day = 1; day <= maxDay; day += 1) {
    for (const activity of getInitLockedDayActivityPool(day)) {
      activities.add(activity)
    }
  }

  return [...activities]
}

/** Compress only dynamic overflow for the sidebar; leaves natural copy intact. */
export function clampInitPresenceActivity(activity: string): string {
  const trimmed = activity.trim()
  if (trimmed.length <= INIT_PRESENCE_ACTIVITY_MAX_CHARS) return trimmed

  const readingDay = trimmed.match(/^Reading Day (\d+): .+$/i)
  if (readingDay) return `Reading day ${readingDay[1]}`

  const viewingDay = trimmed.match(/^Viewing Day (\d+): .+$/i)
  if (viewingDay) return `Viewing day ${viewingDay[1]}`

  if (trimmed.startsWith('Checking: ')) {
    return 'Checking session'
  }

  const exploring = trimmed.match(/^Exploring (.+)$/)
  if (exploring && (exploring[1]?.length ?? 0) > 18) {
    return `Exploring ${exploring[1]!.slice(0, 18).trimEnd()}…`
  }

  const cut = trimmed.slice(0, INIT_PRESENCE_ACTIVITY_MAX_CHARS)
  const lastSpace = cut.lastIndexOf(' ')
  if (lastSpace >= INIT_PRESENCE_ACTIVITY_MAX_CHARS - 10) {
    return `${cut.slice(0, lastSpace)}…`
  }

  return `${cut}…`
}

export function buildInitPreEventBaselineActivity(seed: string): string {
  return pickInitPresenceActivity(INIT_PRE_EVENT_HYPE_ACTIVITIES, `baseline:${seed}`)
}

export function buildInitWaitingForDayActivity(
  day: number,
  options?: { currentDay?: number; seed?: string },
): string {
  const currentDay = options?.currentDay ?? day
  const seed = options?.seed ?? `waiting:${day}`

  if (currentDay <= 0) {
    return pickInitPresenceActivity(INIT_PRE_EVENT_HYPE_ACTIVITIES, seed)
  }

  return pickInitPresenceActivity(getInitLockedDayActivityPool(day), seed)
}

export function buildInitViewingDayActivity(day: number, title?: string): string {
  if (title) return `Viewing Day ${day}: ${title}`
  return `Viewing day ${day}`
}

export function buildInitReadingDayActivity(
  day: number,
  options?: { locked?: boolean; title?: string; currentDay?: number; seed?: string },
): string {
  if (options?.locked) {
    return buildInitWaitingForDayActivity(day, {
      currentDay: options.currentDay,
      seed: options.seed,
    })
  }
  if (options?.title) return `Reading Day ${day}: ${options.title}`
  return `Reading day ${day}`
}

export function buildInitPreviewingDayActivity(
  day: number,
  options: { locked: boolean; title?: string; currentDay?: number; seed?: string },
): string {
  if (options.locked) {
    return buildInitWaitingForDayActivity(day, {
      currentDay: options.currentDay,
      seed: options.seed,
    })
  }
  return buildInitViewingDayActivity(day, options.title)
}

export function buildInitViewingTicketActivity(): string {
  return 'Viewing ticket'
}

export function buildInitCustomizingTicketActivity(): string {
  return 'Customizing a ticket'
}

export function buildInitRecordingTicketActivity(): string {
  return 'Recording ticket video'
}

export function buildInitCheckingScheduleActivity(day?: number): string {
  if (day) return `Checking Day ${day} schedule`
  return 'Checking schedule'
}

export function buildInitPlayingWithJoolActivity(): string {
  return 'Playing with Jool'
}

export function buildInitExploringGlobeActivity(): string {
  return 'Exploring the globe'
}

export function buildInitExploringActivity(label: string): string {
  return `Exploring ${label}`
}

const DAILY_PRIZE_PRESENCE_ACTIVITIES: Record<number, string> = {
  1: 'Eyeing day 1 swag',
  2: 'Keyboard envy',
  3: 'Dark keyboard dreams',
  4: 'Bottle and tee wishlist',
}

export function buildInitViewingDailyPrizeActivity(
  day: number,
  _prizeLabel: string,
): string {
  return DAILY_PRIZE_PRESENCE_ACTIVITIES[day] ?? `Eyeing day ${day} swag`
}

export function buildInitViewingGrandPrizeActivity(_title: string): string {
  return 'Grand prize dreams'
}

export function buildInitRunningGiveawayRaffleActivity(day: number): string {
  return `Drawing day ${day} swag`
}

export function buildInitRunningGrandPrizeRevealActivity(): string {
  return 'Revealing grand prize winner'
}

export function buildInitSpinningGiveawayRaffleActivity(): string {
  return 'Spinning the wheel'
}

export function buildInitSwitchingThemeActivity(theme: 'light' | 'dark'): string {
  return theme === 'light' ? 'Going light' : 'Going dark'
}

export function formatInitPresenceActivityDisplay(activity: string): string {
  return clampInitPresenceActivity(activity)
}
