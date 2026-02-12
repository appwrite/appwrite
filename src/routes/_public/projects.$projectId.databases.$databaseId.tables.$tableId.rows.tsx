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

const TABLES_PER_PAGE = 100
const ROWS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
)({
  pendingComponent: () => (
    <div className="flex h-full items-center justify-center">
      <div className="text-muted-foreground">Loading rows...</div>
    </div>
  ),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, databaseId, tableId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) {
      return
    }

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
          to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
          params: { projectId, databaseId, tableId: firstTable.$id },
          replace: true,
        })
      }
      // No tables: stay on tables/-/rows and render TableView with database-level content
      await queryClient.ensureQueryData(
        databaseQueryOptions(projectId, databaseId),
      )
      return
    }

    if (tableId) {
      // Check if table exists and if there are any tables
      const tablesData = await tablesPromise

      // If no tables exist, redirect to tables/-/rows (database main view)
      if (!tablesData.tables || tablesData.tables.length === 0) {
        throw redirect({
          to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
          params: { projectId, databaseId, tableId: '-' },
          replace: true,
        })
      }

      // Check if the requested table exists in the tables list
      const tableExists = tablesData.tables.some(
        (table: unknown) => table.$id === tableId,
      )
      if (!tableExists) {
        throw redirect({
          to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
          params: { projectId, databaseId, tableId: '-' },
          replace: true,
        })
      }
      // Fetch critical data before rendering to prevent layout shifts
      // All of these must complete before navigation proceeds to prevent loading states
      // This ensures both tables list and rows are loaded before navigation, just like buckets/users/functions
      await Promise.all([
        // Tables list - CRITICAL: blocks navigation until ready (prevents loader when switching tables)
        tablesPromise,

        // Rows - CRITICAL: blocks navigation until ready (prevents loader when switching tables)
        queryClient.ensureQueryData(
          tableRowsQueryOptions(
            projectId,
            databaseId,
            tableId,
            0,
            ROWS_PER_PAGE,
            '',
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
