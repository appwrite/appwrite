import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  useProjectConsoleDatabases,
  useProjectDedicatedDatabases,
  useProject,
  useOrganizationScopes,
  useDedicatedDatabaseCardMetrics,
  useMergedDatabaseSpecifications,
  dedicatedBackupPoliciesQueryOptions,
  dedicatedDatabaseByIdQueryOptions,
} from '@/lib/react-query/hooks'
import { type Models } from '@appwrite.io/console'
import { DatabaseType as ApiDatabaseType } from '@/lib/databases/database-type'
import {
  dedicatedDatabaseHomeLink,
  isDatabaseRouteKind,
  isDatabaseTypeFeatureEnabled,
  isNativeDedicatedDatabase,
  productDatabaseListLink,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { useQueries } from '@tanstack/react-query'
import { AlertCircle, Database, Loader2 } from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
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
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
} from '../../shared/ResourceCard'
import {
  DatabaseClusterPreview,
  clusterNodeStatusesFromDatabaseStatus,
} from './DatabaseClusterPreview'
import { DatabaseContextMenu } from './DatabaseContextMenu'
import { DatabaseOperationsChartPreview } from './DatabaseOperationsChartPreview'
import { NoBackupPoliciesWarningIcon } from './DatabaseBackupsNavLink'
import { DedicatedDatabaseStatusBadge } from './DedicatedDatabaseStatusBadge'
import { DatabaseTypeBadge } from './DatabaseTypeIcon'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canShowDatabaseSecuritySettings } from '@/lib/console-access-checks'
import {
  isDedicatedDatabaseProvisioning,
  isDedicatedDatabaseReady,
} from '@/lib/databases/dedicated-database-status'
import {
  buildProductDedicatedCardSource,
  hasDedicatedDatabaseCompute,
  readDatabaseSpecification,
  resolveDatabaseComputeLabel,
  type DedicatedDatabaseCardSource,
  type ResolveDatabaseComputeLabelOptions,
} from '@/lib/databases/database-compute'
import { engineFromDatabaseTypeValue } from '@/lib/databases/database-type'
import { DEDICATED_FEATURE_UNAVAILABLE } from '@/lib/databases/dedicated-engine'
import { mapDedicatedDatabaseSpecifications } from '@/lib/database-specs'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'

type DatabaseWithBackup = {
  $id: string
  name: string
  enabled?: boolean
  createdAt?: string
  updatedAt?: string
  hasBackupPolicy?: boolean
  backupPolicy?: unknown
  backupPolicyCount?: number
  databaseType?: ApiDatabaseType
  /** Raw `Models.Database.type` before product coercion. */
  apiType?: string | null
  status?: string | null
  replicas?: number | null
  specification?: string | null
}

function databaseComputeHints(db: DatabaseWithBackup) {
  return {
    $id: db.$id,
    name: db.name,
    databaseType: db.apiType ?? db.databaseType,
    status: db.status,
    replicas: db.replicas,
    specification: db.specification,
  }
}

function isListedDatabaseTypeUnavailable(
  db: Pick<DatabaseWithBackup, 'apiType' | 'databaseType'>,
  features: Parameters<typeof isDatabaseTypeFeatureEnabled>[1],
): boolean {
  return !isDatabaseTypeFeatureEnabled(db.apiType ?? db.databaseType, features)
}

/**
 * Product DBs expose policies on `console.listDatabases` (`db.policies`).
 * Native dedicated DBs store policies on the engine API instead; those are
 * resolved separately and passed via `nativeHasBackupPolicyById`.
 * Returns `null` while a native policy lookup is still in flight (no warning flash).
 */
function resolveHasBackupPolicy(
  db: DatabaseWithBackup,
  dedicated: Models.DedicatedDatabase | undefined,
  nativeHasBackupPolicyById: Map<string, boolean | null>,
): boolean | null {
  if (dedicated && isNativeDedicatedDatabase(dedicated)) {
    return nativeHasBackupPolicyById.get(dedicated.$id) ?? null
  }
  return db.hasBackupPolicy ?? false
}

function shouldShowNoBackupWarning(
  hasBackupPolicy: boolean | null,
  showBackups: boolean,
): boolean {
  return showBackups && hasBackupPolicy === false
}

type AllDatabasesSectionProps = {
  projectId: string
  viewMode: 'list' | 'grid'
  /** Header search term (shared URL state). */
  search?: string
  /** Appwrite Query strings from FiltersPopover / type droplist. */
  filterQueries?: string[]
  /** 1-indexed page from URL. */
  page?: number
  /** Page size from URL. */
  limit?: number
  onPageChange?: (page: number) => void
  onPageSizeChange?: (pageSize: number) => void
}

function databaseCardLink(
  projectId: string,
  db: { $id: string; databaseType?: ApiDatabaseType },
  dedicated?: Pick<DedicatedDatabaseCardSource, '$id' | 'api' | 'engine'> | null,
) {
  if (dedicated) {
    const dedicatedLink = dedicatedDatabaseHomeLink(projectId, dedicated)
    if (dedicatedLink) return dedicatedLink
  }
  return productDatabaseListLink(projectId, db.$id, db.databaseType)
}

function formatConnectionsLabel(
  connections: number | null,
  t: (text: string) => string,
): string {
  if (connections == null) return '-'
  return connections === 1
    ? `1 ${t('connection')}`
    : `${connections.toLocaleString()} ${t('connections')}`
}

function AllDatabasesGridCardShell({
  projectId,
  db,
  dedicated,
  showDbSecuritySettings,
  showMonitor,
  showBackups,
  hasBackupPolicy,
  midContent,
  connectionsLabel,
  computeLabelOptions,
}: {
  projectId: string
  db: DatabaseWithBackup
  dedicated?: DedicatedDatabaseCardSource
  showDbSecuritySettings: boolean
  showMonitor: boolean
  showBackups: boolean
  hasBackupPolicy: boolean | null
  midContent: ReactNode
  connectionsLabel?: string
  computeLabelOptions?: ResolveDatabaseComputeLabelOptions
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const cardLink = databaseCardLink(projectId, db, dedicated)
  const computeLabel = resolveDatabaseComputeLabel(
    databaseComputeHints(db),
    dedicated,
    t,
    computeLabelOptions,
  )
  const showFooter = Boolean(computeLabel || connectionsLabel)
  const typeUnavailable = isListedDatabaseTypeUnavailable(db, features)
  const appearDisabled = typeUnavailable || db.enabled === false
  const provisioningDisabled = isDedicatedDatabaseProvisioning(
    dedicated?.status ?? db.status,
  )

  const card = (
    <div
      className={cn(
        RESOURCE_CARD_PADDED_CLASSNAME,
        typeUnavailable
          ? 'cursor-not-allowed opacity-60'
          : RESOURCE_CARD_INTERACTIVE_CLASSNAME,
        showFooter && 'pb-0',
      )}
    >
      <div className="flex min-w-0 items-start justify-between gap-3">
        <div className="min-w-0 overflow-hidden">
          <div className="flex min-w-0 flex-wrap items-center gap-1.5">
            <h3 className="truncate text-[14px] font-medium text-foreground">
              {db.name}
            </h3>
            {shouldShowNoBackupWarning(hasBackupPolicy, showBackups) ? (
              <NoBackupPoliciesWarningIcon />
            ) : null}
            <DedicatedDatabaseStatusBadge
              status={dedicated?.status ?? db.status}
              onlyWhenNotReady
            />
            {appearDisabled ? (
              <Badge
                variant="error"
                className="text-[10px] font-medium shrink-0"
              >
                {t('Disabled')}
              </Badge>
            ) : null}
          </div>
          <div className="mt-1.5">
            <CopyableId id={db.$id} size="xs" maxWidth={120} />
          </div>
        </div>
        <DatabaseTypeBadge
          apiType={db.apiType ?? db.databaseType}
          engine={dedicated?.engine}
          product={dedicated?.api}
          className="shrink-0"
        />
      </div>

      {midContent}

      {showFooter ? (
        <div className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}>
          <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 text-[12px] text-muted-foreground">
            {computeLabel ? (
              <span className="truncate text-muted-foreground">
                {computeLabel}
              </span>
            ) : null}
            {connectionsLabel ? (
              <span className="truncate tabular-nums text-muted-foreground">
                {connectionsLabel}
              </span>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  )

  const cardBody = typeUnavailable ? (
    <Tooltip>
      <TooltipTrigger asChild>
        <div className="block min-w-0">{card}</div>
      </TooltipTrigger>
      <TooltipContent className="max-w-xs text-[12px]">
        {t(DEDICATED_FEATURE_UNAVAILABLE)}
      </TooltipContent>
    </Tooltip>
  ) : (
    <Link {...cardLink} className="block min-w-0">
      {card}
    </Link>
  )

  return (
    <DatabaseContextMenu
      projectId={projectId}
      database={{
        $id: db.$id,
        name: db.name,
        databaseType: db.databaseType,
      }}
      showSecuritySettings={showDbSecuritySettings}
      showMonitor={showMonitor}
      showBackups={showBackups}
      provisioningDisabled={provisioningDisabled}
    >
      {cardBody}
    </DatabaseContextMenu>
  )
}

function AllDatabasesServerlessGridCard({
  projectId,
  db,
  showDbSecuritySettings,
  showMonitor,
  showBackups,
  hasBackupPolicy,
  computeLabelOptions,
}: {
  projectId: string
  db: DatabaseWithBackup
  showDbSecuritySettings: boolean
  showMonitor: boolean
  showBackups: boolean
  hasBackupPolicy: boolean | null
  computeLabelOptions?: ResolveDatabaseComputeLabelOptions
}) {
  return (
    <AllDatabasesGridCardShell
      projectId={projectId}
      db={db}
      showDbSecuritySettings={showDbSecuritySettings}
      showMonitor={showMonitor}
      showBackups={showBackups}
      hasBackupPolicy={hasBackupPolicy}
      computeLabelOptions={computeLabelOptions}
      midContent={
        <DatabaseOperationsChartPreview
          projectId={projectId}
          databaseId={db.$id}
          enabled={showMonitor}
        />
      }
    />
  )
}

function AllDatabasesDedicatedGridCard({
  projectId,
  db,
  dedicated,
  showDbSecuritySettings,
  showMonitor,
  showBackups,
  hasBackupPolicy,
  computeLabelOptions,
}: {
  projectId: string
  db: DatabaseWithBackup
  dedicated: DedicatedDatabaseCardSource
  showDbSecuritySettings: boolean
  showMonitor: boolean
  showBackups: boolean
  hasBackupPolicy: boolean | null
  computeLabelOptions?: ResolveDatabaseComputeLabelOptions
}) {
  const t = useT()
  const replicaCount = dedicated.replicas ?? 0
  const nodeStatuses = clusterNodeStatusesFromDatabaseStatus(
    dedicated.status ?? (db.enabled === false ? 'failed' : 'ready'),
    replicaCount,
  )
  const { nodeMetrics, connections } = useDedicatedDatabaseCardMetrics(
    projectId,
    dedicated.$id,
    replicaCount,
    showMonitor,
  )

  return (
    <AllDatabasesGridCardShell
      projectId={projectId}
      db={db}
      dedicated={dedicated}
      showDbSecuritySettings={showDbSecuritySettings}
      showMonitor={showMonitor}
      showBackups={showBackups}
      hasBackupPolicy={hasBackupPolicy}
      computeLabelOptions={computeLabelOptions}
      midContent={
        <DatabaseClusterPreview
          replicaCount={replicaCount}
          nodeStatuses={nodeStatuses}
          nodeMetrics={nodeMetrics}
        />
      }
      connectionsLabel={formatConnectionsLabel(connections, t)}
    />
  )
}

function AllDatabasesGridCard({
  projectId,
  db,
  dedicated,
  showDbSecuritySettings,
  showMonitor,
  showBackups,
  hasBackupPolicy,
  computeLabelOptions,
}: {
  projectId: string
  db: DatabaseWithBackup
  dedicated?: Models.DedicatedDatabase | DedicatedDatabaseCardSource
  showDbSecuritySettings: boolean
  showMonitor: boolean
  showBackups: boolean
  hasBackupPolicy: boolean | null
  computeLabelOptions?: ResolveDatabaseComputeLabelOptions
}) {
  const cardDedicated = buildProductDedicatedCardSource(
    databaseComputeHints(db),
    dedicated,
  )

  if (cardDedicated) {
    return (
      <AllDatabasesDedicatedGridCard
        projectId={projectId}
        db={db}
        dedicated={cardDedicated}
        showDbSecuritySettings={showDbSecuritySettings}
        showMonitor={showMonitor}
        showBackups={showBackups}
        hasBackupPolicy={hasBackupPolicy}
        computeLabelOptions={computeLabelOptions}
      />
    )
  }

  return (
    <AllDatabasesServerlessGridCard
      projectId={projectId}
      db={db}
      showDbSecuritySettings={showDbSecuritySettings}
      showMonitor={showMonitor}
      showBackups={showBackups}
      hasBackupPolicy={hasBackupPolicy}
      computeLabelOptions={computeLabelOptions}
    />
  )
}

export function AllDatabasesSection({
  projectId,
  viewMode,
  search,
  filterQueries,
  page = 1,
  limit = GRID_DEFAULT_PAGE_SIZE,
  onPageChange,
  onPageSizeChange,
}: AllDatabasesSectionProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const showDbSecuritySettings = canShowDatabaseSecuritySettings(
    access,
    features,
  )

  const [requestedPage, setRequestedPage] = useState(page)
  const [displayedPage, setDisplayedPage] = useState(page)
  const [displayedSearch, setDisplayedSearch] = useState(search)
  const [displayedFilterQueries, setDisplayedFilterQueries] =
    useState(filterQueries)
  const [displayedLimit, setDisplayedLimit] = useState(limit)

  useEffect(() => {
    setRequestedPage((prev) => (prev === page ? prev : page))
  }, [page])

  useEffect(() => {
    setDisplayedLimit((prev) => (prev === limit ? prev : limit))
  }, [limit])

  const filterQueriesKey = filterQueries?.join('\0') ?? ''
  const displayedFilterQueriesKey = displayedFilterQueries?.join('\0') ?? ''

  const {
    isLoading: requestedLoading,
    isFetching: requestedFetching,
    isFetched: requestedFetched,
  } = useProjectConsoleDatabases(
    projectId,
    requestedPage - 1,
    limit,
    search,
    filterQueries,
  )

  const {
    databases,
    total,
    isLoading: displayedLoading,
    isFetching,
    error,
    refetch,
  } = useProjectConsoleDatabases(
    projectId,
    displayedPage - 1,
    displayedLimit,
    displayedSearch,
    displayedFilterQueries,
  )

  useEffect(() => {
    if (requestedFetching || requestedLoading || !requestedFetched) return
    const match =
      requestedPage === displayedPage &&
      (search ?? '') === (displayedSearch ?? '') &&
      filterQueriesKey === displayedFilterQueriesKey &&
      limit === displayedLimit
    if (!match) {
      setDisplayedPage(requestedPage)
      setDisplayedSearch(search)
      setDisplayedFilterQueries(filterQueries)
      setDisplayedLimit(limit)
    }
  }, [
    requestedFetching,
    requestedLoading,
    requestedFetched,
    requestedPage,
    displayedPage,
    search,
    displayedSearch,
    filterQueriesKey,
    displayedFilterQueriesKey,
    filterQueries,
    limit,
    displayedLimit,
  ])

  const { databases: dedicatedDatabases } =
    useProjectDedicatedDatabases(projectId)

  const { data: specificationsData } = useMergedDatabaseSpecifications(projectId)
  const computeLabelOptions = useMemo<ResolveDatabaseComputeLabelOptions>(
    () => ({
      specs: mapDedicatedDatabaseSpecifications(
        specificationsData?.specifications,
      ),
      rawSpecifications: specificationsData?.specifications ?? null,
      unspecifiedLabel: features.dedicatedDbsSupport ? undefined : '',
    }),
    [features.dedicatedDbsSupport, specificationsData?.specifications],
  )

  const listedDedicatedById = useMemo(() => {
    const map = new Map<string, Models.DedicatedDatabase>()
    for (const db of dedicatedDatabases) {
      map.set(db.$id, db)
    }
    return map
  }, [dedicatedDatabases])

  // Product-owned dedicated compute may share the product DB id but be omitted
  // from engine list responses, or listed without a specification slug. Look up
  // via that product's API only (or the native engine API for native DBs).
  const missingDedicatedLookups = useMemo(() => {
    const items: Array<{
      databaseId: string
      source:
        | { type: 'product'; dbKind: DatabaseRouteKind }
        | { type: 'engine'; engine: string }
    }> = []
    const seen = new Set<string>()
    for (const db of databases) {
      if (seen.has(db.$id)) continue
      const hints = databaseComputeHints(db)
      const listed = listedDedicatedById.get(db.$id)
      const hasSpec =
        Boolean(readDatabaseSpecification(listed?.specification)) ||
        Boolean(readDatabaseSpecification(hints.specification))
      if (hasSpec) continue
      if (!hasDedicatedDatabaseCompute(hints, listed)) continue
      seen.add(db.$id)
      // Product-owned DBs (TablesDB / DocumentsDB / VectorsDB) are looked up
      // via their product API; native engines via their engine API. Never
      // probe another product's API for a product-owned ID.
      const source = isDatabaseRouteKind(hints.databaseType ?? '')
        ? ({
            type: 'product',
            dbKind: hints.databaseType as DatabaseRouteKind,
          } as const)
        : ({
            type: 'engine',
            engine: engineFromDatabaseTypeValue(hints.databaseType) ?? 'postgresql',
          } as const)
      items.push({
        databaseId: db.$id,
        source,
      })
    }
    return items
  }, [databases, listedDedicatedById])

  const fetchedDedicatedQueries = useQueries({
    queries: missingDedicatedLookups.map(({ databaseId, source }) => ({
      ...dedicatedDatabaseByIdQueryOptions(projectId, databaseId, source),
      enabled: !!projectId && !!databaseId,
    })),
  })

  const fetchedDedicatedDataKey = fetchedDedicatedQueries
    .map((query) => `${query.dataUpdatedAt}:${query.data?.$id ?? ''}`)
    .join('|')

  const dedicatedById = useMemo(() => {
    const map = new Map<string, Models.DedicatedDatabase>()
    for (const [id, db] of listedDedicatedById) {
      map.set(id, db)
    }
    for (const query of fetchedDedicatedQueries) {
      if (query.data?.$id) map.set(query.data.$id, query.data)
    }
    return map
    // fetchedDedicatedDataKey tracks query result identity without depending on
    // the unstable useQueries array reference.
    // eslint-disable-next-line react-hooks/exhaustive-deps -- see above
  }, [listedDedicatedById, fetchedDedicatedDataKey])

  const nativeDedicatedOnPage = useMemo(() => {
    const seen = new Set<string>()
    const items: Models.DedicatedDatabase[] = []
    for (const db of databases) {
      const dedicated = dedicatedById.get(db.$id)
      if (!dedicated || !isNativeDedicatedDatabase(dedicated)) continue
      if (seen.has(dedicated.$id)) continue
      seen.add(dedicated.$id)
      items.push(dedicated)
    }
    return items
  }, [databases, dedicatedById])

  const showBackups = features.databaseBackups
  const nativeBackupPolicyQueries = useQueries({
    queries: nativeDedicatedOnPage.map((dedicated) => ({
      ...dedicatedBackupPoliciesQueryOptions(
        projectId,
        dedicated.$id,
        dedicated.engine,
      ),
      enabled: showBackups && !!projectId && !!dedicated.$id,
    })),
  })

  const nativeHasBackupPolicyById = useMemo(() => {
    const map = new Map<string, boolean | null>()
    nativeDedicatedOnPage.forEach((dedicated, index) => {
      const query = nativeBackupPolicyQueries[index]
      if (!query || query.isLoading || query.isPending) {
        map.set(dedicated.$id, null)
        return
      }
      map.set(dedicated.$id, (query.data?.policies?.length ?? 0) > 0)
    })
    return map
  }, [nativeBackupPolicyQueries, nativeDedicatedOnPage])

  const errorMessage = error ? getErrorMessage(error) : null
  const hasActiveFilters =
    Boolean(search?.trim()) || Boolean(filterQueries?.length)
  const showLoading = displayedLoading && databases.length === 0
  const canPaginate = Boolean(onPageChange && onPageSizeChange)
  const showPagination = databases.length > 0 && canPaginate

  const handleSectionPageChange = (nextPage: number) => {
    onPageChange?.(nextPage)
  }

  const handleSectionPageSizeChange = (nextPageSize: number) => {
    onPageSizeChange?.(nextPageSize)
  }

  return (
    <section>
      {showLoading ? (
        <div className="rounded-lg border border-border bg-card py-10 text-center">
          <Loader2 className="mx-auto h-5 w-5 animate-spin text-muted-foreground" />
          <p className="mt-3 text-[13px] text-muted-foreground">
            {t('Loading databases...')}
          </p>
        </div>
      ) : errorMessage && databases.length === 0 ? (
        <div className="rounded-lg border border-destructive/30 bg-card py-10 px-6 text-center">
          <AlertCircle className="mx-auto h-9 w-9 text-destructive" />
          <h3 className="mt-4 text-[15px] font-semibold text-foreground">
            {t('Failed to load databases')}
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
        databases.length > 0 ? (
          <>
            {errorMessage ? (
              <Alert variant="destructive" className="mb-4">
                <AlertCircle className="h-4 w-4" />
                <AlertTitle>{t("Couldn't refresh databases")}</AlertTitle>
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
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Type')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                      {t('Status')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Created')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Updated')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {databases.map((db) => {
                    const dedicated = dedicatedById.get(db.$id)
                    const typeUnavailable = isListedDatabaseTypeUnavailable(
                      db,
                      features,
                    )
                    const appearDisabled =
                      typeUnavailable || db.enabled === false
                    const nameContent = (
                      <>
                        <div className="flex min-w-0 items-center gap-1.5">
                          <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
                            {db.name}
                          </p>
                          {shouldShowNoBackupWarning(
                            resolveHasBackupPolicy(
                              db,
                              dedicated,
                              nativeHasBackupPolicyById,
                            ),
                            showBackups,
                          ) ? (
                            <NoBackupPoliciesWarningIcon />
                          ) : null}
                        </div>
                        <div className="mt-0.5">
                          <CopyableId id={db.$id} size="xs" />
                        </div>
                      </>
                    )
                    return (
                    <TableRow
                      key={db.$id}
                      className={cn(
                        'border-b border-border/50',
                        typeUnavailable
                          ? 'cursor-not-allowed opacity-60'
                          : 'cursor-pointer hover:bg-muted/30',
                      )}
                    >
                      <TableCell className="px-4 py-3">
                        {typeUnavailable ? (
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <div className="block min-w-0">{nameContent}</div>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-xs text-[12px]">
                              {t(DEDICATED_FEATURE_UNAVAILABLE)}
                            </TooltipContent>
                          </Tooltip>
                        ) : (
                          <Link
                            {...databaseCardLink(
                              projectId,
                              db,
                              dedicated,
                            )}
                            className="block min-w-0 group"
                          >
                            {nameContent}
                          </Link>
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <DatabaseTypeBadge
                          apiType={db.apiType ?? db.databaseType}
                          engine={dedicated?.engine}
                          product={dedicated?.api}
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center justify-center">
                          {!appearDisabled &&
                          (dedicated?.status || db.status) &&
                          !isDedicatedDatabaseReady(
                            dedicated?.status || db.status,
                          ) ? (
                            <DedicatedDatabaseStatusBadge
                              status={dedicated?.status || db.status}
                              className="text-[11px]"
                            />
                          ) : appearDisabled ? (
                            <Badge
                              variant="error"
                              className="text-[11px] font-medium border px-2 py-0.5"
                            >
                              {t('Disabled')}
                            </Badge>
                          ) : (
                            <Badge
                              variant="success"
                              className="text-[11px] font-medium border px-2 py-0.5"
                            >
                              {t('Enabled')}
                            </Badge>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DateTooltip
                          date={new Date(db.createdAt || new Date())}
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DateTooltip
                          date={
                            new Date(
                              db.updatedAt || db.createdAt || new Date(),
                            )
                          }
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                    </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
            {showPagination ? (
              <Pagination
                currentPage={displayedPage}
                totalItems={total}
                pageSize={displayedLimit}
                pageSizeOptions={[12, 18, 36, 72]}
                onPageChange={handleSectionPageChange}
                onPageSizeChange={handleSectionPageSizeChange}
                itemLabel={t('databases')}
              />
            ) : null}
          </>
        ) : (
          <EmptyState
            icon={Database}
            title={
              hasActiveFilters
                ? t('No databases match your filters')
                : t('No databases yet')
            }
            description={
              hasActiveFilters
                ? t('Try adjusting or clearing filters.')
                : t(
                    'Create this product database from the create database wizard.',
                  )
            }
            isEmpty={!hasActiveFilters}
            variant="card"
          />
        )
      ) : (
        <>
          {errorMessage ? (
            <Alert variant="destructive" className="mb-4">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>{t("Couldn't refresh databases")}</AlertTitle>
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
            {databases.map((db) => (
              <AllDatabasesGridCard
                key={db.$id}
                projectId={projectId}
                db={db}
                dedicated={dedicatedById.get(db.$id)}
                showDbSecuritySettings={showDbSecuritySettings}
                showMonitor={features.usageStats}
                showBackups={showBackups}
                hasBackupPolicy={resolveHasBackupPolicy(
                  db,
                  dedicatedById.get(db.$id),
                  nativeHasBackupPolicyById,
                )}
                computeLabelOptions={computeLabelOptions}
              />
            ))}
            {databases.length === 0 ? (
              <div className="col-span-full">
                <EmptyState
                  icon={Database}
                  title={
                    hasActiveFilters
                      ? t('No databases match your filters')
                      : t('No databases yet')
                  }
                  description={
                    hasActiveFilters
                      ? t('Try adjusting or clearing filters.')
                      : t(
                          'Create this product database from the create database wizard.',
                        )
                  }
                  isEmpty={!hasActiveFilters}
                  variant="card"
                />
              </div>
            ) : null}
          </div>
          {showPagination ? (
            <Pagination
              currentPage={displayedPage}
              totalItems={total}
              pageSize={displayedLimit}
              pageSizeOptions={[12, 18, 36, 72]}
              onPageChange={handleSectionPageChange}
              onPageSizeChange={handleSectionPageSizeChange}
              itemLabel={t('databases')}
            />
          ) : null}
        </>
      )}
    </section>
  )
}
