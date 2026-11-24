import { ANALYTICS_PRODUCT_ICON } from '@/lib/analytics/product-icon'
import {
  productBentoContainer,
  productBentoIdle,
} from '@/components/pages/home/product-bento/MockSyntax'
import { cn } from '@/lib/utils'

const STATS = [
  { label: 'Visitors', value: '12.4k', change: '+18%' },
  { label: 'Pageviews', value: '38.9k', change: '+11%' },
  { label: 'Bounce rate', value: '34%', change: '-4%' },
] as const

const SOURCES = [
  { name: 'Google', share: 41 },
  { name: 'ChatGPT', share: 18 },
] as const

/** A week of visitors: a smooth rise with a Tuesday launch bump. */
const TREND = [18, 22, 20, 34, 30, 38, 44]

function trendPath(values: number[], width: number, height: number) {
  const max = Math.max(...values)
  const points = values.map((value, index) => [
    (index / (values.length - 1)) * width,
    height - (value / max) * (height - 4),
  ])
  return points
    .map(
      ([x, y], index) =>
        `${index === 0 ? 'M' : 'L'}${x!.toFixed(1)} ${y!.toFixed(1)}`,
    )
    .join(' ')
}

/** Init 5 day 1: a site's traffic at a glance, with no cookies. */
export function AnalyticsDayVisual() {
  const Icon = ANALYTICS_PRODUCT_ICON
  const line = trendPath(TREND, 300, 56)

  return (
    <div className="absolute inset-0 flex flex-col overflow-hidden">
      <div
        className={cn(
          'mx-auto flex h-full min-h-0 w-full max-w-[22rem] flex-col',
          productBentoContainer.shell,
        )}
      >
        <div className={cn(productBentoContainer.header, 'px-3 py-2')}>
          <div className="flex items-center gap-2">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-background">
              <Icon
                className={cn('size-3.5', productBentoIdle.brandIcon)}
                aria-hidden
              />
            </span>
            <div className="min-w-0 flex-1">
              <p
                className={cn(
                  'truncate text-[11px] font-medium sm:text-[12px]',
                  productBentoIdle.text,
                )}
              >
                example.com
              </p>
              <p className="truncate text-[10px] text-muted-foreground">
                Last 7 days · no cookies
              </p>
            </div>
            <span className="flex items-center gap-1 text-[9px] font-medium text-muted-foreground sm:text-[10px]">
              <span
                className={cn(
                  'size-1.5 rounded-full',
                  productBentoIdle.brandDot,
                )}
              />
              Live
            </span>
          </div>
        </div>

        <div className="grid grid-cols-3 border-b border-border">
          {STATS.map((stat) => (
            <div
              key={stat.label}
              className="border-e border-border px-2.5 py-1.5 last:border-e-0"
            >
              <p className="truncate text-[9px] text-muted-foreground sm:text-[10px]">
                {stat.label}
              </p>
              <p className="flex items-baseline gap-1">
                <span
                  className={cn(
                    'text-[12px] font-semibold tabular-nums sm:text-[13px]',
                    productBentoIdle.text,
                  )}
                >
                  {stat.value}
                </span>
                <span className="text-[8px] tabular-nums text-muted-foreground sm:text-[9px]">
                  {stat.change}
                </span>
              </p>
            </div>
          ))}
        </div>

        <div className="relative h-14 shrink-0 px-2.5 pt-2">
          <svg
            viewBox="0 0 300 56"
            preserveAspectRatio="none"
            className="h-full w-full overflow-visible"
            aria-hidden
          >
            <defs>
              <linearGradient
                id="init-day-analytics-fill"
                x1="0"
                y1="0"
                x2="0"
                y2="1"
              >
                <stop
                  offset="0%"
                  stopColor="var(--brand-cta)"
                  stopOpacity="0.22"
                />
                <stop
                  offset="100%"
                  stopColor="var(--brand-cta)"
                  stopOpacity="0"
                />
              </linearGradient>
            </defs>
            <path
              d={`${line} L300 56 L0 56 Z`}
              fill="url(#init-day-analytics-fill)"
              className="opacity-40 transition-opacity duration-300 group-hover:opacity-100"
            />
            <path
              d={line}
              fill="none"
              strokeWidth={1.6}
              vectorEffect="non-scaling-stroke"
              className="stroke-muted-foreground transition-colors duration-300 group-hover:stroke-[var(--brand-cta)]"
            />
          </svg>
        </div>

        <div className="min-h-0 flex-1 space-y-1 px-2.5 pb-2 pt-1.5">
          {SOURCES.map((source, index) => (
            <div key={source.name} className="flex items-center gap-2">
              <span
                className={cn(
                  'w-12 shrink-0 truncate text-[9px] sm:text-[10px]',
                  productBentoIdle.text,
                )}
              >
                {source.name}
              </span>
              <span className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
                <span
                  className={cn(
                    'block h-full rounded-full',
                    productBentoIdle.scaleBar,
                  )}
                  style={{
                    width: `${source.share * 2}%`,
                    transitionDelay: `${index * 60}ms`,
                  }}
                />
              </span>
              <span className="w-7 shrink-0 text-end text-[9px] tabular-nums text-muted-foreground sm:text-[10px]">
                {source.share}%
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
