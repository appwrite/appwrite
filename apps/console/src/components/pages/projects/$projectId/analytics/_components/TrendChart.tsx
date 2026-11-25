import { useId } from 'react'
import { Area, AreaChart, ResponsiveContainer, Tooltip, YAxis } from 'recharts'
import { formatNumber } from './format'

/** Same series colour as the analytics overview chart. */
const CHART_COLOR = 'var(--chart-brand)'

export type TrendPoint = { label: string; value: number }

/**
 * Smooth area chart in the style of the analytics and usage charts
 * (monotone curve, brand gradient, hover tooltip), without axes. The latest
 * point carries a dot.
 */
export function TrendChart({
  data,
  valueLabel,
  interactive = true,
}: {
  data: TrendPoint[]
  valueLabel: string
  interactive?: boolean
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
        {interactive ? (
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
        ) : null}
        <Area
          type="monotone"
          dataKey="value"
          stroke={CHART_COLOR}
          strokeWidth={2}
          fill={`url(#${gradientId})`}
          isAnimationActive={false}
          activeDot={
            interactive
              ? {
                  r: 4,
                  fill: CHART_COLOR,
                  stroke: 'var(--background)',
                  strokeWidth: 2,
                }
              : false
          }
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
