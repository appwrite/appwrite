import {
  HANUKKAH_BANNER_START_MS,
  HANUKKAH_BANNER_END_MS,
} from '@/lib/console-banners/catalog'

export const HANUKKAH_NIGHTS = 8

/** Approximate winter sundown. Jewish nights begin at sundown, not midnight. */
export const HANUKKAH_SUNDOWN_HOUR = 16
export const HANUKKAH_SUNDOWN_MINUTE = 30

const MS_PER_DAY = 24 * 60 * 60 * 1000

function startOfLocalDay(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
}

function firstNightLocalStart(): number {
  const start = new Date(HANUKKAH_BANNER_START_MS)
  return new Date(
    start.getUTCFullYear(),
    start.getUTCMonth(),
    start.getUTCDate(),
  ).getTime()
}

function isAfterLocalSundown(date: Date): boolean {
  const minutes = date.getHours() * 60 + date.getMinutes()
  return minutes >= HANUKKAH_SUNDOWN_HOUR * 60 + HANUKKAH_SUNDOWN_MINUTE
}

/**
 * Hanukkah night 0–8 for `date` in the visitor's local calendar.
 * Night 1 starts at sundown on the first day; night 0 is before the first lighting.
 */
export function getHanukkahNight(
  date: Date,
  options?: { preview?: boolean },
): number {
  const nightStart = isAfterLocalSundown(date)
    ? startOfLocalDay(date)
    : startOfLocalDay(date) - MS_PER_DAY
  const raw = Math.floor((nightStart - firstNightLocalStart()) / MS_PER_DAY) + 1

  if (raw >= 1 && raw <= HANUKKAH_NIGHTS) return raw
  if (options?.preview) return HANUKKAH_NIGHTS
  if (raw > HANUKKAH_NIGHTS && date.getTime() <= HANUKKAH_BANNER_END_MS) {
    return HANUKKAH_NIGHTS
  }
  return 0
}

/** Eight branches, 0 = leftmost, 7 = rightmost. Candles are added right to left. */
export function isHanukkahBranchLit(
  branchFromLeft: number,
  night: number,
): boolean {
  if (night <= 0 || night > HANUKKAH_NIGHTS) return false
  if (branchFromLeft < 0 || branchFromLeft >= HANUKKAH_NIGHTS) return false
  return branchFromLeft >= HANUKKAH_NIGHTS - night
}
