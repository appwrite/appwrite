import type { HeaderAlertVariant } from '@/components/global/shared/HeaderAlertBar'

export type DedicatedDatabaseStatusBadgeVariant =
  | 'success'
  | 'warning'
  | 'error'
  | 'info'
  | 'processing'
  | 'inactive'

/**
 * Coerce API/status values to a trimmed string. Avoids `n?.trim is not a function`
 * when a non-string (e.g. number or tagged `{ type, value }` cell) is truthy under
 * optional chaining.
 */
export function coerceTrimmedString(value: unknown): string {
  if (typeof value === 'string') return value.trim()
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value).trim()
  }
  if (typeof value === 'bigint') return value.toString()
  // MySQL VARBINARY cells may arrive as JSON byte arrays before normalization.
  if (Array.isArray(value)) {
    if (
      value.length > 0 &&
      value.every(
        (entry) =>
          typeof entry === 'number' &&
          Number.isInteger(entry) &&
          entry >= 0 &&
          entry <= 255,
      )
    ) {
      try {
        const decoded = new TextDecoder('utf-8', { fatal: false }).decode(
          Uint8Array.from(value as number[]),
        )
        if (decoded && !decoded.includes('\uFFFD')) return decoded.trim()
      } catch {
        // fall through
      }
    }
    return ''
  }
  if (value && typeof value === 'object') {
    const record = value as Record<string, unknown>
    for (const key of [
      'value',
      'Value',
      '$value',
      'text',
      'Text',
      'string',
      'String',
      'name',
      'Name',
    ]) {
      const nested = record[key]
      if (typeof nested === 'string') return nested.trim()
      if (typeof nested === 'number' || typeof nested === 'boolean') {
        return String(nested).trim()
      }
    }
  }
  return ''
}

export function isDedicatedDatabaseReady(
  status: string | null | undefined,
): boolean {
  return coerceTrimmedString(status).toLowerCase() === 'ready'
}

const LIFECYCLE_STABLE_STATUSES = new Set(['ready', 'paused'])

/**
 * Prefer dedicated compute lifecycle when the product API still reports a stable
 * health status (e.g. serverless TablesDB stays `ready` during migration).
 */
export function resolveDatabaseLifecycleStatus(
  productStatus: string | null | undefined,
  dedicatedStatus: string | null | undefined,
): string | null {
  const product = coerceTrimmedString(productStatus)
  const dedicated = coerceTrimmedString(dedicatedStatus)
  const productStable =
    !product || LIFECYCLE_STABLE_STATUSES.has(product.toLowerCase())
  const dedicatedStable =
    !dedicated || LIFECYCLE_STABLE_STATUSES.has(dedicated.toLowerCase())

  if (!dedicatedStable && productStable) return dedicated
  if (!productStable && dedicatedStable) return product
  if (dedicated && !dedicatedStable) return dedicated
  if (product && !productStable) return product
  return product || dedicated || null
}

export function shouldPollDatabaseLifecycleStatus(
  status: string | null | undefined,
): boolean {
  const normalized = coerceTrimmedString(status).toLowerCase()
  if (!normalized) return false
  if (LIFECYCLE_STABLE_STATUSES.has(normalized)) return false
  if (normalized === 'failed' || normalized === 'deleted') return false
  return true
}

/** Initial create/start only. Do not treat paused/failed/scaling as provisioning. */
export function isDedicatedDatabaseProvisioning(
  status: string | null | undefined,
): boolean {
  const normalized = coerceTrimmedString(status).toLowerCase()
  return normalized === 'provisioning' || normalized === 'starting'
}

export function isDedicatedDatabaseDeleting(
  status: string | null | undefined,
): boolean {
  const normalized = coerceTrimmedString(status).toLowerCase()
  return normalized === 'deleting' || normalized === 'deleted'
}

/** Manual or automatic replica promotion. Cluster stays available, like spec changes. */
export function isDedicatedDatabaseFailingOver(
  status: string | null | undefined,
): boolean {
  const normalized = coerceTrimmedString(status).toLowerCase()
  return (
    normalized === 'failing-over' ||
    normalized === 'failingover' ||
    normalized === 'failing_over'
  )
}

export const DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE =
  'Available once the database is ready.'

export function dedicatedDatabaseStatusBadgeVariant(
  status: string | null | undefined,
): DedicatedDatabaseStatusBadgeVariant {
  if (isDedicatedDatabaseFailingOver(status)) return 'info'
  switch (coerceTrimmedString(status).toLowerCase()) {
    case 'ready':
      return 'success'
    case 'provisioning':
    case 'starting':
      return 'processing'
    case 'restoring':
    case 'upgrading':
    case 'migrating':
    case 'pausing':
    case 'resuming':
    case 'deleting':
      return 'warning'
    case 'scaling':
      return 'info'
    case 'failed':
    case 'deleted':
      return 'error'
    case 'paused':
    case 'inactive':
      return 'inactive'
    default:
      return 'info'
  }
}

export function dedicatedDatabaseHeaderAlertVariant(
  status: string,
): HeaderAlertVariant {
  switch (coerceTrimmedString(status).toLowerCase()) {
    case 'failed':
    case 'deleted':
      return 'danger'
    case 'paused':
    case 'inactive':
      return 'warning'
    default:
      return 'info'
  }
}

export function dedicatedDatabaseStatusAlertTitleKey(
  status: string,
): string {
  if (isDedicatedDatabaseFailingOver(status)) {
    return 'Database is failing over'
  }
  switch (coerceTrimmedString(status).toLowerCase()) {
    case 'scaling':
      return 'Database is scaling'
    case 'upgrading':
      return 'Database is upgrading'
    case 'migrating':
      return 'Database is migrating'
    case 'provisioning':
      return 'Database is provisioning'
    case 'restoring':
      return 'Database is restoring'
    case 'pausing':
      return 'Database is pausing'
    case 'resuming':
      return 'Database is resuming'
    case 'deleting':
      return 'Database is deleting'
    case 'paused':
      return 'Database is paused'
    case 'inactive':
      return 'Database is inactive'
    case 'failed':
      return 'Database update failed'
    case 'deleted':
      return 'Database is deleted'
    default:
      return 'Database is not ready'
  }
}

export function dedicatedDatabaseStatusAlertDescriptionKey(
  status: string,
): string {
  if (isDedicatedDatabaseFailingOver(status)) {
    return 'A failover is in progress. Your cluster remains available during this operation.'
  }
  switch (coerceTrimmedString(status).toLowerCase()) {
    case 'scaling':
      return 'A compute tier change is in progress. Your cluster remains available during this operation.'
    case 'upgrading':
      return 'A database upgrade is in progress. Some operations may be temporarily unavailable.'
    case 'migrating':
      return 'A database migration is in progress. Some operations may be temporarily unavailable.'
    case 'provisioning':
      return 'Dedicated compute is being provisioned for this database.'
    case 'restoring':
      return 'This database is being restored. Some operations may be unavailable until it is ready again.'
    case 'pausing':
      return 'This database is being paused. Some operations may be temporarily unavailable.'
    case 'resuming':
      return 'This database is resuming. Some operations may be temporarily unavailable.'
    case 'deleting':
      return 'This database is being deleted and will no longer be available once the operation completes.'
    case 'paused':
      return 'This database is paused. Resume it in Settings to restore access.'
    case 'inactive':
      return 'This database is inactive. Some operations may be unavailable until it is ready again.'
    case 'failed':
      return 'This database could not complete its last operation. Review settings or contact support.'
    case 'deleted':
      return 'This database has been deleted and is no longer available.'
    default:
      return 'This database is not ready yet. Some operations may be unavailable until the operation completes.'
  }
}
