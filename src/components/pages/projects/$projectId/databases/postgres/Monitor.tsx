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
  ArrowLeftRight,
  Gauge,
  HardDrive,
  Layers,
  RefreshCw,
  Table2,
  Users,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Select,
  SelectContent,
  SelectItem,
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
import {
  mapDedicatedDatabaseSpecifications,
  formatDedicatedSpecStorage,
} from '@/lib/database-specs'
import {
  useDatabaseSpecifications,
  usePostgresConnectionStates,
  usePostgresDatabase,
  usePostgresMetricsSampling,
  usePostgresTableActivity,
} from '@/lib/react-query/hooks'
import {
  filterSamplesByRange,
  formatConnectionStateLabel,
} from '@/lib/postgres-metrics'
import { PostgresMetricChart } from './_components/PostgresMetricChart'
import { PostgresMetricBarChart } from './_components/PostgresMetricBarChart'
import { PostgresMetricKpiCard } from './_components/PostgresMetricKpiCard'

type MonitorSection = { id: string; label: string; icon: LucideIcon }

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
    'flex w-full items-center gap-2.5 rounded-md px-3 py-2.5 text-left text-[13px] font-medium transition-colors',
    isActive
      ? 'bg-accent text-foreground'
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
  )
}

function PostgresMonitorNav({
  sections,
  activeSectionId,
  onNavigate,
}: {
  sections: MonitorSection[]
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
            {sections.map((section) => {
              const Icon = section.icon
              return (
                <SelectItem
                  key={section.id}
                  value={section.id}
                  className="text-[13px]"
                >
                  <span className="flex items-center gap-2">
                    <Icon className="h-4 w-4 shrink-0" />
                    {section.label}
                  </span>
                </SelectItem>
              )
            })}
          </SelectContent>
        </Select>
      </div>

      <nav
        className="hidden w-[220px] shrink-0 flex-col gap-2 lg:sticky lg:top-4 lg:flex lg:self-start"
        aria-label="Monitor metrics"
      >
        {sections.map((section) => {
          const Icon = section.icon
          return (
            <button
              key={section.id}
              type="button"
              onClick={() => onNavigate(section.id)}
              className={monitorNavLinkClassName(activeSectionId === section.id)}
            >
              <Icon className="h-4 w-4 shrink-0" />
              <span className="truncate">{section.label}</span>
            </button>
          )
        })}
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
    tables: tableActivity,
    isLoading: tableActivityLoading,
    error: tableActivityError,
  } = usePostgresTableActivity(projectId, databaseId)

  const filteredSamples = useMemo(
    () => filterSamplesByRange(samples, fromMs, toMs),
    [samples, fromMs, toMs],
  )

  const storageLimitBytes = useMemo(() => {
    const storageGb = database?.storage ?? 0
    if (storageGb <= 0) return null
    return storageGb * 1_000_000_000
  }, [database?.storage])

  const maxConnections = useMemo(() => {
    const fromSpec = currentSpec?.connections
    if (fromSpec && fromSpec !== '—' && fromSpec !== 'Shared') {
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

  const sections: MonitorSection[] = [
    { id: 'connections', label: 'Connections', icon: Users },
    { id: 'transactions', label: 'Transactions', icon: ArrowLeftRight },
    { id: 'tuples', label: 'Tuple operations', icon: Layers },
    { id: 'storage', label: 'Storage usage', icon: HardDrive },
    { id: 'cache', label: 'Cache hit ratio', icon: Gauge },
    { id: 'tables', label: 'Largest tables', icon: Table2 },
    { id: 'connection-states', label: 'Connection states', icon: Activity },
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
        value: sample.transactionsPerMin,
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
        label:
          table.tableName.length > 18
            ? `${table.schema}.${table.tableName.slice(0, 14)}…`
            : `${table.schema}.${table.tableName}`,
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
        detail: `${state.count} session${state.count === 1 ? '' : 's'}`,
      })),
    [connectionStates],
  )

  const metricsError =
    error ?? connectionStatesError ?? tableActivityError ?? null
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
          <div className="ml-auto flex shrink-0 items-center gap-3">
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
              sections={sections}
              activeSectionId={activeSectionId}
              onNavigate={scrollToChart}
            />

            <div className="min-w-0 flex-1 space-y-6">
              <div className="rounded-lg border border-border bg-muted/20 px-4 py-3">
                <p className="text-[12px] leading-relaxed text-muted-foreground">
                  Metrics are collected from live PostgreSQL statistics via the
                  SQL API. Time-series charts use samples gathered while this
                  view is open across {rangeLabel.toLowerCase()}.
                </p>
              </div>

              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
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
                      ? `/ ${formatDedicatedSpecStorage(database?.storage ?? 0)}`
                      : undefined
                  }
                  description="Total on-disk size of this database."
                  progress={storageUsagePercent}
                  progressTone={getUsageTone(storageUsagePercent)}
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
                  progressTone={getCacheHitTone(snapshot?.cacheHitRatio ?? null)}
                  progressCaption={
                    snapshot
                      ? `${snapshot.cacheHitRatio.toFixed(1)}% buffer cache hits`
                      : undefined
                  }
                />
                <PostgresMetricKpiCard
                  label="Transactions"
                  value={
                    snapshot
                      ? formatCompactCount(
                          snapshot.xactCommit + snapshot.xactRollback,
                        )
                      : isLoading
                        ? '—'
                        : '0'
                  }
                  subValue="since stats reset"
                  description="Committed and rolled back transactions since PostgreSQL statistics were last reset."
                />
              </div>

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

              <PostgresMetricChart
                id="transactions"
                title="Transaction rate"
                description="Committed and rolled back transactions per minute, derived from pg_stat_database counters."
                unit="/ min"
                data={transactionsSeries}
                formatY={(value) => Math.round(value).toLocaleString()}
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
                formatSecondaryY={(value) => Math.round(value).toLocaleString()}
              />

              <PostgresMetricChart
                id="storage"
                title="Storage usage"
                description="Database size over time from pg_database_size."
                unit=""
                data={storageSeries}
                formatY={(value) => formatCompactBytes(value)}
              />

              <PostgresMetricChart
                id="cache"
                title="Cache hit ratio"
                description="Buffer cache effectiveness from pg_stat_database block reads and hits."
                unit=""
                data={cacheSeries}
                formatY={(value) => `${value.toFixed(1)}%`}
              />

              <PostgresMetricBarChart
                id="tables"
                title="Largest tables"
                description="Top tables by on-disk size, including indexes and TOAST data."
                data={tableSizeBars}
                emptyMessage={
                  tableActivityLoading
                    ? 'Loading table activity...'
                    : 'No user tables found in this database.'
                }
              />

              <PostgresMetricBarChart
                id="connection-states"
                title="Connection states"
                description="Current session states from pg_stat_activity."
                data={connectionStateBars}
                formatValue={(value) => Math.round(value).toLocaleString()}
                emptyMessage={
                  connectionStatesLoading
                    ? 'Loading connection states...'
                    : 'No active client sessions.'
                }
              />

              {snapshot ? (
                <div className="rounded-lg border border-border bg-card px-4 py-4">
                  <h3 className="text-[14px] font-medium text-foreground">
                    Database health
                  </h3>
                  <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                      <p className="text-[11px] uppercase tracking-wider text-muted-foreground">
                        Deadlocks
                      </p>
                      <p className="mt-1 text-[15px] font-semibold tabular-nums text-foreground">
                        {formatCompactCount(snapshot.deadlocks)}
                      </p>
                    </div>
                    <div>
                      <p className="text-[11px] uppercase tracking-wider text-muted-foreground">
                        Conflicts
                      </p>
                      <p className="mt-1 text-[15px] font-semibold tabular-nums text-foreground">
                        {formatCompactCount(snapshot.conflicts)}
                      </p>
                    </div>
                    <div>
                      <p className="text-[11px] uppercase tracking-wider text-muted-foreground">
                        Temp storage
                      </p>
                      <p className="mt-1 text-[15px] font-semibold tabular-nums text-foreground">
                        {formatCompactBytes(snapshot.tempBytes)}
                      </p>
                    </div>
                    <div>
                      <p className="text-[11px] uppercase tracking-wider text-muted-foreground">
                        Last sample
                      </p>
                      <p className="mt-1 text-[15px] font-semibold text-foreground">
                        {format(new Date(snapshot.timestamp), 'MMM d, HH:mm')}
                      </p>
                    </div>
                  </div>
                </div>
              ) : null}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
