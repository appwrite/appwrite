import { Clock, Database, Mail } from 'lucide-react'
import type { CSSProperties, LucideIcon } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle, productBentoLightAngle, productBentoLightStyle, productBentoSoftFillHover } from './MockSyntax'

/** Brand supporting palette (orange, mint, purple) plus primary CTA pink. */
const BRAND = {
  orange: '#FE9567',
  mint: '#85DBD8',
  purple: '#7C67FE',
  pink: 'var(--brand-cta)',
} as const

type UseCase = {
  id: string
  label: string
  detail: string
  color: string
  icon?: LucideIcon
  iconSrc?: string
}

const USE_CASES: UseCase[] = [
  {
    id: 'schedule',
    label: 'Schedules',
    detail: 'Cron schedules trigger functions automatically',
    icon: Clock,
    color: BRAND.orange,
  },
  {
    id: 'stripe',
    label: 'Stripe webhooks',
    detail: 'Verify events and sync billing state',
    iconSrc: '/icons/stripe.svg',
    color: BRAND.purple,
  },
  {
    id: 'events',
    label: 'Database events',
    detail: 'React when rows are created or updated',
    icon: Database,
    color: BRAND.mint,
  },
  {
    id: 'email',
    label: 'Notifications',
    detail: 'Send email when users sign up',
    icon: Mail,
    color: BRAND.pink,
  },
]

function UseCaseRow({ useCase, index }: { useCase: UseCase; index: number }) {
  const t = useT()
  const Icon = useCase.icon

  return (
    <div
      className={cn(
        'flex items-start gap-2 rounded-md border border-border/80 bg-background px-2 py-1.5',
        productBentoSoftFillHover,
      )}
      style={productBentoLightStyle(
        useCase.color,
        productBentoLightAngle(index),
        { '--row-color': useCase.color, transitionDelay: `${index * 70}ms` } as CSSProperties,
      )}
    >
      <span
        className={cn(
          'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md border border-border bg-background',
        )}
      >
        {useCase.iconSrc ? (
          <img src={useCase.iconSrc} alt="" className={cn('size-3.5', productBentoIdle.providerIcon)} aria-hidden />
        ) : Icon ? (
          <Icon
            className="size-3.5 text-muted-foreground transition-colors duration-300 group-hover:text-[var(--row-color)] motion-reduce:group-hover:text-muted-foreground"
            aria-hidden
          />
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
      <div className={cn('flex h-full min-h-0 w-full flex-col', productBentoContainer.shell)}>
        <div className={cn(productBentoContainer.header, 'px-3 py-2')}>
          <p className={cn('text-[11px] font-medium', productBentoIdle.text)}>
            {t('My functions')}
          </p>
          <p className="mt-0.5 text-[10px] text-muted-foreground">
            {t('Auto-scales with demand, schedules, and events')}
          </p>
        </div>

        <div className="space-y-1.5 overflow-hidden p-2.5">
          {USE_CASES.map((useCase, index) => (
            <UseCaseRow key={useCase.id} useCase={useCase} index={index} />
          ))}
        </div>
      </div>
    </div>
  )
}
