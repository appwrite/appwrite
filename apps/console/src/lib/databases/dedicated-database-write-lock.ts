import type { QueryClient } from '@tanstack/react-query'
import { translate } from '@/lib/i18n/translate'
import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
import { readDatabaseOperationalStatus } from '@/lib/databases/read-database-operational-status'

export type DedicatedDatabaseOperationsLockReason = 'failed'

/** @deprecated Use {@link DedicatedDatabaseOperationsLockReason}. */
export type DedicatedDatabaseWriteLockReason = DedicatedDatabaseOperationsLockReason

export type DedicatedDatabaseOperationsLockState = {
  locked: boolean
  reason: DedicatedDatabaseOperationsLockReason | null
}

/** @deprecated Use {@link DedicatedDatabaseOperationsLockState}. */
export type DedicatedDatabaseWriteLockState = DedicatedDatabaseOperationsLockState

/** User-facing message when database operations are blocked. Extend per reason when adding new lock modes. */
export const DEDICATED_DATABASE_OPERATIONS_LOCK_MESSAGE =
  'Database operations are disabled while the database is in a failed state.'

/** @deprecated Use {@link DEDICATED_DATABASE_OPERATIONS_LOCK_MESSAGE}. */
export const DEDICATED_DATABASE_WRITE_LOCK_MESSAGE =
  DEDICATED_DATABASE_OPERATIONS_LOCK_MESSAGE

const STATUS_OPERATIONS_LOCK_REASONS: Partial<
  Record<string, DedicatedDatabaseOperationsLockReason>
> = {
  failed: 'failed',
}

export function getDedicatedDatabaseOperationsLock(
  status: string | null | undefined,
): DedicatedDatabaseOperationsLockState {
  const normalized = coerceTrimmedString(status).toLowerCase()
  if (!normalized) {
    return { locked: false, reason: null }
  }

  const reason = STATUS_OPERATIONS_LOCK_REASONS[normalized] ?? null
  return {
    locked: reason != null,
    reason,
  }
}

/** @deprecated Use {@link getDedicatedDatabaseOperationsLock}. */
export function getDedicatedDatabaseWriteLock(
  status: string | null | undefined,
): DedicatedDatabaseWriteLockState {
  return getDedicatedDatabaseOperationsLock(status)
}

export function isDedicatedDatabaseOperationsLocked(
  status: string | null | undefined,
): boolean {
  return getDedicatedDatabaseOperationsLock(status).locked
}

/** @deprecated Use {@link isDedicatedDatabaseOperationsLocked}. */
export function isDedicatedDatabaseWriteLocked(
  status: string | null | undefined,
): boolean {
  return isDedicatedDatabaseOperationsLocked(status)
}

export function getDedicatedDatabaseOperationsLockTooltipKey(
  _reason: DedicatedDatabaseOperationsLockReason,
): string {
  return DEDICATED_DATABASE_OPERATIONS_LOCK_MESSAGE
}

/** @deprecated Use {@link getDedicatedDatabaseOperationsLockTooltipKey}. */
export function getDedicatedDatabaseWriteLockTooltipKey(
  reason: DedicatedDatabaseWriteLockReason,
): string {
  return getDedicatedDatabaseOperationsLockTooltipKey(reason)
}

export function assertDedicatedDatabaseOperational(
  status: string | null | undefined,
): void {
  if (!isDedicatedDatabaseOperationsLocked(status)) return
  throw new Error(translate(DEDICATED_DATABASE_OPERATIONS_LOCK_MESSAGE))
}

/** @deprecated Use {@link assertDedicatedDatabaseOperational}. */
export function assertDedicatedDatabaseWritable(
  status: string | null | undefined,
): void {
  assertDedicatedDatabaseOperational(status)
}

export function requireOperationalDatabase(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
): void {
  assertDedicatedDatabaseOperational(
    readDatabaseOperationalStatus(queryClient, projectId, databaseId),
  )
}
