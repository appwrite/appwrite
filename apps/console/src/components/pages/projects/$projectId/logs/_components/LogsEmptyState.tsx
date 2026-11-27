import type { ReactNode } from 'react'
import { FileText, Filter, Radio, ScanSearch } from 'lucide-react'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  ProductEmptyStateDocsButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { RuntimeLogLevelBadge } from '@/components/global/shared/RuntimeLogRow'
import { DatabaseTypeIcon } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeIcon'
import { useT } from '@/lib/i18n/translate'
import type { RuntimeLogLevel } from '@/lib/runtime-log-line'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Radio,
    title: 'Every line, as it is written',
    description:
      'What your functions, sites and dedicated databases write to stdout and stderr lands here, newest first. Turn on Live to follow along.',
  },
  {
    icon: Filter,
    title: 'Narrow it down',
    description:
      'Filter by resource, level, stream and time, or search the lines you have loaded.',
  },
  {
    icon: ScanSearch,
    title: 'Inspect any line',
    description:
      'Open a line to read its fields, where it ran, and the line exactly as it was written.',
  },
]

const MARK = 'h-2.5 w-2.5'

const LINES: {
  time: string
  level: RuntimeLogLevel
  /** The resource's own mark, as the rows show it. */
  mark: ReactNode
  source: string
  message: string
  active?: boolean
}[] = [
  {
    time: '12:04:31',
    level: 'info',
    mark: <RuntimeIcon runtime="node-22" size="sm" className={MARK} />,
    source: 'Checkout',
    message: 'request completed',
  },
  {
    time: '12:04:30',
    level: 'error',
    mark: <RuntimeIcon runtime="node-22" size="sm" className={MARK} />,
    source: 'Checkout',
    message: 'payment failed',
    active: true,
  },
  {
    time: '12:04:28',
    level: 'warn',
    mark: (
      <DatabaseTypeIcon
        apiType="postgresql"
        engine="postgresql"
        className={MARK}
      />
    ),
    source: 'Orders',
    message: 'slow query',
  },
  {
    time: '12:04:27',
    level: 'info',
    mark: <FrameworkIcon framework="nextjs" size="sm" className={MARK} />,
    source: 'Storefront',
    message: 'GET /pricing 200',
  },
]

const FIELDS = [
  { key: 'orderId', value: 'ord_8f2k' },
  { key: 'err.type', value: 'CardError' },
  { key: 'region', value: 'fra' },
]

/** Decorative log list, an inspected line, and an active level filter. */
function LogStreamVisual() {
  return (
    <ProductEmptyStateVisual className="w-[440px]">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <FileText className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-16 rounded-full bg-muted-foreground/25" />
          <span className="ms-auto flex items-center gap-1">
            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500 motion-reduce:animate-none" />
            <span className="h-1.5 w-8 rounded-full bg-muted-foreground/20" />
          </span>
        </div>
        <ul className="divide-y divide-border">
          {LINES.map((line) => {
            return (
              <li
                key={line.time}
                className={cn(
                  'flex items-center gap-2.5 px-3 py-2',
                  line.active && 'bg-muted/50',
                )}
              >
                <span
                  dir="ltr"
                  className="w-12 shrink-0 font-mono text-[10px] tabular-nums text-muted-foreground"
                >
                  {line.time}
                </span>
                <RuntimeLogLevelBadge
                  level={line.level}
                  className="h-4 min-w-[38px] px-1 text-[8.5px]"
                />
                <span className="flex w-24 shrink-0 items-center gap-1.5">
                  <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                    {line.mark}
                  </span>
                  <span className="truncate text-[10px] text-foreground">
                    {line.source}
                  </span>
                </span>
                <span
                  dir="ltr"
                  className="min-w-0 flex-1 truncate font-mono text-[10px] text-foreground"
                >
                  {line.message}
                </span>
              </li>
            )
          })}
        </ul>
      </div>

      <div className="absolute -end-40 top-8 w-48 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-1.5">
          <RuntimeLogLevelBadge
            level="error"
            className="h-4 min-w-[38px] px-1 text-[8.5px]"
          />
          <span dir="ltr" className="font-mono text-[10px] text-foreground">
            payment failed
          </span>
        </div>
        <div className="mt-2.5 space-y-1.5">
          {FIELDS.map((field) => (
            <div
              key={field.key}
              dir="ltr"
              className="flex items-center gap-1.5 font-mono text-[10px]"
            >
              <span className="text-muted-foreground">{field.key}</span>
              <span className="truncate text-foreground">{field.value}</span>
            </div>
          ))}
        </div>
      </div>

      <div className="absolute -start-16 -bottom-4 flex items-center gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <Filter className="h-3 w-3 text-muted-foreground" />
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          level
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          = error
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

/**
 * The project Logs page before any line arrives, laid out like the other
 * Observe pages: what lands here, then how to narrow and read it.
 */
export function LogsEmptyState() {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 sm:py-12">
      <ProductEmptyStateHero
        visual={<LogStreamVisual />}
        icon={FileText}
        title={t("Your project's runtime logs")}
        description={t(
          'Lines your functions, sites and dedicated databases write show up here within seconds, ready to search, filter and inspect. Nothing has been written in this time range yet.',
        )}
        actions={
          <ProductEmptyStateDocsButton path="/docs/products/functions/develop#logging" />
        }
      >
        <p className="mt-4 max-w-md text-[12px] leading-relaxed text-muted-foreground">
          {t("Logs may not be collected in this project's region yet.")}
        </p>
      </ProductEmptyStateHero>
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
