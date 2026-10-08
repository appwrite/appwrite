import type { Models } from '@appwrite.io/console'
import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'

const TABLES_DATABASE_MIGRATION_COMPLETE_PHASE = 'done'

/** Phases where DELETE /migrations/{id} is not allowed (cutover already started). */
const TABLES_DATABASE_MIGRATION_NON_ABORTABLE_PHASES = new Set([
  'cutover',
  'soaking',
  TABLES_DATABASE_MIGRATION_COMPLETE_PHASE,
])

type TablesDatabaseMigrationPhaseFields = Pick<
  Models.DatabaseMigration,
  'phase' | 'cutoverAt'
>

export function hasTablesDatabaseMigrationCutover(
  migration: Pick<Models.DatabaseMigration, 'cutoverAt'>,
): boolean {
  return !!coerceTrimmedString(migration.cutoverAt)
}

/** Routing is on dedicated compute; migration is effectively complete for UX. */
export function isTablesDatabaseMigrationEffectivelyComplete(
  migration: TablesDatabaseMigrationPhaseFields,
): boolean {
  return hasTablesDatabaseMigrationCutover(migration)
}

export function isTablesDatabaseMigrationInProgress(
  phaseOrMigration:
    | string
    | null
    | undefined
    | TablesDatabaseMigrationPhaseFields,
): boolean {
  if (phaseOrMigration != null && typeof phaseOrMigration === 'object') {
    if (hasTablesDatabaseMigrationCutover(phaseOrMigration)) return false
    return isTablesDatabaseMigrationInProgress(phaseOrMigration.phase)
  }
  const normalized = coerceTrimmedString(phaseOrMigration).toLowerCase()
  if (!normalized) return false
  if (normalized === TABLES_DATABASE_MIGRATION_COMPLETE_PHASE) return false
  if (normalized === 'failed' || normalized === 'rolled_back') return false
  return true
}

/** Migration row that blocks starting another createMigration (anything except `done`). */
export function getBlockingTablesDatabaseMigration(
  migrations: Models.DatabaseMigration[] | null | undefined,
): Models.DatabaseMigration | null {
  if (!migrations?.length) return null
  const sorted = [...migrations].sort((a, b) =>
    (b.$updatedAt || b.$createdAt || '').localeCompare(
      a.$updatedAt || a.$createdAt || '',
    ),
  )
  for (const migration of sorted) {
    const phase = coerceTrimmedString(migration.phase).toLowerCase()
    if (phase !== TABLES_DATABASE_MIGRATION_COMPLETE_PHASE) {
      return migration
    }
  }
  return null
}

/** @deprecated Prefer {@link getBlockingTablesDatabaseMigration} for UI and guards. */
export function getActiveTablesDatabaseMigration(
  migrations: Models.DatabaseMigration[] | null | undefined,
): Models.DatabaseMigration | null {
  const blocking = getBlockingTablesDatabaseMigration(migrations)
  if (!blocking) return null
  return isTablesDatabaseMigrationInProgress(blocking) ? blocking : null
}

export function canAbortTablesDatabaseMigration(
  phaseOrMigration:
    | string
    | null
    | undefined
    | TablesDatabaseMigrationPhaseFields,
): boolean {
  if (phaseOrMigration != null && typeof phaseOrMigration === 'object') {
    if (hasTablesDatabaseMigrationCutover(phaseOrMigration)) return false
    return canAbortTablesDatabaseMigration(phaseOrMigration.phase)
  }
  const normalized = coerceTrimmedString(phaseOrMigration).toLowerCase()
  if (!normalized) return true
  return !TABLES_DATABASE_MIGRATION_NON_ABORTABLE_PHASES.has(normalized)
}

/** User-visible banner only while migration work is still in flight (not post-cutover soak). */
export function shouldShowTablesDatabaseMigrationBanner(
  migration: Models.DatabaseMigration | null | undefined,
): migration is Models.DatabaseMigration {
  if (!migration) return false
  const phase = coerceTrimmedString(migration.phase).toLowerCase()
  if (phase === 'failed' || phase === 'rolled_back') return true
  return isTablesDatabaseMigrationInProgress(migration)
}

export function parseTablesDatabaseMigrationIdFromError(
  error: unknown,
): string | null {
  const message =
    error instanceof Error
      ? error.message
      : typeof error === 'string'
        ? error
        : ''
  if (!message) return null
  const match = message.match(/\/migrations\/([A-Za-z0-9._-]+)/)
  return match?.[1] ?? null
}

/** User-facing label key for `t()` from migration phase. */
export function tablesDatabaseMigrationPhaseLabelKey(
  phase: string | null | undefined,
): string {
  switch (coerceTrimmedString(phase).toLowerCase()) {
    case 'pending':
      return 'Starting dedicated migration'
    case 'provisioned':
      return 'Dedicated compute provisioned'
    case 'capturing':
      return 'Capturing live changes'
    case 'backfilling':
      return 'Copying data to dedicated compute'
    case 'catching_up':
      return 'Catching up on changes'
    case 'verifying':
      return 'Verifying migrated data'
    case 'ready_to_cutover':
      return 'Ready to cut over'
    case 'cutover':
      return 'Cutting over to dedicated compute'
    case 'soaking':
      return 'Finishing migration'
    case 'done':
      return 'Migration completed'
    case 'failed':
      return 'Migration failed'
    case 'rolled_back':
      return 'Migration rolled back'
    default:
      return 'Migrating to dedicated compute'
  }
}
