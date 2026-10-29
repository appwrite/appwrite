/**
 * Laying out the alerts feed: alerts grouped by the local day they started,
 * and a duration bar per alert on a scale shared by the whole feed.
 */

interface Started {
  $createdAt: string
  resolvedAt: string | null
}

export interface AlertDay<T extends Started> {
  /** `YYYY-MM-DD` in local time, stable for React keys. */
  key: string
  /** Days before today: 0 is today, 1 is yesterday. */
  daysAgo: number
  /** Local midnight of the day. */
  date: Date
  alerts: T[]
}

function dayKey(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}

function midnight(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

/** Groups alerts by the local day they started, keeping the order they came in. */
export function groupAlertsByDay<T extends Started>(
  alerts: T[],
  now = Date.now(),
): AlertDay<T>[] {
  const today = midnight(new Date(now))
  const days = new Map<string, AlertDay<T>>()
  for (const alert of alerts) {
    const date = midnight(new Date(alert.$createdAt))
    const key = dayKey(date)
    let day = days.get(key)
    if (!day) {
      day = {
        key,
        // Rounded: a day across a daylight-saving change is 23 or 25 hours long.
        daysAgo: Math.round((today.getTime() - date.getTime()) / 86_400_000),
        date,
        alerts: [],
      }
      days.set(key, day)
    }
    day.alerts.push(alert)
  }
  return [...days.values()]
}

/** Seconds an alert fired for, up to `now` while it still is. */
export function alertLength(alert: Started, now = Date.now()): number {
  const end = alert.resolvedAt ? Date.parse(alert.resolvedAt) : now
  return Math.max(0, (end - Date.parse(alert.$createdAt)) / 1000)
}

/** The bar's narrowest width, so the shortest alert still shows as a mark. */
export const MIN_BAR_PERCENT = 4

/**
 * Each alert's bar width in percent of the track. The longest alert in the
 * feed fills it, so bars compare across days.
 */
export function barWidths<T extends Started>(
  alerts: T[],
  now = Date.now(),
): Map<T, number> {
  const lengths = alerts.map((alert) => alertLength(alert, now))
  const longest = Math.max(0, ...lengths)
  const widths = new Map<T, number>()
  alerts.forEach((alert, index) => {
    const share = longest === 0 ? 0 : (lengths[index] / longest) * 100
    widths.set(alert, Math.max(MIN_BAR_PERCENT, Math.min(100, share)))
  })
  return widths
}
