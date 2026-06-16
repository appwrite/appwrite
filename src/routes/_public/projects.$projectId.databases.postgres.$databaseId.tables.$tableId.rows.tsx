import { createFileRoute, redirect } from '@tanstack/react-router'
import type { QueryClient } from '@tanstack/react-query'
import {
  normalizePostgresTableRouteId,
  postgresNav,
} from '@/lib/postgres-database-routes'
import {
  postgresTableColumnsQueryOptions,
  postgresTableIndexesQueryOptions,
  postgresTableRowsQueryOptions,
} from '@/lib/react-query/hooks'
import { PostgresTableRowsView } from '@/components/pages/projects/$projectId/databases/postgres/PostgresTableRowsView'
import { prefetchPostgresTableLayoutData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-table-route-loader'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  listSearchSchema,
  queryParamToMap,
} from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

const DEFAULT_PAGE = 1

async function prefetchPostgresRowsRouteData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
  routeSearch?: Record<string, unknown>,
) {
  const normalizedTableId = await prefetchPostgresTableLayoutData(
    queryClient,
    projectId,
    databaseId,
    tableId,
  )

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

  await Promise.all([
    queryClient.ensureQueryData(
      postgresTableColumnsQueryOptions(projectId, databaseId, normalizedTableId),
    ),
    queryClient.ensureQueryData(
      postgresTableIndexesQueryOptions(projectId, databaseId, normalizedTableId),
    ),
    ...(hasFilters
      ? []
      : [
          queryClient.ensureQueryData(
            postgresTableRowsQueryOptions(
              projectId,
              databaseId,
              normalizedTableId,
              DEFAULT_PAGE - 1,
              ROWS_DEFAULT_PAGE_SIZE,
              {
                search,
                filterKeys,
              },
            ),
          ),
        ]),
  ])
}

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/rows',
)({
  beforeLoad: ({ params }) => {
    if (params.tableId === '-') {
      throw redirect({
        ...postgresNav({
          projectId: params.projectId,
          databaseId: params.databaseId,
        }).sql(),
        replace: true,
      })
    }
  },
  validateSearch: listSearchSchema,
  head: () => ({
    meta: [{ title: pageTitle('PostgreSQL', 'Databases') }],
  }),
  loader: async ({ params, context, search: routeSearch }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, tableId } = params
    await prefetchPostgresRowsRouteData(
      context.queryClient,
      projectId,
      databaseId,
      tableId,
      routeSearch as Record<string, unknown> | undefined,
    )
  },
  component: PostgresRowsPage,
})

function PostgresRowsPage() {
  const { databaseId, tableId } = Route.useParams()
  const normalizedTableId = normalizePostgresTableRouteId(tableId)

  return (
    <PostgresTableRowsView databaseId={databaseId} tableId={normalizedTableId} />
  )
}
