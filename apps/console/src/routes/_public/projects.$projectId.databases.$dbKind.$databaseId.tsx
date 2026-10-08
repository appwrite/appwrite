import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  isDatabaseRouteKind,
  isProductDatabaseRouteKindEnabled,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { throwRedirectPostgresDbKind, throwRedirectMysqlDbKind } from '@/lib/database-route-redirects'
import { throwRedirectIfDedicatedDatabaseProvisioning } from '@/lib/databases/dedicated-database-provisioning-access'
import { DatabaseOperationsLockProvider } from '@/components/pages/projects/$projectId/databases/_components/DatabaseOperationsLockContext'
import { DedicatedDatabaseStatusHeaderAlert } from '@/components/pages/projects/$projectId/databases/_components/DedicatedDatabaseStatusHeaderAlert'
import { DatabaseTypeUnavailable } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeUnavailable'
import { useRedirectIfDedicatedDatabaseProvisioning } from '@/components/pages/projects/$projectId/databases/_components/useRedirectIfDedicatedDatabaseProvisioning'
import {
  resolveDatabaseLifecycleStatus,
  shouldPollDatabaseLifecycleStatus,
} from '@/lib/databases/dedicated-database-status'
import { TablesDatabaseDedicatedMigrationBanner } from '@/components/pages/projects/$projectId/databases/_components/TablesDatabaseDedicatedMigrationBanner'
import {
  databaseQueryOptions,
  dedicatedDatabasesQueryOptions,
  invalidateDatabaseModel,
  productRouteKindQueryOptions,
  projectQueryOptions,
  refetchProjectDatabaseLists,
  resolveProductRouteKindForDatabase,
  seedDatabaseProductRouteKind,
  tablesDatabaseMigrationsQueryKey,
  useActiveTablesDatabaseMigration,
  useProjectDatabase,
  useProjectDedicatedDatabases,
} from '@/lib/react-query/hooks'
import { useEffect, useMemo, useRef } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  hasTablesDatabaseMigrationCutover,
  shouldShowTablesDatabaseMigrationBanner,
} from '@/lib/databases/tables-database-migration'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId',
)({
  head: () => ({ meta: [{ title: pageTitle('Databases') }] }),
  beforeLoad: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return
    const { projectId, dbKind, databaseId } = params
        throwRedirectPostgresDbKind(dbKind, { projectId, databaseId })
    throwRedirectMysqlDbKind(dbKind, { projectId, databaseId })
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

    let status: string | null | undefined
    try {
      const database = await queryClient.ensureQueryData(
        databaseQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
        ),
      )
        const productStatus = (database as { status?: string | null } | null)
          ?.status
        status = productStatus
    } catch {
      status = undefined
    }
    try {
      const dedicated = await queryClient.ensureQueryData(
        dedicatedDatabasesQueryOptions(projectId),
      )
      const dedicatedStatus = dedicated?.databases?.find(
        (db) => db.$id === databaseId,
      )?.status
      status = resolveDatabaseLifecycleStatus(status, dedicatedStatus)
    } catch {
      /* Restriction is best-effort; page still loads if status is unknown. */
    }
    throwRedirectIfDedicatedDatabaseProvisioning(status, location.pathname, {
      to: '/projects/$projectId/databases/$dbKind/$databaseId/',
      params: { projectId, dbKind, databaseId },
    })
  },
  component: DatabaseKindLayout,
})

function DatabaseKindLayout() {
  const { projectId, dbKind, databaseId } = Route.useParams()
  const features = getActiveProfileFeatures()
  const routeKind = (
    isDatabaseRouteKind(dbKind) ? dbKind : 'tablesdb'
  ) as DatabaseRouteKind

  const { database } = useProjectDatabase(projectId, databaseId, routeKind)
  const { databases: dedicatedDatabases } = useProjectDedicatedDatabases(
    projectId,
  )
  const dedicatedStatus = dedicatedDatabases.find(
    (db) => db.$id === databaseId,
  )?.status
  const status = useMemo(
    () =>
      resolveDatabaseLifecycleStatus(
        (database as { status?: string | null } | null)?.status,
        dedicatedStatus,
      ),
    [database, dedicatedStatus],
  )

  const pollIntervalMs = shouldPollDatabaseLifecycleStatus(status) ? 5000 : false

  useQuery({
    ...databaseQueryOptions(projectId, databaseId, routeKind),
    refetchInterval: pollIntervalMs,
  })
  useQuery({
    ...dedicatedDatabasesQueryOptions(projectId),
    refetchInterval: pollIntervalMs,
  })

  const queryClient = useQueryClient()
  const refreshedAfterCutoverRef = useRef(false)
  const { migration: activeMigration } = useActiveTablesDatabaseMigration(
    projectId,
    databaseId,
    routeKind,
  )
  const showMigrationBanner =
    shouldShowTablesDatabaseMigrationBanner(activeMigration)

  useEffect(() => {
    if (!activeMigration || refreshedAfterCutoverRef.current) return
    if (
      !hasTablesDatabaseMigrationCutover(activeMigration) &&
      activeMigration.phase?.toLowerCase() !== 'done'
    ) {
      return
    }
    refreshedAfterCutoverRef.current = true
    invalidateDatabaseModel(projectId, databaseId)
    void Promise.all([
      queryClient.invalidateQueries({
        queryKey: ['database', 'project', projectId, databaseId],
      }),
      queryClient.invalidateQueries({
        queryKey: tablesDatabaseMigrationsQueryKey(projectId, databaseId),
      }),
      refetchProjectDatabaseLists(queryClient, projectId),
    ])
  }, [activeMigration, databaseId, projectId, queryClient])

  useRedirectIfDedicatedDatabaseProvisioning(
    status,
    '/projects/$projectId/databases/$dbKind/$databaseId/',
    { projectId, dbKind, databaseId },
  )

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
        {showMigrationBanner ? (
          <TablesDatabaseDedicatedMigrationBanner
            projectId={projectId}
            databaseId={databaseId}
            migration={activeMigration}
          />
        ) : (
          <DedicatedDatabaseStatusHeaderAlert status={status} />
        )}
        <div className="min-h-0 flex-1 overflow-hidden">
          <Outlet />
        </div>
      </div>
    </DatabaseOperationsLockProvider>
  )
}
