import { type DatabaseRouteKind } from '@/lib/database-routes'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { DatabaseType } from '@/lib/databases/database-type'
import { Workspace } from '@/components/pages/projects/$projectId/databases/View'
import {
  listSearchSchema,
  parseListSearch,
} from '@/lib/table-filters'
import {
  COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
  projectQueryOptions,
  tablesQueryOptions,
  databaseQueryOptions,
  tableColumnsQueryOptions,
  tableIndexesQueryOptions,
  tableQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { throwRedirectCollectionsDbFromTablesChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/columns',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          (
            loaderData as
              | {
                  database?: { name?: string }
                  table?: { name?: string }
                }
              | undefined
          )?.table?.name ??
            (loaderData as { database?: { name?: string } } | undefined)
              ?.database?.name ??
            'Database',
          'Databases',
        ),
      },
    ],
  }),
  validateSearch: listSearchSchema,
  // @ts-expect-error - route tree may infer loader as never; loader returns { database } on client
  loader: async ({ params, context, search: routeSearch }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, dbKind, databaseId, tableId } = params
    const { queryClient } = context

    if (!projectId || !databaseId || !tableId) {
      return
    }

    throwRedirectCollectionsDbFromTablesChild(dbKind, 'columns', {
      projectId,
      dbKind,
      databaseId,
      tableId,
    })

    const { page, limit, filterQueries: columnsFilterQueries } =
      parseListSearch(routeSearch, {
        page: 1,
        limit: COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
      })
    const pageIndexed = Math.max(0, page - 1)

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts.
    // Prefetch columns with same filterQueries/page/limit as the View so one request and UI shows filtered list.
    await Promise.all([
      // Fetch tables list - blocks navigation until ready
      queryClient.ensureQueryData(
        tablesQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          0,
          TABLES_PER_PAGE,
        ),
      ),
      // Fetch database - blocks navigation until ready
      queryClient.ensureQueryData(databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind)),
      // Fetch columns with URL filters/page/limit so component uses same query key (single request, filtered list)
      queryClient.ensureQueryData(
        tableColumnsQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          tableId,
          columnsFilterQueries,
          pageIndexed,
          limit,
        ),
      ),
      // Prefetch indexes (optional data)
      queryClient.prefetchQuery(
        tableIndexesQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          tableId,
        ),
      ),
      // Fetch table - blocks navigation until ready
      queryClient.ensureQueryData(
        tableQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          tableId,
        ),
      ),
    ])
    const database = queryClient.getQueryData<{
      name?: string
      databaseType?: DatabaseType
    }>(databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind).queryKey)
    const dbType = database?.databaseType
    if (
      dbType === DatabaseType.Documentsdb ||
      dbType === DatabaseType.Vectorsdb
    ) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
        params: { projectId, dbKind, databaseId, tableId },
        replace: true,
      })
    }
    const table = queryClient.getQueryData<{ name?: string }>(
      tableQueryOptions(
        projectId,
        databaseId,
        dbKind as DatabaseRouteKind,
        tableId,
      ).queryKey,
    )
    return { database, table }
  },
  component: ColumnsPage,
})

function ColumnsPage() {
  const { databaseId, tableId } = Route.useParams()

  return (
    <Workspace databaseId={databaseId} tableId={tableId} activeTab="columns" />
  )
}
