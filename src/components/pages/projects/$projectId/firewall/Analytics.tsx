import { useState, useMemo, useEffect, useCallback } from 'react'
import { subHours } from 'date-fns'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import type { DateRange } from 'react-day-picker'
import {
  ShieldCheck,
  ShieldX,
  TrendingUp,
  TrendingDown,
  Activity,
  AlertTriangle,
  Globe,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { USAGE_CHART_Y_AXIS_WIDTH } from '../overview/chart-panel'
import { USAGE_CHART_MARGIN } from '@/lib/usage/chart-layout'
import { SeriesChartXAxis } from '@/components/global/shared/ChartXAxis'
import {
  Area,
  AreaChart,
  ResponsiveContainer,
  Tooltip,
  YAxis,
  Legend,
} from 'recharts'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  mockFirewallAnalytics,
  type FirewallAnalytics,
} from '@/lib/utils/mock-data'
import { DateRangePicker } from '../analytics/DateRangePicker'
import { useT } from '@/lib/i18n/translate'

interface AnalyticsTabProps {
  projectId: string
}

interface StatCardProps {
  label: string
  value: string | number
  change?: number
  icon: React.ReactNode
  trend?: 'up' | 'down'
  className?: string
}

function StatCard({
  label,
  value,
  change,
  icon,
  trend,
  className,
}: StatCardProps) {
  const t = useT()
  return (
    <Card className={cn('p-4', className)}>
      <div className="flex items-start justify-between">
        <div className="flex-1">
          <p className="text-[12px] text-muted-foreground mb-1">{label}</p>
          <p className="text-[20px] font-semibold text-foreground mb-1">
            {typeof value === 'number' ? value.toLocaleString() : value}
          </p>
          {change !== undefined && (
            <div className="flex items-center gap-1">
              {trend === 'up' ? (
                <TrendingUp className="h-3 w-3 text-emerald-500" />
              ) : trend === 'down' ? (
                <TrendingDown className="h-3 w-3 text-red-500" />
              ) : null}
              <span
                className={cn(
                  'text-[11px] font-medium',
                  trend === 'up' && 'text-emerald-500',
                  trend === 'down' && 'text-red-500',
                  !trend && 'text-muted-foreground',
                )}
              >
                {change > 0 ? '+' : ''}
                {change}%
              </span>
              <span className="text-[11px] text-muted-foreground">
                {t('vs last period')}
              </span>
            </div>
          )}
        </div>
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
          {icon}
        </div>
      </div>
    </Card>
  )
}

interface CustomTooltipProps {
  active?: boolean
  payload?: Array<{
    value: number
    dataKey: string
    color?: string
  }>
  label?: string
}

const CustomTooltip = ({ active, payload, label }: CustomTooltipProps) => {
  if (active && payload && payload.length) {
    return (
      <div className="rounded-lg border border-border bg-popover px-3 py-2.5">
        <p className="mb-2 text-[12px] font-medium text-foreground">{label}</p>
        <div className="space-y-1.5">
          {payload.map((entry, index) => (
            <div
              key={index}
              className="flex items-center justify-between gap-6"
            >
              <span className="text-[11px] text-muted-foreground capitalize">
                {entry.dataKey.replace(/([A-Z])/g, ' $1').trim()}
              </span>
              <span className="text-[12px] font-medium text-foreground">
                {entry.value.toLocaleString()}
              </span>
            </div>
          ))}
        </div>
      </div>
    )
  }
  return null
}

function getDefaultFirewallAnalyticsRange(): DateRange {
  const now = new Date()
  return { from: subHours(now, 24), to: now }
}

function buildFirewallChartData(
  from: Date,
  to: Date,
): Array<{
  time: string
  fullTime: string
  requests: number
  blocked: number
  allowed: number
  challenged: number
}> {
  const spanMs = Math.max(to.getTime() - from.getTime(), 60 * 60 * 1000)
  const useHourly = spanMs <= 48 * 60 * 60 * 1000
  const stepMs = useHourly ? 60 * 60 * 1000 : 24 * 60 * 60 * 1000
  let count = Math.ceil(spanMs / stepMs) + 1
  count = Math.min(Math.max(count, 2), 150)
  const step = spanMs / (count - 1)

  const rows: Array<{
    time: string
    fullTime: string
    requests: number
    blocked: number
    allowed: number
    challenged: number
  }> = []

  const phase = from.getTime() / 1e12
  for (let i = 0; i < count; i++) {
    const ts = new Date(from.getTime() + i * step)
    const u = (Math.sin(i * 2.9898 + phase) + 1) / 2
    const baseRequests = 100 + u * 200
    const blocked = Math.floor(baseRequests * (0.1 + u * 0.2))
    const challenged = Math.floor(baseRequests * (0.05 + u * 0.1))
    const allowed = Math.floor(baseRequests - blocked - challenged)

    rows.push({
      time: useHourly ? formatLocalizedDate(ts, 'HH:mm') : formatLocalizedDate(ts, 'MMM d'),
      fullTime: formatLocalizedDate(ts, 'MMM d, yyyy HH:mm'),
      requests: Math.floor(baseRequests),
      blocked,
      allowed,
      challenged,
    })
  }

  return rows
}

export function AnalyticsTab({}: AnalyticsTabProps) {
  const t = useT()
  const [analytics, setAnalytics] = useState<FirewallAnalytics | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [dateRange, setDateRange] = useState<DateRange | undefined>(() =>
    getDefaultFirewallAnalyticsRange(),
  )

  const reloadAnalytics = useCallback(() => {
    setIsLoading(true)
    setTimeout(() => {
      setAnalytics(mockFirewallAnalytics)
      setIsLoading(false)
    }, 500)
  }, [])

  useEffect(() => {
    reloadAnalytics()
  }, [dateRange, reloadAnalytics])

  // Listen for refresh event
  useEffect(() => {
    const handleRefresh = () => {
      reloadAnalytics()
    }

    window.addEventListener('firewall-refresh-analytics', handleRefresh)
    return () => {
      window.removeEventListener('firewall-refresh-analytics', handleRefresh)
    }
  }, [reloadAnalytics])

  const chartData = useMemo(() => {
    if (!analytics || !dateRange?.from) return []
    const to = dateRange.to ?? dateRange.from
    return buildFirewallChartData(dateRange.from, to)
  }, [analytics, dateRange])

  const chartAxisMax = useMemo(
    () =>
      chartData.reduce(
        (max, point) =>
          Math.max(
            max,
            point.blocked + point.challenged + point.allowed,
          ),
        0,
      ),
    [chartData],
  )
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  const topBlockedIPs = useMemo(() => {
    if (!analytics) return []
    return analytics.topBlockedIPs.slice(0, 5)
  }, [analytics])

  const topBlockedCountries = useMemo(() => {
    if (!analytics) return []
    return analytics.topBlockedCountries.slice(0, 5)
  }, [analytics])

  if (isLoading) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-6">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
        <Skeleton className="h-64 mb-6" />
        <div className="grid gap-4 md:grid-cols-2">
          <Skeleton className="h-64" />
          <Skeleton className="h-64" />
        </div>
      </div>
    )
  }

  if (!analytics) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
        <div className="rounded-xl border border-dashed border-border bg-card/50 p-8">
          <div className="flex flex-col items-center text-center">
            <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
              <Activity className="h-6 w-6 text-muted-foreground" />
            </div>
            <h3 className="mb-2 text-[15px] font-medium text-foreground">
              {t('No analytics data')}
            </h3>
            <p className="max-w-sm text-[13px] text-muted-foreground">
              {t(
                'Analytics data will appear here once your firewall rules start processing requests.',
              )}
            </p>
          </div>
        </div>
      </div>
    )
  }

  const blockRate =
    analytics.totalRequests > 0
      ? ((analytics.totalBlocked / analytics.totalRequests) * 100).toFixed(1)
      : '0.0'

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
      <div className="mb-6 flex items-center justify-end gap-2">
        <DateRangePicker
          dateRange={dateRange}
          onDateRangeChange={setDateRange}
          className="h-9"
        />
      </div>

      {/* Stats Grid */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-6">
        <StatCard
          label={t('Total requests')}
          value={analytics.totalRequests}
          change={analytics.requestsChange}
          trend={analytics.requestsChange > 0 ? 'up' : 'down'}
          icon={<Activity className="h-5 w-5" />}
        />
        <StatCard
          label={t('Blocked')}
          value={analytics.totalBlocked}
          change={analytics.blockedChange}
          trend={analytics.blockedChange > 0 ? 'up' : 'down'}
          icon={<ShieldX className="h-5 w-5" />}
        />
        <StatCard
          label={t('Allowed')}
          value={analytics.totalAllowed}
          change={analytics.allowedChange}
          trend={analytics.allowedChange > 0 ? 'up' : 'down'}
          icon={<ShieldCheck className="h-5 w-5" />}
        />
        <StatCard
          label={t('Block rate')}
          value={`${blockRate}%`}
          change={analytics.blockRateChange}
          trend={analytics.blockRateChange > 0 ? 'up' : 'down'}
          icon={<AlertTriangle className="h-5 w-5" />}
        />
      </div>

      {/* Main Chart */}
      <Card className="p-5 mb-6">
        <div className="mb-4">
          <h3 className="text-[14px] font-semibold text-foreground mb-1">
            {t('Request activity over time')}
          </h3>
          <p className="text-[12px] text-muted-foreground">
            {t('Real-time view of requests processed by firewall rules')}
          </p>
        </div>
        <ResponsiveContainer width="100%" height={300}>
          <AreaChart data={chartData} margin={USAGE_CHART_MARGIN}>
            <defs>
              <linearGradient id="colorBlocked" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#ef4444" stopOpacity={0.3} />
                <stop offset="95%" stopColor="#ef4444" stopOpacity={0} />
              </linearGradient>
              <linearGradient id="colorAllowed" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#10b981" stopOpacity={0.3} />
                <stop offset="95%" stopColor="#10b981" stopOpacity={0} />
              </linearGradient>
              <linearGradient id="colorChallenged" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#f59e0b" stopOpacity={0.3} />
                <stop offset="95%" stopColor="#f59e0b" stopOpacity={0} />
              </linearGradient>
            </defs>
            <SeriesChartXAxis
              pointCount={chartData.length}
              dataKey="time"
              tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
              dy={10}
            />
            <YAxis
              tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
              axisLine={false}
              tickLine={false}
              tickFormatter={yAxisTickFormatter}
              dx={-5}
              width={USAGE_CHART_Y_AXIS_WIDTH}
            />
            <Tooltip content={<CustomTooltip />} />
            <Legend
              wrapperStyle={{ fontSize: '12px', paddingTop: '20px' }}
              iconType="circle"
            />
            <Area
              type="monotone"
              dataKey="blocked"
              stackId="1"
              stroke="#ef4444"
              fill="url(#colorBlocked)"
              name={t('Blocked')}
            />
            <Area
              type="monotone"
              dataKey="challenged"
              stackId="1"
              stroke="#f59e0b"
              fill="url(#colorChallenged)"
              name={t('Challenged')}
            />
            <Area
              type="monotone"
              dataKey="allowed"
              stackId="1"
              stroke="#10b981"
              fill="url(#colorAllowed)"
              name={t('Allowed')}
            />
          </AreaChart>
        </ResponsiveContainer>
      </Card>

      {/* Bottom Grid */}
      <div className="grid gap-4 md:grid-cols-2">
        {/* Top Blocked IPs */}
        <Card className="p-5">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h3 className="text-[14px] font-semibold text-foreground mb-1">
                {t('Top blocked IPs')}
              </h3>
              <p className="text-[12px] text-muted-foreground">
                {t('IP addresses with the most blocked requests')}
              </p>
            </div>
            <Globe className="h-5 w-5 text-muted-foreground" />
          </div>
          <div className="space-y-3">
            {topBlockedIPs.length === 0 ? (
              <p className="text-[12px] text-muted-foreground text-center py-4">
                {t('No blocked IPs in this period')}
              </p>
            ) : (
              topBlockedIPs.map((item, index) => (
                <div
                  key={item.ip}
                  className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-3"
                >
                  <div className="flex items-center gap-3">
                    <div className="flex h-6 w-6 items-center justify-center rounded bg-muted text-[10px] font-medium text-muted-foreground">
                      {index + 1}
                    </div>
                    <div>
                      <p className="text-[13px] font-medium text-foreground font-mono">
                        {item.ip}
                      </p>
                      {item.country && (
                        <p className="text-[11px] text-muted-foreground">
                          {item.country}
                        </p>
                      )}
                    </div>
                  </div>
                  <div className="text-end">
                    <p className="text-[13px] font-semibold text-red-500">
                      {item.count.toLocaleString()}
                    </p>
                    <p className="text-[11px] text-muted-foreground">
                      {t('blocked')}
                    </p>
                  </div>
                </div>
              ))
            )}
          </div>
        </Card>

        {/* Top Blocked Countries */}
        <Card className="p-5">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h3 className="text-[14px] font-semibold text-foreground mb-1">
                {t('Top blocked countries')}
              </h3>
              <p className="text-[12px] text-muted-foreground">
                {t('Countries with the most blocked requests')}
              </p>
            </div>
            <Globe className="h-5 w-5 text-muted-foreground" />
          </div>
          <div className="space-y-3">
            {topBlockedCountries.length === 0 ? (
              <p className="text-[12px] text-muted-foreground text-center py-4">
                {t('No blocked countries in this period')}
              </p>
            ) : (
              topBlockedCountries.map((item, index) => (
                <div
                  key={item.country}
                  className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-3"
                >
                  <div className="flex items-center gap-3">
                    <div className="flex h-6 w-6 items-center justify-center rounded bg-muted text-[10px] font-medium text-muted-foreground">
                      {index + 1}
                    </div>
                    <div>
                      <p className="text-[13px] font-medium text-foreground">
                        {item.country}
                      </p>
                      <p className="text-[11px] text-muted-foreground">
                        {item.code}
                      </p>
                    </div>
                  </div>
                  <div className="text-end">
                    <p className="text-[13px] font-semibold text-red-500">
                      {item.count.toLocaleString()}
                    </p>
                    <p className="text-[11px] text-muted-foreground">
                      {t('blocked')}
                    </p>
                  </div>
                </div>
              ))
            )}
          </div>
        </Card>
      </div>
    </div>
  )
}
