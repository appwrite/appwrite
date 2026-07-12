import { useState, useMemo } from 'react'
import { StatsCard } from '../overview/StatsCard'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { DateRange } from 'react-day-picker'
import {
  RefreshControls,
  type RefreshInterval,
} from '@/components/global/shared/RefreshControls'
import { RealtimeConcurrencyChart } from './charts/ConcurrencyChart'
import { RealtimeMessagesChart } from './charts/MessagesChart'
import { RealtimeChannelsChart } from './charts/ChannelsChart'
import { useRealtimeStats } from '@/lib/react-query/hooks/realtime'
import { useT } from '@/lib/i18n/translate'

interface RealtimeOverviewProps {
  projectId: string | null | undefined
}

// Shared state hook for Overview components
function useRealtimeOverviewState(projectId: string | null | undefined) {
  const [dateRange, setDateRange] = useState<DateRange | undefined>(() => {
    // Default to last 7 days
    const to = new Date()
    const from = new Date()
    from.setDate(from.getDate() - 6)
    return { from, to }
  })
  const [refreshInterval, setRefreshInterval] = useState<RefreshInterval>('30s')

  const {
    data: stats,
    isLoading,
    refetch,
  } = useRealtimeStats(projectId, dateRange)

  return {
    dateRange,
    setDateRange,
    refreshInterval,
    setRefreshInterval,
    stats,
    isLoading,
    refetch,
  }
}

export function RealtimeOverviewKPIs({ projectId }: RealtimeOverviewProps) {
  const t = useT()
  const { stats, isLoading } = useRealtimeOverviewState(projectId)

  const kpis = useMemo(() => {
    if (!stats) return null

    return [
      {
        title: 'Connections',
        value: stats.currentConnections.toLocaleString(),
        change: stats.connectionsChange,
        description: 'Concurrent connections',
      },
      {
        title: 'Messages',
        value: `${stats.messagesPerMinute.toLocaleString()}/min`,
        change: stats.messagesChange,
        description: 'Messages per minute',
      },
      {
        title: 'Channels',
        value: stats.activeChannels.toLocaleString(),
        change: stats.channelsChange,
        description: 'Currently listened',
      },
    ]
  }, [stats])

  return (
    <div className="py-6">
      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-3">
          {[1, 2, 3].map((i) => (
            <div
              key={i}
              className="h-24 animate-pulse rounded-lg border border-border bg-card"
            />
          ))}
        </div>
      ) : kpis ? (
        <div className="grid gap-4 sm:grid-cols-3">
          {kpis.map((kpi) => (
            <StatsCard
              key={kpi.title}
              title={t(kpi.title)}
              value={kpi.value}
              change={kpi.change}
            />
          ))}
        </div>
      ) : null}
    </div>
  )
}

export function RealtimeOverviewControlsAndCharts({
  projectId,
}: RealtimeOverviewProps) {
  const t = useT()
  const {
    dateRange,
    setDateRange,
    refreshInterval,
    setRefreshInterval,
    stats,
    isLoading,
    refetch,
  } = useRealtimeOverviewState(projectId)

  return (
    <div className="flex flex-col gap-6 py-6">
      {/* Controls Bar */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-end">
        <div className="flex items-center gap-2">
          <RefreshControls
            onRefresh={refetch}
            refreshInterval={refreshInterval}
            onRefreshIntervalChange={setRefreshInterval}
            projectId={projectId}
          />
          <DateRangePicker
            dateRange={dateRange}
            onDateRangeChange={setDateRange}
            className="h-9"
          />
        </div>
      </div>

      {/* Charts */}
      {isLoading ? (
        <div className="space-y-6">
          {[1, 2, 3].map((i) => (
            <div
              key={i}
              className="h-64 animate-pulse rounded-lg border border-border bg-card"
            />
          ))}
        </div>
      ) : (
        <div className="space-y-6">
          {/* Concurrency Chart */}
          <RealtimeConcurrencyChart
            data={stats?.concurrencyData || []}
            dateRange={dateRange}
            projectId={projectId}
            description={t('Real-time connection count over time. Shows the number of active WebSocket connections at any given moment.')}
          />

          {/* Messages Throughput Chart */}
          <RealtimeMessagesChart
            data={stats?.messagesData || []}
            dateRange={dateRange}
            projectId={projectId}
            description={t('Messages per minute over time. Tracks the rate at which realtime messages are being sent through the system.') /* pragma: allowlist secret */}
          />

          {/* Channels Chart */}
          <RealtimeChannelsChart
            data={stats?.channelsData || []}
            dateRange={dateRange}
            projectId={projectId}
            description={t('Number of active channels over time. Displays how many different realtime channels are currently being listened to.')}
          />
        </div>
      )}
    </div>
  )
}

// Keep the original export for backward compatibility
export function RealtimeOverview({ projectId }: RealtimeOverviewProps) {
  return (
    <>
      <RealtimeOverviewKPIs projectId={projectId} />
      <RealtimeOverviewControlsAndCharts projectId={projectId} />
    </>
  )
}
