import { useId } from 'react'
import { ArrowUpRight } from 'lucide-react'
import { Area, AreaChart, ResponsiveContainer, Tooltip, YAxis } from 'recharts'
import { SheetClose } from '@/components/ui/sheet'
import {
  HoverCard,
  HoverCardContent,
  HoverCardTrigger,
} from '@/components/ui/hover-card'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  GITHUB_STARS_HISTORY,
  MARKETING_SOCIAL_STATS,
} from '@/lib/marketing/social-stats'
import { starHistoryGrowthSince } from '@/lib/marketing/github-stars-history'
import { ANALYTICS_ACTIONS, analyticsAttrs } from '@/lib/analytics-actions'
import { useAnalytics } from '@/hooks/use-analytics'
import { formatCompactCount } from '@/lib/usage/format-metric'
import { formatDateMonthYear } from '@/lib/date-utils'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'

const CHART_COLOR = 'var(--chart-brand)'
const GITHUB_REPO_LABEL = 'appwrite/appwrite'

/** Circular GitHub mark, filled with `currentColor`. */
export function GitHubSolidIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

type MarketingGitHubStarsLinkProps = {
  className?: string
  mobile?: boolean
}

type StarsChartPoint = {
  date: string
  stars: number
}

function yearLabel(date: string): string {
  return date.slice(0, 4)
}

function GitHubStarsGrowthChart({ data }: { data: StarsChartPoint[] }) {
  const t = useT()
  const gradientId = useId().replace(/:/g, '')
  const lastIndex = data.length - 1
  const first = data[0]
  const last = data[lastIndex]
  const chartLabel = t('GitHub stars over time')

  return (
    <div className="overflow-hidden rounded-lg border border-border/80 bg-muted/30">
      <div
        className={cn('h-[7.25rem] w-full', FORCE_LTR_CLASS)}
        role="img"
        aria-label={chartLabel}
      >
        <ResponsiveContainer width="100%" height="100%" minWidth={0}>
          <AreaChart
            data={data}
            margin={{ top: 12, right: 10, bottom: 4, left: 6 }}
          >
            <defs>
              <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={CHART_COLOR} stopOpacity={0.22} />
                <stop offset="55%" stopColor={CHART_COLOR} stopOpacity={0.06} />
                <stop offset="100%" stopColor={CHART_COLOR} stopOpacity={0} />
              </linearGradient>
            </defs>
            <YAxis
              hide
              domain={[0, (max: number) => Math.ceil(max * 1.08) || 1]}
            />
            <Tooltip
              isAnimationActive={false}
              cursor={{ stroke: 'var(--border)', strokeDasharray: '3 3' }}
              content={({ active, payload }) => {
                const point = payload?.[0]?.payload as
                  | StarsChartPoint
                  | undefined
                if (!active || !point) return null
                return (
                  <div className="rounded-md border border-border bg-popover px-2.5 py-1.5 shadow-sm">
                    <p className="text-[11px] text-muted-foreground">
                      {formatDateMonthYear(point.date)}
                    </p>
                    <p className="mt-0.5 text-[12px] font-medium tabular-nums text-foreground">
                      {formatCompactCount(point.stars)} {t('stars')}
                    </p>
                  </div>
                )
              }}
            />
            <Area
              type="monotone"
              dataKey="stars"
              stroke={CHART_COLOR}
              strokeWidth={1.75}
              fill={`url(#${gradientId})`}
              {...CHART_ANIMATION_DISABLED}
              activeDot={{
                r: 3.5,
                fill: CHART_COLOR,
                stroke: 'var(--popover)',
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
                    r={3}
                    fill={CHART_COLOR}
                    stroke="var(--popover)"
                    strokeWidth={2}
                  />
                ) : (
                  <g key={props.index} />
                )
              }
            />
          </AreaChart>
        </ResponsiveContainer>
      </div>
      {first && last ? (
        <div
          className={cn(
            'flex items-center justify-between border-t border-border/70 px-2.5 py-1.5 text-[10px] tabular-nums text-muted-foreground',
            FORCE_LTR_CLASS,
          )}
        >
          <span>{yearLabel(first.date)}</span>
          <span>{yearLabel(last.date)}</span>
        </div>
      ) : null}
    </div>
  )
}

export function MarketingGitHubStarsLink({
  className,
  mobile = false,
}: MarketingGitHubStarsLinkProps) {
  const t = useT()
  const { track } = useAnalytics()
  const { link, stat } = MARKETING_SOCIAL_STATS.github
  const chartData = GITHUB_STARS_HISTORY.filter(
    (point) => point.date && Number.isFinite(point.stars),
  )
  const yearlyGrowth = starHistoryGrowthSince(chartData, 365)

  const anchor = (
    <a
      href={link}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={
        `${t('Appwrite on GitHub')}, ${stat} ${t('stars')}` /* pragma: allowlist secret */
      }
      {...analyticsAttrs('marketing-nav-github')}
      className={cn(
        mobile
          ? 'flex h-10 w-full items-center justify-start gap-1.5 rounded-md px-3 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground'
          : 'inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground',
        className,
      )}
    >
      <GitHubSolidIcon className="h-4 w-4 shrink-0" />
      <span>{stat}</span>
    </a>
  )

  if (mobile) {
    return <SheetClose asChild>{anchor}</SheetClose>
  }

  return (
    <HoverCard
      openDelay={200}
      closeDelay={160}
      onOpenChange={(open) => {
        if (open) {
          track(ANALYTICS_ACTIONS['marketing-nav-github-hover'])
        }
      }}
    >
      <HoverCardTrigger asChild>{anchor}</HoverCardTrigger>
      <HoverCardContent
        align="center"
        side="bottom"
        sideOffset={10}
        className="w-[22rem] overflow-hidden rounded-xl p-0 shadow-lg"
      >
        <div className="flex flex-col text-start">
          <div className="flex flex-col gap-3 px-4 pt-4 pb-3">
            <div className="flex items-center gap-2">
              <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                <GitHubSolidIcon className="size-3.5 text-muted-foreground" />
              </span>
              <p className="truncate text-[12px] font-medium text-muted-foreground">
                {GITHUB_REPO_LABEL}
              </p>
            </div>

            <div className="flex items-end justify-between gap-3">
              <div className="min-w-0">
                <p
                  className={cn(
                    'font-aeonik-pro text-[28px] leading-none tabular-nums tracking-tight text-foreground',
                    FORCE_LTR_CLASS,
                  )}
                >
                  {stat}
                </p>
                <p className="mt-1.5 text-[12px] text-muted-foreground">
                  {t('stars')}
                </p>
              </div>
              {yearlyGrowth ? (
                <div className="text-end">
                  <p
                    className={cn(
                      'text-[13px] font-medium tabular-nums text-emerald-600 dark:text-emerald-400',
                      FORCE_LTR_CLASS,
                    )}
                  >
                    +{formatCompactCount(yearlyGrowth)}
                  </p>
                  <p className="mt-1.5 text-[12px] text-muted-foreground">
                    {t('in the last year')}
                  </p>
                </div>
              ) : null}
            </div>

            {chartData.length >= 2 ? (
              <GitHubStarsGrowthChart data={chartData} />
            ) : null}

            <p className="text-[12px] leading-5 text-muted-foreground">
              {t(
                'A GitHub star is the easiest way to support the work we do in the open.',
              )}
            </p>
          </div>

          <div className="border-t border-border bg-muted/30 px-4 py-3">
            <Button asChild variant="outline" size="sm" className="h-9 w-full">
              <a
                href={link}
                target="_blank"
                rel="noopener noreferrer"
                {...analyticsAttrs('marketing-nav-github-star')}
              >
                {t('Star on GitHub')}
                <ArrowUpRight />
              </a>
            </Button>
          </div>
        </div>
      </HoverCardContent>
    </HoverCard>
  )
}
