import { type DatabaseRouteKind } from '@/lib/database-routes'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { Workspace } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/monitor',
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

    const { projectId, dbKind, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return
    if (!getActiveProfileFeatures().usageStats) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/',
        params,
        replace: true,
      })
    }

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind),
    )
    await queryClient.ensureQueryData(
      tablesQueryOptions(
        projectId,
        databaseId,
        dbKind as DatabaseRouteKind,
        0,
        TABLES_PER_PAGE,
        undefined,
      ),
    )

    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind).queryKey,
    )
    return { database }
  },
  component: MonitorPage,
})

function MonitorPage() {
  const { databaseId } = Route.useParams()
  return (
    <Workspace
      databaseId={databaseId}
      tableId="-"
      activeTab="rows"
      databaseTab="monitor"
    />
  )
}
