/** Controlled Init "current day" value for before launch week starts. */
export const INIT_MOCK_DAY_BEFORE = 0

export const INIT_LAUNCH_WEEK_DAY_COUNT = 5

/** Org promo banner stays visible this many days after the event ends. */
export const INIT_ORG_PROMO_BANNER_DAYS_AFTER_EVENT = 7

/**
 * Default Init current day when debug localStorage has no override.
 * Days never unlock from the calendar; change this (or use the debug Day
 * slider) when ready to reveal a day.
 */
export function getInitMockCurrentDayDefault(): number {
  return 4
}

export function getInitMockDayAfter(
  dayCount = INIT_LAUNCH_WEEK_DAY_COUNT,
): number {
  return dayCount + 1
}

/** Value for when the org promo banner is hidden (7+ days after the event). */
export function getInitMockDayBannerExpired(
  dayCount = INIT_LAUNCH_WEEK_DAY_COUNT,
): number {
  return getInitMockDayAfter(dayCount) + 1
}

export function getInitMockCurrentDayMax(
  dayCount = INIT_LAUNCH_WEEK_DAY_COUNT,
): number {
  return getInitMockDayBannerExpired(dayCount)
}

export function isValidInitMockCurrentDay(
  day: number,
  dayCount = INIT_LAUNCH_WEEK_DAY_COUNT,
): boolean {
  return (
    Number.isInteger(day) &&
    day >= INIT_MOCK_DAY_BEFORE &&
    day <= getInitMockCurrentDayMax(dayCount)
  )
}

export function formatInitMockCurrentDay(day: number): string {
  if (day === INIT_MOCK_DAY_BEFORE) return 'Before event (all days locked)'
  if (day === getInitMockDayAfter()) {
    return 'Simulates after the event. Recap mode with all days unlocked.'
  }
  if (day === getInitMockDayBannerExpired()) {
    return '7+ days after event. Org promo banner hidden.'
  }
  return `Day ${day} of ${INIT_LAUNCH_WEEK_DAY_COUNT}`
}
