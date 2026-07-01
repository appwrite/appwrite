import { useCallback, useMemo, useState } from 'react'
import {
  differenceInCalendarDays,
  endOfDay,
  format,
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
  ArrowLeftRight,
  Cpu,
  Gauge,
  HardDrive,
  HeartPulse,
  Layers,
  MemoryStick,
  Network,
  RefreshCw,
  ScanLine,
  Table2,
  Timer,
  Users,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
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
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { DateRangePicker } from '@/components/pages/projects/$projectId/analytics/DateRangePicker'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  formatCompactBytes,
  formatCompactCount,
} from '@/lib/usage/format-metric'
import { mapDedicatedDatabaseSpecifications } from '@/lib/database-specs'
import {
  useDatabaseSpecifications,
  usePostgresConnectionApps,
  usePostgresConnectionStates,
  usePostgresDatabase,
  usePostgresMetricsSampling,
  usePostgresTableActivity,
} from '@/lib/react-query/hooks'
import {
  filterSamplesByRange,
  formatConnectionStateLabel,
  formatPostgresUptime,
} from '@/lib/postgres-metrics'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  buildMockUsageMetricSeries,
  latestMockSeriesValue,
  POSTGRES_USAGE_PLACEHOLDER_NOTE,
} from '@/lib/postgres-usage-placeholder-metrics'
import { PostgresMetricChart } from './_components/PostgresMetricChart'
import { PostgresMetricRankedList } from './_components/PostgresMetricRankedList'
import { PostgresMetricKpiCard } from './_components/PostgresMetricKpiCard'
import { PostgresMetricsBentoCard } from './_components/PostgresMetricsBentoCard'

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

function dateRangeToBounds(range: DateRange | undefined): {
  fromMs: number
  toMs: number
} {
  if (!range?.from) {
    const fallback = getDefaultMonitorDateRange()
    return {
      fromMs: startOfDay(fallback.from!).getTime(),
      toMs: endOfDay(fallback.to ?? fallback.from!).getTime(),
    }
  }
  return {
    fromMs: startOfDay(range.from).getTime(),
    toMs: endOfDay(range.to ?? range.from).getTime(),
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

function PostgresMonitorNav({
  navGroups,
  activeSectionId,
  onNavigate,
}: {
  navGroups: MonitorNavGroup[]
  activeSectionId: string
  onNavigate: (id: string) => void
}) {
  return (
    <>
      <div className="space-y-2 lg:hidden" aria-label="Monitor metrics">
        <Select value={activeSectionId} onValueChange={onNavigate}>
          <SelectTrigger size="sm" className="h-9 w-full text-[13px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {navGroups.map((group) => (
              <SelectGroup key={group.id}>
                <SelectLabel className="text-[11px] uppercase tracking-wider">
                  {group.label}
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
                        {item.label}
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
        aria-label="Monitor metrics"
      >
        {navGroups.map((group) => (
          <div key={group.id}>
            <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {group.label}
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
                    <span className="truncate">{item.label}</span>
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
  const [dateRange, setDateRange] = useState<DateRange>(getDefaultMonitorDateRange)
  const [activeSectionId, setActiveSectionId] = useState('connections')
  const { fromMs, toMs } = useMemo(
    () => dateRangeToBounds(dateRange),
    [dateRange],
  )

  const { database } = usePostgresDatabase(projectId, databaseId)
  const { data: specificationsData } = useDatabaseSpecifications(projectId)
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
    samples,
    lastRecordedAt,
    isLoading,
    isFetching,
    error,
    refresh,
  } = usePostgresMetricsSampling(projectId, databaseId)

  const {
    states: connectionStates,
    isLoading: connectionStatesLoading,
    error: connectionStatesError,
  } = usePostgresConnectionStates(projectId, databaseId)

  const {
    apps: connectionApps,
    isLoading: connectionAppsLoading,
    error: connectionAppsError,
  } = usePostgresConnectionApps(projectId, databaseId)

  const {
    tables: tableActivity,
    isLoading: tableActivityLoading,
    error: tableActivityError,
  } = usePostgresTableActivity(projectId, databaseId)

  const filteredSamples = useMemo(
    () => filterSamplesByRange(samples, fromMs, toMs),
    [samples, fromMs, toMs],
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
    if (fromSpec && fromSpec !== '—' && fromSpec !== 'Serverless') {
      const parsed = Number.parseInt(fromSpec, 10)
      if (Number.isFinite(parsed) && parsed > 0) return parsed
    }
    return null
  }, [currentSpec?.connections])

  const storageUsagePercent = useMemo(() => {
    if (!snapshot || storageLimitBytes == null || storageLimitBytes <= 0) {
      return null
    }
    return (snapshot.databaseSizeBytes / storageLimitBytes) * 100
  }, [snapshot, storageLimitBytes])

  const connectionUsagePercent = useMemo(() => {
    if (!snapshot || maxConnections == null) return null
    return (snapshot.totalConnections / maxConnections) * 100
  }, [snapshot, maxConnections])

  const navGroups: MonitorNavGroup[] = [
    {
      id: 'overview',
      label: 'Overview',
      items: [
        { id: 'health', label: 'Database health', icon: HeartPulse },
      ],
    },
    {
      id: 'compute',
      label: 'Compute',
      items: [
        { id: 'cpu', label: 'CPU usage', icon: Cpu },
        { id: 'memory', label: 'Memory usage', icon: MemoryStick },
        { id: 'disk-io', label: 'Disk I/O', icon: HardDrive },
        { id: 'network', label: 'Network throughput', icon: Network },
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
        { id: 'storage', label: 'Storage usage', icon: HardDrive },
        { id: 'tables', label: 'Largest tables', icon: Table2 },
        { id: 'table-bloat', label: 'Dead tuples', icon: Table2 },
        { id: 'sequential-scans', label: 'Sequential scans', icon: ScanLine },
      ],
    },
    {
      id: 'workload',
      label: 'Workload',
      items: [
        { id: 'transactions', label: 'Transaction rate', icon: ArrowLeftRight },
        { id: 'tuples', label: 'Tuple operations', icon: Layers },
        { id: 'disk-reads', label: 'Disk block reads', icon: HardDrive },
        { id: 'cache', label: 'Cache hit ratio', icon: Gauge },
      ],
    },
  ]

  const scrollToChart = useCallback((id: string) => {
    setActiveSectionId(id)
    const el = document.getElementById(`postgres-metric-chart-${id}`)
    el?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [])

  const connectionsSeries = useMemo(
    () =>
      filteredSamples.map((sample) => ({
        timestamp: sample.timestamp,
        value: sample.totalConnections,
        secondaryValue: sample.activeQueries,
      })),
    [filteredSamples],
  )

  const transactionsSeries = useMemo(
    () =>
      filteredSamples.map((sample) => ({
        timestamp: sample.timestamp,
        value: sample.commitsPerMin,
        secondaryValue: sample.rollbacksPerMin,
      })),
    [filteredSamples],
  )

  const diskReadsSeries = useMemo(
    () =>
      filteredSamples.map((sample) => ({
        timestamp: sample.timestamp,
        value: sample.blockReadsPerMin,
      })),
    [filteredSamples],
  )

  const tuplesSeries = useMemo(
    () =>
      filteredSamples.map((sample) => ({
        timestamp: sample.timestamp,
        value: sample.tuplesReadPerMin,
        secondaryValue: sample.tuplesWrittenPerMin,
      })),
    [filteredSamples],
  )

  const storageSeries = useMemo(
    () =>
      filteredSamples.map((sample) => ({
        timestamp: sample.timestamp,
        value: sample.databaseSizeBytes,
      })),
    [filteredSamples],
  )

  const cacheSeries = useMemo(
    () =>
      filteredSamples.map((sample) => ({
        timestamp: sample.timestamp,
        value: sample.cacheHitRatio,
      })),
    [filteredSamples],
  )

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

  const placeholderEpoch = lastRecordedAt ?? 0

  const cpuPlaceholderSeries = useMemo(
    () =>
      buildMockUsageMetricSeries(fromMs, toMs, 0.5, 42, 18, {
        refreshEpoch: placeholderEpoch,
      }),
    [fromMs, toMs, placeholderEpoch],
  )

  const memoryPlaceholderSeries = useMemo(
    () =>
      buildMockUsageMetricSeries(fromMs, toMs, 0.9, 58, 15, {
        refreshEpoch: placeholderEpoch,
      }),
    [fromMs, toMs, placeholderEpoch],
  )

  const diskIoPlaceholderSeries = useMemo(
    () =>
      buildMockUsageMetricSeries(fromMs, toMs, 1.2, 18, 8, {
        refreshEpoch: placeholderEpoch,
        secondaryBase: 12,
        secondaryAmplitude: 5,
      }),
    [fromMs, toMs, placeholderEpoch],
  )

  const networkPlaceholderSeries = useMemo(
    () =>
      buildMockUsageMetricSeries(fromMs, toMs, 2.0, 24, 10, {
        refreshEpoch: placeholderEpoch,
        secondaryBase: 16,
        secondaryAmplitude: 6,
      }),
    [fromMs, toMs, placeholderEpoch],
  )

  const lastDiskIoPoint =
    diskIoPlaceholderSeries[diskIoPlaceholderSeries.length - 1]
  const lastNetworkPoint =
    networkPlaceholderSeries[networkPlaceholderSeries.length - 1]

  const mockCpuPercent = latestMockSeriesValue(cpuPlaceholderSeries)
  const mockMemoryPercent = latestMockSeriesValue(memoryPlaceholderSeries)
  const mockDiskReadMbps = lastDiskIoPoint?.value ?? null
  const mockDiskWriteMbps = lastDiskIoPoint?.secondaryValue ?? null
  const mockNetworkIngressMbps = lastNetworkPoint?.value ?? null
  const mockNetworkEgressMbps = lastNetworkPoint?.secondaryValue ?? null

  const metricsError =
    error ??
    connectionStatesError ??
    connectionAppsError ??
    tableActivityError ??
    null
  const rangeLabel = useMemo(() => {
    if (!dateRange.from) return 'Last 24 hours'
    const to = dateRange.to ?? dateRange.from
    const days = differenceInCalendarDays(endOfDay(to), startOfDay(dateRange.from)) + 1
    if (days <= 1) return 'Last 24 hours'
    return `${days} day${days === 1 ? '' : 's'}`
  }, [dateRange])

  return (
    <div className="flex h-full min-h-0 w-full flex-col overflow-hidden">
      <div className="shrink-0 border-b border-border bg-background px-4 py-3 sm:px-6">
        <div className="flex w-full flex-wrap items-center gap-3">
          {lastRecordedAt ? (
            <span className="text-[12px] text-muted-foreground">
              Updated {formatDistanceToNow(lastRecordedAt, { addSuffix: true })}
            </span>
          ) : null}
          <div className="ms-auto flex shrink-0 items-center gap-3">
            <DateRangePicker
              dateRange={dateRange}
              onDateRangeChange={(range) =>
                setDateRange(range ?? getDefaultMonitorDateRange())
              }
              className="h-9 min-w-[200px]"
            />
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 w-9 shrink-0 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50"
                    type="button"
                    onClick={() => void refresh()}
                    disabled={isFetching}
                  >
                    <RefreshCw
                      className={cn(
                        'h-4 w-4 transition-transform duration-500',
                        isFetching && 'animate-spin',
                      )}
                    />
                  </Button>
                </TooltipTrigger>
                <TooltipContent>Refresh metrics</TooltipContent>
              </Tooltip>
            </TooltipProvider>
          </div>
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="w-full px-4 py-4 sm:px-6 sm:py-6">
          {metricsError ? (
            <Alert variant="destructive" className="mb-6">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>Failed to load metrics</AlertTitle>
              <AlertDescription className="text-[13px]">
                {getErrorMessage(metricsError)}
              </AlertDescription>
            </Alert>
          ) : null}

          <div className="flex flex-col gap-4 lg:flex-row lg:gap-10">
            <PostgresMonitorNav
              navGroups={navGroups}
              activeSectionId={activeSectionId}
              onNavigate={scrollToChart}
            />

            <div className="min-w-0 flex-1 space-y-10">
              <div className="rounded-lg border border-border bg-muted/20 px-4 py-3">
                <p className="text-[12px] leading-relaxed text-muted-foreground">
                  Metrics are collected from live PostgreSQL statistics via the
                  SQL API. Time-series charts use samples gathered while this
                  view is open across {rangeLabel.toLowerCase()}.
                </p>
              </div>

              <section className="space-y-6">
                <MonitorSectionHeading title="Overview" />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  <PostgresMetricKpiCard
                    label="Connections"
                    value={
                      snapshot
                        ? String(snapshot.totalConnections)
                        : isLoading
                          ? '—'
                          : '0'
                    }
                    subValue={
                      maxConnections != null ? `/ ${maxConnections}` : undefined
                    }
                    description="Active client sessions connected to this database."
                    progress={connectionUsagePercent}
                    progressTone={getUsageTone(connectionUsagePercent)}
                  />
                  <PostgresMetricKpiCard
                    label="Storage used"
                    value={
                      snapshot
                        ? formatCompactBytes(snapshot.databaseSizeBytes)
                        : isLoading
                          ? '—'
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
                    description="On-disk database size compared to provisioned storage for this instance."
                    progress={storageUsagePercent}
                    progressTone={getUsageTone(storageUsagePercent)}
                    progressCaption={
                      snapshot && storageLimitBytes != null
                        ? `${formatCompactBytes(snapshot.databaseSizeBytes)} of ${formatCompactBytes(storageLimitBytes)} available${
                            storageUsagePercent != null
                              ? ` (${storageUsagePercent.toFixed(1)}%)`
                              : ''
                          }`
                        : undefined
                    }
                  />
                  <PostgresMetricKpiCard
                    label="Cache hit ratio"
                    value={
                      snapshot
                        ? `${snapshot.cacheHitRatio.toFixed(1)}%`
                        : isLoading
                          ? '—'
                          : '0%'
                    }
                    description="Share of blocks served from memory instead of disk."
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
                    id="postgres-metric-chart-health"
                    className={MONITOR_SCROLL_MARGIN}
                  >
                    <PostgresMetricsBentoCard
                      title="Database health"
                      columns={4}
                      tiles={[
                        {
                          id: 'server-uptime',
                          label: 'Server uptime',
                          value: formatPostgresUptime(snapshot.uptimeSeconds),
                          description:
                            'Time elapsed since the PostgreSQL server process last started.',
                        },
                        {
                          id: 'server-started',
                          label: 'Server started',
                          value: snapshot.serverStartedAt ? (
                            <DateTooltip
                              date={new Date(snapshot.serverStartedAt)}
                            />
                          ) : (
                            '—'
                          ),
                          description:
                            'When the PostgreSQL server process was last started.',
                        },
                        {
                          id: 'commits',
                          label: 'Commits',
                          value: formatCompactCount(snapshot.xactCommit),
                          subValue: 'since stats reset',
                          description:
                            'Committed transactions since PostgreSQL statistics were last reset.',
                        },
                        {
                          id: 'rollbacks',
                          label: 'Rollbacks',
                          value: formatCompactCount(snapshot.xactRollback),
                          subValue:
                            rollbackRatio != null
                              ? `${rollbackRatio.toFixed(1)}% of transactions`
                              : 'since stats reset',
                          description:
                            'Rolled back transactions since PostgreSQL statistics were last reset.',
                        },
                        {
                          id: 'deadlocks',
                          label: 'Deadlocks',
                          value: formatCompactCount(snapshot.deadlocks),
                          description:
                            'Deadlocks detected since PostgreSQL statistics were last reset.',
                        },
                        {
                          id: 'conflicts',
                          label: 'Conflicts',
                          value: formatCompactCount(snapshot.conflicts),
                          description:
                            'Query conflicts on standby replicas since statistics were last reset.',
                        },
                        {
                          id: 'temp-storage',
                          label: 'Temp storage',
                          value: formatCompactBytes(snapshot.tempBytes),
                          description:
                            'Temporary files written by queries since statistics were last reset.',
                        },
                        {
                          id: 'last-sample',
                          label: 'Last sample',
                          value: format(
                            new Date(snapshot.timestamp),
                            'MMM d, HH:mm',
                          ),
                          description:
                            'When the most recent metrics snapshot was collected.',
                        },
                      ]}
                    />
                  </div>
                ) : null}
              </section>

              <section className="space-y-6">
                <MonitorSectionHeading title="Compute" />

                <div className="rounded-lg border border-dashed border-border bg-muted/20 px-4 py-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="info" className="text-[10px] shrink-0">
                      Sample data
                    </Badge>
                    <p className="text-[12px] leading-relaxed text-muted-foreground">
                      {POSTGRES_USAGE_PLACEHOLDER_NOTE}
                    </p>
                  </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <PostgresMetricKpiCard
                    className="border-dashed"
                    label="CPU usage"
                    value={
                      mockCpuPercent != null
                        ? `${mockCpuPercent.toFixed(1)}%`
                        : '—'
                    }
                    description="Average CPU utilization for this database instance."
                    progress={mockCpuPercent}
                    progressTone={getUsageTone(mockCpuPercent)}
                  />
                  <PostgresMetricKpiCard
                    className="border-dashed"
                    label="Memory usage"
                    value={
                      mockMemoryPercent != null
                        ? `${mockMemoryPercent.toFixed(1)}%`
                        : '—'
                    }
                    description="Memory utilization relative to provisioned RAM."
                    progress={mockMemoryPercent}
                    progressTone={getUsageTone(mockMemoryPercent)}
                  />
                  <PostgresMetricKpiCard
                    className="border-dashed"
                    label="Disk I/O"
                    value={
                      mockDiskReadMbps != null
                        ? `${mockDiskReadMbps.toFixed(1)} MB/s`
                        : '—'
                    }
                    subValue={
                      mockDiskWriteMbps != null
                        ? `${mockDiskWriteMbps.toFixed(1)} MB/s writes`
                        : undefined
                    }
                    description="Combined read and write throughput for instance storage."
                  />
                  <PostgresMetricKpiCard
                    className="border-dashed"
                    label="Network"
                    value={
                      mockNetworkIngressMbps != null
                        ? `${mockNetworkIngressMbps.toFixed(1)} MB/s`
                        : '—'
                    }
                    subValue={
                      mockNetworkEgressMbps != null
                        ? `${mockNetworkEgressMbps.toFixed(1)} MB/s egress`
                        : undefined
                    }
                    description="Ingress and egress throughput for this database instance."
                  />
                </div>

                <div className="space-y-6">
                  <PostgresMetricChart
                    id="cpu"
                    title="CPU usage"
                    description="Average CPU utilization for this database instance."
                    unit=""
                    data={cpuPlaceholderSeries}
                    formatY={(value) => `${value.toFixed(1)}%`}
                    usageValue={mockCpuPercent}
                    usageQuota={100}
                    usageQuotaLabel="capacity"
                    isPlaceholder
                  />

                  <PostgresMetricChart
                    id="memory"
                    title="Memory usage"
                    description="Memory utilization relative to provisioned RAM."
                    unit=""
                    data={memoryPlaceholderSeries}
                    formatY={(value) => `${value.toFixed(1)}%`}
                    usageValue={mockMemoryPercent}
                    usageQuota={100}
                    usageQuotaLabel="capacity"
                    isPlaceholder
                  />

                  <PostgresMetricChart
                    id="disk-io"
                    title="Disk I/O"
                    description="Read and write throughput for instance storage."
                    unit="MB/s reads"
                    secondaryLabel="Writes"
                    secondaryUnit="MB/s"
                    data={diskIoPlaceholderSeries}
                    formatY={(value) => value.toFixed(1)}
                    formatSecondaryY={(value) => value.toFixed(1)}
                    isPlaceholder
                  />

                  <PostgresMetricChart
                    id="network"
                    title="Network throughput"
                    description="Ingress and egress for this database instance."
                    unit="MB/s ingress"
                    secondaryLabel="Egress"
                    secondaryUnit="MB/s"
                    data={networkPlaceholderSeries}
                    formatY={(value) => value.toFixed(1)}
                    formatSecondaryY={(value) => value.toFixed(1)}
                    isPlaceholder
                  />
                </div>
              </section>

              <section className="space-y-6">
                <MonitorSectionHeading title="Connections" />

                <div className="space-y-6">
                  <PostgresMetricChart
                    id="connections"
                    title="Connections"
                    description="Total client sessions and active queries sampled from pg_stat_activity."
                    unit="connections"
                    secondaryLabel="Active queries"
                    data={connectionsSeries}
                    formatY={(value) => Math.round(value).toLocaleString()}
                    emptyMessage={`Collecting connection samples for ${rangeLabel.toLowerCase()}. Samples refresh automatically every minute.`}
                  />

                  <PostgresMetricRankedList
                    id="connection-states"
                    title="Connection states"
                    description="Current session states from pg_stat_activity."
                    items={connectionStateBars}
                    formatValue={(value) => Math.round(value).toLocaleString()}
                    emptyMessage={
                      connectionStatesLoading
                        ? 'Loading connection states...'
                        : 'No active client sessions.'
                    }
                  />

                  <PostgresMetricRankedList
                    id="connection-apps"
                    title="Connections by app"
                    description="Client sessions grouped by application_name from pg_stat_activity."
                    items={connectionAppBars}
                    formatValue={(value) => Math.round(value).toLocaleString()}
                    emptyMessage={
                      connectionAppsLoading
                        ? 'Loading connection apps...'
                        : 'No active client sessions.'
                    }
                  />

                  {snapshot ? (
                    <div
                      id="postgres-metric-chart-session-signals"
                      className={MONITOR_SCROLL_MARGIN}
                    >
                      <PostgresMetricsBentoCard
                        title="Session signals"
                        tiles={[
                          {
                            id: 'idle-in-transaction',
                            label: 'Idle in transaction',
                            value: String(snapshot.idleInTransaction),
                            description:
                              'Sessions holding an open transaction without running a query. These can block vacuum and hold locks.',
                          },
                          {
                            id: 'long-running',
                            label: 'Long-running queries',
                            value: String(snapshot.longRunningQueries),
                            subValue: 'active over 10s',
                            description:
                              'Currently active queries that have been running for more than 10 seconds.',
                          },
                        ]}
                      />
                    </div>
                  ) : null}
                </div>
              </section>

              <section className="space-y-6">
                <MonitorSectionHeading title="Storage" />

                <div className="space-y-6">
                  <PostgresMetricChart
                    id="storage"
                    title="Storage usage"
                    description="Database size over time from pg_database_size, relative to provisioned storage."
                    unit=""
                    data={storageSeries}
                    formatY={(value) => formatCompactBytes(value)}
                    usageValue={snapshot?.databaseSizeBytes ?? null}
                    usageQuota={storageLimitBytes}
                  />

                  <PostgresMetricRankedList
                    id="tables"
                    title="Largest tables"
                    description="Top tables by on-disk size, including indexes and TOAST data."
                    items={tableSizeBars}
                    emptyMessage={
                      tableActivityLoading
                        ? 'Loading table activity...'
                        : 'No user tables found in this database.'
                    }
                  />

                  <PostgresMetricRankedList
                    id="table-bloat"
                    title="Dead tuples"
                    description="Tables with the most dead rows waiting for vacuum. High dead tuple ratios can slow scans and waste space."
                    items={tableBloatBars}
                    formatValue={(value) => formatCompactCount(value)}
                    emptyMessage={
                      tableActivityLoading
                        ? 'Loading table activity...'
                        : 'No dead tuples found across user tables.'
                    }
                  />

                  <PostgresMetricRankedList
                    id="sequential-scans"
                    title="Sequential scans"
                    description="Tables with the most sequential scans since statistics were reset. Compare with index scans to spot missing or unused indexes."
                    items={sequentialScanBars}
                    formatValue={(value) => formatCompactCount(value)}
                    emptyMessage={
                      tableActivityLoading
                        ? 'Loading table activity...'
                        : 'No sequential scans recorded on user tables.'
                    }
                  />
                </div>
              </section>

              <section className="space-y-6">
                <MonitorSectionHeading title="Workload" />

                <PostgresMetricChart
                  id="transactions"
                  title="Transaction rate"
                  description="Commits and rollbacks per minute, derived from pg_stat_database counters."
                  unit="commits / min"
                  secondaryLabel="Rollbacks"
                  secondaryUnit="/ min"
                  data={transactionsSeries}
                  formatY={(value) => Math.round(value).toLocaleString()}
                  formatSecondaryY={(value) =>
                    Math.round(value).toLocaleString()
                  }
                />

                <PostgresMetricChart
                  id="tuples"
                  title="Tuple operations"
                  description="Read and write tuple throughput per minute from pg_stat_database."
                  unit="reads / min"
                  secondaryLabel="Writes"
                  secondaryUnit="/ min"
                  data={tuplesSeries}
                  formatY={(value) => Math.round(value).toLocaleString()}
                  formatSecondaryY={(value) =>
                    Math.round(value).toLocaleString()
                  }
                />

                <PostgresMetricChart
                  id="disk-reads"
                  title="Disk block reads"
                  description="Blocks read from disk per minute. Rising disk reads alongside a falling cache hit ratio can signal memory pressure."
                  unit="blocks / min"
                  data={diskReadsSeries}
                  formatY={(value) => Math.round(value).toLocaleString()}
                />

                <PostgresMetricChart
                  id="cache"
                  title="Cache hit ratio"
                  description="Buffer cache effectiveness from pg_stat_database block reads and hits."
                  unit=""
                  data={cacheSeries}
                  formatY={(value) => `${value.toFixed(1)}%`}
                />
              </section>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
