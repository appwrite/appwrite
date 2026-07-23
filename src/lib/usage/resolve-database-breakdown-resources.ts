import { DatabaseType } from '@/lib/databases/database-type'
import {
  DATABASE_HOME_TO,
  databaseRouteKindFromApiType,
  type TanStackNavLink,
} from '@/lib/database-routes'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import { fetchProjectDatabasesByIds } from '@/lib/react-query/hooks/databases'

export interface DatabaseBreakdownResource {
  id: string
  name: string
  databaseType: DatabaseType | string
}

export type DatabaseBreakdownResourceMap = Record<
  string,
  DatabaseBreakdownResource
>

/** Usage may group reads by composite keys (e.g. databaseId/tableId). */
export function parseDatabaseIdFromUsageResourceLabel(label: string): string {
  const trimmed = label.trim()
  if (!trimmed) return ''

  const slashIndex = trimmed.indexOf('/')
  if (slashIndex > 0) {
    return trimmed.slice(0, slashIndex).trim()
  }

  return trimmed
}

export function resolveDatabaseBreakdownResource(
  label: string,
  databaseLookup?: DatabaseBreakdownResourceMap | null,
): DatabaseBreakdownResource | undefined {
  if (!databaseLookup) return undefined

  const direct = databaseLookup[label]
  if (direct) return direct

  const parsedId = parseDatabaseIdFromUsageResourceLabel(label)
  if (parsedId && parsedId !== label) {
    return databaseLookup[parsedId]
  }

  return undefined
}

export function normalizeDatabaseBreakdownResourceIds(
  resourceIds: string[],
): string[] {
  return [
    ...new Set(
      resourceIds
        .map((id) => parseDatabaseIdFromUsageResourceLabel(id))
        .filter((id) => id.length > 0),
    ),
  ].slice(0, OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT)
}

export async function fetchDatabaseBreakdownResources(
  projectId: string,
  resourceIds: string[],
): Promise<{ resources: DatabaseBreakdownResourceMap }> {
  const ids = normalizeDatabaseBreakdownResourceIds(resourceIds)
  if (!projectId || ids.length === 0) {
    return { resources: {} }
  }

  const { databases } = await fetchProjectDatabasesByIds(projectId, ids)

  const resources: DatabaseBreakdownResourceMap = {}
  for (const database of databases) {
    if (!database?.$id) continue
    resources[database.$id] = {
      id: database.$id,
      name: database.name,
      databaseType: database.type,
    }
  }

  return { resources }
}

export function getDatabaseBreakdownResourceRoute(
  projectId: string,
  resource: DatabaseBreakdownResource,
): TanStackNavLink {
  const dbKind = databaseRouteKindFromApiType(
    resource.databaseType as DatabaseType,
  )

  return {
    to: DATABASE_HOME_TO,
    params: {
      projectId,
      dbKind,
      databaseId: resource.id,
    },
  }
}

export function getDatabaseBreakdownServiceLabel(
  databaseType: DatabaseType | string,
): string {
  const dbKind = databaseRouteKindFromApiType(databaseType as DatabaseType)
  if (dbKind === 'documentsdb') return 'DocumentsDB'
  if (dbKind === 'vectorsdb') return 'VectorsDB'
  return 'TablesDB'
}
