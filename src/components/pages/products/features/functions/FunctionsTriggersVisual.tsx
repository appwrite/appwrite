import type { ReactNode } from 'react'
import {
  ArrowRight,
  BarChart3,
  CalendarClock,
  ShoppingBag,
  Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const EVENT_TRIGGERS = [
  'databases.*.tables.*.rows.*.create',
  'users.*.sessions.*.create',
  'storage.buckets.*.files.*.create',
] as const

const CRON_PRESETS = [
  { label: 'Every hour', cron: '0 * * * *', active: true },
  { label: 'Every day at midnight', cron: '0 0 * * *', active: false },
  { label: 'Every Monday at 9 AM', cron: '0 9 * * 1', active: false },
] as const

type UseCaseCardProps = {
  icon: LucideIcon
  trigger: string
  outcome: string
  className?: string
}

function UseCaseCard({
  icon: Icon,
  trigger,
  outcome,
  className,
}: UseCaseCardProps) {
  const t = useT()
  return (
    <div
      className={cn(
        'rounded-xl border border-border bg-card px-3.5 py-3 ring-1 ring-black/[0.04] dark:ring-white/[0.06]',
        className,
      )}
    >
      <div className="flex items-start gap-2.5">
        <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
          <Icon className="size-3.5 text-muted-foreground" aria-hidden />
        </span>
        <p className="text-[12px] font-medium leading-snug text-foreground">{t(trigger)}</p>
      </div>
      <div className="mt-2 flex items-start gap-2 ps-0.5 text-[11px] leading-5 text-muted-foreground">
        <ArrowRight
          className="mt-0.5 size-3.5 shrink-0 text-muted-foreground/70"
          aria-hidden
        />
        <span>{t(outcome)}</span>
      </div>
    </div>
  )
}

function ComparisonDivider({ className }: { className?: string }) {
  const t = useT()
  return (
    <div
      className={cn('flex shrink-0 items-center justify-center py-1', className)}
      aria-hidden
    >
      <span className="rounded-full bg-background px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {t('or')}
      </span>
    </div>
  )
}

function TriggerColumn({
  useCase,
  children,
}: {
  useCase: Omit<UseCaseCardProps, 'className'>
  children: ReactNode
}) {
  return (
    <div className="relative min-w-0 overflow-visible">
      <ProductFeatureVisualFrame contentClassName="relative flex min-h-full flex-col">
        <div className="relative z-0 flex min-h-full flex-col">{children}</div>
        <div
          className="pointer-events-none absolute inset-x-0 bottom-0 z-[1] h-28 bg-gradient-to-t from-card via-card/85 to-transparent sm:h-32"
          aria-hidden
        />
      </ProductFeatureVisualFrame>

      <UseCaseCard
        {...useCase}
        className="absolute inset-x-2 bottom-0 z-10 translate-y-1/2 sm:inset-x-3"
      />
    </div>
  )
}

export function FunctionsTriggersVisual() {
  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 overflow-visible pb-12 sm:pb-14">
      <div className="grid gap-6 overflow-visible sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-start sm:gap-x-8">
        <TriggerColumn
          useCase={{
            icon: ShoppingBag,
            trigger: 'A customer completes checkout',
            outcome: 'Send receipt, update inventory, and notify fulfillment',
          }}
        >
          <EventTriggersContent />
        </TriggerColumn>

        <ComparisonDivider className="py-2 sm:self-center sm:px-3" />

        <TriggerColumn
          useCase={{
            icon: BarChart3,
            trigger: 'Every Monday at 9 AM',
            outcome: 'Email the team a weekly sales and usage digest',
          }}
        >
          <ScheduleContent />
        </TriggerColumn>
      </div>

      <ExecutionModesSubsection />
    </div>
  )
}

function ExecutionModesSubsection() {
  const t = useT()
  return (
    <div className="mt-16 overflow-hidden rounded-xl border border-border bg-card/45 sm:mt-20">
      <div className="border-b border-border px-4 py-3 sm:px-5">
        <p className="text-center text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Execution modes')}
        </p>
      </div>
      <div className="grid sm:grid-cols-2 sm:divide-x sm:divide-border">
        <div className="px-4 py-3.5 sm:px-5">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-[12px] font-semibold text-foreground">{t('Sync')}</p>
            <Badge variant="outline" className="text-[10px]">
              {t('30s limit')}
            </Badge>
          </div>
          <p className="mt-1.5 text-[11px] leading-5 text-muted-foreground">
            {t('HTTP domains and SDK calls with async disabled. Response body returned to the caller.')}
          </p>
        </div>
        <div className="border-t border-border px-4 py-3.5 sm:border-t-0 sm:px-5">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-[12px] font-semibold text-foreground">{t('Async')}</p>
            <Badge variant="info" className="text-[10px]">
              {t('Background')}
            </Badge>
          </div>
          <p className="mt-1.5 text-[11px] leading-5 text-muted-foreground">
            {t('Platform events, cron schedules, and queued executions. Uses your configured timeout.')}
          </p>
        </div>
      </div>
    </div>
  )
}

function EventTriggersContent() {
  const t = useT()
  return (
    <>
      <div className="mb-1">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Event triggers')}
        </p>
        <p className="mt-0.5 text-[13px] font-semibold text-foreground">{t('When something happens')}</p>
      </div>

      <div className="mb-3 flex items-start gap-2.5">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/30">
          <Zap className="size-4 text-muted-foreground" aria-hidden />
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-[12px] font-medium text-foreground">{t('Event-driven')}</p>
            <Badge variant="info" className="text-[10px]">
              {t('Async')}
            </Badge>
          </div>
          <p className="mt-0.5 text-[11px] leading-5 text-muted-foreground">
            {t('React to platform events as they occur.')}
          </p>
        </div>
      </div>

      <div className="space-y-1.5">
        {EVENT_TRIGGERS.map((event, index) => (
          <div
            key={event}
            className={cn(
              'rounded-lg border border-border bg-background/80 px-3 py-2 font-mono text-[10px] text-foreground',
              index === 0 && 'border-foreground/10 bg-muted/40',
            )}
          >
            {event}
          </div>
        ))}
      </div>

      <p className="mt-auto pt-3 text-[11px] leading-5 text-muted-foreground">
        {t('Webhooks, sync jobs, and reactive workflows.')}
      </p>
    </>
  )
}

function ScheduleContent() {
  const t = useT()
  return (
    <>
      <div className="mb-1">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Scheduled executions')}
        </p>
        <p className="mt-0.5 text-[13px] font-semibold text-foreground">{t('When the clock hits')}</p>
      </div>

      <div className="mb-3 flex items-start gap-2.5">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/30">
          <CalendarClock className="size-4 text-muted-foreground" aria-hidden />
        </span>
        <div className="min-w-0">
          <p className="text-[12px] font-medium text-foreground">{t('Time-based')}</p>
          <p className="mt-0.5 text-[11px] leading-5 text-muted-foreground">
            {t('Pick a preset or write a custom cron expression.')}
          </p>
        </div>
      </div>

      <div className="rounded-lg border border-border bg-background/80 px-3 py-2.5">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Preset')}
        </p>
        <p className="mt-1 text-[12px] font-medium text-foreground">{t('Every hour')}</p>
        <p className="mt-0.5 font-mono text-[10px] text-muted-foreground">0 * * * *</p>
      </div>

      <div className="mt-2 space-y-1">
        {CRON_PRESETS.map((preset) => (
          <div
            key={preset.cron}
            className={cn(
              'flex items-center justify-between rounded-md px-2.5 py-1.5',
              preset.active && 'border border-foreground/10 bg-muted/40',
            )}
          >
            <span className="text-[11px]">{t(preset.label)}</span>
            <span className="font-mono text-[10px] text-muted-foreground">{preset.cron}</span>
          </div>
        ))}
      </div>

      <p className="mt-auto pt-3 text-[11px] leading-5 text-muted-foreground">
        {t('Reports, cleanups, and recurring background jobs.')}
      </p>
    </>
  )
}
