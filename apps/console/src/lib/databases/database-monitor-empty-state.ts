/** Usage metrics may still be collecting for this long after database creation. */
export const DATABASE_MONITOR_RECENT_THRESHOLD_MS = 5 * 60 * 1000

export const DATABASE_MONITOR_EMPTY_MESSAGE_RECENT =
  'Metrics are still being collected for this newly created database. Charts will populate within a few minutes once the instance is ready.'

export function resolveDatabaseCreatedAt(
  database:
    | {
        $createdAt?: string | null
        createdAt?: string | null
      }
    | null
    | undefined,
): string | null {
  if (!database) return null
  const createdAt = database.$createdAt ?? database.createdAt ?? null
  if (!createdAt) return null
  const trimmed = createdAt.trim()
  return trimmed.length > 0 ? trimmed : null
}

export function isRecentlyCreatedDatabase(
  createdAt: string | null | undefined,
  thresholdMs: number = DATABASE_MONITOR_RECENT_THRESHOLD_MS,
): boolean {
  if (!createdAt) return false
  const createdMs = new Date(createdAt).getTime()
  if (!Number.isFinite(createdMs)) return false
  return Date.now() - createdMs < thresholdMs
}

export function getDatabaseMonitorEmptyMessage(
  createdAt: string | null | undefined,
  defaultMessage: string,
): string {
  return isRecentlyCreatedDatabase(createdAt)
    ? DATABASE_MONITOR_EMPTY_MESSAGE_RECENT
    : defaultMessage
}
