/** Mock value for previewing Init before launch week starts. */
export const INIT_MOCK_DAY_BEFORE = 0

export const INIT_LAUNCH_WEEK_DAY_COUNT = 5

export function getInitMockDayAfter(
  dayCount = INIT_LAUNCH_WEEK_DAY_COUNT,
): number {
  return dayCount + 1
}

export function isValidInitMockCurrentDay(
  day: number,
  dayCount = INIT_LAUNCH_WEEK_DAY_COUNT,
): boolean {
  return (
    Number.isInteger(day) &&
    day >= INIT_MOCK_DAY_BEFORE &&
    day <= getInitMockDayAfter(dayCount)
  )
}

export function formatInitMockCurrentDay(day: number | null): string {
  if (day === null) return 'Using real calendar date'
  if (day === INIT_MOCK_DAY_BEFORE) return 'Before event (all days locked)'
  if (day === getInitMockDayAfter()) {
    return 'Simulates after the event. Recap mode with all days unlocked.'
  }
  return `Day ${day} of ${INIT_LAUNCH_WEEK_DAY_COUNT}`
}
