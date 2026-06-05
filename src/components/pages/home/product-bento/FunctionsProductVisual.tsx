import {
  ArrowDown,
  CalendarClock,
  CheckCircle2,
  Clock,
  Database,
  Mail,
  TrendingUp,
  Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { FunctionsStripeSnippet } from './MockSyntax'

type HighlightKey = 'webhook' | 'schedule'

type UseCase = {
  id: string
  label: string
  detail: string
  icon?: LucideIcon
  iconSrc?: string
  highlightKey?: HighlightKey
}

const AUTO_SCALE_BARS = [
  { idle: 'h-1', active: 'group-hover:h-2' },
  { idle: 'h-1', active: 'group-hover:h-2.5' },
  { idle: 'h-1.5', active: 'group-hover:h-3' },
  { idle: 'h-1', active: 'group-hover:h-3.5' },
  { idle: 'h-1', active: 'group-hover:h-2' },
  { idle: 'h-1.5', active: 'group-hover:h-4' },
] as const

const USE_CASES: UseCase[] = [
  {
    id: 'schedule',
    label: 'Schedules',
    detail: 'Cron schedules trigger functions automatically',
    icon: Clock,
    highlightKey: 'schedule',
  },
  {
    id: 'stripe',
    label: 'Stripe webhooks',
    detail: 'Verify events and sync billing state',
    iconSrc: '/icons/stripe.svg',
    highlightKey: 'webhook',
  },
  {
    id: 'events',
    label: 'Database events',
    detail: 'React when rows are created or updated',
    icon: Database,
  },
  {
    id: 'email',
    label: 'Notifications',
    detail: 'Send email when users sign up',
    icon: Mail,
  },
]

function FlowReveal({
  children,
  className,
  delayMs = 0,
  maxHeightClass = 'group-hover:max-h-64',
}: {
  children: ReactNode
  className?: string
  delayMs?: number
  maxHeightClass?: string
}) {
  return (
    <div
      className={cn(
        'max-h-0 overflow-hidden opacity-0 transition-[max-height,opacity] duration-500 motion-reduce:group-hover:max-h-64 motion-reduce:group-hover:opacity-100',
        maxHeightClass,
        'group-hover:opacity-100',
        className,
      )}
      style={{ transitionDelay: `${delayMs}ms` }}
    >
      {children}
    </div>
  )
}

function UseCaseRow({ useCase }: { useCase: UseCase }) {
  const Icon = useCase.icon
  const highlighted = Boolean(useCase.highlightKey)

  return (
    <div
      className={cn(
        'flex items-start gap-2 rounded-md border px-2 py-1.5 transition-[border-color,background-color,opacity] duration-300',
        highlighted
          ? 'border-border/80 bg-background/70 group-hover:border-[color-mix(in_srgb,var(--brand-cta)_30%,var(--border))] group-hover:bg-[color-mix(in_srgb,var(--brand-cta)_8%,var(--background))] motion-reduce:group-hover:border-border/80 motion-reduce:group-hover:bg-background/70'
          : 'border-transparent opacity-85 group-hover:opacity-45 motion-reduce:group-hover:opacity-85',
      )}
    >
      <span
        className={cn(
          'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md border border-border bg-background',
          highlighted &&
            'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_25%,var(--border))] motion-reduce:group-hover:border-border',
        )}
      >
        {useCase.iconSrc ? (
          <img src={useCase.iconSrc} alt="" className="size-3.5" aria-hidden />
        ) : Icon ? (
          <Icon className="size-3.5 text-muted-foreground" aria-hidden />
        ) : null}
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-[11px] font-medium leading-tight text-foreground">
          {useCase.label}
        </p>
        <p className="mt-0.5 text-[10px] leading-snug text-muted-foreground">
          {useCase.detail}
        </p>
      </div>
    </div>
  )
}

function FlowStep({
  icon,
  iconSrc,
  title,
  subtitle,
  complete,
  delayMs,
}: {
  icon?: LucideIcon
  iconSrc?: string
  title: string
  subtitle: string
  complete?: boolean
  delayMs?: number
}) {
  const Icon = icon

  return (
    <div
      className="flex items-center gap-2 rounded-md border border-border/80 bg-background/90 px-2 py-1.5 opacity-0 group-hover:animate-[product-bento-oauth-highlight_0.45s_ease-out_both] motion-reduce:opacity-100 motion-reduce:group-hover:animate-none"
      style={delayMs !== undefined ? { animationDelay: `${delayMs}ms` } : undefined}
    >
      <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-muted/40">
        {iconSrc ? (
          <img src={iconSrc} alt="" className="size-3.5" aria-hidden />
        ) : Icon ? (
          <Icon className="size-3.5 text-[var(--brand-cta)]" aria-hidden />
        ) : null}
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-[10px] font-medium text-foreground">{title}</p>
        <p className="truncate font-mono text-[9px] text-muted-foreground">{subtitle}</p>
      </div>
      {complete ? (
        <CheckCircle2
          className="size-3.5 shrink-0 text-emerald-600 opacity-0 group-hover:animate-[product-bento-oauth-highlight_0.35s_ease-out_both] dark:text-emerald-400 motion-reduce:opacity-100 motion-reduce:group-hover:animate-none"
          style={{ animationDelay: `${(delayMs ?? 0) + 120}ms` }}
          aria-hidden
        />
      ) : null}
    </div>
  )
}

function AutoScalingHint() {
  return (
    <div className="border-t border-border/80 px-2.5 py-2">
      <div className="flex items-center gap-2">
        <TrendingUp className="size-3.5 shrink-0 text-[var(--brand-cta)]" aria-hidden />
        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-medium text-foreground">Auto-scaling</p>
          <p className="text-[9px] text-muted-foreground">Scales with incoming executions</p>
        </div>
        <p className="shrink-0 text-right text-[9px] tabular-nums text-muted-foreground">
          <span className="font-medium text-foreground group-hover:hidden motion-reduce:inline">
            1
          </span>
          <span className="hidden font-medium text-foreground group-hover:inline motion-reduce:hidden">
            24
          </span>{' '}
          concurrent
        </p>
      </div>
      <div className="mt-1.5 flex h-4 items-end gap-0.5" aria-hidden>
        {AUTO_SCALE_BARS.map((bar, index) => (
          <div
            key={index}
            className={cn(
              'flex-1 rounded-sm bg-[var(--brand-cta)]/20 transition-all duration-500 group-hover:bg-[var(--brand-cta)]/80 motion-reduce:group-hover:bg-[var(--brand-cta)]/20',
              bar.idle,
              bar.active,
            )}
            style={{ transitionDelay: `${index * 40}ms` }}
          />
        ))}
      </div>
    </div>
  )
}

function ScheduleRevealPanel() {
  return (
    <div
      className="rounded-md border border-border/80 bg-background/90 p-2 opacity-0 group-hover:animate-[product-bento-oauth-highlight_0.45s_ease-out_both] motion-reduce:opacity-100 motion-reduce:group-hover:animate-none"
      style={{ animationDelay: '140ms' }}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-[10px] font-medium text-foreground">
            cleanup-expired-sessions
          </p>
          <p className="mt-0.5 font-mono text-[9px] text-muted-foreground">0 2 * * *</p>
        </div>
        <span className="shrink-0 rounded-full border border-border bg-muted/30 px-1.5 py-0.5 text-[9px] font-medium text-muted-foreground">
          Schedule
        </span>
      </div>

      <div className="mt-2 flex items-center gap-2 rounded-md border border-border bg-muted/20 px-2 py-1.5">
        <CalendarClock className="size-3.5 shrink-0 text-[var(--brand-cta)]" aria-hidden />
        <div className="min-w-0 flex-1">
          <p className="text-[10px] text-foreground">Daily at 2:00 AM UTC</p>
          <p className="text-[9px] text-muted-foreground">Cron expression schedule</p>
        </div>
      </div>

      <div className="mt-2 flex items-center justify-between gap-2 text-[9px]">
        <span className="text-muted-foreground">Next run</span>
        <span className="font-medium tabular-nums text-foreground opacity-70 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:opacity-100">
          in 6h 12m
        </span>
      </div>
    </div>
  )
}

export function FunctionsProductVisual() {
  return (
    <div className="absolute inset-0 overflow-hidden px-3 pt-2 transition-transform duration-500 group-hover:-translate-y-1 motion-reduce:group-hover:translate-y-0 sm:px-4 sm:pt-3">
      <div className="mx-auto w-full max-w-[20rem] overflow-hidden rounded-xl border border-border bg-card/95 shadow-sm">
        <div className="border-b border-border bg-muted/20 px-3 py-2">
          <p className="text-[11px] font-medium text-foreground">My functions</p>
          <p className="mt-0.5 text-[10px] text-muted-foreground">
            Auto-scales with demand, schedules, and events
          </p>
        </div>

        <div className="space-y-1.5 p-2.5">
          {USE_CASES.map((useCase) => (
            <UseCaseRow key={useCase.id} useCase={useCase} />
          ))}
        </div>

        <AutoScalingHint />

        <FlowReveal
          className="space-y-3 border-t border-border bg-muted/10 px-2.5 pb-2.5 pt-2"
          delayMs={80}
          maxHeightClass="group-hover:max-h-[26rem]"
        >
          <div>
            <p className="mb-2 text-[9px] font-medium uppercase tracking-wider text-muted-foreground">
              Function schedule
            </p>
            <ScheduleRevealPanel />
          </div>

          <div>
            <p className="mb-2 text-[9px] font-medium uppercase tracking-wider text-muted-foreground">
              Stripe checkout flow
            </p>
            <div className="space-y-1">
              <FlowStep
                iconSrc="/icons/stripe.svg"
                title="Stripe webhook"
                subtitle="checkout.session.completed"
                delayMs={360}
              />
              <div className="flex justify-center py-0.5">
                <ArrowDown
                  className="size-3 text-muted-foreground/50 opacity-0 group-hover:animate-[product-bento-oauth-highlight_0.35s_ease-out_both] motion-reduce:opacity-100 motion-reduce:group-hover:animate-none"
                  style={{ animationDelay: '460ms' }}
                  aria-hidden
                />
              </div>
              <FlowStep
                icon={Zap}
                title="stripe-webhook"
                subtitle="verify signature + handle event"
                delayMs={520}
              />
              <div className="flex justify-center py-0.5">
                <ArrowDown
                  className="size-3 text-muted-foreground/50 opacity-0 group-hover:animate-[product-bento-oauth-highlight_0.35s_ease-out_both] motion-reduce:opacity-100 motion-reduce:group-hover:animate-none"
                  style={{ animationDelay: '600ms' }}
                  aria-hidden
                />
              </div>
              <FlowStep
                icon={Database}
                title="Users table"
                subtitle="plan → pro"
                complete
                delayMs={660}
              />
            </div>

            <div
              className="mt-2 overflow-hidden rounded-md border border-border/80 bg-background/90 px-2 py-1.5 opacity-0 group-hover:animate-[product-bento-oauth-highlight_0.45s_ease-out_both] motion-reduce:opacity-100 motion-reduce:group-hover:animate-none"
              style={{ animationDelay: '740ms' }}
            >
              <FunctionsStripeSnippet />
            </div>
          </div>
        </FlowReveal>
      </div>
    </div>
  )
}
