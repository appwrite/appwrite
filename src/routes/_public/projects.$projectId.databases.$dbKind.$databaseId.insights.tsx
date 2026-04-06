import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/insights',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseInsights) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/',
        params: {
          projectId: params.projectId,
          dbKind: params.dbKind,
          databaseId: params.databaseId,
        },
        replace: true,
      })
    }
  },
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

    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId).queryKey,
    )
    return { database }
  },
  component: InsightsPage,
})

function InsightsPage() {
  const { databaseId } = Route.useParams()
  return (
    <TableView
      databaseId={databaseId}
      tableId="-"
      activeTab="rows"
      databaseTab="insights"
    />
  )
}
