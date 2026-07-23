import type { Models } from '@appwrite.io/console'

export type MigrationTableRef = {
  databaseId: string
  tableId: string
}

/**
 * Resolve database + table/collection IDs from a CSV migration.
 *
 * Newer migrations store `parentResourceId` (database) + `resourceId` (table).
 * Older migrations used a composite `resourceId` of `databaseId:tableId`.
 */
export function getMigrationTableRef(
  migration: Pick<
    Models.Migration,
    'resourceId' | 'parentResourceId'
  >,
): MigrationTableRef | null {
  const resourceId = migration.resourceId?.trim() ?? ''
  const parentResourceId = migration.parentResourceId?.trim() ?? ''

  if (
    parentResourceId &&
    resourceId &&
    !parentResourceId.includes(':') &&
    !resourceId.includes(':')
  ) {
    return { databaseId: parentResourceId, tableId: resourceId }
  }

  const idx = resourceId.indexOf(':')
  if (idx > 0) {
    return {
      databaseId: resourceId.slice(0, idx),
      tableId: resourceId.slice(idx + 1),
    }
  }

  return null
}

export function migrationMatchesDatabaseTables(
  migration: Pick<Models.Migration, 'resourceId' | 'parentResourceId'>,
  databaseId: string,
  tableIds: ReadonlySet<string>,
): boolean {
  const ref = getMigrationTableRef(migration)
  if (!ref) return false
  return ref.databaseId === databaseId && tableIds.has(ref.tableId)
}
