import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  PostgresDatabaseReplicasCard,
  PostgresDatabaseSyncModeCard,
} from '../postgres/_components/PostgresDatabaseConfigSettings'
import { PostgresDatabasePrimaryCard } from '../postgres/_components/PostgresDatabasePrimaryCard'
import { useDatabaseSettingsPage } from './useDatabaseSettingsPage'
import { DatabaseSettingsLoading } from './DatabaseSettingsLoading'
import {
  dedicatedDatabaseByIdQueryOptions,
  useProjectDatabase,
} from '@/lib/react-query/hooks'
import {
  canConfigureDedicatedReplication,
  productDedicatedEngineHints,
} from '@/lib/databases/database-compute'
import { dedicatedReplicationSourceFromRouteKind } from '@/lib/databases/dedicated-replication'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

function toReplicationDatabase(
  dedicated: Models.DedicatedDatabase | null | undefined,
  product: {
    $id: string
    name: string
    status?: string | null
    replicas?: number | null
  } | null,
  fallbackEngine: string,
): Models.DedicatedDatabase | null {
  if (dedicated?.$id) return dedicated
  if (!product?.$id) return null
  // Minimal shape so HA cards can render before the engine row is listed.
  return {
    $id: product.$id,
    name: product.name,
    status: product.status ?? 'ready',
    replicas: typeof product.replicas === 'number' ? product.replicas : 0,
    syncMode: 'async',
    engine: fallbackEngine,
    api: 'tablesdb',
    specification: '',
  } as Models.DedicatedDatabase
}

export function View() {
  const t = useT()
  const { projectId, databaseId, dbKind, canWrite, isLoading } =
    useDatabaseSettingsPage()
  const { database: productDatabase } = useProjectDatabase(
    projectId,
    databaseId,
    dbKind,
  )
  const engineHints = useMemo(
    () => productDedicatedEngineHints(dbKind),
    [dbKind],
  )
  const { data: dedicated, isLoading: dedicatedLoading } = useQuery(
    dedicatedDatabaseByIdQueryOptions(projectId, databaseId, {
      type: 'product',
      dbKind,
    }),
  )

  if (isLoading || dedicatedLoading) return <DatabaseSettingsLoading />

  const productHints = {
    $id: productDatabase?.$id,
    name: productDatabase?.name,
    databaseType: dbKind,
    status: (productDatabase as { status?: string | null } | null)?.status,
    replicas: (productDatabase as { replicas?: number | null } | null)?.replicas,
    specification: (
      productDatabase as { specification?: string | null } | null
    )?.specification,
  }

  if (!canConfigureDedicatedReplication(productHints, dedicated)) {
    return (
      <EmptyState
        title={t('Replication is available on dedicated databases')}
        description={t(
          'Upgrade this database to a dedicated specification to configure read replicas and failover.',
        )}
      />
    )
  }

  const database = toReplicationDatabase(dedicated, {
    $id: productDatabase?.$id ?? databaseId,
    name: productDatabase?.name ?? databaseId,
    status: productHints.status,
    replicas: productHints.replicas,
  }, engineHints[0] || 'postgresql')
  if (!database) return null

  const replicationSource = dedicatedReplicationSourceFromRouteKind(dbKind)
  const haEngine = dedicated?.engine || engineHints[0] || 'postgresql'
  const cardProps = {
    projectId,
    databaseId,
    database,
    canWrite,
    replicationSource,
    haEngine,
  }

  const cards: SettingsCardItem[] = [
    {
      id: 'replicas',
      search: {
        title: 'Read replicas',
        keywords: ['replica', 'replicas', 'failover', 'ha', 'topology', 'cluster'],
      },
      node: <PostgresDatabaseReplicasCard {...cardProps} />,
    },
    ...(database.replicas && database.replicas > 0
      ? [
          {
            id: 'primary',
            search: {
              title: 'Primary instance',
              keywords: [
                'primary',
                'main',
                'leader',
                'failover',
                'promote',
                'promotion',
              ],
            },
            node: <PostgresDatabasePrimaryCard {...cardProps} />,
          } satisfies SettingsCardItem,
        ]
      : []),
    {
      id: 'sync-mode',
      search: {
        title: 'Sync mode',
        keywords: ['sync', 'async', 'synchronous', 'quorum', 'replication'],
      },
      node: <PostgresDatabaseSyncModeCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
