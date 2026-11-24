import { useId } from 'react'
import { ANALYTICS_PRODUCT_ICON } from '@/lib/analytics/product-icon'
import { CoverCard, InitDayCover } from './InitDayCover'

/** A week of visitors: a steady climb with a launch-day bump. */
const TREND = [18, 22, 20, 34, 30, 38, 46]

function trendPath(width: number, height: number) {
  const max = Math.max(...TREND)
  return TREND.map((value, index) => {
    const x = (index / (TREND.length - 1)) * width
    const y = height - (value / max) * (height - 8)
    return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`
  }).join(' ')
}

/** Init 5 day 1: a site's traffic at a glance, with no cookies. */
export function AnalyticsDayVisual() {
  const Icon = ANALYTICS_PRODUCT_ICON
  const fillId = `init-cover-analytics-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`
  const line = trendPath(300, 100)

  return (
    <InitDayCover headline="Analytics without cookies">
      <CoverCard className="overflow-hidden">
        <div className="flex items-center gap-[0.6em] border-b border-white/8 px-[1em] py-[0.8em]">
          <Icon className="size-[1.3em] text-[var(--brand-cta)]" aria-hidden />
          <span className="font-medium text-white">example.com</span>
          <span className="ms-auto flex items-center gap-[0.4em] text-[0.85em] text-white/60">
            <span className="size-[0.55em] rounded-full bg-[var(--brand-cta)]" />
            Live
          </span>
        </div>
        <div className="grid grid-cols-2 gap-[1em] px-[1em] pt-[0.9em]">
          {[
            ['Visitors', '12.4k'],
            ['Pageviews', '38.9k'],
          ].map(([label, value]) => (
            <div key={label}>
              <p className="text-[0.8em] text-white/50">{label}</p>
              <p className="text-[1.6em] font-semibold tabular-nums leading-tight text-white">
                {value}
              </p>
            </div>
          ))}
        </div>
        <svg
          viewBox="0 0 300 100"
          preserveAspectRatio="none"
          className="mt-[0.6em] block h-[11em] w-full"
          aria-hidden
        >
          <defs>
            <linearGradient id={fillId} x1="0" y1="0" x2="0" y2="1">
              <stop
                offset="0%"
                stopColor="var(--brand-cta)"
                stopOpacity="0.45"
              />
              <stop
                offset="100%"
                stopColor="var(--brand-cta)"
                stopOpacity="0"
              />
            </linearGradient>
          </defs>
          <path d={`${line} L300 100 L0 100 Z`} fill={`url(#${fillId})`} />
          <path
            d={line}
            fill="none"
            stroke="var(--brand-cta)"
            strokeWidth={2}
            vectorEffect="non-scaling-stroke"
          />
        </svg>
      </CoverCard>
    </InitDayCover>
  )
}
