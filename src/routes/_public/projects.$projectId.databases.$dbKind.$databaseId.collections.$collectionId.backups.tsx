import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
  backupPoliciesQueryOptions,
  backupArchivesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { throwRedirectTablesDbFromCollectionsChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/backups',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseBackups) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
        params: {
          projectId: params.projectId,
          dbKind: params.dbKind,
          databaseId: params.databaseId,
          collectionId: params.collectionId,
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

    const { projectId, dbKind, databaseId, collectionId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    throwRedirectTablesDbFromCollectionsChild(dbKind, 'backups', {
      projectId,
      dbKind,
      databaseId,
      collectionId,
    })

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId),
    )
    const tablesData = await queryClient.ensureQueryData(
      tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
    )

    if (collectionId !== '-') {
      const tableExists = (tablesData.tables || []).some(
        (t: { $id: string }) => t.$id === collectionId,
      )
      if (!tableExists) {
        const first = [...(tablesData.tables || [])].sort((a, b) =>
          (a.name?.toLowerCase() || '').localeCompare(
            b.name?.toLowerCase() || '',
          ),
        )[0]
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/backups',
          params: {
            projectId,
            databaseId,
            collectionId: first?.$id ?? '-',
          },
          replace: true,
        })
      }
    }

    await Promise.all([
      queryClient.prefetchQuery(
        backupPoliciesQueryOptions(projectId, databaseId),
      ),
      queryClient.prefetchQuery(
        backupArchivesQueryOptions(projectId, databaseId, 0, 10),
      ),
    ]).catch(() => {})

    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId).queryKey,
    )
    return { database }
  },
  component: BackupsPage,
})

function BackupsPage() {
  const { databaseId, collectionId } = Route.useParams()
  return (
    <TableView
      databaseId={databaseId}
      collectionId={collectionId}
      activeTab="rows"
      databaseTab="backups"
    />
  )
}
