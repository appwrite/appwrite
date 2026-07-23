import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  isDatabaseRouteKind,
  isProductDatabaseRouteKindEnabled,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { throwRedirectPostgresDbKind } from '@/lib/database-route-redirects'
import { DatabaseOperationsLockProvider } from '@/components/pages/projects/$projectId/databases/_components/DatabaseOperationsLockContext'
import { DedicatedDatabaseStatusHeaderAlert } from '@/components/pages/projects/$projectId/databases/_components/DedicatedDatabaseStatusHeaderAlert'
import { DatabaseTypeUnavailable } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeUnavailable'
import {
  productRouteKindQueryOptions,
  projectQueryOptions,
  resolveProductRouteKindForDatabase,
  seedDatabaseProductRouteKind,
  useProjectDatabase,
  useProjectDedicatedDatabases,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId',
)({
  head: () => ({ meta: [{ title: pageTitle('Databases') }] }),
  beforeLoad: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, dbKind, databaseId } = params
    throwRedirectPostgresDbKind(dbKind, { projectId, databaseId })
    if (!isDatabaseRouteKind(dbKind)) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }

    const features = getActiveProfileFeatures()
    if (
      !isProductDatabaseRouteKindEnabled(
        dbKind as DatabaseRouteKind,
        features,
      )
    ) {
      return
    }

    // Pin product API routing before any get/probe so VectorsDB never hits DocumentsDB.
    seedDatabaseProductRouteKind(projectId, databaseId, dbKind)

    const { queryClient } = context
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    const routeKindKey = productRouteKindQueryOptions(projectId, databaseId)
      .queryKey
    let expected = queryClient.getQueryData<
      Awaited<ReturnType<typeof resolveProductRouteKindForDatabase>>
    >(routeKindKey)

    if (expected !== dbKind) {
      const resolved = await resolveProductRouteKindForDatabase(
        projectId,
        databaseId,
        dbKind,
      )
      if (resolved != null) {
        expected = resolved
        queryClient.setQueryData(routeKindKey, resolved)
      }
    }

    if (expected == null) {
      expected = await resolveProductRouteKindForDatabase(
        projectId,
        databaseId,
        dbKind,
      )
      if (expected != null) {
        queryClient.setQueryData(routeKindKey, expected)
      }
    }
    if (!expected) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }
    if (dbKind !== expected) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId',
        params: { projectId, dbKind: expected, databaseId },
        replace: true,
      })
    }

    // Re-seed after resolution in case cache was cleared during validation.
    seedDatabaseProductRouteKind(projectId, databaseId, dbKind)
  },
  component: DatabaseKindLayout,
})

function DatabaseKindLayout() {
  const { projectId, dbKind, databaseId } = Route.useParams()
  const features = getActiveProfileFeatures()
  const routeKind = isDatabaseRouteKind(dbKind)
    ? (dbKind as DatabaseRouteKind)
    : undefined

  const { database } = useProjectDatabase(projectId, databaseId, routeKind)
  const { databases: dedicatedDatabases } = useProjectDedicatedDatabases(
    projectId,
  )
  const dedicatedStatus = dedicatedDatabases.find(
    (db) => db.$id === databaseId,
  )?.status
  const status =
    (database as { status?: string | null } | null)?.status ??
    dedicatedStatus ??
    null

  if (
    isDatabaseRouteKind(dbKind) &&
    !isProductDatabaseRouteKindEnabled(dbKind, features)
  ) {
    return <DatabaseTypeUnavailable projectId={projectId} />
  }

  return (
    <DatabaseOperationsLockProvider
      projectId={projectId}
      databaseId={databaseId}
      status={status}
    >
      <div className="flex h-full min-h-0 flex-1 flex-col overflow-hidden">
        <DedicatedDatabaseStatusHeaderAlert status={status} />
        <div className="min-h-0 flex-1 overflow-hidden">
          <Outlet />
        </div>
      </div>
    </DatabaseOperationsLockProvider>
  )
}
