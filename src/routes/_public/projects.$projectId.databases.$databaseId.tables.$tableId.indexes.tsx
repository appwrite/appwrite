import { createFileRoute } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  getQueryParam,
  listSearchSchema,
  queryParamToMap,
} from '@/lib/table-filters'
import {
  projectQueryOptions,
  tablesQueryOptions,
  databaseQueryOptions,
  tableColumnsQueryOptions,
  tableIndexesQueryOptions,
  tableQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/tables/$tableId/indexes',
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

    const { projectId, databaseId, tableId } = params
    const { queryClient } = context

    if (!projectId || !databaseId || !tableId) {
      return
    }

    const url = new URL(location.pathname + location.search, 'http://localhost')
    const queryParam = getQueryParam(url)
    const filterMap = queryParamToMap(queryParam)
    const indexesFilterQueries =
      filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts.
    // Prefetch indexes with same filterQueries as the View so one request and UI shows filtered list.
    await Promise.all([
      // Fetch tables list - blocks navigation until ready
      queryClient.ensureQueryData(
        tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE),
      ),
      // Fetch database - blocks navigation until ready
      queryClient.ensureQueryData(databaseQueryOptions(projectId, databaseId)),
      // Fetch columns - blocks navigation until ready
      queryClient.ensureQueryData(
        tableColumnsQueryOptions(projectId, databaseId, tableId),
      ),
      // Fetch indexes with URL filters so component uses same query key (single request, filtered list)
      queryClient.ensureQueryData(
        tableIndexesQueryOptions(
          projectId,
          databaseId,
          tableId,
          indexesFilterQueries,
        ),
      ),
      // Fetch table - blocks navigation until ready
      queryClient.ensureQueryData(
        tableQueryOptions(projectId, databaseId, tableId),
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
  const { databaseId, tableId } = Route.useParams()

  return (
    <TableView databaseId={databaseId} tableId={tableId} activeTab="indexes" />
  )
}
