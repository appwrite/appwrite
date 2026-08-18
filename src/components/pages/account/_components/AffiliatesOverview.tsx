import { useMemo, useState } from 'react'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import { Gift, Info } from 'lucide-react'
import type { DateRange } from 'react-day-picker'
import type { Models } from '@appwrite.io/console'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { UsageChartIntervalToggle } from '@/components/pages/projects/$projectId/overview/UsageChartIntervalToggle'
import { OVERVIEW_CHART_HEIGHT } from '@/components/pages/projects/$projectId/overview/chart-panel'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip as UiTooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import {
  createCompactCountAxisTickFormatter,
  formatCompactCount,
} from '@/lib/usage/format-metric'
import { resolveUsageDateBounds } from '@/lib/usage/usage-date-range'
import {
  AFFILIATE_ATTRIBUTION_DAYS,
  AFFILIATE_REWARD_AMOUNT_USD,
  AFFILIATE_USAGE_INTERVALS,
  buildAffiliateFunnelChartPoints,
  getDefaultAffiliateUsageQueryParams,
  resolveAffiliateUsageInterval,
  sumPendingAffiliateRewardAmount,
  useAffiliateUsage,
  usePendingAffiliateRewards,
  type AffiliateUsageInterval,
} from '@/lib/react-query/hooks'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { ClaimAffiliateReward } from './ClaimAffiliateReward'

const CLICKS_COLOR = 'var(--chart-2)'
const SIGNUPS_COLOR = 'var(--chart-brand)'
const CONVERSIONS_COLOR = 'var(--chart-1)'
const ALL_LINKS_VALUE = 'all'

type AffiliatesOverviewProps = {
  initialUsage?: Models.UsageEventList
  initialPendingRewards?: Models.AffiliateRewardList
  links: Models.AffiliateLink[]
  organizations: Models.Organization[]
}

function formatRate(numerator: number, denominator: number): string | null {
  if (denominator <= 0) return null
  const rate = (numerator / denominator) * 100
  if (!Number.isFinite(rate)) return null
  return `${rate.toFixed(rate >= 10 ? 0 : 1)}%`
}

function sumMetric(
  usage: Models.UsageEventList | undefined,
  metric: string,
): number {
  return (
    usage?.metrics
      ?.find((entry) => entry.metric === metric)
      ?.points?.reduce((sum, point) => sum + (point.value || 0), 0) ?? 0
  )
}

function MetricTile({
  label,
  value,
  hint,
  info,
  isLoading,
}: {
  label: string
  value: number
  hint?: string | null
  /** Optional tooltip explaining the rate or metric. */
  info?: string
  isLoading: boolean
}) {
  const t = useT()

  return (
    <div className="min-w-0 px-5 py-4 sm:px-6">
      <div className="flex items-center gap-1.5">
        <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t(label)}
        </p>
        {info ? (
          <UiTooltip delayDuration={0}>
            <TooltipTrigger asChild>
              <button
                type="button"
                className="inline-flex shrink-0 text-muted-foreground transition-colors hover:text-foreground"
                aria-label={`${t('More about')} ${t(label)}`}
              >
                <Info className="h-3 w-3" aria-hidden />
              </button>
            </TooltipTrigger>
            <TooltipContent
              side="top"
              className="max-w-xs text-[12px] leading-relaxed"
            >
              <p>{t(info)}</p>
            </TooltipContent>
          </UiTooltip>
        ) : null}
      </div>
      {isLoading ? (
        <Skeleton className="mt-2 h-7 w-16 rounded-sm" />
      ) : (
        <p className="mt-1.5 text-[22px] font-semibold tabular-nums tracking-tight text-foreground">
          {formatCompactCount(value)}
        </p>
      )}
      {hint ? (
        <p className="mt-1 text-[12px] tabular-nums text-muted-foreground">
          {hint}
        </p>
      ) : (
        <p className="mt-1 text-[12px] text-transparent" aria-hidden>
          -
        </p>
      )}
    </div>
  )
}

function ChartLegendItem({
  label,
  color,
}: {
  label: string
  color: string
}) {
  const t = useT()
  return (
    <div className="flex items-center gap-1.5">
      <div
        className="h-2 w-2 rounded-full"
        style={{ backgroundColor: color }}
        aria-hidden
      />
      <span className="text-[11px] text-muted-foreground">{t(label)}</span>
    </div>
  )
}

export function AffiliatesOverview({
  initialUsage,
  initialPendingRewards,
  links,
  organizations,
}: AffiliatesOverviewProps) {
  const t = useT()
  const [dateRange, setDateRange] = useState<DateRange>(() =>
    getStableUsageChartDateRange(),
  )
  const [chartInterval, setChartInterval] =
    useState<AffiliateUsageInterval>('1h')
  const [selectedLinkId, setSelectedLinkId] = useState(ALL_LINKS_VALUE)
  const [rewardToClaim, setRewardToClaim] =
    useState<Models.AffiliateReward | null>(null)

  const resolvedInterval = resolveAffiliateUsageInterval(
    chartInterval,
    dateRange,
  )
  const { from, to } = useMemo(
    () => resolveUsageDateBounds(dateRange),
    [dateRange],
  )

  const linkIdFilter =
    selectedLinkId === ALL_LINKS_VALUE ? undefined : selectedLinkId

  const usageParams = useMemo(
    () => ({
      linkId: linkIdFilter,
      interval: resolvedInterval,
      startAt: from.toISOString(),
      endAt: to.toISOString(),
    }),
    [linkIdFilter, resolvedInterval, from, to],
  )

  const {
    usage: usageFromHook,
    clicks,
    signups,
    conversions,
    isLoading,
    isFetching,
    isError,
    refetch,
  } = useAffiliateUsage(usageParams)

  const pendingQuery = usePendingAffiliateRewards()
  const pendingRewards =
    pendingQuery.data?.rewards ?? initialPendingRewards?.rewards ?? []
  const pendingAmount = sumPendingAffiliateRewardAmount(pendingRewards)
  const pendingCount =
    pendingQuery.data?.total ??
    initialPendingRewards?.total ??
    pendingRewards.length
  const firstPendingReward = pendingRewards[0] ?? null
  const canClaim = organizations.length > 0 && !!firstPendingReward

  const defaultUsageParams = getDefaultAffiliateUsageQueryParams()
  const canUseInitialUsage =
    !linkIdFilter &&
    usageParams.interval === defaultUsageParams.interval &&
    usageParams.startAt === defaultUsageParams.startAt &&
    usageParams.endAt === defaultUsageParams.endAt
  const usage =
    usageFromHook ?? (canUseInitialUsage ? initialUsage : undefined)
  const showSkeleton = isLoading && !usage
  const displayClicks = usageFromHook
    ? clicks
    : sumMetric(usage, 'affiliates.clicks')
  const displaySignups = usageFromHook
    ? signups
    : sumMetric(usage, 'affiliates.signups')
  const displayConversions = usageFromHook
    ? conversions
    : sumMetric(usage, 'affiliates.conversions')

  const chartPoints = useMemo(
    () => buildAffiliateFunnelChartPoints(usage, from, to, resolvedInterval),
    [usage, from, to, resolvedInterval],
  )

  const chartData = useMemo(
    () =>
      chartPoints.map((point) => ({
        date: point.date,
        day: point.day,
        fullDate: formatLocalizedDate(
          point.day,
          resolvedInterval === '1h' ? 'MMM d, yyyy HH:mm' : 'MMM d, yyyy',
        ),
        clicks: point.clicks,
        signups: point.signups,
        conversions: point.conversions,
        total: point.clicks,
      })),
    [chartPoints, resolvedInterval],
  )

  const axisMax = useMemo(
    () =>
      chartPoints.reduce(
        (max, point) =>
          Math.max(max, point.clicks, point.signups, point.conversions),
        0,
      ),
    [chartPoints],
  )

  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(axisMax),
    [axisMax],
  )

  const signupRate = formatRate(displaySignups, displayClicks)
  const conversionRate = formatRate(displayConversions, displaySignups)
  const hasSeries = chartPoints.some(
    (point) => point.clicks > 0 || point.signups > 0 || point.conversions > 0,
  )

  const handleDateRangeChange = (next: DateRange | undefined) => {
    if (!next?.from) return
    setDateRange(next)
    setChartInterval((current) => resolveAffiliateUsageInterval(current, next))
  }

  const handleIntervalChange = (next: AffiliateUsageInterval) => {
    setChartInterval(resolveAffiliateUsageInterval(next, dateRange))
  }

  const claimDisabledTooltip =
    organizations.length === 0
      ? t('Create or join an organization you own to claim credits.')
      : undefined

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="flex flex-col gap-4 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0 flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Affiliates program')}
            </h3>
            <p className="mt-2 text-[13px] text-muted-foreground max-w-xl">
              {t(
                'Create shareable links and earn $15 in credits when a referred user upgrades to Pro. Attribution lasts 180 days.',
              )}
            </p>
          </div>
          <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
            {links.length > 0 ? (
              <Select
                value={selectedLinkId}
                onValueChange={setSelectedLinkId}
              >
                <SelectTrigger className="h-9 w-full sm:w-[180px]">
                  <SelectValue placeholder={t('All links')} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL_LINKS_VALUE}>
                    {t('All links')}
                  </SelectItem>
                  {links.map((link) => (
                    <SelectItem key={link.$id} value={link.$id}>
                      {link.name?.trim() || link.$id}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : null}
            <UsageChartIntervalToggle
              value={resolvedInterval}
              onValueChange={(value) =>
                handleIntervalChange(value as AffiliateUsageInterval)
              }
              dateRange={dateRange}
              allowedIntervals={AFFILIATE_USAGE_INTERVALS}
              className="h-9 w-full sm:w-fit"
            />
            <DateRangePicker
              dateRange={dateRange}
              onDateRangeChange={handleDateRangeChange}
              className="h-9 w-full min-w-0 sm:w-auto sm:min-w-[180px]"
              popoverContentAlign="end"
            />
          </div>
        </div>

        {pendingAmount > 0 ? (
          <>
            <div className="border-t border-border" />
            <div className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-muted/30">
              <div className="flex min-w-0 items-start gap-3">
                <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-background border border-border">
                  <Gift className="h-4 w-4 text-muted-foreground" aria-hidden />
                </div>
                <div className="min-w-0">
                  <p className="text-[15px] font-semibold tabular-nums text-foreground">
                    {formatCurrency(pendingAmount)}{' '}
                    <span className="font-medium text-muted-foreground">
                      {t('ready to claim')}
                    </span>
                  </p>
                  <p className="mt-1 text-[13px] text-muted-foreground">
                    {pendingCount === 1
                      ? t('1 pending reward')
                      : `${pendingCount} ${t('pending rewards')}`}
                  </p>
                </div>
              </div>
              {claimDisabledTooltip ? (
                <UiTooltip delayDuration={0}>
                  <TooltipTrigger asChild>
                    <span className="inline-flex w-full sm:w-auto">
                      <Button
                        type="button"
                        size="sm"
                        className="h-9 w-full text-[13px] sm:w-auto"
                        disabled
                      >
                        {t('Claim')}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent side="top" className="max-w-xs text-[12px]">
                    <p>{claimDisabledTooltip}</p>
                  </TooltipContent>
                </UiTooltip>
              ) : (
                <Button
                  type="button"
                  size="sm"
                  className="h-9 w-full text-[13px] sm:w-auto"
                  disabled={!canClaim}
                  onClick={() => setRewardToClaim(firstPendingReward)}
                  {...analyticsAttrs('claim-affiliate-reward')}
                >
                  {t('Claim')}
                </Button>
              )}
            </div>
          </>
        ) : null}

        <div className="border-t border-border" />

        <div
          className={cn(
            'grid grid-cols-1 divide-y divide-border sm:grid-cols-3 sm:divide-x sm:divide-y-0',
            isFetching && !showSkeleton && 'opacity-90',
          )}
        >
          <MetricTile
            label="Clicks"
            value={displayClicks}
            hint={t('Invite link visits')}
            isLoading={showSkeleton}
          />
          <MetricTile
            label="Signups"
            value={displaySignups}
            hint={
              signupRate
                ? `${t('Signup rate')}: ${signupRate}`
                : t('Attributed accounts')
            }
            info="Signup rate is the share of invite link clicks that resulted in a new account in the selected date range."
            isLoading={showSkeleton}
          />
          <MetricTile
            label="Conversions"
            value={displayConversions}
            hint={
              conversionRate
                ? `${t('Conversion rate')}: ${conversionRate}`
                : t('Pro upgrades')
            }
            info="Conversion rate is the share of attributed signups that upgraded to Pro in the selected date range."
            isLoading={showSkeleton}
          />
        </div>

        <div className="border-t border-border" />

        <div className="px-4 py-4 sm:px-6">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p className="text-[12px] font-medium text-foreground">
              {t('Funnel over time')}
            </p>
            <div className="flex flex-wrap items-center gap-3">
              <ChartLegendItem label="Clicks" color={CLICKS_COLOR} />
              <ChartLegendItem label="Signups" color={SIGNUPS_COLOR} />
              <ChartLegendItem label="Conversions" color={CONVERSIONS_COLOR} />
            </div>
          </div>

          <div
            className={cn(
              'relative w-full shrink-0 text-muted-foreground',
              FORCE_LTR_CLASS,
            )}
            style={{ height: OVERVIEW_CHART_HEIGHT }}
          >
            {showSkeleton ? (
              <Skeleton className="h-full w-full rounded-md" aria-hidden />
            ) : isError ? (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-center">
                <p className="text-[13px] text-muted-foreground">
                  {t('Could not load affiliate analytics')}
                </p>
                <button
                  type="button"
                  className="text-[13px] font-medium text-foreground underline-offset-4 hover:underline"
                  onClick={() => refetch()}
                >
                  {t('Retry')}
                </button>
              </div>
            ) : !hasSeries ? (
              <div className="absolute inset-0 flex items-center justify-center text-[13px] text-muted-foreground">
                {t('No affiliate activity in this date range')}
              </div>
            ) : (
              <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
                <AreaChart data={chartData} margin={USAGE_CHART_MARGIN}>
                  <defs>
                    <linearGradient
                      id="affiliate-clicks-fill"
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor={CLICKS_COLOR}
                        stopOpacity={0.16}
                      />
                      <stop
                        offset="100%"
                        stopColor={CLICKS_COLOR}
                        stopOpacity={0}
                      />
                    </linearGradient>
                    <linearGradient
                      id="affiliate-signups-fill"
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor={SIGNUPS_COLOR}
                        stopOpacity={0.18}
                      />
                      <stop
                        offset="100%"
                        stopColor={SIGNUPS_COLOR}
                        stopOpacity={0}
                      />
                    </linearGradient>
                    <linearGradient
                      id="affiliate-conversions-fill"
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor={CONVERSIONS_COLOR}
                        stopOpacity={0.2}
                      />
                      <stop
                        offset="100%"
                        stopColor={CONVERSIONS_COLOR}
                        stopOpacity={0}
                      />
                    </linearGradient>
                  </defs>
                  <CartesianGrid
                    strokeDasharray="3 3"
                    stroke="hsl(var(--border))"
                    vertical={false}
                  />
                  <UsageChartXAxis
                    points={chartPoints.map((point) => ({
                      date: point.date,
                      day: point.day,
                      total: point.clicks,
                    }))}
                    dateRange={dateRange}
                    chartInterval={resolvedInterval}
                  />
                  <UsageChartYAxis
                    tickFormatter={yAxisTickFormatter}
                    domain={
                      axisMax > 0 ? [0, Math.ceil(axisMax * 1.1)] : [0, 1]
                    }
                  />
                  <Tooltip
                    content={({ active, payload }) => {
                      if (!active || !payload?.length) return null
                      const data = payload[0]?.payload as {
                        fullDate: string
                        clicks: number
                        signups: number
                        conversions: number
                      }
                      if (!data) return null
                      return (
                        <div className="rounded-md border border-border bg-popover px-3 py-2 shadow-sm">
                          <p className="mb-1.5 text-[11px] text-muted-foreground">
                            {data.fullDate}
                          </p>
                          <div className="space-y-0.5">
                            <p className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                              <ChartSeriesDot color={CLICKS_COLOR} />
                              {formatCompactCount(data.clicks)}{' '}
                              <span className="font-normal text-muted-foreground">
                                {t('clicks')}
                              </span>
                            </p>
                            <p className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                              <ChartSeriesDot color={SIGNUPS_COLOR} />
                              {formatCompactCount(data.signups)}{' '}
                              <span className="font-normal text-muted-foreground">
                                {t('signups')}
                              </span>
                            </p>
                            <p className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                              <ChartSeriesDot color={CONVERSIONS_COLOR} />
                              {formatCompactCount(data.conversions)}{' '}
                              <span className="font-normal text-muted-foreground">
                                {t('conversions')}
                              </span>
                            </p>
                          </div>
                        </div>
                      )
                    }}
                  />
                  <Area
                    type="monotone"
                    dataKey="clicks"
                    name="Clicks"
                    stroke={CLICKS_COLOR}
                    strokeWidth={2}
                    fill="url(#affiliate-clicks-fill)"
                    {...CHART_ANIMATION_DISABLED}
                  />
                  <Area
                    type="monotone"
                    dataKey="signups"
                    name="Signups"
                    stroke={SIGNUPS_COLOR}
                    strokeWidth={2}
                    fill="url(#affiliate-signups-fill)"
                    {...CHART_ANIMATION_DISABLED}
                  />
                  <Area
                    type="monotone"
                    dataKey="conversions"
                    name="Conversions"
                    stroke={CONVERSIONS_COLOR}
                    strokeWidth={2}
                    fill="url(#affiliate-conversions-fill)"
                    {...CHART_ANIMATION_DISABLED}
                  />
                </AreaChart>
              </ResponsiveContainer>
            )}
          </div>
        </div>

        <div className="border-t border-border bg-muted/30 px-6 py-3">
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-muted-foreground">
            <span>
              {t('Reward')}: {formatCurrency(AFFILIATE_REWARD_AMOUNT_USD)}
            </span>
            <span aria-hidden className="hidden h-3 w-px bg-border sm:block" />
            <span>
              {t('Attribution window')}: {AFFILIATE_ATTRIBUTION_DAYS}{' '}
              {t('days')}
            </span>
            <span aria-hidden className="hidden h-3 w-px bg-border sm:block" />
            <span>
              {t('Qualifying plan')}: Pro
            </span>
          </div>
        </div>
      </div>

      <ClaimAffiliateReward
        reward={rewardToClaim}
        open={!!rewardToClaim}
        onOpenChange={(open) => {
          if (!open) setRewardToClaim(null)
        }}
        organizations={organizations}
      />
    </>
  )
}
