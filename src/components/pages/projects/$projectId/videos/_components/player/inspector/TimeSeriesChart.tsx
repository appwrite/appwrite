import {
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type PointerEvent,
} from 'react'
import { cn } from '@/lib/utils'

export type ChartSeries = {
  id: string
  label: string
  color: string
  values: Array<number | null>
  area?: boolean
  step?: boolean
  dashed?: boolean
}

export type ChartReference = { value: number; label: string }
export type ChartBand = { start: number; end: number }

const PAD_LEFT = 52
const PAD_RIGHT = 8
const PAD_TOP = 8
const PAD_BOTTOM = 20
const MIN_SPAN_MS = 10_000

function formatOffset(ms: number): string {
  const total = Math.max(0, Math.round(ms / 1000))
  const m = Math.floor(total / 60)
  const s = String(total % 60).padStart(2, '0')
  return `${m}:${s}`
}

/** Tracks an element's width with the ResizeObserver of the window that owns it (works inside pop-ups). */
export function useElementWidth<T extends HTMLElement>() {
  const ref = useRef<T | null>(null)
  const [width, setWidth] = useState(0)
  useEffect(() => {
    const el = ref.current
    if (!el) return
    const View = el.ownerDocument.defaultView ?? window
    const update = () => setWidth(el.getBoundingClientRect().width)
    update()
    const observer = new View.ResizeObserver(update)
    observer.observe(el)
    return () => observer.disconnect()
  }, [])
  return [ref, width] as const
}

export function TimeSeriesChart({
  times,
  series,
  height = 160,
  yFormat,
  yMax: yMaxProp,
  references = [],
  bands = [],
  className,
}: {
  times: number[]
  series: ChartSeries[]
  height?: number
  yFormat: (value: number) => string
  yMax?: number
  references?: ChartReference[]
  bands?: ChartBand[]
  className?: string
}) {
  const gradientPrefix = useId()
  const [ref, width] = useElementWidth<HTMLDivElement>()
  const [hoverIndex, setHoverIndex] = useState<number | null>(null)

  const plotW = Math.max(0, width - PAD_LEFT - PAD_RIGHT)
  const plotH = height - PAD_TOP - PAD_BOTTOM
  const t0 = times[0] ?? 0
  const t1 = Math.max(times[times.length - 1] ?? 0, t0 + MIN_SPAN_MS)

  const yMax = useMemo(() => {
    if (yMaxProp) return yMaxProp
    let max = 0
    for (const s of series) {
      for (const v of s.values) if (v != null && v > max) max = v
    }
    for (const r of references) if (r.value > max) max = r.value
    return max > 0 ? max * 1.15 : 1
  }, [series, references, yMaxProp])

  const x = (t: number) => PAD_LEFT + ((t - t0) / (t1 - t0)) * plotW
  const y = (v: number) => PAD_TOP + plotH - (Math.min(v, yMax) / yMax) * plotH

  const paths = useMemo(
    () =>
      series.map((s) => {
        let line = ''
        let area = ''
        let segmentStart: number | null = null
        let prevX = 0
        let prevY = 0
        const closeArea = () => {
          if (segmentStart != null) {
            area += ` L${prevX},${PAD_TOP + plotH} L${segmentStart},${PAD_TOP + plotH} Z`
          }
          segmentStart = null
        }
        s.values.forEach((v, i) => {
          if (v == null || times[i] == null) {
            closeArea()
            return
          }
          const px = x(times[i])
          const py = y(v)
          if (segmentStart == null) {
            line += ` M${px},${py}`
            area += ` M${px},${py}`
            segmentStart = px
          } else if (s.step) {
            line += ` L${px},${prevY} L${px},${py}`
            area += ` L${px},${prevY} L${px},${py}`
          } else {
            line += ` L${px},${py}`
            area += ` L${px},${py}`
          }
          prevX = px
          prevY = py
        })
        closeArea()
        return { line: line.trim(), area: area.trim() }
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [series, times, plotW, plotH, yMax, t0, t1],
  )

  const yTicks = [0, 0.5, 1].map((f) => (yMax / 1.15) * f)
  const xTicks = [0, 0.25, 0.5, 0.75, 1].map((f) => t0 + (t1 - t0) * f)

  const onPointerMove = (event: PointerEvent<SVGSVGElement>) => {
    if (times.length === 0 || plotW <= 0) return
    const rect = event.currentTarget.getBoundingClientRect()
    const t = t0 + ((event.clientX - rect.left - PAD_LEFT) / plotW) * (t1 - t0)
    let best = 0
    let bestDist = Infinity
    times.forEach((time, i) => {
      const d = Math.abs(time - t)
      if (d < bestDist) {
        bestDist = d
        best = i
      }
    })
    setHoverIndex(best)
  }

  const hoverX = hoverIndex != null ? x(times[hoverIndex]) : null

  return (
    <div ref={ref} dir="ltr" className={cn('relative w-full', className)}>
      {width > 0 ? (
        <svg
          width={width}
          height={height}
          className="block overflow-visible"
          onPointerMove={onPointerMove}
          onPointerLeave={() => setHoverIndex(null)}
        >
          <defs>
            {series.map((s) => (
              <linearGradient
                key={s.id}
                id={`${gradientPrefix}-${s.id}`}
                x1="0"
                x2="0"
                y1="0"
                y2="1"
              >
                <stop offset="0%" stopColor={s.color} stopOpacity={0.28} />
                <stop offset="100%" stopColor={s.color} stopOpacity={0} />
              </linearGradient>
            ))}
          </defs>

          {yTicks.map((v) => (
            <g key={v}>
              <line
                x1={PAD_LEFT}
                x2={PAD_LEFT + plotW}
                y1={y(v)}
                y2={y(v)}
                stroke="var(--border)"
                strokeOpacity={v === 0 ? 1 : 0.5}
              />
              <text
                x={PAD_LEFT - 8}
                y={y(v)}
                textAnchor="end"
                dominantBaseline="middle"
                className="fill-muted-foreground font-mono text-[10px]"
              >
                {yFormat(v)}
              </text>
            </g>
          ))}
          {xTicks.map((t, i) => (
            <text
              key={t}
              x={x(t)}
              y={height - 4}
              textAnchor={
                i === 0 ? 'start' : i === xTicks.length - 1 ? 'end' : 'middle'
              }
              className="fill-muted-foreground font-mono text-[10px]"
            >
              {formatOffset(t)}
            </text>
          ))}

          {bands.map((band) => (
            <rect
              key={`${band.start}-${band.end}`}
              x={x(Math.max(band.start, t0))}
              y={PAD_TOP}
              width={Math.max(
                2,
                x(Math.min(band.end, t1)) - x(Math.max(band.start, t0)),
              )}
              height={plotH}
              fill="rgb(239 68 68 / 0.14)"
            />
          ))}

          {references.map((r) => (
            <g key={`${r.label}-${r.value}`}>
              <line
                x1={PAD_LEFT}
                x2={PAD_LEFT + plotW}
                y1={y(r.value)}
                y2={y(r.value)}
                stroke="var(--muted-foreground)"
                strokeOpacity={0.45}
                strokeDasharray="3 4"
              />
              <text
                x={PAD_LEFT + plotW - 2}
                y={y(r.value) - 4}
                textAnchor="end"
                className="fill-muted-foreground font-mono text-[9px]"
              >
                {r.label}
              </text>
            </g>
          ))}

          {series.map((s, i) =>
            s.area && paths[i].area ? (
              <path
                key={`${s.id}-area`}
                d={paths[i].area}
                fill={`url(#${gradientPrefix}-${s.id})`}
              />
            ) : null,
          )}
          {series.map((s, i) =>
            paths[i].line ? (
              <path
                key={s.id}
                d={paths[i].line}
                fill="none"
                stroke={s.color}
                strokeWidth={1.75}
                strokeLinejoin="round"
                strokeLinecap="round"
                strokeDasharray={s.dashed ? '4 3' : undefined}
              />
            ) : null,
          )}

          {hoverX != null && hoverIndex != null ? (
            <g>
              <line
                x1={hoverX}
                x2={hoverX}
                y1={PAD_TOP}
                y2={PAD_TOP + plotH}
                stroke="var(--foreground)"
                strokeOpacity={0.35}
              />
              {series.map((s) => {
                const v = s.values[hoverIndex]
                return v == null ? null : (
                  <circle
                    key={s.id}
                    cx={hoverX}
                    cy={y(v)}
                    r={3}
                    fill={s.color}
                    stroke="var(--background)"
                    strokeWidth={1.5}
                  />
                )
              })}
            </g>
          ) : null}
        </svg>
      ) : (
        <div style={{ height }} />
      )}

      {hoverX != null && hoverIndex != null ? (
        <div
          className="pointer-events-none absolute top-1 z-20 min-w-[140px] rounded-md border border-border bg-popover px-2.5 py-2 text-[11px] text-popover-foreground shadow-md"
          style={
            hoverX > width / 2
              ? { right: width - hoverX + 10 }
              : { left: hoverX + 10 }
          }
        >
          <div className="mb-1 font-mono text-[10px] text-muted-foreground">
            {formatOffset(times[hoverIndex])}
          </div>
          {series.map((s) => {
            const v = s.values[hoverIndex]
            return (
              <div key={s.id} className="flex items-center gap-2">
                <span
                  className="size-2 shrink-0 rounded-[2px]"
                  style={{ background: s.color }}
                />
                <span className="flex-1 text-muted-foreground">{s.label}</span>
                <span className="font-mono tabular-nums">
                  {v == null ? '-' : yFormat(v)}
                </span>
              </div>
            )
          })}
        </div>
      ) : null}
    </div>
  )
}

export function ChartLegend({
  items,
}: {
  items: Array<{ label: string; color: string; dashed?: boolean }>
}) {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
      {items.map((item) => (
        <span key={item.label} className="inline-flex items-center gap-1.5">
          <span
            className="h-0.5 w-3 rounded-full"
            style={{
              background: item.dashed
                ? `repeating-linear-gradient(90deg, ${item.color} 0 3px, transparent 3px 5px)`
                : item.color,
            }}
          />
          {item.label}
        </span>
      ))}
    </div>
  )
}
