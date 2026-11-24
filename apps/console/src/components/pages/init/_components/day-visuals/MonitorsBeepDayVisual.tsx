import { BellRing, ShieldAlert, Sparkles, type LucideIcon } from 'lucide-react'
import {
  productBentoContainer,
  productBentoIdle,
} from '@/components/pages/home/product-bento/MockSyntax'
import { cn } from '@/lib/utils'

/** Bandwidth over a day, crossing the monitor's threshold near the end. */
const USAGE = [22, 26, 24, 31, 36, 34, 45, 52, 61, 70, 82]
const THRESHOLD = 80

/** Project updates arriving in Beep: one monitor alert among them. */
const PUSHES: {
  icon: LucideIcon
  title: string
  detail: string
  alert?: boolean
}[] = [
  {
    icon: BellRing,
    title: 'Monitor triggered',
    detail: 'Bandwidth at 82%',
    alert: true,
  },
  {
    icon: ShieldAlert,
    title: 'SSL certificate failed',
    detail: 'api.example.com',
  },
  { icon: Sparkles, title: 'New in Appwrite', detail: 'Init 5, day 4' },
]

function usagePath(width: number, height: number) {
  return USAGE.map((value, index) => {
    const x = (index / (USAGE.length - 1)) * width
    const y = height - (value / 100) * height
    return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`
  }).join(' ')
}

/** Init 5 day 4: a monitor fires and Beep puts it on your phone. */
export function MonitorsBeepDayVisual() {
  const thresholdY = 60 - (THRESHOLD / 100) * 60

  return (
    <div className="absolute inset-0 flex items-stretch justify-center gap-2.5 overflow-hidden">
      <div
        className={cn(
          'flex min-w-0 flex-1 flex-col',
          productBentoContainer.shell,
        )}
      >
        <div className={cn(productBentoContainer.header, 'px-2.5 py-2')}>
          <p
            className={cn(
              'truncate text-[11px] font-medium sm:text-[12px]',
              productBentoIdle.text,
            )}
          >
            Bandwidth monitor
          </p>
          <p className="truncate text-[9px] text-muted-foreground sm:text-[10px]">
            Alert at {THRESHOLD}% of the plan
          </p>
        </div>
        <div className="relative min-h-0 flex-1 p-2.5">
          <svg
            viewBox="0 0 160 60"
            preserveAspectRatio="none"
            className="h-full w-full overflow-visible"
            aria-hidden
          >
            <line
              x1="0"
              x2="160"
              y1={thresholdY}
              y2={thresholdY}
              strokeDasharray="3 3"
              vectorEffect="non-scaling-stroke"
              className="stroke-muted-foreground/50 transition-colors duration-300 group-hover:stroke-[var(--brand-cta)]"
            />
            <path
              d={usagePath(160, 60)}
              fill="none"
              strokeWidth={1.6}
              vectorEffect="non-scaling-stroke"
              className="stroke-muted-foreground transition-colors duration-300 group-hover:stroke-foreground"
            />
            <circle
              cx="160"
              cy={60 - (USAGE[USAGE.length - 1]! / 100) * 60}
              r="3"
              className="fill-muted-foreground transition-colors duration-300 group-hover:fill-[var(--brand-cta)]"
            />
          </svg>
        </div>
        <div className="flex items-center justify-between border-t border-border bg-muted/8 px-2.5 py-1.5 text-[9px] text-muted-foreground sm:text-[10px]">
          <span>Now</span>
          <span className="font-medium tabular-nums transition-colors duration-300 group-hover:text-[var(--brand-cta)]">
            82%
          </span>
        </div>
      </div>

      {/* Beep: the phone, drawn as one crisp outline. */}
      <div className="relative flex w-[44%] max-w-[9.5rem] shrink-0 flex-col rounded-[18px] border border-border bg-card/80 p-1.5 shadow-sm">
        <span
          className="mx-auto mb-1.5 mt-0.5 h-1 w-8 rounded-full bg-muted"
          aria-hidden
        />
        <p className="px-1 text-[9px] font-semibold text-muted-foreground sm:text-[10px]">
          Beep
        </p>
        <ul className="mt-1 space-y-1">
          {PUSHES.map((push, index) => {
            const PushIcon = push.icon
            return (
              <li
                key={push.title}
                className={cn(
                  'rounded-lg border border-border/70 bg-background/80 px-1.5 py-1 transition-[border-color,background-color] duration-300',
                  push.alert &&
                    'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))] group-hover:bg-[color-mix(in_srgb,var(--brand-cta)_8%,var(--background))]',
                )}
                style={{ transitionDelay: `${index * 60}ms` }}
              >
                <p className="flex items-center gap-1 text-[9px] font-medium sm:text-[10px]">
                  <PushIcon
                    className={cn(
                      'size-3 shrink-0',
                      push.alert
                        ? productBentoIdle.brandIcon
                        : 'text-muted-foreground',
                    )}
                    aria-hidden
                  />
                  <span className={cn('truncate', productBentoIdle.text)}>
                    {push.title}
                  </span>
                </p>
                <p className="truncate ps-4 text-[8px] text-muted-foreground sm:text-[9px]">
                  {push.detail}
                </p>
              </li>
            )
          })}
        </ul>
      </div>
    </div>
  )
}
