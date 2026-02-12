import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
  allTablesForVisualizerQueryOptions,
} from '@/lib/react-query/hooks'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/tables/$tableId/visualizer',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, tableId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId),
    )
    const tablesData = await queryClient.ensureQueryData(
      tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
    )

    if (tableId !== '-') {
      const tableExists = (tablesData.tables || []).some(
        (t: { $id: string }) => t.$id === tableId,
      )
      if (!tableExists) {
        const first = [...(tablesData.tables || [])].sort((a, b) =>
          (a.name?.toLowerCase() || '').localeCompare(
            b.name?.toLowerCase() || '',
          ),
        )[0]
        throw redirect({
          to: '/projects/$projectId/databases/$databaseId/tables/$tableId/visualizer',
          params: {
            projectId,
            databaseId,
            tableId: first?.$id ?? '-',
          },
          replace: true,
        })
      }
    }

    await queryClient.ensureQueryData(
      allTablesForVisualizerQueryOptions(projectId, databaseId),
    )
  },
  component: VisualizerPage,
})

function VisualizerPage() {
  const { databaseId, tableId } = Route.useParams()
  return (
    <TableView
      databaseId={databaseId}
      tableId={tableId}
      activeTab="rows"
      databaseTab="visualizer"
    />
  )
}
