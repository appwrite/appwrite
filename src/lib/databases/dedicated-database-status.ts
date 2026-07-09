import type { HeaderAlertVariant } from '@/components/global/shared/HeaderAlertBar'

export const DEDICATED_DATABASE_STATUS_POLL_INTERVAL_MS = 5000

export function isDedicatedDatabaseReady(
  status: string | null | undefined,
): boolean {
  return status?.trim().toLowerCase() === 'ready'
}

export function shouldPollDedicatedDatabaseStatus(
  status: string | null | undefined,
): boolean {
  return !!status?.trim() && !isDedicatedDatabaseReady(status)
}

export function dedicatedDatabaseHeaderAlertVariant(
  status: string,
): HeaderAlertVariant {
  switch (status.trim().toLowerCase()) {
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
  switch (status.trim().toLowerCase()) {
    case 'scaling':
      return 'Database is scaling'
    case 'provisioning':
      return 'Database is provisioning'
    case 'restoring':
      return 'Database is restoring'
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
  switch (status.trim().toLowerCase()) {
    case 'scaling':
      return 'A compute tier change is in progress. Some operations may be temporarily unavailable.'
    case 'provisioning':
      return 'Dedicated compute is being provisioned for this database.'
    case 'restoring':
      return 'This database is being restored. Some operations may be unavailable until it is ready again.'
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
