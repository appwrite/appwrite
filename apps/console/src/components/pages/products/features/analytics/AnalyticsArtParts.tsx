import { useId, type ReactNode } from 'react'
import { cn } from '@/lib/utils'

/**
 * Shared pieces for the Analytics product page art: a smooth area chart in
 * the product tone (same curve style as the console charts, no sharp
 * corners) and a ranked breakdown row.
 */

/** Catmull-Rom spline through the points, as cubic Béziers (smooth, no overshoot at ends). */
function smoothLine(points: [number, number][]): string {
  if (points.length < 2) return ''
  let d = `M${points[0][0].toFixed(1)} ${points[0][1].toFixed(1)}`
  for (let i = 0; i < points.length - 1; i++) {
    const p0 = points[i - 1] ?? points[i]
    const p1 = points[i]
    const p2 = points[i + 1]
    const p3 = points[i + 2] ?? p2
    const c1x = p1[0] + (p2[0] - p0[0]) / 6
    const c1y = p1[1] + (p2[1] - p0[1]) / 6
    const c2x = p2[0] - (p3[0] - p1[0]) / 6
    const c2y = p2[1] - (p3[1] - p1[1]) / 6
    d += ` C${c1x.toFixed(1)} ${c1y.toFixed(1)} ${c2x.toFixed(1)} ${c2y.toFixed(1)} ${p2[0].toFixed(1)} ${p2[1].toFixed(1)}`
  }
  return d
}

export function AnalyticsAreaChart({
  values,
  previous,
  className,
  height = 120,
}: {
  values: readonly number[]
  /** Optional comparison series, drawn dashed underneath. */
  previous?: readonly number[]
  className?: string
  height?: number
}) {
  const gradientId = useId().replace(/:/g, '')
  const width = 400
  const max = Math.max(...values, ...(previous ?? []), 1) * 1.12
  const toPoints = (series: readonly number[]): [number, number][] =>
    series.map((value, index) => [
      (index / (series.length - 1)) * width,
      height - (value / max) * (height - 6),
    ])
  const line = smoothLine(toPoints(values))
  const previousLine = previous ? smoothLine(toPoints(previous)) : null

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={cn('block w-full overflow-visible', className)}
      aria-hidden
    >
      <defs>
        <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
          <stop offset="0%" stopColor="rgb(var(--tone-rgb))" stopOpacity="0.28" />
          <stop offset="100%" stopColor="rgb(var(--tone-rgb))" stopOpacity="0" />
        </linearGradient>
      </defs>
      {[0.25, 0.5, 0.75].map((ratio) => (
        <line
          key={ratio}
          x1="0"
          x2={width}
          y1={height * ratio}
          y2={height * ratio}
          stroke="currentColor"
          strokeOpacity="0.08"
          strokeDasharray="3 4"
          vectorEffect="non-scaling-stroke"
        />
      ))}
      {previousLine ? (
        <path
          d={previousLine}
          fill="none"
          stroke="currentColor"
          strokeOpacity="0.3"
          strokeWidth="1.5"
          strokeDasharray="4 4"
          vectorEffect="non-scaling-stroke"
        />
      ) : null}
      <path d={`${line} L${width} ${height} L0 ${height} Z`} fill={`url(#${gradientId})`} />
      <path
        d={line}
        fill="none"
        stroke="var(--tone-ink)"
        strokeWidth="2"
        strokeLinecap="round"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  )
}

/** Ranked breakdown row with a proportional bar, like the console cards. */
export function AnalyticsArtRow({
  label,
  value,
  percent,
  leading,
  mono = false,
  highlight = false,
}: {
  label: string
  value: string
  percent: number
  leading?: ReactNode
  mono?: boolean
  highlight?: boolean
}) {
  return (
    <div className="relative flex h-7 items-center gap-2 overflow-hidden rounded-md px-2 text-[11.5px]">
      <span
        className={cn(
          'absolute inset-y-0 start-0 rounded-md',
          highlight ? 'bg-[rgb(var(--tone-rgb)/0.22)]' : 'bg-[rgb(var(--tone-rgb)/0.1)]',
        )}
        style={{ width: `${percent}%` }}
        aria-hidden
      />
      {leading ? <span className="relative shrink-0">{leading}</span> : null}
      <span
        dir="ltr"
        className={cn('relative min-w-0 flex-1 truncate text-foreground', mono && 'font-mono text-[11px]')}
      >
        {label}
      </span>
      <span dir="ltr" className="relative shrink-0 font-medium tabular-nums text-foreground">
        {value}
      </span>
    </div>
  )
}

/** Visitor trend used across the page art (30 days). */
export const ANALYTICS_ART_TREND = [
  34, 38, 36, 42, 47, 44, 51, 49, 55, 61, 58, 64, 70, 66, 72, 78, 75, 83, 88, 84, 91, 97, 94,
  102, 108, 104, 113, 119, 116, 124,
] as const

export const ANALYTICS_ART_PREVIOUS = [
  30, 31, 33, 32, 35, 38, 36, 39, 42, 40, 44, 46, 45, 48, 51, 49, 53, 55, 54, 58, 60, 59, 63,
  65, 64, 68, 70, 69, 72, 75,
] as const
