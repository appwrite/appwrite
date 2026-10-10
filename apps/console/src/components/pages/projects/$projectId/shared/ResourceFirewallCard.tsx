import { useId, useMemo } from 'react'
import { Link } from '@tanstack/react-router'
import { WafRuleAction } from '@appwrite.io/console'
import { Area, AreaChart, ResponsiveContainer, Tooltip, YAxis } from 'recharts'
import { Button } from '@/components/ui/button'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  useFirewallRules,
  useOrganizationScopes,
  useProject,
  useProjectFirewallTrafficOverview,
} from '@/lib/react-query/hooks'
import { canSeeProjectNavItem } from '@/lib/console-access-checks'
import { isCloudProfile } from '@/lib/console-profiles'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  firewallListSearch,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'
import { getFirewallActionChartColor } from '@/lib/firewall/actions'
import { getUsageDateRangePresetByValue } from '@/lib/usage/usage-date-range-presets'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { ChangeBadge } from '../analytics/_components/ChangeBadge'
import { formatNumber } from '../analytics/_components/format'

export const OVERVIEW_FIREWALL_RULES_LIMIT = 1

const CHART_COLOR = getFirewallActionChartColor(WafRuleAction.Deny)
const LEFT_COLUMN_CLASS = 'flex min-w-0 flex-col gap-4 lg:h-[176px]'
const CHART_FRAME_CLASS =
  'flex h-[176px] min-w-0 flex-col rounded-lg border border-border/60 bg-background/40 px-3 pb-2.5 pt-3'
const DOT_GRID_STYLE = {
  backgroundImage: 'radial-gradient(var(--border) 1px, transparent 1px)',
  backgroundSize: '12px 12px',
  maskImage: 'linear-gradient(to bottom, black, transparent)',
  WebkitMaskImage: 'linear-gradient(to bottom, black, transparent)',
} as const

type TrendPoint = { label: string; value: number }

function TrendChart({
  data,
  valueLabel,
}: {
  data: TrendPoint[]
  valueLabel: string
}) {
  const gradientId = useId().replace(/:/g, '')
  const lastIndex = data.length - 1
  return (
    <ResponsiveContainer width="100%" height="100%">
      <AreaChart data={data} margin={{ top: 8, right: 6, bottom: 2, left: 6 }}>
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={CHART_COLOR} stopOpacity={0.28} />
            <stop offset="100%" stopColor={CHART_COLOR} stopOpacity={0} />
          </linearGradient>
        </defs>
        <YAxis hide domain={[0, (max: number) => Math.ceil(max * 1.15) || 1]} />
        <Tooltip
          isAnimationActive={false}
          cursor={{ stroke: 'var(--border)' }}
          content={({ active, payload }) => {
            const point = payload?.[0]?.payload as TrendPoint | undefined
            if (!active || !point) return null
            return (
              <div className="rounded-md border border-border bg-popover px-2.5 py-1.5 text-[11px]">
                <p className="text-muted-foreground">{point.label}</p>
                <p className="mt-0.5 font-medium tabular-nums text-foreground">
                  {formatNumber(point.value)} {valueLabel}
                </p>
              </div>
            )
          }}
        />
        <Area
          type="monotone"
          dataKey="value"
          stroke={CHART_COLOR}
          strokeWidth={2}
          fill={`url(#${gradientId})`}
          isAnimationActive={false}
          activeDot={{
            r: 4,
            fill: CHART_COLOR,
            stroke: 'var(--background)',
            strokeWidth: 2,
          }}
          dot={(props: { cx?: number; cy?: number; index?: number }) =>
            props.index === lastIndex &&
            props.cx != null &&
            props.cy != null ? (
              <circle
                key="last"
                cx={props.cx}
                cy={props.cy}
                r={3.5}
                fill={CHART_COLOR}
                stroke="var(--background)"
                strokeWidth={2}
              />
            ) : (
              <g key={props.index} />
            )
          }
        />
      </AreaChart>
    </ResponsiveContainer>
  )
}

function MiniStat({
  label,
  value,
}: {
  label: string
  value: string | null
}) {
  return (
    <div className="flex h-[52px] min-w-0 items-center rounded-lg border border-border/60 bg-background/40 px-3">
      <div className="min-w-0">
        <p className="truncate text-[11px] text-muted-foreground">{label}</p>
        {value === null ? (
          <span className="mt-1 block h-3 w-10 animate-pulse rounded bg-muted" />
        ) : (
          <p className="text-[13px] font-semibold tabular-nums text-foreground">
            {value}
          </p>
        )}
      </div>
    </div>
  )
}

export function ResourceFirewallCard({
  projectId,
  resourceType,
  resourceId,
}: {
  projectId: string
  resourceType: Extract<FirewallResourceType, 'sites' | 'functions'>
  resourceId: string
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const canSeeFirewall =
    isCloudProfile() && canSeeProjectNavItem(access, features, 'firewall')

  const dateRange = useMemo(
    () => getUsageDateRangePresetByValue('24h')?.getRange(),
    [],
  )

  const { total: rulesTotal, isLoading: rulesLoading } = useFirewallRules(
    canSeeFirewall ? projectId : null,
    0,
    OVERVIEW_FIREWALL_RULES_LIMIT,
    undefined,
    resourceType,
    resourceId,
  )
  const { data: overview, isLoading: trafficLoading } =
    useProjectFirewallTrafficOverview(
      canSeeFirewall ? projectId : null,
      dateRange,
      DEFAULT_USAGE_CHART_INTERVAL,
      undefined,
      resourceType,
      resourceId,
      '24h',
    )

  if (!canSeeFirewall) return null

  const firewallSearch = firewallListSearch({ resourceType, resourceId })
  const loading = rulesLoading || (trafficLoading && !overview)
  const denied = overview?.totalDenied ?? 0
  const challenged = overview?.totalChallenged ?? 0
  const rateLimited = overview?.totalRateLimited ?? 0
  const hasActivity = denied + challenged + rateLimited > 0
  const trend = (overview?.chartPoints ?? []).map((point) => ({
    label: point.date,
    value: point.denied,
  }))
  const rulesLabel =
    rulesTotal === 1 ? t('1 rule') : `${rulesTotal.toLocaleString()} ${t('rules')}`

  return (
    <div className="relative overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="relative flex h-16 items-center justify-between gap-3 px-6">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold leading-5 text-foreground">
            {t('Firewall')}
          </h3>
          <p className="h-4 truncate text-[12px] leading-4 text-muted-foreground">
            {loading ? (
              <span className="inline-block h-3 w-40 animate-pulse rounded bg-muted align-middle" />
            ) : (
              `${t('Last 24 hours')} · ${rulesLoading ? '—' : rulesLabel}`
            )}
          </p>
        </div>
      </div>
      <div className="relative border-t border-border" />

      <div className="relative grid gap-6 px-6 py-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">
        <div className={LEFT_COLUMN_CLASS}>
          <div className="h-[60px]">
            <p className="text-[12px] text-muted-foreground">{t('Denied')}</p>
            <div className="mt-1.5 flex h-[34px] items-baseline gap-2.5">
              {loading ? (
                <span className="h-8 w-24 animate-pulse self-center rounded-md bg-muted" />
              ) : (
                <>
                  <span
                    className={cn(
                      'text-[34px] font-semibold leading-none tracking-tight tabular-nums text-foreground',
                      USAGE_CHART_FADE_IN_CLASS_NAME,
                    )}
                  >
                    {formatNumber(denied)}
                  </span>
                  <ChangeBadge
                    change={overview?.deniedChange}
                    suffix={
                      <span className="ms-1 font-normal text-muted-foreground">
                        {t('vs previous 24 hours')}
                      </span>
                    }
                  />
                </>
              )}
            </div>
          </div>
          <div className="grid grid-cols-3 gap-2">
            <MiniStat
              label={t('Challenged')}
              value={loading ? null : formatNumber(challenged)}
            />
            <MiniStat
              label={t('Rate limited')}
              value={loading ? null : formatNumber(rateLimited)}
            />
            <MiniStat
              label={t('Rules')}
              value={rulesLoading ? null : rulesTotal.toLocaleString()}
            />
          </div>
        </div>

        <div className={CHART_FRAME_CLASS}>
          <div className={cn('relative min-h-0 flex-1', FORCE_LTR_CLASS)}>
            <div aria-hidden className="absolute inset-0" style={DOT_GRID_STYLE} />
            {loading ? (
              <div className="absolute inset-x-0 bottom-0 top-6 animate-pulse rounded-md bg-muted/40" />
            ) : hasActivity ? (
              <div className={cn('absolute inset-0', USAGE_CHART_FADE_IN_CLASS_NAME)}>
                <TrendChart data={trend} valueLabel={t('Denied')} />
              </div>
            ) : (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 text-center">
                <p className="text-[12px] font-medium text-foreground">
                  {rulesTotal > 0
                    ? t('No blocked traffic yet')
                    : resourceType === 'sites'
                      ? t('Protect this site')
                      : t('Protect this function')}
                </p>
                <p className="text-[11px] text-muted-foreground">
                  {rulesTotal > 0
                    ? t('Denied requests will show up here.')
                    : resourceType === 'sites'
                      ? t('Rules that apply to this site appear here.')
                      : t('Rules that apply to this function appear here.')}
                </p>
              </div>
            )}
          </div>
          <div className="mt-2 flex h-3 shrink-0 justify-between text-[10px] leading-3 text-muted-foreground">
            {loading || !hasActivity ? null : (
              <>
                <span>{t('24 hours ago')}</span>
                <span>{t('Now')}</span>
              </>
            )}
          </div>
        </div>
      </div>

      <div className="relative flex h-[68px] items-center justify-end border-t border-border bg-muted/30 px-6">
        <Button variant="secondary" size="sm" className="h-9 text-[13px]" asChild>
          <Link
            to="/projects/$projectId/firewall"
            params={{ projectId }}
            search={firewallSearch}
          >
            {t('Configure rules')}
          </Link>
        </Button>
      </div>
    </div>
  )
}
