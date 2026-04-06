import { createFileRoute, redirect } from '@tanstack/react-router'
import { DatabaseType } from '@appwrite.io/console'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  getLimit,
  getPage,
  getQueryParam,
  listSearchSchema,
  queryParamToMap,
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
          (loaderData as { database?: { name?: string } } | undefined)?.database
            ?.name ?? 'Database',
          'Databases',
        ),
      },
    ],
  }),
  validateSearch: listSearchSchema,
  // @ts-expect-error - route tree may infer loader as never; loader returns { database } on client
  loader: async ({ params, context, location }) => {
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

    const url = new URL(location.pathname + location.search, 'http://localhost')
    const queryParam = getQueryParam(url)
    const filterMap = queryParamToMap(queryParam)
    const columnsFilterQueries =
      filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
    const page = getPage(url, 1)
    const limit = getLimit(url, COLUMNS_INDEXES_DEFAULT_PAGE_SIZE)
    const pageIndexed = Math.max(0, page - 1)

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts.
    // Prefetch columns with same filterQueries/page/limit as the View so one request and UI shows filtered list.
    await Promise.all([
      // Fetch tables list - blocks navigation until ready
      queryClient.ensureQueryData(
        tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE),
      ),
      // Fetch database - blocks navigation until ready
      queryClient.ensureQueryData(databaseQueryOptions(projectId, databaseId)),
      // Fetch columns with URL filters/page/limit so component uses same query key (single request, filtered list)
      queryClient.ensureQueryData(
        tableColumnsQueryOptions(
          projectId,
          databaseId,
          tableId,
          columnsFilterQueries,
          pageIndexed,
          limit,
        ),
      ),
      // Prefetch indexes (optional data)
      queryClient.prefetchQuery(
        tableIndexesQueryOptions(projectId, databaseId, tableId),
      ),
      // Fetch table - blocks navigation until ready
      queryClient.ensureQueryData(
        tableQueryOptions(projectId, databaseId, tableId),
      ),
    ])
    const database = queryClient.getQueryData<{
      name?: string
      databaseType?: DatabaseType
    }>(databaseQueryOptions(projectId, databaseId).queryKey)
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
    return { database }
  },
  component: ColumnsPage,
})

function ColumnsPage() {
  const { databaseId, tableId } = Route.useParams()

  return (
    <TableView databaseId={databaseId} tableId={tableId} activeTab="columns" />
  )
}
