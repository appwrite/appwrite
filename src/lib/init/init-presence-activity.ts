export const INIT_PRESENCE_ACTIVITY_ON_INIT = 'On Init'
export const INIT_PRESENCE_ACTIVITY_LEFT = 'Left Init'
export const INIT_PRESENCE_ACTIVITY_OFFLINE = 'Offline'

export function buildInitWaitingForDayActivity(day: number): string {
  return `Waiting for day ${day}`
}

export function buildInitViewingDayActivity(day: number, title?: string): string {
  if (title) return `Viewing Day ${day}: ${title}`
  return `Viewing day ${day}`
}

export function buildInitReadingDayActivity(
  day: number,
  options?: { locked?: boolean; title?: string },
): string {
  if (options?.locked) return buildInitWaitingForDayActivity(day)
  if (options?.title) return `Reading Day ${day}: ${options.title}`
  return `Reading day ${day}`
}

export function buildInitPreviewingDayActivity(
  day: number,
  options: { locked: boolean; title?: string },
): string {
  if (options.locked) return buildInitWaitingForDayActivity(day)
  return buildInitViewingDayActivity(day, options.title)
}

export function buildInitViewingTicketActivity(): string {
  return 'Viewing ticket'
}

export function buildInitCustomizingTicketActivity(): string {
  return 'Customizing ticket'
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

export function buildInitExploringActivity(label: string): string {
  return `Exploring ${label}`
}

export function buildInitViewingDailyPrizeActivity(
  day: number,
  prizeLabel: string,
): string {
  return `Viewing Day ${day} prize: ${prizeLabel}`
}

export function buildInitViewingGrandPrizeActivity(title: string): string {
  return `Viewing grand prize: ${title}`
}

export function buildInitSwitchingThemeActivity(theme: 'light' | 'dark'): string {
  return theme === 'light' ? 'Going light' : 'Going dark'
}
