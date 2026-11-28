import { useId, type ReactNode } from 'react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/**
 * Shared pieces for the Monitors page art: a usage chart with a threshold
 * line, where the line turns red wherever usage is past it, and the status
 * pills the Console uses for monitors and alerts.
 */

/** Catmull-Rom spline through the points, as cubic Béziers (smooth, no overshoot at the ends). */
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

export function MonitorsUsageChart({
  values,
  threshold,
  max,
  height = 120,
  className,
  thresholdLabel,
  showHandle = false,
  markLast = true,
}: {
  values: readonly number[]
  threshold: number
  /** Top of the scale; defaults to a little above the highest value or threshold. */
  max?: number
  height?: number
  className?: string
  thresholdLabel?: ReactNode
  /** Draws the drag handle the Console's threshold chart has. */
  showHandle?: boolean
  /** Pulse on the last point when it is past the threshold. */
  markLast?: boolean
}) {
  const id = useId().replace(/:/g, '')
  const width = 400
  const top = max ?? Math.max(...values, threshold) * 1.15
  const y = (value: number) => height - (value / top) * (height - 8)
  const points: [number, number][] = values.map((value, index) => [
    (index / (values.length - 1)) * width,
    y(value),
  ])
  const line = smoothLine(points)
  const thresholdY = y(threshold)
  const last = points[points.length - 1]
  const lastOver = values[values.length - 1] > threshold

  return (
    <div className={cn('relative', className)}>
      <svg
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="none"
        className="block w-full overflow-visible"
        style={{ height }}
        aria-hidden
      >
        <defs>
          <linearGradient id={`${id}-fill`} x1="0" x2="0" y1="0" y2="1">
            <stop
              offset="0%"
              stopColor="rgb(var(--tone-rgb))"
              stopOpacity="0.22"
            />
            <stop
              offset="100%"
              stopColor="rgb(var(--tone-rgb))"
              stopOpacity="0"
            />
          </linearGradient>
          <clipPath id={`${id}-over`}>
            <rect x="0" y="-20" width={width} height={thresholdY + 20} />
          </clipPath>
          <clipPath id={`${id}-under`}>
            <rect
              x="0"
              y={thresholdY}
              width={width}
              height={height - thresholdY + 20}
            />
          </clipPath>
        </defs>
        {[0.25, 0.5, 0.75].map((ratio) => (
          <line
            key={ratio}
            x1="0"
            x2={width}
            y1={height * ratio}
            y2={height * ratio}
            stroke="currentColor"
            strokeOpacity="0.07"
            strokeDasharray="3 4"
            vectorEffect="non-scaling-stroke"
          />
        ))}
        <rect
          x="0"
          y="-20"
          width={width}
          height={thresholdY + 20}
          className="fill-rose-500/[0.06]"
        />
        <path
          d={`${line} L${width} ${height} L0 ${height} Z`}
          fill={`url(#${id}-fill)`}
          clipPath={`url(#${id}-under)`}
        />
        <path
          d={line}
          fill="none"
          stroke="var(--tone-ink)"
          strokeWidth="2"
          strokeLinecap="round"
          vectorEffect="non-scaling-stroke"
          clipPath={`url(#${id}-under)`}
        />
        <path
          d={line}
          fill="none"
          className="stroke-rose-500"
          strokeWidth="2"
          strokeLinecap="round"
          vectorEffect="non-scaling-stroke"
          clipPath={`url(#${id}-over)`}
        />
        <line
          x1="0"
          x2={width}
          y1={thresholdY}
          y2={thresholdY}
          className="stroke-rose-500"
          strokeWidth="1.25"
          strokeDasharray="5 4"
          vectorEffect="non-scaling-stroke"
        />
      </svg>

      {showHandle ? (
        <span
          className="absolute end-[-6px] flex h-4 w-7 -translate-y-1/2 items-center justify-center gap-[2px] rounded-full border border-rose-500/60 bg-background shadow-sm dark:bg-card"
          style={{ top: `${(thresholdY / height) * 100}%` }}
          aria-hidden
        >
          <span className="h-2 w-px bg-rose-500/70" />
          <span className="h-2 w-px bg-rose-500/70" />
          <span className="h-2 w-px bg-rose-500/70" />
        </span>
      ) : null}

      {thresholdLabel ? (
        <span
          className="absolute start-1 -translate-y-[calc(100%+3px)] rounded-[5px] bg-rose-500/10 px-1.5 py-px text-[10px] font-medium text-rose-600 dark:text-rose-400"
          style={{ top: `${(thresholdY / height) * 100}%` }}
        >
          {thresholdLabel}
        </span>
      ) : null}

      {markLast && lastOver && last ? (
        <span
          className="absolute flex size-2.5 -translate-x-1/2 -translate-y-1/2"
          style={{ left: '100%', top: `${(last[1] / height) * 100}%` }}
          aria-hidden
        >
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-rose-500/60 motion-reduce:animate-none" />
          <span className="relative inline-flex size-full rounded-full border-2 border-background bg-rose-500" />
        </span>
      ) : null}
    </div>
  )
}

export type MonitorsStatus =
  | 'firing'
  | 'ok'
  | 'pending'
  | 'paused'
  | 'resolved'
  | 'acknowledged'

const STATUS_STYLE: Record<
  MonitorsStatus,
  { dot: string; text: string; label: string }
> = {
  firing: {
    dot: 'bg-red-500',
    text: 'bg-red-500/10 text-red-600 dark:text-red-400',
    label: 'Firing',
  },
  ok: {
    dot: 'bg-emerald-500',
    text: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    label: 'OK',
  },
  pending: {
    dot: 'border-[1.5px] border-blue-600 dark:border-blue-400',
    text: 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
    label: 'Pending',
  },
  paused: {
    dot: 'bg-muted-foreground',
    text: 'bg-muted text-muted-foreground',
    label: 'Paused',
  },
  resolved: {
    dot: 'bg-emerald-500',
    text: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    label: 'Resolved',
  },
  acknowledged: {
    dot: 'bg-amber-500',
    text: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    label: 'Acknowledged',
  },
}

export function MonitorsStatusPill({
  status,
  className,
}: {
  status: MonitorsStatus
  className?: string
}) {
  const t = useT()
  const style = STATUS_STYLE[status]
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10.5px] font-medium',
        style.text,
        className,
      )}
    >
      <span className={cn('size-1.5 rounded-full', style.dot)} />
      {t(style.label)}
    </span>
  )
}

export type MonitorsSeverity = 'critical' | 'error' | 'warning' | 'info'

const SEVERITY_STYLE: Record<
  MonitorsSeverity,
  { pill: string; label: string }
> = {
  critical: {
    pill: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    label: 'Critical',
  },
  error: {
    pill: 'bg-orange-500/15 text-orange-600 dark:text-orange-400',
    label: 'Error',
  },
  warning: {
    pill: 'bg-amber-400/20 text-amber-700 dark:text-amber-300',
    label: 'Warning',
  },
  info: { pill: 'bg-sky-500/15 text-sky-700 dark:text-sky-300', label: 'Info' },
}

/** Severity as the Console and Beep show it. */
export function MonitorsSeverityPill({
  severity,
  className,
}: {
  severity: MonitorsSeverity
  className?: string
}) {
  const t = useT()
  const style = SEVERITY_STYLE[severity]
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-[5px] px-1.5 py-px text-[10px] font-semibold leading-[15px]',
        style.pill,
        className,
      )}
    >
      {t(style.label)}
    </span>
  )
}

/** A Console form control drawn as a box: its value, and a chevron when it is a select. */
export function MonitorsField({
  value,
  select = false,
  mono = false,
  className,
}: {
  value: ReactNode
  select?: boolean
  mono?: boolean
  className?: string
}) {
  return (
    <span
      className={cn(
        'inline-flex h-7 min-w-0 items-center justify-between gap-2 rounded-md border border-border bg-background px-2 text-[11px] text-foreground dark:bg-card',
        mono && 'font-mono text-[10.5px]',
        className,
      )}
    >
      <span className="min-w-0 truncate">{value}</span>
      {select ? (
        <svg
          viewBox="0 0 10 10"
          className="size-2.5 shrink-0 text-muted-foreground"
          aria-hidden
        >
          <path
            d="M2.5 4l2.5 2.5L7.5 4"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.3"
            strokeLinecap="round"
          />
        </svg>
      ) : null}
    </span>
  )
}

/** Small uppercase caption above a group of fields or rows. */
export function MonitorsCaption({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return (
    <p
      className={cn(
        'text-[10px] font-semibold uppercase tracking-wider text-muted-foreground',
        className,
      )}
    >
      {children}
    </p>
  )
}

/** Hourly outbound bandwidth (GB) for the page's example monitor, crossing 5 GB late in the day. */
export const MONITORS_EGRESS_DAY = [
  1.1, 1.0, 0.9, 0.9, 1.0, 1.2, 1.6, 2.1, 2.4, 2.6, 2.7, 2.5, 2.4, 2.6, 2.9,
  3.2, 3.6, 4.1, 4.6, 5.3, 5.9, 6.4, 6.9, 6.2,
] as const
