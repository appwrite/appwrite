import { useId, type ReactNode } from 'react'
import { Area, AreaChart, ResponsiveContainer } from 'recharts'
import { cn } from '@/lib/utils'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import { ChangeBadge } from '../analytics/_components/ChangeBadge'

const XL_COLS: Record<number, string> = {
  1: 'xl:grid-cols-1',
  2: 'xl:grid-cols-2',
  3: 'xl:grid-cols-3',
  4: 'xl:grid-cols-4',
  5: 'xl:grid-cols-5',
  6: 'xl:grid-cols-6',
  7: 'xl:grid-cols-7',
}

const CHART_COLOR = 'var(--chart-brand)'

export type OverviewStatTile = {
  label: string
  value: ReactNode
  hint?: string
  change?: number
  points?: number[]
  loading?: boolean
}

function MiniChart({ points }: { points: number[] }) {
  const gradientId = useId().replace(/:/g, '')
  const data = points.map((value, index) => ({ index, value }))
  return (
    <div className={cn('h-8 min-w-[56px] flex-1', FORCE_LTR_CLASS)}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={data}
          margin={{ top: 2, right: 0, left: 0, bottom: 0 }}
        >
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={CHART_COLOR} stopOpacity={0.28} />
              <stop offset="100%" stopColor={CHART_COLOR} stopOpacity={0} />
            </linearGradient>
          </defs>
          <Area
            type="monotone"
            dataKey="value"
            stroke={CHART_COLOR}
            strokeWidth={1.5}
            fill={`url(#${gradientId})`}
            isAnimationActive={false}
            dot={false}
            activeDot={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}

export function OverviewStatTiles({
  items,
  className,
}: {
  items: OverviewStatTile[]
  className?: string
}) {
  const count = items.length

  return (
    <div
      className={cn(
        'grid grid-cols-2 overflow-hidden rounded-xl border border-border bg-card/50 sm:grid-cols-3',
        XL_COLS[count] ?? 'xl:grid-cols-6',
        className,
      )}
    >
      {items.map((item, index) => {
        const isLast = index === count - 1
        const lastRowStartMobile = count - (count % 2 || 2)
        const lastRowStartSm = count - (count % 3 || 3)
        const showBottomBorderMobile = index < lastRowStartMobile
        const showBottomBorderSm = index < lastRowStartSm
        const showEndBorderMobile = index % 2 === 0 && !isLast
        const showEndBorderSm = index % 3 !== 2 && !isLast
        const hasChart = Boolean(item.points && item.points.length > 1)

        return (
          <div
            key={item.label}
            className={cn(
              'px-4 py-3 sm:px-6',
              showBottomBorderMobile && 'border-b border-border',
              !showBottomBorderSm && 'sm:border-b-0',
              'xl:border-b-0',
              showEndBorderMobile && 'border-e border-border sm:border-e-0',
              showEndBorderSm && 'sm:border-e sm:border-border xl:border-e-0',
              !isLast && 'xl:border-e xl:border-border',
            )}
          >
            <div className="flex min-w-0 items-baseline justify-between gap-x-2">
              <p className="min-w-0 truncate text-[12px] text-muted-foreground">
                {item.label}
              </p>
              {item.hint ? (
                <p className="shrink-0 text-[11px] text-muted-foreground/80">
                  {item.hint}
                </p>
              ) : null}
            </div>
            <div className="mt-0.5 flex min-w-0 items-end justify-between gap-2">
              <div className="flex min-w-0 items-baseline gap-x-2">
                {item.loading ? (
                  <span className="h-6 w-14 animate-pulse rounded bg-muted" />
                ) : (
                  <>
                    <div
                      className={cn(
                        'min-w-0 truncate text-[20px] font-semibold tabular-nums text-foreground',
                        USAGE_CHART_FADE_IN_CLASS_NAME,
                      )}
                    >
                      {item.value}
                    </div>
                    <ChangeBadge change={item.change} />
                  </>
                )}
              </div>
              {hasChart && !item.loading ? (
                <MiniChart points={item.points!} />
              ) : null}
            </div>
          </div>
        )
      })}
    </div>
  )
}
