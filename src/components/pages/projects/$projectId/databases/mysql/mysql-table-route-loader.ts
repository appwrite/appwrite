import type { QueryClient } from '@tanstack/react-query'
import { isRedirect, redirect } from '@tanstack/react-router'
import {
  mysqlTableColumnsQueryOptions,
  mysqlTableIndexesQueryOptions,
  mysqlTableInfoQueryOptions,
  mysqlTablePoliciesQueryOptions,
  mysqlTableRlsQueryOptions,
  mysqlTableRowColumnsQueryOptions,
  mysqlTableRowsQueryOptions,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  normalizeMysqlTableRouteId,
  mysqlNav,
} from '@/lib/mysql-database-routes'
import { queryParamToMap } from '@/lib/table-filters'

export async function prefetchMysqlTableLayoutData(
  _queryClient: QueryClient,
  _projectId: string,
  _databaseId: string,
  tableId: string,
) {
  return normalizeMysqlTableRouteId(tableId)
}

export async function prefetchMysqlTableRowsRouteData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
  routeSearch?: Record<string, unknown>,
) {
  const normalizedTableId = normalizeMysqlTableRouteId(tableId)

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
    mysqlTableRowColumnsQueryOptions(
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
            mysqlTableRowsQueryOptions(
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
        ...mysqlNav({ projectId, databaseId }).sql(),
        replace: true,
      })
    }
  } catch (error) {
    if (isRedirect(error)) throw error
  }
}

export async function prefetchMysqlTableRouteData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
  options?: {
    includeFullIndexes?: boolean
    includeTableInfo?: boolean
  },
) {
  const normalizedTableId = await prefetchMysqlTableLayoutData(
    queryClient,
    projectId,
    databaseId,
    tableId,
  )

  await Promise.all([
    queryClient.ensureQueryData(
      mysqlTableColumnsQueryOptions(
        projectId,
        databaseId,
        normalizedTableId,
      ),
    ),
    ...(options?.includeTableInfo
      ? [
          queryClient.ensureQueryData(
            mysqlTableInfoQueryOptions(
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
            mysqlTableIndexesQueryOptions(
              projectId,
              databaseId,
              normalizedTableId,
            ),
          ),
        ]
      : []),
  ])
}

export async function prefetchMysqlTableSecurityRouteData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const normalizedTableId = await prefetchMysqlTableLayoutData(
    queryClient,
    projectId,
    databaseId,
    tableId,
  )

  await Promise.all([
    queryClient.ensureQueryData(
      mysqlTableInfoQueryOptions(
        projectId,
        databaseId,
        normalizedTableId,
      ),
    ),
    queryClient.ensureQueryData(
      mysqlTableRlsQueryOptions(projectId, databaseId, normalizedTableId),
    ),
    queryClient.ensureQueryData(
      mysqlTablePoliciesQueryOptions(
        projectId,
        databaseId,
        normalizedTableId,
      ),
    ),
  ])
}
