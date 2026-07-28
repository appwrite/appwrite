import { Query } from '@appwrite.io/console'
import { DatabaseType } from '@/lib/databases/database-type'
import { sdk } from '@/lib/appwrite/sdk'
import {
  databaseRouteKindFromApiType,
  dbNavLink,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import {
  fetchProjectConsoleDatabases,
  fetchProjectDatabases,
  fetchProjectTable,
  resolveProjectDatabaseType,
} from '@/lib/react-query/hooks/databases'
import { USAGE_BREAKDOWN_DRAWER_LIMIT } from '@/lib/usage/breakdown-limits'

export interface TableBreakdownResource {
  id: string
  name: string
  databaseId: string
  databaseType: DatabaseType | string
}

export type TableBreakdownResourceMap = Record<string, TableBreakdownResource>

export function parseTableUsageResourceLabel(label: string): {
  databaseId?: string
  tableId: string
} {
  const trimmed = label.trim()
  if (!trimmed) return { tableId: '' }

  for (const separator of ['/', ':']) {
    const index = trimmed.indexOf(separator)
    if (index > 0) {
      return {
        databaseId: trimmed.slice(0, index).trim(),
        tableId: trimmed.slice(index + 1).trim(),
      }
    }
  }

  return { tableId: trimmed }
}

export function normalizeTableBreakdownResourceLabels(labels: string[]): string[] {
  return [
    ...new Set(labels.filter((label) => typeof label === 'string' && label.trim())),
  ].slice(0, USAGE_BREAKDOWN_DRAWER_LIMIT)
}

/** Resolve a database's product route kind via a single console lookup (no per-product probing). */
async function resolveDbKindForDatabase(
  projectId: string,
  databaseId: string,
): Promise<DatabaseRouteKind | null> {
  const { databases } = await fetchProjectConsoleDatabases(
    projectId,
    0,
    1,
    undefined,
    [Query.equal('$id', [databaseId])],
  ).catch(() => ({ databases: [], total: 0 }))
  const type = databases[0]?.type
  return type ? databaseRouteKindFromApiType(type) : null
}

async function listTablesByIdsInDatabase(
  projectId: string,
  databaseId: string,
  dbKind: DatabaseRouteKind,
  tableIds: string[],
): Promise<Array<{ $id: string; name: string }>> {
  if (tableIds.length === 0) return []

  const projectSdk = sdk.forProject(projectId)
  const buildIdQuery = (ids: string[]) =>
    ids.length === 1
      ? Query.equal('$id', ids[0])
      : Query.or(ids.map((id) => Query.equal('$id', id)))
  const buildNameQuery = (names: string[]) =>
    names.length === 1
      ? Query.equal('name', names[0])
      : Query.or(names.map((name) => Query.equal('name', name)))

  const kind = resolveProjectDatabaseType(dbKind)

  const listMatches = async (query: string[]) => {
    if (kind === DatabaseType.Documentsdb) {
      const response = await projectSdk.documentsDB.listCollections({
        databaseId,
        queries: [...query, Query.limit(tableIds.length)],
      })
      return (response.collections ?? []).map((collection) => ({
        $id: collection.$id,
        name: collection.name,
      }))
    }

    if (kind === DatabaseType.Vectorsdb) {
      const response = await projectSdk.vectorsDB.listCollections({
        databaseId,
        queries: [...query, Query.limit(tableIds.length)],
      })
      return (response.collections ?? []).map((collection) => ({
        $id: collection.$id,
        name: collection.name,
      }))
    }

    const response = await projectSdk.tablesDB.listTables({
      databaseId,
      queries: [...query, Query.limit(tableIds.length)],
    })

    return (response.tables ?? []).map((table) => ({
      $id: table.$id,
      name: table.name,
    }))
  }

  const byId = await listMatches([buildIdQuery(tableIds)]).catch(() => [])
  if (byId.length >= tableIds.length) {
    return byId
  }

  const matchedIds = new Set(byId.map((table) => table.$id))
  const unmatchedLabels = tableIds.filter((label) => {
    return !byId.some(
      (table) => table.$id === label || table.name === label,
    )
  })

  if (unmatchedLabels.length === 0) {
    return byId
  }

  const byName = await listMatches([buildNameQuery(unmatchedLabels)]).catch(
    () => [],
  )

  const merged = [...byId]
  for (const table of byName) {
    if (matchedIds.has(table.$id)) continue
    matchedIds.add(table.$id)
    merged.push(table)
  }

  return merged
}

function storeTableBreakdownResource(
  resources: TableBreakdownResourceMap,
  label: string,
  resource: TableBreakdownResource,
) {
  resources[label] = resource
  resources[resource.id] = resource
}

export async function fetchTableBreakdownResources(
  projectId: string,
  labels: string[],
): Promise<{ resources: TableBreakdownResourceMap }> {
  const normalizedLabels = normalizeTableBreakdownResourceLabels(labels)
  if (!projectId || normalizedLabels.length === 0) {
    return { resources: {} }
  }

  const resources: TableBreakdownResourceMap = {}
  const plainTableLabels: Array<{ label: string; tableId: string }> = []

  await Promise.all(
    normalizedLabels.map(async (label) => {
      const { databaseId, tableId } = parseTableUsageResourceLabel(label)
      if (!tableId) return

      if (databaseId) {
        const resolvedDbKind = await resolveDbKindForDatabase(
          projectId,
          databaseId,
        ).catch(() => null)
        if (!resolvedDbKind) return

        const table = await fetchProjectTable(
          projectId,
          databaseId,
          resolvedDbKind,
          tableId,
        ).catch(() => null)
        if (!table) return

        const databaseType = resolveProjectDatabaseType(resolvedDbKind)

        storeTableBreakdownResource(resources, label, {
          id: table.$id,
          name: table.name,
          databaseId,
          databaseType,
        })
        return
      }

      plainTableLabels.push({ label, tableId })
    }),
  )

  if (plainTableLabels.length === 0) {
    return { resources }
  }

  const unresolvedTableIds = new Set(
    plainTableLabels
      .map(({ tableId }) => tableId)
      .filter((tableId) => !resources[tableId]),
  )

  if (unresolvedTableIds.size === 0) {
    return { resources }
  }

  const { databases } = await fetchProjectDatabases(projectId, 0, 500).catch(
    () => ({ databases: [], total: 0 }),
  )

  for (const database of databases) {
    if (unresolvedTableIds.size === 0) break
    if (!database?.$id) continue

    const databaseType = database.type ?? DatabaseType.Tablesdb
    const dbKind = databaseRouteKindFromApiType(databaseType)
    const idsToQuery = [...unresolvedTableIds]
    const tables = await listTablesByIdsInDatabase(
      projectId,
      database.$id,
      dbKind,
      idsToQuery,
    ).catch(() => [])

    for (const table of tables) {
      const resource: TableBreakdownResource = {
        id: table.$id,
        name: table.name,
        databaseId: database.$id,
        databaseType,
      }

      for (const { label, tableId } of plainTableLabels) {
        if (tableId === table.$id || tableId === table.name) {
          storeTableBreakdownResource(resources, label, resource)
          unresolvedTableIds.delete(tableId)
        }
      }
    }
  }

  return { resources }
}

export function getTableBreakdownResourceTypeLabel(
  databaseType: DatabaseType | string,
): string {
  const dbKind = databaseRouteKindFromApiType(databaseType as DatabaseType)
  return dbKind === 'tablesdb' ? 'Tables' : 'Collections'
}

export function getTableBreakdownResourceRoute(
  projectId: string,
  resource: TableBreakdownResource,
): {
  to: string
  params: Record<string, string>
} {
  const dbKind = databaseRouteKindFromApiType(
    resource.databaseType as DatabaseType,
  )
  const link = dbNavLink(dbKind as DatabaseRouteKind).dataGrid({
    projectId,
    dbKind: dbKind as DatabaseRouteKind,
    databaseId: resource.databaseId,
    resourceId: resource.id,
  })

  return {
    to: link.to,
    params: link.params as unknown as Record<string, string>,
  }
}

export function resolveTableBreakdownResource(
  label: string,
  lookup?: TableBreakdownResourceMap | null,
): TableBreakdownResource | undefined {
  if (!lookup) return undefined

  const trimmed = label.trim()
  if (!trimmed) return undefined

  const direct = lookup[trimmed]
  if (direct) return direct

  const { tableId } = parseTableUsageResourceLabel(trimmed)
  if (tableId && tableId !== trimmed) {
    return lookup[tableId]
  }

  return undefined
}
