import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  fetchProjectTables,
  fetchProjectDatabase,
  fetchProjectTableColumns,
  fetchProjectTableIndexes,
  fetchProjectTableRows,
  fetchProjectTable,
} from '@/lib/react-query/hooks'

const TABLES_PER_PAGE = 100
const ROWS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
)({
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

    // Fetch tables list (needed for redirect logic) - blocks navigation
    const tablesPromise = queryClient.fetchQuery({
      queryKey: [
        'tables',
        'project',
        projectId,
        databaseId,
        0,
        TABLES_PER_PAGE,
        undefined,
      ],
      queryFn: () =>
        fetchProjectTables(
          projectId,
          databaseId,
          0,
          TABLES_PER_PAGE,
          undefined,
        ),
      staleTime: 30 * 1000, // 30 seconds
    })

    // If tableId is '-', fetch first table and redirect to it
    if (tableId === '-') {
      const tablesData = await tablesPromise
      // Sort tables by name in ascending order before selecting the first one
      const sortedTables = [...(tablesData.tables || [])].sort((a, b) => {
        const nameA = a.name?.toLowerCase() || ''
        const nameB = b.name?.toLowerCase() || ''
        return nameA.localeCompare(nameB)
      })
      const firstTable = sortedTables[0]

      if (firstTable?.$id) {
        // Redirect to the first table with replace to update URL history
        throw redirect({
          to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
          params: { projectId, databaseId, tableId: firstTable.$id },
          replace: true,
        })
      }
      // If no tables exist, redirect to database index (tables view)
      throw redirect({
        to: '/projects/$projectId/databases/$databaseId/',
        params: { projectId, databaseId },
        replace: true,
      })
    }

    if (tableId) {
      // Check if table exists and if there are any tables
      const tablesData = await tablesPromise

      // If no tables exist, redirect to database index (tables view)
      if (!tablesData.tables || tablesData.tables.length === 0) {
        throw redirect({
          to: '/projects/$projectId/databases/$databaseId/',
          params: { projectId, databaseId },
          replace: true,
        })
      }

      // Check if the requested table exists in the tables list
      const tableExists = tablesData.tables.some(
        (table: any) => table.$id === tableId,
      )
      if (!tableExists) {
        // Table not found, redirect to database index (tables view)
        throw redirect({
          to: '/projects/$projectId/databases/$databaseId/',
          params: { projectId, databaseId },
          replace: true,
        })
      }
      // Fetch critical data before rendering to prevent layout shifts
      const databasePromise = queryClient.fetchQuery({
        queryKey: ['database', 'project', projectId, databaseId],
        queryFn: () => fetchProjectDatabase(projectId, databaseId),
        staleTime: 30 * 1000,
      })

      const columnsPromise = queryClient.fetchQuery({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
        queryFn: () => fetchProjectTableColumns(projectId, databaseId, tableId),
        staleTime: 30 * 1000, // 30 seconds
      })

      // Prefetch indexes (optional data, not critical for rows tab)
      const indexesPromise = queryClient.prefetchQuery({
        queryKey: ['indexes', 'project', projectId, databaseId, tableId],
        queryFn: () => fetchProjectTableIndexes(projectId, databaseId, tableId),
        staleTime: 30 * 1000, // 30 seconds
      })

      const rowsPromise = queryClient.fetchQuery({
        queryKey: [
          'rows',
          'project',
          projectId,
          databaseId,
          tableId,
          0,
          ROWS_PER_PAGE,
          '',
        ],
        queryFn: () =>
          fetchProjectTableRows(
            projectId,
            databaseId,
            tableId,
            0,
            ROWS_PER_PAGE,
            '',
          ),
        staleTime: 30 * 1000, // 30 seconds
      })

      const tablePromise = queryClient.fetchQuery({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
        queryFn: () => fetchProjectTable(projectId, databaseId, tableId),
        staleTime: 30 * 1000, // 30 seconds
      })

      await Promise.all([
        tablesPromise,
        databasePromise,
        columnsPromise,
        indexesPromise,
        rowsPromise,
        tablePromise,
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
