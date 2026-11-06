import { Clock, Globe, Zap, type LucideIcon } from 'lucide-react'
import type { CSSProperties } from 'react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { ArtWindow, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const TRIGGERS: { id: string; tag: string; value: string; icon: LucideIcon }[] = [
  { id: 'http', tag: 'HTTP', value: 'POST /orders', icon: Globe },
  { id: 'cron', tag: 'Cron', value: '0 */6 * * *', icon: Clock },
  { id: 'event', tag: 'Event', value: 'users.*.create', icon: Zap },
]

const LOG_LINES = [
  { status: '200', source: 'POST /orders', duration: '34ms' },
  { status: '200', source: 'cron 0 */6 * * *', duration: '1.2s' },
  { status: '200', source: 'users.*.create', duration: '18ms' },
  { status: '201', source: 'POST /orders', duration: '41ms' },
  { status: '200', source: 'GET /orders/42', duration: '12ms' },
] as const

function Track({ delayMs }: { delayMs: number }) {
  return (
    <div className="relative h-0 w-full border-t border-dashed border-foreground/25" aria-hidden>
      <span
        className="product-hero-travel absolute top-1/2 size-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[var(--tone-ink)] opacity-0 shadow-[0_0_10px_rgb(var(--tone-rgb))]"
        style={{ '--travel-delay': `${delayMs}ms` } as CSSProperties}
      />
    </div>
  )
}

export function FunctionsHeroArt() {
  const t = useT()

  return (
    <div className="grid items-stretch gap-4 text-start md:grid-cols-[minmax(0,0.95fr)_40px_minmax(0,0.75fr)_40px_minmax(0,1.2fr)] md:grid-rows-3 md:gap-x-0 md:gap-y-3">
      {TRIGGERS.map((trigger, index) => {
        const Icon = trigger.icon
        return (
          <div
            key={trigger.id}
            className="product-hero-rise flex items-center gap-3 rounded-xl border border-border bg-background px-3 py-2.5 shadow-sm dark:bg-card md:col-start-1"
            style={riseStyle(100 + index * 120, { gridRowStart: index + 1 })}
          >
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40 text-muted-foreground">
              <Icon className="size-4" aria-hidden />
            </span>
            <div className="min-w-0">
              <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {trigger.id === 'event' ? t(trigger.tag) : trigger.tag}
              </p>
              <p dir="ltr" className="truncate font-mono text-[12px] text-foreground">{trigger.value}</p>
            </div>
          </div>
        )
      })}

      {TRIGGERS.map((trigger, index) => (
        <div
          key={`${trigger.id}-track`}
          className="hidden items-center md:col-start-2 md:flex"
          style={{ gridRowStart: index + 1 }}
        >
          <Track delayMs={index * 700} />
        </div>
      ))}

      <div
        className="product-hero-rise product-tone-shadow flex flex-col justify-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background p-4 dark:bg-card md:col-start-3 md:row-span-3 md:row-start-1"
        style={riseStyle(300)}
      >
        <span className="flex size-10 items-center justify-center rounded-xl border border-border bg-muted/40">
          <RuntimeIcon runtime="node-22" className="size-5" />
        </span>
        <p dir="ltr" className="mt-3 truncate text-[14px] font-semibold text-foreground">process-order</p>
        <p className="mt-0.5 text-[11px] text-muted-foreground">Node.js 22</p>
        <div className="mt-3 flex flex-wrap items-center gap-1.5">
          <span className="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:text-emerald-400">
            {t('Ready')}
          </span>
          <span dir="ltr" className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
            1 vCPU · 512 MB
          </span>
        </div>
      </div>

      <div className="hidden items-center md:col-start-4 md:row-start-2 md:flex">
        <Track delayMs={350} />
      </div>

      <ArtWindow
        className="product-hero-rise md:col-start-5 md:row-span-3 md:row-start-1"
        style={riseStyle(450)}
        title={t('Execution logs')}
        bodyClassName="px-0 py-1.5"
      >
        <ul dir="ltr" className="font-mono text-[11.5px]">
          {LOG_LINES.map((line, index) => (
            <li
              key={`${line.source}-${index}`}
              className="product-hero-rise flex items-center gap-3 px-3.5 py-1.5"
              style={riseStyle(800 + index * 260)}
            >
              <span className="text-emerald-600 dark:text-emerald-400">{line.status}</span>
              <span className="min-w-0 flex-1 truncate text-foreground">{line.source}</span>
              <span className="text-muted-foreground tabular-nums">{line.duration}</span>
            </li>
          ))}
        </ul>
      </ArtWindow>
    </div>
  )
}
