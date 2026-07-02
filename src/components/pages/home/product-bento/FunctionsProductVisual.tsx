import { Clock, Database, Mail } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from './MockSyntax'

type HighlightKey = 'webhook' | 'schedule'

type UseCase = {
  id: string
  label: string
  detail: string
  icon?: LucideIcon
  iconSrc?: string
  highlightKey?: HighlightKey
}

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

function UseCaseRow({ useCase }: { useCase: UseCase }) {
  const t = useT()
  const Icon = useCase.icon
  const highlighted = Boolean(useCase.highlightKey)

  return (
    <div
      className={cn(
        'flex items-start gap-2 rounded-md border border-border/80 bg-background px-2 py-1.5 transition-[border-color,background-color] duration-300',
        highlighted &&
          'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_30%,var(--border))] group-hover:bg-muted/30 motion-reduce:group-hover:border-border/80 motion-reduce:group-hover:bg-background',
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
          <img src={useCase.iconSrc} alt="" className={cn('size-3.5', productBentoIdle.providerIcon)} aria-hidden />
        ) : Icon ? (
          <Icon className="size-3.5 text-muted-foreground" aria-hidden />
        ) : null}
      </span>
      <div className="min-w-0 flex-1">
        <p className={cn('text-[11px] font-medium leading-tight', productBentoIdle.text)}>
          {t(useCase.label)}
        </p>
        <p className="mt-0.5 text-[10px] leading-snug text-muted-foreground">
          {t(useCase.detail)}
        </p>
      </div>
    </div>
  )
}

export function FunctionsProductVisual() {
  const t = useT()
  return (
    <div className="absolute inset-0 flex flex-col overflow-hidden transition-transform duration-500 group-hover:-translate-y-1 motion-reduce:group-hover:translate-y-0">
      <div className={cn('mx-auto flex h-full min-h-0 w-full max-w-[20rem] flex-col', productBentoContainer.shell)}>
        <div className={cn(productBentoContainer.header, 'px-3 py-2')}>
          <p className={cn('text-[11px] font-medium', productBentoIdle.text)}>
            {t('My functions')}
          </p>
          <p className="mt-0.5 text-[10px] text-muted-foreground">
            {t('Auto-scales with demand, schedules, and events')}
          </p>
        </div>

        <div className="space-y-1.5 overflow-hidden p-2.5">
          {USE_CASES.map((useCase) => (
            <UseCaseRow key={useCase.id} useCase={useCase} />
          ))}
        </div>
      </div>
    </div>
  )
}
