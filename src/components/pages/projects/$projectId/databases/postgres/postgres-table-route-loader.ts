import type { QueryClient } from '@tanstack/react-query'
import { isRedirect, redirect } from '@tanstack/react-router'
import {
  postgresTableColumnsQueryOptions,
  postgresTableIndexesQueryOptions,
  postgresTableInfoQueryOptions,
  postgresTablePoliciesQueryOptions,
  postgresTableRlsQueryOptions,
  postgresTableRowColumnsQueryOptions,
  postgresTableRowsQueryOptions,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  normalizePostgresTableRouteId,
  postgresNav,
} from '@/lib/postgres-database-routes'
import { queryParamToMap } from '@/lib/table-filters'

export async function prefetchPostgresTableLayoutData(
  _queryClient: QueryClient,
  _projectId: string,
  _databaseId: string,
  tableId: string,
) {
  return normalizePostgresTableRouteId(tableId)
}

export async function prefetchPostgresTableRowsRouteData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
  routeSearch?: Record<string, unknown>,
) {
  const normalizedTableId = normalizePostgresTableRouteId(tableId)

  const search =
    typeof routeSearch?.search === 'string'
      ? routeSearch.search.trim() || undefined
      : undefined
  const filterMap = queryParamToMap(
    typeof routeSearch?.query === 'string' ? routeSearch.query : null,
  )
  const filterKeys =
    filterMap.size > 0 ? Array.from(filterMap.keys()) : undefined
  const hasFilters = filterMap.size > 0

  const rowColumnsPromise = queryClient.ensureQueryData(
    postgresTableRowColumnsQueryOptions(
      projectId,
      databaseId,
      normalizedTableId,
    ),
  )

  await Promise.all([
    rowColumnsPromise,
    ...(hasFilters
      ? []
      : [
          queryClient.ensureQueryData(
            postgresTableRowsQueryOptions(
              projectId,
              databaseId,
              normalizedTableId,
              0,
              ROWS_DEFAULT_PAGE_SIZE,
              {
                search,
                filterKeys,
              },
            ),
          ),
        ]),
  ])

  try {
    const rowColumns = await rowColumnsPromise
    if (!rowColumns.exists) {
      throw redirect({
        ...postgresNav({ projectId, databaseId }).sql(),
        replace: true,
      })
    }
  } catch (error) {
    if (isRedirect(error)) throw error
  }
}

export async function prefetchPostgresTableRouteData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
  options?: {
    includeFullIndexes?: boolean
    includeTableInfo?: boolean
  },
) {
  const normalizedTableId = await prefetchPostgresTableLayoutData(
    queryClient,
    projectId,
    databaseId,
    tableId,
  )

  await Promise.all([
    queryClient.ensureQueryData(
      postgresTableColumnsQueryOptions(
        projectId,
        databaseId,
        normalizedTableId,
      ),
    ),
    ...(options?.includeTableInfo
      ? [
          queryClient.ensureQueryData(
            postgresTableInfoQueryOptions(
              projectId,
              databaseId,
              normalizedTableId,
            ),
          ),
        ]
      : []),
    ...(options?.includeFullIndexes
      ? [
          queryClient.ensureQueryData(
            postgresTableIndexesQueryOptions(
              projectId,
              databaseId,
              normalizedTableId,
            ),
          ),
        ]
      : []),
  ])
}

export async function prefetchPostgresTableSecurityRouteData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const normalizedTableId = await prefetchPostgresTableLayoutData(
    queryClient,
    projectId,
    databaseId,
    tableId,
  )

  await Promise.all([
    queryClient.ensureQueryData(
      postgresTableInfoQueryOptions(
        projectId,
        databaseId,
        normalizedTableId,
      ),
    ),
    queryClient.ensureQueryData(
      postgresTableRlsQueryOptions(projectId, databaseId, normalizedTableId),
    ),
    queryClient.ensureQueryData(
      postgresTablePoliciesQueryOptions(
        projectId,
        databaseId,
        normalizedTableId,
      ),
    ),
  ])
}
