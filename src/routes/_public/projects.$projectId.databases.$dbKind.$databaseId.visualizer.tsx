import { createFileRoute } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
  allTablesForVisualizerQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/visualizer',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.database?.name ?? 'Database', 'Databases'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId),
    )
    await queryClient.ensureQueryData(
      tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
    )
    await queryClient.ensureQueryData(
      allTablesForVisualizerQueryOptions(projectId, databaseId),
    )

    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId).queryKey,
    )
    return { database }
  },
  component: VisualizerPage,
})

function VisualizerPage() {
  const { databaseId } = Route.useParams()
  return (
    <TableView
      databaseId={databaseId}
      tableId="-"
      activeTab="rows"
      databaseTab="visualizer"
    />
  )
}
