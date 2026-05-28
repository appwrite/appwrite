export function parseDateOnly(isoDate: string): Date {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(year, month - 1, day)
}

/** Local midnight when a launch day unlocks (day 1 = event start date). */
export function resolveInitDayUnlockDate(startDate: string, dayNumber: number): Date {
  const unlock = parseDateOnly(startDate)
  unlock.setDate(unlock.getDate() + Math.max(dayNumber - 1, 0))
  return unlock
}
