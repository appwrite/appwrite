import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  tablesQueryOptions,
  databaseQueryOptions,
  tableColumnsQueryOptions,
  tableIndexesQueryOptions,
  tableRowsQueryOptions,
  tableQueryOptions,
  projectQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  listSearchSchema,
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  queryParamToMap,
} from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'
import { throwRedirectCollectionsDbFromTablesChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100
const DEFAULT_PAGE = 1

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.database?.name ?? 'Database', 'Databases'),
      },
    ],
  }),
  // No pendingComponent: keep the previous page visible until the loader's
  // prefetch is done, then transition with data already in cache. See
  // AGENTS.md → "Loading & Navigation" / "List & tab pages: never use pendingComponent".
  validateSearch: listSearchSchema,
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return

    const { projectId, dbKind, databaseId, tableId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    throwRedirectCollectionsDbFromTablesChild(dbKind, 'dataGrid', {
      projectId,
      dbKind,
      databaseId,
      tableId,
    })

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    const projectData = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )

    // Fetch tables list (needed for redirect logic) - blocks navigation
    const tablesPromise = queryClient.ensureQueryData(
      tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
    )

    // If tableId is '-', fetch first table and redirect to it if one exists
    if (tableId === '-') {
      const tablesData = await tablesPromise
      const sortedTables = [...(tablesData.tables || [])].sort((a, b) => {
        const nameA = a.name?.toLowerCase() || ''
        const nameB = b.name?.toLowerCase() || ''
        return nameA.localeCompare(nameB)
      })
      const firstTable = sortedTables[0]

      if (firstTable?.$id) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
          params: { projectId, dbKind, databaseId, tableId: firstTable.$id },
          replace: true,
        })
      }
      // No tables: stay on tables/-/rows and render TableView with database-level content
      await queryClient.ensureQueryData(
        databaseQueryOptions(projectId, databaseId),
      )
      const database = queryClient.getQueryData<{ name?: string }>(
        databaseQueryOptions(projectId, databaseId).queryKey,
      )
      return { database }
    }

    if (tableId) {
      // Check if table exists and if there are any tables
      const tablesData = await tablesPromise

      // If no tables exist, redirect to tables/-/rows (database main view)
      if (!tablesData.tables || tablesData.tables.length === 0) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
          params: { projectId, dbKind, databaseId, tableId: '-' },
          replace: true,
        })
      }

      // Check if the requested table exists in the tables list
      const tableExists = tablesData.tables.some(
        (table: unknown) => table.$id === tableId,
      )
      if (!tableExists) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
          params: { projectId, dbKind, databaseId, tableId: '-' },
          replace: true,
        })
      }
      const url = new URL(
        location.pathname + location.search,
        'http://localhost',
      )
      const search = getSearch(url)
      const page = getPage(url, DEFAULT_PAGE)
      const limit = getLimit(url, ROWS_DEFAULT_PAGE_SIZE)
      const queryParam = getQueryParam(url)
      const filterMap = queryParamToMap(queryParam)
      const filterQueries =
        filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

      await Promise.all([
        tablesPromise,

        queryClient.ensureQueryData(
          tableRowsQueryOptions(
            projectId,
            databaseId,
            tableId,
            page - 1,
            limit,
            search ?? undefined,
            'desc',
            '$createdAt',
            filterQueries,
          ),
        ),

        // Database details - blocks navigation until ready
        queryClient.ensureQueryData(
          databaseQueryOptions(projectId, databaseId),
        ),

        // Columns - blocks navigation until ready
        queryClient.ensureQueryData(
          tableColumnsQueryOptions(projectId, databaseId, tableId),
        ),

        // Table details - blocks navigation until ready
        queryClient.ensureQueryData(
          tableQueryOptions(projectId, databaseId, tableId),
        ),

        // Organization plan - CRITICAL for limit checking
        projectData?.teamId
          ? queryClient.ensureQueryData(
              organizationPlanQueryOptions(projectData.teamId),
            )
          : Promise.resolve(),

        // Prefetch indexes (optional data, not critical for rows tab) - doesn't block
        queryClient
          .prefetchQuery(
            tableIndexesQueryOptions(projectId, databaseId, tableId),
          )
          .catch(() => {
            // Don't block on optional data errors
          }),
      ])
      const database = queryClient.getQueryData<{ name?: string }>(
        databaseQueryOptions(projectId, databaseId).queryKey,
      )
      return { database }
    } else {
      await tablesPromise
    }
  },
  component: RowsPage,
})

function RowsPage() {
  const { databaseId, tableId } = Route.useParams()

  return (
    <TableView databaseId={databaseId} tableId={tableId} activeTab="rows" />
  )
}
