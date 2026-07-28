import { type DatabaseRouteKind } from '@/lib/database-routes'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { Workspace } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
  backupPoliciesQueryOptions,
  backupArchivesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/backups',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseBackups) {
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

    const { projectId, dbKind, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

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

    await Promise.all([
      queryClient.prefetchQuery(
        backupPoliciesQueryOptions(projectId, databaseId),
      ),
      queryClient.prefetchQuery(
        backupArchivesQueryOptions(projectId, databaseId, 0, 10),
      ),
    ]).catch(() => {})

    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind).queryKey,
    )
    return { database }
  },
  component: BackupsPage,
})

function BackupsPage() {
  const { databaseId } = Route.useParams()
  return (
    <Workspace
      databaseId={databaseId}
      tableId="-"
      activeTab="rows"
      databaseTab="backups"
    />
  )
}
