import type { CSSProperties } from 'react'
import {
  ArrowRight,
  BarChart3,
  CalendarClock,
  Database,
  HardDrive,
  ShoppingBag,
  UserRound,
  Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtConnector,
  ArtIconBadge,
  ArtLiveDot,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const EVENT_TRIGGERS: { event: string; icon: LucideIcon; offset: string }[] = [
  { event: 'databases.*.tables.*.rows.*.create', icon: Database, offset: 'lg:me-0' },
  { event: 'users.*.sessions.*.create', icon: UserRound, offset: 'lg:ms-8' },
  { event: 'storage.buckets.*.files.*.create', icon: HardDrive, offset: 'lg:ms-3' },
]

const CRON_PRESETS = [
  { label: 'Every hour', cron: '0 * * * *', active: false },
  { label: 'Every day at midnight', cron: '0 0 * * *', active: false },
  { label: 'Every Monday at 9 AM', cron: '0 9 * * 1', active: true },
] as const

const CRON_FIELDS = ['0', '9', '*', '*', '1'] as const

const EXECUTION_MODES = [
  {
    id: 'sync',
    title: 'Sync',
    badge: '30s limit',
    badgeVariant: 'outline',
    description:
      'HTTP domains and SDK calls with async disabled. Response body returned to the caller.',
    share: 0.14,
  },
  {
    id: 'async',
    title: 'Async',
    badge: 'Background',
    badgeVariant: 'info',
    description:
      'Platform events, cron schedules, and queued executions. Uses your configured timeout.',
    share: 1,
  },
] as const

function ColumnHeading({
  icon,
  eyebrow,
  title,
  description,
  delayMs,
}: {
  icon: LucideIcon
  eyebrow: string
  title: string
  description: string
  delayMs: number
}) {
  const t = useT()
  return (
    <div className="product-hero-rise flex items-start gap-3" style={riseStyle(delayMs)}>
      <ArtIconBadge icon={icon} className="size-8" />
      <div className="min-w-0">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t(eyebrow)}
        </p>
        <p className="mt-0.5 text-[13px] font-semibold text-foreground">{t(title)}</p>
        <p className="mt-0.5 text-[11px] leading-5 text-muted-foreground">{t(description)}</p>
      </div>
    </div>
  )
}

function UseCase({
  icon: Icon,
  trigger,
  outcome,
  delayMs,
  className,
}: {
  icon: LucideIcon
  trigger: string
  outcome: string
  delayMs: number
  className?: string
}) {
  const t = useT()
  return (
    <ArtPanel
      className={className}
      innerClassName="px-3.5 py-3"
      delayMs={delayMs}
      float
      floatDelayMs={delayMs}
    >
      <div className="flex items-center gap-2.5">
        <ArtIconBadge icon={Icon} tone="secondary" />
        <p className="text-[12px] font-medium leading-snug text-foreground">{t(trigger)}</p>
      </div>
      <div className="mt-2 flex items-start gap-2 text-[11px] leading-5 text-muted-foreground">
        <ArrowRight
          className="mt-0.5 size-3.5 shrink-0 text-[var(--tone-ink)] rtl:rotate-180"
          aria-hidden
        />
        <span>{t(outcome)}</span>
      </div>
    </ArtPanel>
  )
}

function ExecutionModes() {
  const t = useT()
  return (
    <ArtPanel innerClassName="product-tone-shadow px-4 py-4" delayMs={500}>
      <p className="text-center text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {t('Execution modes')}
      </p>
      <div className="mt-3 space-y-4">
        {EXECUTION_MODES.map((mode, index) => (
          <div key={mode.id}>
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-[12px] font-semibold text-foreground">{t(mode.title)}</p>
              <Badge variant={mode.badgeVariant} className="text-[10px]">
                {t(mode.badge)}
              </Badge>
            </div>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
              <div
                className={cn(
                  'product-hero-fill h-full rounded-full',
                  mode.id === 'async' ? 'bg-[var(--tone-ink)]' : 'bg-[rgb(var(--tone2-rgb)/0.8)]',
                )}
                style={
                  {
                    width: `${mode.share * 100}%`,
                    '--fill-delay': `${900 + index * 400}ms`,
                    '--fill-duration': mode.id === 'async' ? '2.6s' : '0.9s',
                  } as CSSProperties
                }
              />
            </div>
            <p className="mt-2 text-[11px] leading-5 text-muted-foreground">{t(mode.description)}</p>
          </div>
        ))}
      </div>
      <div
        dir="ltr"
        className="mt-3 flex items-center justify-between border-t border-border pt-2 font-mono text-[10px] text-muted-foreground"
      >
        <span>0s</span>
        <span>30s</span>
        <span>{t('15 minutes')}</span>
      </div>
    </ArtPanel>
  )
}

export function FunctionsTriggersVisual() {
  const t = useT()

  return (
    <div className="mx-auto grid max-w-6xl items-center gap-10 lg:grid-cols-[minmax(0,1fr)_40px_minmax(0,0.85fr)_40px_minmax(0,1fr)] lg:gap-0">
      <div className="min-w-0 space-y-3">
        <ColumnHeading
          icon={Zap}
          eyebrow="Event triggers"
          title="When something happens"
          description="React to platform events as they occur."
          delayMs={0}
        />
        <div className="space-y-2 pt-1">
          {EVENT_TRIGGERS.map((trigger, index) => {
            const first = index === 0
            return (
              <ArtPanel
                key={trigger.event}
                className={cn('lg:max-w-[340px]', trigger.offset)}
                innerClassName={cn(
                  'flex items-center gap-2.5 px-3 py-2',
                  first &&
                    'border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
                )}
                delayMs={150 + index * 120}
              >
                <ArtIconBadge icon={trigger.icon} tone="neutral" />
                <span
                  dir="ltr"
                  className="min-w-0 flex-1 truncate text-start font-mono text-[10px] text-foreground sm:text-[11px]"
                >
                  {trigger.event}
                </span>
                {first ? <ArtLiveDot className="shrink-0" /> : null}
              </ArtPanel>
            )
          })}
        </div>
        <UseCase
          icon={ShoppingBag}
          trigger="A customer completes checkout"
          outcome="Send receipt, update inventory, and notify fulfillment"
          delayMs={600}
          className="lg:me-6 lg:ms-6"
        />
      </div>

      <div className="hidden items-center lg:flex">
        <ArtConnector travel travelDelayMs={200} />
      </div>

      <div className="mx-auto w-full min-w-0 max-w-[400px]">
        <ExecutionModes />
      </div>

      <div className="hidden items-center lg:flex">
        <ArtConnector travel travelDelayMs={1300} className="rotate-180" />
      </div>

      <div className="min-w-0 space-y-3">
        <ColumnHeading
          icon={CalendarClock}
          eyebrow="Scheduled executions"
          title="When the clock hits"
          description="Pick a preset or write a custom cron expression."
          delayMs={200}
        />
        <ArtPanel
          className="lg:ms-6"
          innerClassName="px-3.5 py-3"
          delayMs={350}
        >
          <div dir="ltr" className="flex gap-1.5">
            {CRON_FIELDS.map((field, index) => (
              <span
                key={index}
                className={cn(
                  'product-hero-rise flex h-9 flex-1 items-center justify-center rounded-lg border font-mono text-[15px] font-medium',
                  field === '*'
                    ? 'border-border bg-muted/30 text-muted-foreground'
                    : 'border-[rgb(var(--tone-rgb)/0.4)] bg-[rgb(var(--tone-rgb)/0.1)] text-[var(--tone-ink)]',
                )}
                style={riseStyle(500 + index * 90)}
              >
                {field}
              </span>
            ))}
          </div>
          <div className="mt-3 space-y-1">
            {CRON_PRESETS.map((preset) => (
              <div
                key={preset.cron}
                className={cn(
                  'flex items-center justify-between gap-3 rounded-md px-2.5 py-1.5',
                  preset.active ? 'bg-muted/60' : 'text-muted-foreground',
                )}
              >
                <span className="text-[11px]">{t(preset.label)}</span>
                <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
                  {preset.cron}
                </span>
              </div>
            ))}
          </div>
        </ArtPanel>
        <UseCase
          icon={BarChart3}
          trigger="Every Monday at 9 AM"
          outcome="Email the team a weekly sales and usage digest"
          delayMs={750}
          className="lg:me-8"
        />
      </div>
    </div>
  )
}
