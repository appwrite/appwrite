import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  productRouteKindQueryOptions,
  useDedicatedDatabaseCardMetrics,
  useProjectDedicatedDatabases,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  dedicatedDatabaseHomeLink,
  isNativeDedicatedDatabase,
  isProductOwnedDedicatedDatabase,
  needsDedicatedProductTypeLookup,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import {
  getNativeDatabaseEmptyLabel,
  matchesNativeEngine,
  NATIVE_DATABASE_ENGINE_LABELS,
  type NativeDatabaseEngine,
} from '@/lib/databases/native-database-engines'
import { useQueries } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useMemo } from 'react'
import { AlertCircle, Cpu, Loader2, type LucideIcon } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import {
  MySQLDolphinIcon,
  MongoDbLeafIcon,
  PostgresElephantIcon,
} from './database-mascot-icons'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
} from '../../shared/ResourceCard'
import {
  DedicatedDatabaseRegionUnavailableBadge,
  DedicatedDatabaseRegionUnavailableCard,
} from './DedicatedDatabaseRegionUnavailableCard'
import {
  DatabaseClusterPreview,
  clusterNodeStatusesFromDatabaseStatus,
} from './DatabaseClusterPreview'
import { useT } from '@/lib/i18n/translate'
import { localizeResourceStatusLabel } from '@/lib/i18n/resource-status-labels'
import { getStatusColor } from '@/lib/utils/status-badge'

type DedicatedDatabasesSectionProps = {
  projectId: string
  viewMode: 'list' | 'grid'
  regionSupported?: boolean
  /** When set, only list databases for this native engine. */
  nativeEngine?: NativeDatabaseEngine
  /** Hide PostgreSQL and MySQL rows when they have their own sections. */
  excludeNativeEngines?: boolean
}

type SectionIcon =
  | LucideIcon
  | typeof PostgresElephantIcon
  | typeof MySQLDolphinIcon
  | typeof MongoDbLeafIcon

type DedicatedSectionCopy = {
  title: string
  description: string
  loadingLabel: string
  failedLabel: string
  refreshFailedLabel: string
  emptyTitle: string
  emptyDescription: string
  icon: SectionIcon
  showEngineColumn: boolean
}

function getDedicatedSectionCopy(
  nativeEngine?: NativeDatabaseEngine,
): DedicatedSectionCopy {
  if (nativeEngine === 'postgres') {
    return {
      title: NATIVE_DATABASE_ENGINE_LABELS.postgres,
      description:
        'A dedicated PostgreSQL database for relational workloads, SQL tooling, and portable schemas.',
      loadingLabel: 'Loading PostgreSQL databases...',
      failedLabel: 'Failed to load PostgreSQL databases',
      refreshFailedLabel: "Couldn't refresh PostgreSQL databases",
      emptyTitle: getNativeDatabaseEmptyLabel('postgres'),
      emptyDescription:
        'Create a PostgreSQL database from the create database wizard.',
      icon: PostgresElephantIcon,
      showEngineColumn: false,
    }
  }

  if (nativeEngine === 'mysql') {
    return {
      title: NATIVE_DATABASE_ENGINE_LABELS.mysql,
      description:
        'A dedicated MySQL database for common relational workloads and existing MySQL applications.',
      loadingLabel: 'Loading MySQL databases...',
      failedLabel: 'Failed to load MySQL databases',
      refreshFailedLabel: "Couldn't refresh MySQL databases",
      emptyTitle: getNativeDatabaseEmptyLabel('mysql'),
      emptyDescription:
        'Create a MySQL database from the create database wizard.',
      icon: MySQLDolphinIcon,
      showEngineColumn: false,
    }
  }

  if (nativeEngine === 'mongo') {
    return {
      title: NATIVE_DATABASE_ENGINE_LABELS.mongo,
      description:
        'A dedicated MongoDB database for document workloads, flexible schemas, and existing MongoDB applications.',
      loadingLabel: 'Loading MongoDB databases...',
      failedLabel: 'Failed to load MongoDB databases',
      refreshFailedLabel: "Couldn't refresh MongoDB databases",
      emptyTitle: getNativeDatabaseEmptyLabel('mongo'),
      emptyDescription:
        'Create a MongoDB database from the create database wizard.',
      icon: MongoDbLeafIcon,
      showEngineColumn: false,
    }
  }

  return {
    title: 'Dedicated databases',
    description:
      'Always-on dedicated databases for PostgreSQL, MySQL, MongoDB, and product-backed engines.',
    loadingLabel: 'Loading dedicated databases...',
    failedLabel: 'Failed to load dedicated databases',
    refreshFailedLabel: "Couldn't refresh dedicated databases",
    emptyTitle: 'No dedicated databases yet',
    emptyDescription:
      'Create a PostgreSQL, MySQL, or MongoDB database to get started with dedicated compute.',
    icon: Cpu,
    showEngineColumn: true,
  }
}

function filterDedicatedDatabases(
  databases: Models.DedicatedDatabase[],
  nativeEngine?: NativeDatabaseEngine,
  excludeNativeEngines?: boolean,
): Models.DedicatedDatabase[] {
  if (nativeEngine) {
    return databases.filter(
      (db) =>
        isNativeDedicatedDatabase(db) &&
        matchesNativeEngine(db.engine, nativeEngine),
    )
  }
  if (excludeNativeEngines) {
    // Product-owned dedicated compute (not listed in native postgres/mysql sections).
    return databases.filter((db) => isProductOwnedDedicatedDatabase(db))
  }
  return databases
}

function formatEngineLabel(engine: string): string {
  switch (engine.toLowerCase()) {
    case 'postgres':
    case 'postgresql':
      return 'PostgreSQL'
    case 'mysql':
      return 'MySQL'
    case 'mariadb':
      return 'MariaDB'
    case 'mongodb':
    case 'mongo':
      return 'MongoDB'
    default:
      return engine
  }
}

function dedicatedStatusVariant(
  status: string,
): 'success' | 'warning' | 'error' | 'info' {
  switch (status.toLowerCase()) {
    case 'ready':
      return 'success'
    case 'provisioning':
    case 'restoring':
    case 'scaling':
    case 'upgrading':
    case 'migrating':
    case 'pausing':
    case 'resuming':
    case 'deleting':
      return 'info'
    case 'inactive':
    case 'paused':
      return 'warning'
    case 'failed':
    case 'deleted':
      return 'error'
    default:
      return 'info'
  }
}

function DedicatedDatabaseStatusBadge({ status }: { status: string }) {
  const t = useT()
  return (
    <Badge
      variant={dedicatedStatusVariant(status)}
      className="text-[11px] font-medium border px-2 py-0.5 capitalize"
    >
      {localizeResourceStatusLabel(status, t)}
    </Badge>
  )
}

function DedicatedDatabaseCard({
  db,
  projectId,
  productRouteKindByDedicatedId,
  icon = Cpu,
  showEngineMetadata = true,
}: {
  db: Models.DedicatedDatabase
  projectId: string
  productRouteKindByDedicatedId: Map<string, DatabaseRouteKind>
  icon?: SectionIcon
  showEngineMetadata?: boolean
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const Icon = (icon ?? Cpu) as LucideIcon
  const link = dedicatedDatabaseHomeLink(
    projectId,
    db,
    productRouteKindByDedicatedId.get(db.$id),
  )
  const status = dedicatedStatusVariant(db.status)
  const statusLabel = localizeResourceStatusLabel(db.status, t)
  const replicaCount = db.replicas ?? 0
  const { nodeMetrics, connections } = useDedicatedDatabaseCardMetrics(
    projectId,
    db.$id,
    replicaCount,
    features.usageStats,
  )
  const connectionsLabel =
    connections == null
      ? '-'
      : connections === 1
        ? `1 ${t('connection')}`
        : `${connections.toLocaleString()} ${t('connections')}`

  const card = (
    <div
      className={cn(
        RESOURCE_CARD_PADDED_CLASSNAME,
        link && RESOURCE_CARD_INTERACTIVE_CLASSNAME,
        'pb-0',
      )}
    >
      <div className="flex min-w-0 items-start gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
          <Icon className="h-5 w-5" />
        </div>
        <div className="min-w-0 flex-1 overflow-hidden">
          <div className="flex min-w-0 flex-wrap items-center gap-1.5">
            <h3 className="truncate text-[14px] font-medium text-foreground">
              {db.name}
            </h3>
            <span
              className={cn(
                'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium',
                getStatusColor(status),
              )}
            >
              {statusLabel}
            </span>
          </div>
          <div className="mt-1.5">
            <CopyableId id={db.$id} size="xs" maxWidth={120} />
          </div>
        </div>
      </div>

      <DatabaseClusterPreview
        replicaCount={replicaCount}
        nodeStatuses={clusterNodeStatusesFromDatabaseStatus(
          db.status,
          replicaCount,
        )}
        nodeMetrics={nodeMetrics}
      />

      <div className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}>
        <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted-foreground">
          {showEngineMetadata ? (
            <span className="truncate">
              <span className="text-muted-foreground/80">{t('Engine')}</span>{' '}
              <span className="font-medium text-foreground">
                {formatEngineLabel(db.engine)}
              </span>
            </span>
          ) : null}
          <span className="truncate text-muted-foreground">
            {db.specification || t('Not set')}
          </span>
          <span className="truncate">
            <span className="text-muted-foreground/80">{t('Replicas')}</span>{' '}
            <span className="font-medium text-foreground">
              {db.replicas > 0 ? db.replicas : t('None')}
            </span>
          </span>
          <span className="truncate tabular-nums text-muted-foreground">
            {connectionsLabel}
          </span>
        </div>
      </div>
    </div>
  )

  if (!link) return card

  return (
    <Link {...link} className="block min-w-0">
      {card}
    </Link>
  )
}

export function DedicatedDatabasesSection({
  projectId,
  viewMode,
  regionSupported = true,
  nativeEngine,
  excludeNativeEngines,
}: DedicatedDatabasesSectionProps) {
  const t = useT()
  const sectionCopy = getDedicatedSectionCopy(nativeEngine)
  const SectionIcon = sectionCopy.icon

  if (!regionSupported) {
    return (
      <section className="mt-10">
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <h2 className="text-[15px] font-semibold text-foreground">
            {t(sectionCopy.title)}
          </h2>
          <DedicatedDatabaseRegionUnavailableBadge />
          <p className="w-full text-[13px] text-muted-foreground">
            {t(sectionCopy.description)}
          </p>
        </div>
        <DedicatedDatabaseRegionUnavailableCard
          icon={SectionIcon as LucideIcon}
        />
      </section>
    )
  }

  return (
    <DedicatedDatabasesSectionContent
      projectId={projectId}
      viewMode={viewMode}
      nativeEngine={nativeEngine}
      excludeNativeEngines={excludeNativeEngines}
    />
  )
}

function DedicatedDatabasesSectionContent({
  projectId,
  viewMode,
  nativeEngine,
  excludeNativeEngines,
}: Omit<DedicatedDatabasesSectionProps, 'regionSupported'>) {
  const t = useT()
  const sectionCopy = getDedicatedSectionCopy(nativeEngine)
  const SectionIcon = sectionCopy.icon
  const {
    databases,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useProjectDedicatedDatabases(projectId)

  const visibleDatabases = useMemo(
    () =>
      filterDedicatedDatabases(
        databases,
        nativeEngine,
        excludeNativeEngines,
      ),
    [databases, excludeNativeEngines, nativeEngine],
  )

  const dedicatedDatabasesNeedingProductType = useMemo(
    () => visibleDatabases.filter(needsDedicatedProductTypeLookup),
    [visibleDatabases],
  )

  const productRouteKindLookups = useQueries({
    queries: dedicatedDatabasesNeedingProductType.map((db) =>
      productRouteKindQueryOptions(projectId, db.$id),
    ),
  })

  const productRouteKindByDedicatedId = useMemo(() => {
    const map = new Map<string, DatabaseRouteKind>()
    dedicatedDatabasesNeedingProductType.forEach((db, index) => {
      const kind = productRouteKindLookups[index]?.data
      if (kind) map.set(db.$id, kind)
    })
    return map
  }, [dedicatedDatabasesNeedingProductType, productRouteKindLookups])

  const errorMessage = error ? getErrorMessage(error) : null

  return (
    <section className="mt-10">
      <div className="mb-4">
        <h2 className="text-[15px] font-semibold text-foreground">
          {t(sectionCopy.title)}
        </h2>
        <p className="mt-1 text-[13px] text-muted-foreground">
          {t(sectionCopy.description)}
        </p>
      </div>

      {isLoading ? (
        <div className="rounded-lg border border-border bg-card py-10 text-center">
          <Loader2 className="mx-auto h-5 w-5 animate-spin text-muted-foreground" />
          <p className="mt-3 text-[13px] text-muted-foreground">
            {t(sectionCopy.loadingLabel)}
          </p>
        </div>
      ) : errorMessage && visibleDatabases.length === 0 ? (
        <div className="rounded-lg border border-destructive/30 bg-card py-10 px-6 text-center">
          <AlertCircle className="mx-auto h-9 w-9 text-destructive" />
          <h3 className="mt-4 text-[15px] font-semibold text-foreground">
            {t(sectionCopy.failedLabel)}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">{errorMessage}</p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="mt-6"
            onClick={() => void refetch()}
            disabled={isFetching}
          >
            {t('Try again')}
          </Button>
        </div>
      ) : viewMode === 'list' ? (
        visibleDatabases.length > 0 ? (
          <>
            {errorMessage ? (
              <Alert variant="destructive" className="mb-4">
                <AlertCircle className="h-4 w-4" />
                <AlertTitle>{t(sectionCopy.refreshFailedLabel)}</AlertTitle>
                <AlertDescription className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <p className="text-[13px]">{errorMessage}</p>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="shrink-0 border-destructive/40 bg-background"
                    onClick={() => void refetch()}
                    disabled={isFetching}
                  >
                    {t('Try again')}
                  </Button>
                </AlertDescription>
              </Alert>
            ) : null}
            <div className="rounded-lg border border-border bg-card overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Database')}
                    </TableHead>
                    {sectionCopy.showEngineColumn ? (
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Engine')}
                      </TableHead>
                    ) : null}
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Tier')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                      {t('Status')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Replicas')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Created')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {visibleDatabases.map((db) => {
                    const link = dedicatedDatabaseHomeLink(
                      projectId,
                      db,
                      productRouteKindByDedicatedId.get(db.$id),
                    )
                    return (
                    <TableRow
                      key={db.$id}
                      className={cn(
                        'border-b border-border/50',
                        link && 'hover:bg-muted/30',
                      )}
                    >
                      <TableCell className="px-4 py-3">
                        {link ? (
                          <Link {...link} className="block min-w-0 group">
                            <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
                              {db.name}
                            </p>
                            <div className="mt-0.5">
                              <CopyableId id={db.$id} size="xs" />
                            </div>
                          </Link>
                        ) : (
                          <div className="min-w-0">
                            <p className="truncate text-[13px] font-medium text-foreground">
                              {db.name}
                            </p>
                            <div className="mt-0.5">
                              <CopyableId id={db.$id} size="xs" />
                            </div>
                          </div>
                        )}
                      </TableCell>
                      {sectionCopy.showEngineColumn ? (
                        <TableCell className="px-4 py-3 text-[13px] text-foreground">
                          {formatEngineLabel(db.engine)}
                        </TableCell>
                      ) : null}
                      <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                        {db.specification || t('Not set')}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center justify-center">
                          <DedicatedDatabaseStatusBadge status={db.status} />
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end text-[13px] text-muted-foreground">
                        {db.replicas > 0 ? db.replicas : 'None'}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DateTooltip
                          date={new Date(db.$createdAt)}
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                    </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
          </>
        ) : (
          <EmptyState
            icon={SectionIcon as LucideIcon}
            title={t(sectionCopy.emptyTitle)}
            description={t(sectionCopy.emptyDescription)}
            isEmpty
            variant="card"
          />
        )
      ) : (
        <>
          {errorMessage ? (
            <Alert variant="destructive" className="mb-4">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>{t(sectionCopy.refreshFailedLabel)}</AlertTitle>
              <AlertDescription className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-[13px]">{errorMessage}</p>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="shrink-0 border-destructive/40 bg-background"
                  onClick={() => void refetch()}
                  disabled={isFetching}
                >
                  {t('Try again')}
                </Button>
              </AlertDescription>
            </Alert>
          ) : null}
          <div className={cn(RESOURCE_CARD_GRID_CLASSNAME)}>
            {visibleDatabases.map((db) => (
              <DedicatedDatabaseCard
                key={db.$id}
                db={db}
                projectId={projectId}
                productRouteKindByDedicatedId={productRouteKindByDedicatedId}
                icon={SectionIcon}
                showEngineMetadata={sectionCopy.showEngineColumn}
              />
            ))}
            {visibleDatabases.length === 0 ? (
              <div className="col-span-full">
                <EmptyState
                  icon={SectionIcon as LucideIcon}
                  title={t(sectionCopy.emptyTitle)}
                  description={t(sectionCopy.emptyDescription)}
                  isEmpty
                  variant="card"
                />
              </div>
            ) : null}
          </div>
        </>
      )}
    </section>
  )
}
