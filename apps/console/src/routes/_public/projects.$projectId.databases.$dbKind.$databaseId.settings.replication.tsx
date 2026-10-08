import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/settings/Replication'
import {
  databaseQueryOptions,
  dedicatedDatabaseByIdQueryOptions,
  dedicatedDatabaseReplicasQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { canConfigureDedicatedReplication } from '@/lib/databases/database-compute'
import { dedicatedReplicationSourceFromRouteKind } from '@/lib/databases/dedicated-replication'
import {
  isDatabaseRouteKind,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import type { Models } from '@appwrite.io/console'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/settings/replication',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, dbKind: rawDbKind } = params
    const { queryClient } = context
    if (!projectId || !databaseId) return

    const dbKind = (
      isDatabaseRouteKind(rawDbKind ?? '') ? rawDbKind : 'tablesdb'
    ) as DatabaseRouteKind

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId, dbKind),
    )

    const dedicated = await queryClient.ensureQueryData(
      dedicatedDatabaseByIdQueryOptions(projectId, databaseId, {
        type: 'product',
        dbKind,
      }),
    )

    const product = queryClient.getQueryData<Models.Database>(
      databaseQueryOptions(projectId, databaseId, dbKind).queryKey,
    )

    const allowed = canConfigureDedicatedReplication(
      {
        $id: product?.$id,
        name: product?.name,
        databaseType: dbKind,
        status: product?.status as string | null | undefined,
        replicas: product?.replicas,
        specification: (
          product as { specification?: string | null } | null | undefined
        )?.specification,
      },
      dedicated,
    )

    if (!allowed) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/settings',
        params: { projectId, dbKind, databaseId },
        replace: true,
      })
    }

    const replicaCount =
      dedicated?.replicas ??
      (typeof product?.replicas === 'number' ? product.replicas : 0)

    if (replicaCount > 0) {
      await queryClient.ensureQueryData(
        dedicatedDatabaseReplicasQueryOptions(
          projectId,
          databaseId,
          dedicatedReplicationSourceFromRouteKind(dbKind),
        ),
      )
    }
  },
  component: View,
})
