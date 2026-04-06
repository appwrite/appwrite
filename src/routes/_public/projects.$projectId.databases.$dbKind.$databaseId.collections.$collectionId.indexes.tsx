import { createFileRoute } from '@tanstack/react-router'
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
import { throwRedirectTablesDbFromCollectionsChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/indexes',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.database?.name ?? 'Database', 'Databases'),
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

    const { projectId, dbKind, databaseId, collectionId } = params
    const { queryClient } = context

    if (!projectId || !databaseId || !collectionId) {
      return
    }

    throwRedirectTablesDbFromCollectionsChild(dbKind, 'indexes', {
      projectId,
      dbKind,
      databaseId,
      collectionId,
    })

    const url = new URL(location.pathname + location.search, 'http://localhost')
    const queryParam = getQueryParam(url)
    const filterMap = queryParamToMap(queryParam)
    const indexesFilterQueries =
      filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
    const page = getPage(url, 1)
    const limit = getLimit(url, COLUMNS_INDEXES_DEFAULT_PAGE_SIZE)
    const pageIndexed = Math.max(0, page - 1)

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts.
    // Prefetch indexes with same filterQueries/page/limit as the View so one request and UI shows filtered list.
    await Promise.all([
      // Fetch tables list - blocks navigation until ready
      queryClient.ensureQueryData(
        tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE),
      ),
      // Fetch database - blocks navigation until ready
      queryClient.ensureQueryData(databaseQueryOptions(projectId, databaseId)),
      // Fetch columns - blocks navigation until ready
      queryClient.ensureQueryData(
        tableColumnsQueryOptions(projectId, databaseId, collectionId),
      ),
      // Fetch indexes with URL filters/page/limit so component uses same query key (single request, filtered list)
      queryClient.ensureQueryData(
        tableIndexesQueryOptions(
          projectId,
          databaseId,
          collectionId,
          indexesFilterQueries,
          pageIndexed,
          limit,
        ),
      ),
      // Fetch table - blocks navigation until ready
      queryClient.ensureQueryData(
        tableQueryOptions(projectId, databaseId, collectionId),
      ),
    ])
    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId).queryKey,
    )
    return { database }
  },
  component: IndexesPage,
})

function IndexesPage() {
  const { databaseId, collectionId } = Route.useParams()

  return (
    <TableView databaseId={databaseId} tableId={collectionId} activeTab="indexes" />
  )
}
