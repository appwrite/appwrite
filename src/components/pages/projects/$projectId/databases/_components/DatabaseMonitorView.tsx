import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { DatabaseType as ApiDatabaseType } from '@/lib/databases/database-type'
import { cn } from '@/lib/utils'
import {
  refetchDedicatedDatabaseMonitorQueries,
  useDatabaseReadsForDatabaseBreakdowns,
  useDatabaseReadsForDatabaseChart,
  useDatabaseWritesForDatabaseBreakdowns,
  useDatabaseWritesForDatabaseChart,
  useDedicatedDatabaseMonitorMetrics,
  useProjectDatabase,
  useProjectDedicatedDatabases,
  useRefetchOnMonitorChartTick,
} from '@/lib/react-query/hooks'
import {
  getEffectiveDatabaseSpecIdForMonitoring,
  isServerlessDatabaseMonitoring,
} from '@/lib/database-specs'
import { useT } from '@/lib/i18n/translate'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import { UsageChartIntervalToggle } from '@/components/pages/projects/$projectId/overview/UsageChartIntervalToggle'
import { DatabaseMonitorNodeSelect } from './DatabaseMonitorNodeSelect'
import { UsageTimeSeriesChartCard } from '@/components/pages/projects/$projectId/usage/_components/UsageTimeSeriesChartCard'
import { DatabaseOperationBentoCard } from '@/components/pages/projects/$projectId/usage/_components/DatabaseOperationBentoCard'
import { UsageBreakdownDrawer } from '@/components/pages/projects/$projectId/usage/_components/UsageBreakdownDrawer'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'
import {
  DATABASE_READS_AND_WRITES_DOCS_HREF,
  DATABASE_READS_FOR_DATABASE_DESCRIPTION,
  DATABASE_WRITES_FOR_DATABASE_DESCRIPTION,
  sumUsageChartPoints,
} from '@/lib/usage/database-usage'
import {
  DEDICATED_DATABASE_CONNECTIONS_DESCRIPTION,
  DEDICATED_DATABASE_CPU_DESCRIPTION,
  DEDICATED_DATABASE_IOPS_DESCRIPTION,
  DEDICATED_DATABASE_MEMORY_DESCRIPTION,
  DEDICATED_DATABASE_QPS_DESCRIPTION,
  DEDICATED_DATABASE_STORAGE_DESCRIPTION,
  formatDedicatedDatabaseCountTotal,
  formatDedicatedDatabaseCountValue,
  formatDedicatedDatabasePercentTotal,
  formatDedicatedDatabasePercentValue,
  formatDedicatedDatabaseStorageTotal,
  formatDedicatedDatabaseStorageValue,
  getDedicatedDatabaseGaugeHeadline,
  getDedicatedDatabaseRateHeadline,
  mergeDualUsageChartSeries,
} from '@/lib/usage/dedicated-databases-usage'
import { useDebugOverrides } from '@/lib/debug-overrides'
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import {
  collectUsageResourceBreakdownItems,
} from '@/lib/usage/usage-resources-breakdown'
import { useUsageResourceBreakdownLookups } from '@/lib/react-query/hooks'

type MonitorSection = { id: string; label: string }

const MONITOR_USAGE_ERROR = {
  title: "Couldn't load database metrics",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type BreakdownDrawerState = {
  operation: 'reads' | 'writes'
  title: string
  description: string
  dimension: UsageEventBreakdownDimension
  labelVariant: 'mono' | 'default'
}

function MonitorSidebarNav({
  sections,
  onNavigate,
  className,
}: {
  sections: MonitorSection[]
  onNavigate: (id: string) => void
  className?: string
}) {
  const t = useT()
  return (
    <nav
      className={cn('space-y-0.5', className)}
      role="navigation"
      aria-label={t('Monitor metrics')}
    >
      <p className="px-2 pb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {t('Metrics')}
      </p>
      {sections.map((s) => (
        <button
          key={s.id}
          type="button"
          onClick={() => onNavigate(s.id)}
          className={cn(
            'flex w-full items-center rounded-md px-2 py-1.5 text-start text-[13px] transition-colors',
            'text-muted-foreground hover:bg-accent hover:text-foreground',
          )}
        >
          <span className="truncate">{t(s.label)}</span>
        </button>
      ))}
    </nav>
  )
}

function MonitorChartAnchor({
  id,
  children,
}: {
  id: string
  children: ReactNode
}) {
  return (
    <div
      id={`monitor-chart-${id}`}
      className="scroll-mt-[calc(4rem+env(safe-area-inset-top))]"
    >
      {children}
    </div>
  )
}

export type DatabaseMonitorViewProps = {
  databaseId: string
  dbKind: DatabaseRouteKind
  dateRange: DateRange
  chartTick: number
}

export function DatabaseMonitorView({
  databaseId,
  dbKind,
  dateRange,
  chartTick,
}: DatabaseMonitorViewProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const { disableUsageBreakdownQueries } = useDebugOverrides()
  const showBreakdown = !disableUsageBreakdownQueries

  const [chartInterval, setChartInterval] = useState<UsageChartInterval>(
    DEFAULT_USAGE_CHART_INTERVAL,
  )
  const [selectedOrdinal, setSelectedOrdinal] = useState(0)
  const [breakdownDrawer, setBreakdownDrawer] =
    useState<BreakdownDrawerState | null>(null)

  const resolvedInterval = useMemo(
    () => resolveUsageChartIntervalForRange(chartInterval, dateRange),
    [chartInterval, dateRange],
  )

  const { database } = useProjectDatabase(projectId, databaseId, dbKind)
  const { databases: dedicatedDatabases } = useProjectDedicatedDatabases(
    projectId,
  )
  const databaseType =
    (database as { databaseType?: ApiDatabaseType } | null)?.databaseType ??
    ApiDatabaseType.Tablesdb

  const dedicated = useMemo(
    () => dedicatedDatabases.find((item) => item.$id === databaseId),
    [dedicatedDatabases, databaseId],
  )
  // Product DBs with dedicated compute may be missing from the engine list;
  // fall back to the specification on the product database model itself.
  const productSpecId =
    (database as { specification?: string | null } | null)?.specification ??
    null
  const apiSpecId =
    coerceTrimmedString(dedicated?.specification) || coerceTrimmedString(productSpecId) || null
  const specId = getEffectiveDatabaseSpecIdForMonitoring(
    databaseType,
    apiSpecId,
  )
  const serverless = isServerlessDatabaseMonitoring(databaseType, specId)
  const productReplicas =
    (database as { replicas?: number | null } | null)?.replicas ?? null
  const replicaCount = dedicated?.replicas ?? productReplicas ?? 0
  const metricsOrdinal = !serverless && replicaCount > 0 ? selectedOrdinal : undefined

  useEffect(() => {
    if (selectedOrdinal > replicaCount) {
      setSelectedOrdinal(0)
    }
  }, [replicaCount, selectedOrdinal])

  const readsQuery = useDatabaseReadsForDatabaseChart(
    projectId,
    databaseId,
    dateRange,
    serverless,
    resolvedInterval,
  )
  const writesQuery = useDatabaseWritesForDatabaseChart(
    projectId,
    databaseId,
    dateRange,
    serverless,
    resolvedInterval,
  )
  const readsBreakdowns = useDatabaseReadsForDatabaseBreakdowns(
    projectId,
    databaseId,
    dateRange,
    serverless && showBreakdown,
  )
  const writesBreakdowns = useDatabaseWritesForDatabaseBreakdowns(
    projectId,
    databaseId,
    dateRange,
    serverless && showBreakdown,
  )

  const dedicatedMetrics = useDedicatedDatabaseMonitorMetrics(
    projectId,
    databaseId,
    dateRange,
    !serverless,
    resolvedInterval,
    metricsOrdinal,
  )

  const resourceBreakdownItems = useMemo(
    () =>
      showBreakdown && serverless
        ? collectUsageResourceBreakdownItems([
            ...readsBreakdowns,
            ...writesBreakdowns,
          ])
        : [],
    [readsBreakdowns, writesBreakdowns, showBreakdown, serverless],
  )
  const { computeLookup, databaseLookup, storageLookup, tableLookup } =
    useUsageResourceBreakdownLookups(
      projectId,
      resourceBreakdownItems,
      showBreakdown && serverless && resourceBreakdownItems.length > 0,
    )

  const refetchMonitor = useCallback(async () => {
    await refetchDedicatedDatabaseMonitorQueries(
      queryClient,
      projectId,
      databaseId,
    )
  }, [queryClient, projectId, databaseId])

  useRefetchOnMonitorChartTick(chartTick, refetchMonitor)

  const sections: MonitorSection[] = serverless
    ? [
        { id: 'reads', label: 'Read operations' },
        { id: 'writes', label: 'Write operations' },
      ]
    : [
        { id: 'cpu', label: 'CPU' },
        { id: 'memory', label: 'Memory' },
        { id: 'storage', label: 'Storage' },
        { id: 'connections', label: 'Connections' },
        { id: 'qps', label: 'Queries per second' },
        { id: 'iops', label: 'Disk IOPS' },
      ]

  const scrollToChart = useCallback((id: string) => {
    document.getElementById(`monitor-chart-${id}`)?.scrollIntoView({
      behavior: 'smooth',
      block: 'start',
    })
  }, [])

  const readsPoints = readsQuery.isError
    ? []
    : (readsQuery.data?.chartPoints ?? [])
  const writesPoints = writesQuery.isError
    ? []
    : (writesQuery.data?.chartPoints ?? [])

  const cpuPoints = dedicatedMetrics.cpu.isError
    ? []
    : (dedicatedMetrics.cpu.data?.chartPoints ?? [])
  const memoryPoints = dedicatedMetrics.memory.isError
    ? []
    : (dedicatedMetrics.memory.data?.chartPoints ?? [])
  const storagePoints = dedicatedMetrics.storage.isError
    ? []
    : (dedicatedMetrics.storage.data?.chartPoints ?? [])
  const connectionsPoints = dedicatedMetrics.connections.isError
    ? []
    : (dedicatedMetrics.connections.data?.chartPoints ?? [])
  const qpsPoints = dedicatedMetrics.qps.isError
    ? []
    : (dedicatedMetrics.qps.data?.chartPoints ?? [])
  const iopsReadPoints = dedicatedMetrics.iopsRead.isError
    ? []
    : (dedicatedMetrics.iopsRead.data?.chartPoints ?? [])
  const iopsWritePoints = dedicatedMetrics.iopsWrite.isError
    ? []
    : (dedicatedMetrics.iopsWrite.data?.chartPoints ?? [])

  const iopsDualPoints = useMemo(
    () => mergeDualUsageChartSeries(iopsReadPoints, iopsWritePoints),
    [iopsReadPoints, iopsWritePoints],
  )

  return (
    <div className="flex h-full min-h-0 w-full overflow-hidden">
      <aside className="hidden w-[200px] shrink-0 border-e border-border lg:block">
        <div className="h-full overflow-y-auto p-3">
          <MonitorSidebarNav sections={sections} onNavigate={scrollToChart} />
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        <div className="min-h-0 flex-1 overflow-y-auto">
          <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex min-w-0 flex-wrap items-center gap-3">
                {!serverless ? (
                  <DatabaseMonitorNodeSelect
                    replicaCount={replicaCount}
                    value={selectedOrdinal}
                    onValueChange={setSelectedOrdinal}
                  />
                ) : null}
                <p className="text-[13px] text-muted-foreground">
                  {serverless
                    ? t('Read and write operations for this database.')
                    : t('Instance metrics for this dedicated database.')}
                </p>
              </div>
              <UsageChartIntervalToggle
                value={resolvedInterval}
                onValueChange={setChartInterval}
                dateRange={dateRange}
                className="h-7"
              />
            </div>

            {serverless ? (
              <>
                <MonitorChartAnchor id="reads">
                  <DatabaseOperationBentoCard
                    projectId={projectId}
                    operation="reads"
                    title="Read operations"
                    description={DATABASE_READS_FOR_DATABASE_DESCRIPTION}
                    unitLabel="reads"
                    chartGradientId="monitor-database-reads-gradient"
                    chartPoints={readsPoints}
                    total={sumUsageChartPoints(readsPoints)}
                    changePercent={readsQuery.data?.changePercent ?? 0}
                    isLoading={shouldShowUsageChartSkeleton(
                      readsQuery.isError,
                      readsQuery.isLoading,
                      readsQuery.isPlaceholderData,
                    )}
                    isError={readsQuery.isError}
                    queryError={readsQuery.error}
                    showBreakdown={showBreakdown}
                    breakdowns={readsBreakdowns}
                    computeLookup={computeLookup}
                    databaseLookup={databaseLookup}
                    storageLookup={storageLookup}
                    tableLookup={tableLookup}
                    onRetry={() => void refetchMonitor()}
                    onOpenBreakdownDrawer={setBreakdownDrawer}
                    docsHref={DATABASE_READS_AND_WRITES_DOCS_HREF}
                    dateRange={dateRange}
                    chartInterval={resolvedInterval}
                  />
                </MonitorChartAnchor>

                <MonitorChartAnchor id="writes">
                  <DatabaseOperationBentoCard
                    projectId={projectId}
                    operation="writes"
                    title="Write operations"
                    description={DATABASE_WRITES_FOR_DATABASE_DESCRIPTION}
                    unitLabel="writes"
                    chartGradientId="monitor-database-writes-gradient"
                    chartPoints={writesPoints}
                    total={sumUsageChartPoints(writesPoints)}
                    changePercent={writesQuery.data?.changePercent ?? 0}
                    isLoading={shouldShowUsageChartSkeleton(
                      writesQuery.isError,
                      writesQuery.isLoading,
                      writesQuery.isPlaceholderData,
                    )}
                    isError={writesQuery.isError}
                    queryError={writesQuery.error}
                    showBreakdown={showBreakdown}
                    breakdowns={writesBreakdowns}
                    computeLookup={computeLookup}
                    databaseLookup={databaseLookup}
                    storageLookup={storageLookup}
                    tableLookup={tableLookup}
                    onRetry={() => void refetchMonitor()}
                    onOpenBreakdownDrawer={setBreakdownDrawer}
                    docsHref={DATABASE_READS_AND_WRITES_DOCS_HREF}
                    dateRange={dateRange}
                    chartInterval={resolvedInterval}
                  />
                </MonitorChartAnchor>
              </>
            ) : (
              <>
                <MonitorChartAnchor id="cpu">
                  <UsageTimeSeriesChartCard
                    title="CPU"
                    description={DEDICATED_DATABASE_CPU_DESCRIPTION}
                    unitLabel="utilization"
                    chartGradientId="monitor-dedicated-cpu-gradient"
                    total={getDedicatedDatabaseGaugeHeadline(cpuPoints)}
                    changePercent={dedicatedMetrics.cpu.data?.changePercent ?? 0}
                    chartPoints={cpuPoints}
                    isLoading={shouldShowUsageChartSkeleton(
                      dedicatedMetrics.cpu.isError,
                      dedicatedMetrics.cpu.isLoading,
                      dedicatedMetrics.cpu.isPlaceholderData,
                    )}
                    isError={dedicatedMetrics.cpu.isError}
                    queryError={dedicatedMetrics.cpu.error}
                    errorTitle={MONITOR_USAGE_ERROR.title}
                    errorMessage={MONITOR_USAGE_ERROR.message}
                    formatTotal={formatDedicatedDatabasePercentTotal}
                    formatValue={formatDedicatedDatabasePercentValue}
                    onRetry={() => void refetchMonitor()}
                    dateRange={dateRange}
                    chartInterval={resolvedInterval}
                  />
                </MonitorChartAnchor>

                <MonitorChartAnchor id="memory">
                  <UsageTimeSeriesChartCard
                    title="Memory"
                    description={DEDICATED_DATABASE_MEMORY_DESCRIPTION}
                    unitLabel="utilization"
                    chartGradientId="monitor-dedicated-memory-gradient"
                    total={getDedicatedDatabaseGaugeHeadline(memoryPoints)}
                    changePercent={
                      dedicatedMetrics.memory.data?.changePercent ?? 0
                    }
                    chartPoints={memoryPoints}
                    isLoading={shouldShowUsageChartSkeleton(
                      dedicatedMetrics.memory.isError,
                      dedicatedMetrics.memory.isLoading,
                      dedicatedMetrics.memory.isPlaceholderData,
                    )}
                    isError={dedicatedMetrics.memory.isError}
                    queryError={dedicatedMetrics.memory.error}
                    errorTitle={MONITOR_USAGE_ERROR.title}
                    errorMessage={MONITOR_USAGE_ERROR.message}
                    formatTotal={formatDedicatedDatabasePercentTotal}
                    formatValue={formatDedicatedDatabasePercentValue}
                    onRetry={() => void refetchMonitor()}
                    dateRange={dateRange}
                    chartInterval={resolvedInterval}
                  />
                </MonitorChartAnchor>

                <MonitorChartAnchor id="storage">
                  <UsageTimeSeriesChartCard
                    title="Storage"
                    description={DEDICATED_DATABASE_STORAGE_DESCRIPTION}
                    unitLabel="used"
                    chartGradientId="monitor-dedicated-storage-gradient"
                    total={getDedicatedDatabaseGaugeHeadline(storagePoints)}
                    changePercent={
                      dedicatedMetrics.storage.data?.changePercent ?? 0
                    }
                    chartPoints={storagePoints}
                    isLoading={shouldShowUsageChartSkeleton(
                      dedicatedMetrics.storage.isError,
                      dedicatedMetrics.storage.isLoading,
                      dedicatedMetrics.storage.isPlaceholderData,
                    )}
                    isError={dedicatedMetrics.storage.isError}
                    queryError={dedicatedMetrics.storage.error}
                    errorTitle={MONITOR_USAGE_ERROR.title}
                    errorMessage={MONITOR_USAGE_ERROR.message}
                    formatTotal={formatDedicatedDatabaseStorageTotal}
                    formatValue={formatDedicatedDatabaseStorageValue}
                    axisFormat="bytes"
                    onRetry={() => void refetchMonitor()}
                    dateRange={dateRange}
                    chartInterval={resolvedInterval}
                  />
                </MonitorChartAnchor>

                <MonitorChartAnchor id="connections">
                  <UsageTimeSeriesChartCard
                    title="Connections"
                    description={DEDICATED_DATABASE_CONNECTIONS_DESCRIPTION}
                    unitLabel="connections"
                    chartGradientId="monitor-dedicated-connections-gradient"
                    total={getDedicatedDatabaseGaugeHeadline(connectionsPoints)}
                    changePercent={
                      dedicatedMetrics.connections.data?.changePercent ?? 0
                    }
                    chartPoints={connectionsPoints}
                    isLoading={shouldShowUsageChartSkeleton(
                      dedicatedMetrics.connections.isError,
                      dedicatedMetrics.connections.isLoading,
                      dedicatedMetrics.connections.isPlaceholderData,
                    )}
                    isError={dedicatedMetrics.connections.isError}
                    queryError={dedicatedMetrics.connections.error}
                    errorTitle={MONITOR_USAGE_ERROR.title}
                    errorMessage={MONITOR_USAGE_ERROR.message}
                    formatTotal={formatDedicatedDatabaseCountTotal}
                    formatValue={formatDedicatedDatabaseCountValue}
                    onRetry={() => void refetchMonitor()}
                    dateRange={dateRange}
                    chartInterval={resolvedInterval}
                  />
                </MonitorChartAnchor>

                <MonitorChartAnchor id="qps">
                  <UsageTimeSeriesChartCard
                    title="Queries per second"
                    description={DEDICATED_DATABASE_QPS_DESCRIPTION}
                    unitLabel="qps"
                    chartGradientId="monitor-dedicated-qps-gradient"
                    total={getDedicatedDatabaseRateHeadline(qpsPoints)}
                    changePercent={dedicatedMetrics.qps.data?.changePercent ?? 0}
                    chartPoints={qpsPoints}
                    isLoading={shouldShowUsageChartSkeleton(
                      dedicatedMetrics.qps.isError,
                      dedicatedMetrics.qps.isLoading,
                      dedicatedMetrics.qps.isPlaceholderData,
                    )}
                    isError={dedicatedMetrics.qps.isError}
                    queryError={dedicatedMetrics.qps.error}
                    errorTitle={MONITOR_USAGE_ERROR.title}
                    errorMessage={MONITOR_USAGE_ERROR.message}
                    formatTotal={(value) => value.toFixed(1)}
                    formatValue={(value) => value.toFixed(1)}
                    onRetry={() => void refetchMonitor()}
                    dateRange={dateRange}
                    chartInterval={resolvedInterval}
                  />
                </MonitorChartAnchor>

                <MonitorChartAnchor id="iops">
                  <UsageTimeSeriesChartCard
                    title="Disk IOPS"
                    description={DEDICATED_DATABASE_IOPS_DESCRIPTION}
                    unitLabel="read iops"
                    chartGradientId="monitor-dedicated-iops-gradient"
                    total={getDedicatedDatabaseRateHeadline(iopsReadPoints)}
                    changePercent={
                      dedicatedMetrics.iopsRead.data?.changePercent ?? 0
                    }
                    chartPoints={iopsReadPoints}
                    isLoading={shouldShowUsageChartSkeleton(
                      dedicatedMetrics.iopsRead.isError ||
                        dedicatedMetrics.iopsWrite.isError,
                      dedicatedMetrics.iopsRead.isLoading ||
                        dedicatedMetrics.iopsWrite.isLoading,
                      dedicatedMetrics.iopsRead.isPlaceholderData ||
                        dedicatedMetrics.iopsWrite.isPlaceholderData,
                    )}
                    isError={
                      dedicatedMetrics.iopsRead.isError ||
                      dedicatedMetrics.iopsWrite.isError
                    }
                    queryError={
                      dedicatedMetrics.iopsRead.error ??
                      dedicatedMetrics.iopsWrite.error
                    }
                    errorTitle={MONITOR_USAGE_ERROR.title}
                    errorMessage={MONITOR_USAGE_ERROR.message}
                    formatTotal={(value) =>
                      `${value.toFixed(1)} / ${getDedicatedDatabaseRateHeadline(iopsWritePoints).toFixed(1)} write`
                    }
                    formatValue={(value) => value.toFixed(1)}
                    onRetry={() => void refetchMonitor()}
                    dateRange={dateRange}
                    chartInterval={resolvedInterval}
                  />
                  {iopsDualPoints.length > 0 ? (
                    <p className="mt-2 text-[12px] text-muted-foreground">
                      {t('Write IOPS latest')}:{' '}
                      {getDedicatedDatabaseRateHeadline(iopsWritePoints).toFixed(
                        1,
                      )}
                    </p>
                  ) : null}
                </MonitorChartAnchor>
              </>
            )}
          </div>
        </div>
      </div>

      {breakdownDrawer ? (
        <UsageBreakdownDrawer
          open
          onOpenChange={(open) => {
            if (!open) setBreakdownDrawer(null)
          }}
          projectId={projectId}
          dateRange={dateRange}
          title={breakdownDrawer.title}
          description={breakdownDrawer.description}
          dimension={breakdownDrawer.dimension}
          labelVariant={breakdownDrawer.labelVariant}
          kind={
            breakdownDrawer.operation === 'reads'
              ? 'database-reads'
              : 'database-writes'
          }
          computeLookup={computeLookup}
          databaseLookup={databaseLookup}
          storageLookup={storageLookup}
          tableLookup={tableLookup}
        />
      ) : null}
    </div>
  )
}
