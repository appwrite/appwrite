import { useId } from 'react'
import { Area, AreaChart, ResponsiveContainer, Tooltip, YAxis } from 'recharts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { formatDateMonthYear } from '@/lib/date-utils'
import { formatCompactCount } from '@/lib/usage/format-metric'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import type { GitHubStarHistoryPoint } from '@/lib/marketing/github-stars-history'

const CHART_COLOR = 'var(--chart-brand)'

function yearLabel(date: string): string {
  return date.slice(0, 4)
}

export function GitHubStarsGrowthChart({
  data,
}: {
  data: GitHubStarHistoryPoint[]
}) {
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
                  | GitHubStarHistoryPoint
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
