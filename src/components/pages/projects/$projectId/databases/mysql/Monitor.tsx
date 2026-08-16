import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  endOfDay,
  formatDistanceToNow,
  startOfDay,
  subDays,
} from 'date-fns'
import type { DateRange } from 'react-day-picker'
import type { LucideIcon } from 'lucide-react'
import {
  Activity,
  AlertCircle,
  AppWindow,
  Cpu,
  HardDrive,
  HeartPulse,
  MemoryStick,
  ScanLine,
  Table2,
  Timer,
  Users,
  Zap,
} from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  formatCompactBytes,
  formatCompactCount,
} from '@/lib/usage/format-metric'
import { mapDedicatedDatabaseSpecifications } from '@/lib/database-specs'
import {
  MYSQL_DATABASE_SPECS_SOURCE,
  useDatabaseSpecifications,
  useDedicatedDatabaseMonitorMetrics,
  useMysqlConnectionApps,
  useMysqlConnectionStates,
  useMysqlDatabase,
  useMysqlMetricsSampling,
  useMysqlTableActivity,
} from '@/lib/react-query/hooks'
import {
  formatConnectionStateLabel,
  formatMysqlUptime,
} from '@/lib/mysql-metrics'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  DEDICATED_DATABASE_CONNECTIONS_DESCRIPTION,
  DEDICATED_DATABASE_CPU_DESCRIPTION,
  DEDICATED_DATABASE_IOPS_DESCRIPTION,
  DEDICATED_DATABASE_MEMORY_DESCRIPTION,
  DEDICATED_DATABASE_QPS_DESCRIPTION,
  DEDICATED_DATABASE_STORAGE_DESCRIPTION,
  getDedicatedDatabaseGaugeHeadline,
  getDedicatedDatabaseRateHeadline,
  mergeDualUsageChartSeries,
  usageChartPointsToMonitorSeries,
} from '@/lib/usage/dedicated-databases-usage'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import { shouldShowUsageChartSkeleton } from '@/lib/usage/usage-chart-loading'
import { UsageChartIntervalToggle } from '@/components/pages/projects/$projectId/overview/UsageChartIntervalToggle'
import { DatabaseMonitorNodeSelect } from '../_components/DatabaseMonitorNodeSelect'
import { MysqlMetricChart } from './_components/MysqlMetricChart'
import { MysqlMetricRankedList } from './_components/MysqlMetricRankedList'
import { MysqlMetricKpiCard } from './_components/MysqlMetricKpiCard'
import { MysqlMetricsBentoCard } from './_components/MysqlMetricsBentoCard'
import { useT } from '@/lib/i18n/translate'

type MonitorNavItem = { id: string; label: string; icon: LucideIcon }
type MonitorNavGroup = { id: string; label: string; items: MonitorNavItem[] }

const MONITOR_SCROLL_MARGIN =
  'scroll-mt-[calc(4rem+env(safe-area-inset-top))]' as const

function getDefaultMonitorDateRange(): DateRange {
  return {
    from: startOfDay(subDays(new Date(), 1)),
    to: endOfDay(new Date()),
  }
}

function getUsageTone(
  percentage: number | null,
): 'normal' | 'warning' | 'critical' {
  if (percentage == null) return 'normal'
  if (percentage >= 90) return 'critical'
  if (percentage >= 75) return 'warning'
  return 'normal'
}

function getCacheHitTone(
  ratio: number | null,
): 'normal' | 'warning' | 'critical' {
  if (ratio == null) return 'normal'
  if (ratio < 90) return 'critical'
  if (ratio < 95) return 'warning'
  return 'normal'
}

function monitorNavLinkClassName(isActive: boolean) {
  return cn(
    'flex w-full items-center gap-2.5 rounded-md px-3 py-2.5 text-start text-[13px] font-medium transition-colors',
    isActive
      ? 'bg-accent text-foreground'
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
  )
}

function MonitorSectionHeading({ title }: { title: string }) {
  return (
    <h2 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
      {title}
    </h2>
  )
}

function MysqlMonitorNav({
  navGroups,
  activeSectionId,
  onNavigate,
}: {
  navGroups: MonitorNavGroup[]
  activeSectionId: string
  onNavigate: (id: string) => void
}) {
  const t = useT()
  return (
    <>
      <div className="space-y-2 lg:hidden" aria-label={t('Monitor metrics')}>
        <Select value={activeSectionId} onValueChange={onNavigate}>
          <SelectTrigger size="sm" className="h-9 w-full text-[13px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {navGroups.map((group) => (
              <SelectGroup key={group.id}>
                <SelectLabel className="text-[11px] uppercase tracking-wider">
                  {t(group.label)}
                </SelectLabel>
                {group.items.map((item) => {
                  const Icon = item.icon
                  return (
                    <SelectItem
                      key={item.id}
                      value={item.id}
                      className="text-[13px]"
                    >
                      <span className="flex items-center gap-2">
                        <Icon className="h-4 w-4 shrink-0" />
                        {t(item.label)}
                      </span>
                    </SelectItem>
                  )
                })}
              </SelectGroup>
            ))}
          </SelectContent>
        </Select>
      </div>

      <nav
        className="hidden w-[220px] shrink-0 flex-col gap-5 lg:sticky lg:top-4 lg:flex lg:self-start"
        aria-label={t('Monitor metrics')}
      >
        {navGroups.map((group) => (
          <div key={group.id}>
            <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t(group.label)}
            </p>
            <div className="flex flex-col gap-0.5">
              {group.items.map((item) => {
                const Icon = item.icon
                return (
                  <button
                    key={item.id}
                    type="button"
                    onClick={() => onNavigate(item.id)}
                    className={monitorNavLinkClassName(
                      activeSectionId === item.id,
                    )}
                  >
                    <Icon className="h-4 w-4 shrink-0" />
                    <span className="truncate">{t(item.label)}</span>
                  </button>
                )
              })}
            </div>
          </div>
        ))}
      </nav>
    </>
  )
}

type MonitorProps = {
  projectId: string
  databaseId: string
}

export function View({ projectId, databaseId }: MonitorProps) {
  const t = useT()
  const [dateRange, setDateRange] = useState<DateRange>(getDefaultMonitorDateRange)
  const [chartInterval, setChartInterval] = useState<UsageChartInterval>(
    DEFAULT_USAGE_CHART_INTERVAL,
  )
  const [selectedOrdinal, setSelectedOrdinal] = useState(0)
  const [activeSectionId, setActiveSectionId] = useState('connections')
  const resolvedInterval = useMemo(
    () => resolveUsageChartIntervalForRange(chartInterval, dateRange),
    [chartInterval, dateRange],
  )

  const { database } = useMysqlDatabase(projectId, databaseId)
  const replicaCount = database?.replicas ?? 0
  const metricsOrdinal = replicaCount > 0 ? selectedOrdinal : undefined

  useEffect(() => {
    if (selectedOrdinal > replicaCount) {
      setSelectedOrdinal(0)
    }
  }, [replicaCount, selectedOrdinal])

  const { data: specificationsData } = useDatabaseSpecifications(projectId, MYSQL_DATABASE_SPECS_SOURCE)
  const specs = useMemo(
    () =>
      mapDedicatedDatabaseSpecifications(specificationsData?.specifications),
    [specificationsData?.specifications],
  )
  const currentSpec = useMemo(
    () => specs.find((spec) => spec.id === database?.specification),
    [specs, database?.specification],
  )

  const {
    snapshot,
    lastRecordedAt,
    isLoading,
    isFetching,
    error,
    refresh,
  } = useMysqlMetricsSampling(projectId, databaseId)

  const {
    states: connectionStates,
    isLoading: connectionStatesLoading,
    error: connectionStatesError,
  } = useMysqlConnectionStates(projectId, databaseId)

  const {
    apps: connectionApps,
    isLoading: connectionAppsLoading,
    error: connectionAppsError,
  } = useMysqlConnectionApps(projectId, databaseId)

  const {
    tables: tableActivity,
    isLoading: tableActivityLoading,
    error: tableActivityError,
  } = useMysqlTableActivity(projectId, databaseId)

  const dedicatedMetrics = useDedicatedDatabaseMonitorMetrics(
    projectId,
    databaseId,
    dateRange,
    true,
    resolvedInterval,
    metricsOrdinal,
  )

  const storageLimitGb = useMemo(() => {
    if (database?.storage && database.storage > 0) {
      return database.storage
    }
    const rawSpec = specificationsData?.specifications?.find(
      (spec) => spec.slug === database?.specification,
    )
    if (rawSpec?.includedStorage && rawSpec.includedStorage > 0) {
      return rawSpec.includedStorage
    }
    return null
  }, [database?.specification, database?.storage, specificationsData?.specifications])

  const storageLimitBytes = useMemo(() => {
    if (storageLimitGb == null || storageLimitGb <= 0) return null
    return storageLimitGb * 1_000_000_000
  }, [storageLimitGb])

  const maxConnections = useMemo(() => {
    const fromSpec = currentSpec?.connections
    if (fromSpec && fromSpec !== '-' && fromSpec !== 'Serverless') {
      const parsed = Number.parseInt(fromSpec, 10)
      if (Number.isFinite(parsed) && parsed > 0) return parsed
    }
    return null
  }, [currentSpec?.connections])

  const connectionUsagePercent = useMemo(() => {
    if (!snapshot || maxConnections == null) return null
    return (snapshot.totalConnections / maxConnections) * 100
  }, [snapshot, maxConnections])

  const navGroups: MonitorNavGroup[] = [
    {
      id: 'overview',
      label: 'Overview',
      items: [
        { id: 'health', label: 'Health', icon: HeartPulse },
      ],
    },
    {
      id: 'compute',
      label: 'Compute',
      items: [
        { id: 'cpu', label: 'CPU', icon: Cpu },
        { id: 'memory', label: 'Memory', icon: MemoryStick },
        { id: 'qps', label: 'Queries per second', icon: Zap },
        { id: 'iops', label: 'Disk IOPS', icon: HardDrive },
      ],
    },
    {
      id: 'connections',
      label: 'Connections',
      items: [
        { id: 'connections', label: 'Connections', icon: Users },
        {
          id: 'connection-states',
          label: 'Connection states',
          icon: Activity,
        },
        {
          id: 'connection-apps',
          label: 'Connections by app',
          icon: AppWindow,
        },
        {
          id: 'session-signals',
          label: 'Session signals',
          icon: Timer,
        },
      ],
    },
    {
      id: 'storage',
      label: 'Storage',
      items: [
        { id: 'storage', label: 'Storage', icon: HardDrive },
        { id: 'tables', label: 'Largest tables', icon: Table2 },
        { id: 'table-bloat', label: 'Dead tuples', icon: Table2 },
        { id: 'sequential-scans', label: 'Sequential scans', icon: ScanLine },
      ],
    },
  ]

  const scrollToChart = useCallback((id: string) => {
    setActiveSectionId(id)
    const el = document.getElementById(`mysql-metric-chart-${id}`)
    el?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [])

  const tableSizeBars = useMemo(
    () =>
      tableActivity.map((table) => ({
        label: table.tableName,
        fullLabel: `${table.schema}.${table.tableName}`,
        value: table.totalBytes,
        detail: `${formatCompactCount(table.liveTuples)} live rows`,
      })),
    [tableActivity],
  )

  const connectionStateBars = useMemo(
    () =>
      connectionStates.map((state) => ({
        label: formatConnectionStateLabel(state.state),
        value: state.count,
      })),
    [connectionStates],
  )

  const connectionAppBars = useMemo(
    () =>
      connectionApps.map((app) => ({
        label: app.applicationName,
        fullLabel: app.applicationName,
        value: app.count,
      })),
    [connectionApps],
  )

  const tableBloatBars = useMemo(
    () =>
      [...tableActivity]
        .filter((table) => table.deadTuples > 0)
        .sort((a, b) => b.deadTuples - a.deadTuples)
        .slice(0, 12)
        .map((table) => {
          const tupleTotal = table.liveTuples + table.deadTuples
          const deadRatio =
            tupleTotal > 0 ? (table.deadTuples / tupleTotal) * 100 : 0
          return {
            label: table.tableName,
            fullLabel: `${table.schema}.${table.tableName}`,
            value: table.deadTuples,
            detail: `${deadRatio.toFixed(1)}% dead · ${formatCompactCount(table.liveTuples)} live`,
          }
        }),
    [tableActivity],
  )

  const sequentialScanBars = useMemo(
    () =>
      [...tableActivity]
        .filter((table) => table.seqScans > 0)
        .sort((a, b) => b.seqScans - a.seqScans)
        .slice(0, 12)
        .map((table) => ({
          label: table.tableName,
          fullLabel: `${table.schema}.${table.tableName}`,
          value: table.seqScans,
          detail: `${formatCompactCount(table.idxScans)} index scans`,
        })),
    [tableActivity],
  )

  const rollbackRatio = useMemo(() => {
    if (!snapshot) return null
    const total = snapshot.xactCommit + snapshot.xactRollback
    if (total <= 0) return null
    return (snapshot.xactRollback / total) * 100
  }, [snapshot])

  const cpuPoints = dedicatedMetrics.cpu.isError
    ? []
    : (dedicatedMetrics.cpu.data?.chartPoints ?? [])
  const memoryPoints = dedicatedMetrics.memory.isError
    ? []
    : (dedicatedMetrics.memory.data?.chartPoints ?? [])
  const qpsPoints = dedicatedMetrics.qps.isError
    ? []
    : (dedicatedMetrics.qps.data?.chartPoints ?? [])
  const iopsReadPoints = dedicatedMetrics.iopsRead.isError
    ? []
    : (dedicatedMetrics.iopsRead.data?.chartPoints ?? [])
  const iopsWritePoints = dedicatedMetrics.iopsWrite.isError
    ? []
    : (dedicatedMetrics.iopsWrite.data?.chartPoints ?? [])
  const dedicatedStoragePoints = dedicatedMetrics.storage.isError
    ? []
    : (dedicatedMetrics.storage.data?.chartPoints ?? [])
  const dedicatedConnectionsPoints = dedicatedMetrics.connections.isError
    ? []
    : (dedicatedMetrics.connections.data?.chartPoints ?? [])
  const storageUsedBytes =
    dedicatedStoragePoints.length > 0
      ? getDedicatedDatabaseGaugeHeadline(dedicatedStoragePoints)
      : null
  const storageUsagePercent = useMemo(() => {
    if (
      storageUsedBytes == null ||
      storageLimitBytes == null ||
      storageLimitBytes <= 0
    ) {
      return null
    }
    return (storageUsedBytes / storageLimitBytes) * 100
  }, [storageUsedBytes, storageLimitBytes])

  const cpuSeries = useMemo(
    () => usageChartPointsToMonitorSeries(cpuPoints),
    [cpuPoints],
  )
  const memorySeries = useMemo(
    () => usageChartPointsToMonitorSeries(memoryPoints),
    [memoryPoints],
  )
  const qpsSeries = useMemo(
    () => usageChartPointsToMonitorSeries(qpsPoints),
    [qpsPoints],
  )
  const iopsSeries = useMemo(
    () => mergeDualUsageChartSeries(iopsReadPoints, iopsWritePoints),
    [iopsReadPoints, iopsWritePoints],
  )
  const dedicatedStorageSeries = useMemo(
    () => usageChartPointsToMonitorSeries(dedicatedStoragePoints),
    [dedicatedStoragePoints],
  )
  const dedicatedConnectionsSeries = useMemo(
    () => usageChartPointsToMonitorSeries(dedicatedConnectionsPoints),
    [dedicatedConnectionsPoints],
  )

  const cpuPercent = getDedicatedDatabaseGaugeHeadline(cpuPoints)
  const memoryPercent = getDedicatedDatabaseGaugeHeadline(memoryPoints)
  const qpsLatest = getDedicatedDatabaseRateHeadline(qpsPoints)
  const iopsReadLatest = getDedicatedDatabaseRateHeadline(iopsReadPoints)
  const iopsWriteLatest = getDedicatedDatabaseRateHeadline(iopsWritePoints)

  const cpuChartLoading = shouldShowUsageChartSkeleton(
    dedicatedMetrics.cpu.isError,
    dedicatedMetrics.cpu.isLoading,
    dedicatedMetrics.cpu.isPlaceholderData,
  )
  const memoryChartLoading = shouldShowUsageChartSkeleton(
    dedicatedMetrics.memory.isError,
    dedicatedMetrics.memory.isLoading,
    dedicatedMetrics.memory.isPlaceholderData,
  )
  const qpsChartLoading = shouldShowUsageChartSkeleton(
    dedicatedMetrics.qps.isError,
    dedicatedMetrics.qps.isLoading,
    dedicatedMetrics.qps.isPlaceholderData,
  )
  const iopsChartLoading = shouldShowUsageChartSkeleton(
    dedicatedMetrics.iopsRead.isError || dedicatedMetrics.iopsWrite.isError,
    dedicatedMetrics.iopsRead.isLoading || dedicatedMetrics.iopsWrite.isLoading,
    dedicatedMetrics.iopsRead.isPlaceholderData ||
      dedicatedMetrics.iopsWrite.isPlaceholderData,
  )
  const connectionsChartLoading = shouldShowUsageChartSkeleton(
    dedicatedMetrics.connections.isError,
    dedicatedMetrics.connections.isLoading,
    dedicatedMetrics.connections.isPlaceholderData,
  )
  const storageChartLoading = shouldShowUsageChartSkeleton(
    dedicatedMetrics.storage.isError,
    dedicatedMetrics.storage.isLoading,
    dedicatedMetrics.storage.isPlaceholderData,
  )

  const refetchDedicatedMetrics = dedicatedMetrics.refetchAll
  const handleRefresh = useCallback(async () => {
    await Promise.all([refresh(), refetchDedicatedMetrics()])
  }, [refresh, refetchDedicatedMetrics])

  const metricsError =
    error ??
    connectionStatesError ??
    connectionAppsError ??
    tableActivityError ??
    null

  return (
    <div className="flex h-full min-h-0 w-full flex-col overflow-hidden">
      <div className="shrink-0 border-b border-border bg-background px-4 py-3 sm:px-6">
        <div className="flex w-full flex-wrap items-center gap-3">
          <DatabaseMonitorNodeSelect
            replicaCount={replicaCount}
            value={selectedOrdinal}
            onValueChange={setSelectedOrdinal}
            className="h-9"
          />
          {lastRecordedAt ? (
            <span className="text-[12px] text-muted-foreground">
              Updated {formatDistanceToNow(lastRecordedAt, { addSuffix: true })}
            </span>
          ) : null}
          <div className="ms-auto flex shrink-0 flex-wrap items-center justify-end gap-3">
            <UsageChartIntervalToggle
              value={resolvedInterval}
              onValueChange={setChartInterval}
              dateRange={dateRange}
            />
            <DateRangePicker
              dateRange={dateRange}
              onDateRangeChange={(range) =>
                setDateRange(range ?? getDefaultMonitorDateRange())
              }
              className="h-9 min-w-[200px]"
            />
            <RefreshButton
              onClick={() => void handleRefresh()}
              isRefreshing={
                isFetching ||
                dedicatedMetrics.cpu.isFetching ||
                dedicatedMetrics.memory.isFetching
              }
              tooltip={t('Refresh metrics')}
            />
          </div>
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="w-full px-4 py-4 sm:px-6 sm:py-6">
          {metricsError ? (
            <Alert variant="destructive" className="mb-6">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>{t('Failed to load metrics')}</AlertTitle>
              <AlertDescription className="text-[13px]">
                {getErrorMessage(metricsError)}
              </AlertDescription>
            </Alert>
          ) : null}

          <div className="flex flex-col gap-4 lg:flex-row lg:gap-10">
            <MysqlMonitorNav
              navGroups={navGroups}
              activeSectionId={activeSectionId}
              onNavigate={scrollToChart}
            />

            <div className="min-w-0 flex-1 space-y-10">
              <section className="space-y-6">
                <MonitorSectionHeading title={t('Overview')} />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  <MysqlMetricKpiCard
                    label={t('Connections')}
                    value={
                      snapshot
                        ? String(snapshot.totalConnections)
                        : isLoading
                          ? '-'
                          : '0'
                    }
                    subValue={
                      maxConnections != null ? `/ ${maxConnections}` : undefined
                    }
                    description={t('Active client sessions connected to this database.')}
                    progress={connectionUsagePercent}
                    progressTone={getUsageTone(connectionUsagePercent)}
                  />
                  <MysqlMetricKpiCard
                    label={t('Storage used')}
                    value={
                      storageUsedBytes != null
                        ? formatCompactBytes(storageUsedBytes)
                        : storageChartLoading
                          ? '-'
                          : '0B'
                    }
                    subValue={
                      storageLimitBytes != null
                        ? `/ ${formatCompactBytes(storageLimitBytes)} available${
                            storageUsagePercent != null
                              ? ` · ${storageUsagePercent.toFixed(1)}%`
                              : ''
                          }`
                        : undefined
                    }
                    description={t(
                      'Storage used by this database instance compared to provisioned capacity.',
                    )}
                    progress={storageUsagePercent}
                    progressTone={getUsageTone(storageUsagePercent)}
                    progressCaption={
                      storageUsedBytes != null && storageLimitBytes != null
                        ? `${formatCompactBytes(storageUsedBytes)} of ${formatCompactBytes(storageLimitBytes)} available${
                            storageUsagePercent != null
                              ? ` (${storageUsagePercent.toFixed(1)}%)`
                              : ''
                          }`
                        : undefined
                    }
                  />
                  <MysqlMetricKpiCard
                    label={t('Cache hit ratio')}
                    value={
                      snapshot
                        ? `${snapshot.cacheHitRatio.toFixed(1)}%`
                        : isLoading
                          ? '-'
                          : '0%'
                    }
                    description={t('Share of blocks served from memory instead of disk.')}
                    progress={snapshot?.cacheHitRatio ?? null}
                    progressTone={getCacheHitTone(
                      snapshot?.cacheHitRatio ?? null,
                    )}
                    progressCaption={
                      snapshot
                        ? `${snapshot.cacheHitRatio.toFixed(1)}% buffer cache hits`
                        : undefined
                    }
                  />
                </div>

                {snapshot ? (
                  <div
                    id="mysql-metric-chart-health"
                    className={MONITOR_SCROLL_MARGIN}
                  >
                    <MysqlMetricsBentoCard
                      title={t('Health')}
                      columns={4}
                      tiles={[
                        {
                          id: 'server-uptime',
                          label: 'Server uptime',
                          value: formatMysqlUptime(snapshot.uptimeSeconds),
                          description:
                            'Time elapsed since the MySQL server process last started.',
                        },
                        {
                          id: 'server-started',
                          label: 'Server started',
                          value: snapshot.serverStartedAt ? (
                            <DateTooltip
                              date={new Date(snapshot.serverStartedAt)}
                            />
                          ) : (
                            '-'
                          ),
                          description:
                            'When the MySQL server process was last started.',
                        },
                        {
                          id: 'commits',
                          label: 'Commits',
                          value: formatCompactCount(snapshot.xactCommit),
                          subValue: t('since stats reset'),
                          description:
                            'Committed transactions since MySQL statistics were last reset.',
                        },
                        {
                          id: 'rollbacks',
                          label: 'Rollbacks',
                          value: formatCompactCount(snapshot.xactRollback),
                          subValue:
                            rollbackRatio != null
                              ? `${rollbackRatio.toFixed(1)}% ${t('of transactions')}`
                              : t('since stats reset'),
                          description:
                            'Rolled back transactions since MySQL statistics were last reset.',
                        },
                      ]}
                    />
                  </div>
                ) : null}
              </section>

              <section className="space-y-6">
                <MonitorSectionHeading title={t('Compute')} />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <MysqlMetricKpiCard
                    label={t('CPU')}
                    value={
                      cpuPoints.length > 0 ? `${cpuPercent.toFixed(1)}%` : '-'
                    }
                    description={t(DEDICATED_DATABASE_CPU_DESCRIPTION)}
                    progress={cpuPoints.length > 0 ? cpuPercent : null}
                    progressTone={getUsageTone(
                      cpuPoints.length > 0 ? cpuPercent : null,
                    )}
                  />
                  <MysqlMetricKpiCard
                    label={t('Memory')}
                    value={
                      memoryPoints.length > 0
                        ? `${memoryPercent.toFixed(1)}%`
                        : '-'
                    }
                    description={t(DEDICATED_DATABASE_MEMORY_DESCRIPTION)}
                    progress={memoryPoints.length > 0 ? memoryPercent : null}
                    progressTone={getUsageTone(
                      memoryPoints.length > 0 ? memoryPercent : null,
                    )}
                  />
                  <MysqlMetricKpiCard
                    label={t('Queries per second')}
                    value={
                      qpsPoints.length > 0 ? qpsLatest.toFixed(1) : '-'
                    }
                    description={t(DEDICATED_DATABASE_QPS_DESCRIPTION)}
                  />
                  <MysqlMetricKpiCard
                    label={t('Disk IOPS')}
                    value={
                      iopsChartLoading &&
                      iopsReadPoints.length === 0 &&
                      iopsWritePoints.length === 0
                        ? '-'
                        : `${(iopsReadPoints.length > 0 ? iopsReadLatest : 0).toFixed(1)} ${t('read')} · ${(iopsWritePoints.length > 0 ? iopsWriteLatest : 0).toFixed(1)} ${t('write')}`
                    }
                    description={t(DEDICATED_DATABASE_IOPS_DESCRIPTION)}
                  />
                </div>

                <div className="space-y-6">
                  <MysqlMetricChart
                    id="cpu"
                    title={t('CPU')}
                    description={t(DEDICATED_DATABASE_CPU_DESCRIPTION)}
                    unit=""
                    data={cpuSeries}
                    formatY={(value) => `${value.toFixed(1)}%`}
                    usageValue={cpuPoints.length > 0 ? cpuPercent : null}
                    usageQuota={100}
                    usageUnitLabel="utilization"
                    isLoading={cpuChartLoading}
                    emptyMessage={t('No CPU metrics for this date range')}
                  />

                  <MysqlMetricChart
                    id="memory"
                    title={t('Memory')}
                    description={t(DEDICATED_DATABASE_MEMORY_DESCRIPTION)}
                    unit=""
                    data={memorySeries}
                    formatY={(value) => `${value.toFixed(1)}%`}
                    usageValue={memoryPoints.length > 0 ? memoryPercent : null}
                    usageQuota={100}
                    usageUnitLabel="utilization"
                    isLoading={memoryChartLoading}
                    emptyMessage={t('No memory metrics for this date range')}
                  />

                  <MysqlMetricChart
                    id="qps"
                    title={t('Queries per second')}
                    description={t(DEDICATED_DATABASE_QPS_DESCRIPTION)}
                    unit="qps"
                    data={qpsSeries}
                    formatY={(value) => value.toFixed(1)}
                    isLoading={qpsChartLoading}
                    emptyMessage={t('No QPS metrics for this date range')}
                  />

                  <MysqlMetricChart
                    id="iops"
                    title={t('Disk IOPS')}
                    description={t(DEDICATED_DATABASE_IOPS_DESCRIPTION)}
                    unit="iops"
                    primaryLabel="Reads"
                    secondaryLabel="Writes"
                    secondaryUnit="iops"
                    data={iopsSeries}
                    formatY={(value) => value.toFixed(1)}
                    formatSecondaryY={(value) => value.toFixed(1)}
                    usageValue={
                      iopsReadPoints.length > 0 || iopsWritePoints.length > 0
                        ? iopsReadPoints.length > 0
                          ? iopsReadLatest
                          : 0
                        : null
                    }
                    usageSecondaryValue={
                      iopsReadPoints.length > 0 || iopsWritePoints.length > 0
                        ? iopsWritePoints.length > 0
                          ? iopsWriteLatest
                          : 0
                        : null
                    }
                    usageUnitLabel="read"
                    usageSecondaryUnitLabel="write"
                    isLoading={iopsChartLoading}
                    emptyMessage={t('No IOPS metrics for this date range')}
                  />
                </div>
              </section>

              <section className="space-y-6">
                <MonitorSectionHeading title={t('Connections')} />

                <div className="space-y-6">
                  <MysqlMetricChart
                    id="connections"
                    title={t('Connections')}
                    description={t(DEDICATED_DATABASE_CONNECTIONS_DESCRIPTION)}
                    unit="connections"
                    data={dedicatedConnectionsSeries}
                    formatY={(value) => Math.round(value).toLocaleString()}
                    isLoading={connectionsChartLoading}
                    emptyMessage={t('No connection metrics for this date range')}
                  />

                  <MysqlMetricRankedList
                    id="connection-states"
                    title={t('Connection states')}
                    description={t('Current session states from information_schema.PROCESSLIST.')}
                    items={connectionStateBars}
                    formatValue={(value) => Math.round(value).toLocaleString()}
                    emptyMessage={
                      connectionStatesLoading
                        ? t('Loading connection states...')
                        : t('No active client sessions.')
                    }
                  />

                  <MysqlMetricRankedList
                    id="connection-apps"
                    title={t('Connections by app')}
                    description={t('Client sessions grouped by user from information_schema.PROCESSLIST.')}
                    items={connectionAppBars}
                    formatValue={(value) => Math.round(value).toLocaleString()}
                    emptyMessage={
                      connectionAppsLoading
                        ? t('Loading connection apps...')
                        : t('No active client sessions.')
                    }
                  />

                  {snapshot ? (
                    <div
                      id="mysql-metric-chart-session-signals"
                      className={MONITOR_SCROLL_MARGIN}
                    >
                      <MysqlMetricsBentoCard
                        title={t('Session signals')}
                        tiles={[
                          {
                            id: 'idle-in-transaction',
                            label: t('Idle in transaction'),
                            value: String(snapshot.idleInTransaction),
                            description: t(
                              'Sessions holding an open transaction without running a query. These can block vacuum and hold locks.',
                            ),
                          },
                          {
                            id: 'long-running',
                            label: t('Long-running queries'),
                            value: String(snapshot.longRunningQueries),
                            subValue: t('active over 10s'),
                            description: t(
                              'Currently active queries that have been running for more than 10 seconds.',
                            ),
                          },
                        ]}
                      />
                    </div>
                  ) : null}
                </div>
              </section>

              <section className="space-y-6">
                <MonitorSectionHeading title={t('Storage')} />

                <div className="space-y-6">
                  <MysqlMetricChart
                    id="storage"
                    title={t('Storage')}
                    description={t(DEDICATED_DATABASE_STORAGE_DESCRIPTION)}
                    unit=""
                    data={dedicatedStorageSeries}
                    formatY={(value) => formatCompactBytes(value)}
                    usageValue={
                      dedicatedStoragePoints.length > 0
                        ? getDedicatedDatabaseGaugeHeadline(dedicatedStoragePoints)
                        : null
                    }
                    usageQuota={storageLimitBytes}
                    isLoading={storageChartLoading}
                    emptyMessage={t('No storage metrics for this date range')}
                  />

                  <MysqlMetricRankedList
                    id="tables"
                    title={t('Largest tables')}
                    description={t('Top tables by on-disk size, including indexes and TOAST data.')}
                    items={tableSizeBars}
                    emptyMessage={
                      tableActivityLoading
                        ? t('Loading table activity...')
                        : t('No user tables found in this database.')
                    }
                  />

                  <MysqlMetricRankedList
                    id="table-bloat"
                    title={t('Dead tuples')}
                    description={t('Tables with the most dead rows waiting for vacuum. High dead tuple ratios can slow scans and waste space.')}
                    items={tableBloatBars}
                    formatValue={(value) => formatCompactCount(value)}
                    emptyMessage={
                      tableActivityLoading
                        ? t('Loading table activity...')
                        : t('No dead tuples found across user tables.')
                    }
                  />

                  <MysqlMetricRankedList
                    id="sequential-scans"
                    title={t('Sequential scans')}
                    description={t('Tables with the most sequential scans since statistics were reset. Compare with index scans to spot missing or unused indexes.')}
                    items={sequentialScanBars}
                    formatValue={(value) => formatCompactCount(value)}
                    emptyMessage={
                      tableActivityLoading
                        ? t('Loading table activity...')
                        : t('No sequential scans recorded on user tables.')
                    }
                  />
                </div>
              </section>

            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
