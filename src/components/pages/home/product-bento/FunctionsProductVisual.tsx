import { assetUrl } from '@/lib/asset-url'
import { Clock, Database, Mail } from 'lucide-react'
import type { CSSProperties, LucideIcon } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from './MockSyntax'

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
    iconSrc: assetUrl('/icons/stripe.svg'),
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

function rowColorStyle(color: string, extra?: CSSProperties): CSSProperties {
  return { '--row-color': color, ...extra } as CSSProperties
}

function UseCaseRow({ useCase, index }: { useCase: UseCase; index: number }) {
  const t = useT()
  const Icon = useCase.icon

  return (
    <div
      className={cn(
        'flex items-start gap-2 rounded-md border border-border/80 bg-background px-2 py-1.5 transition-[border-color,background-color] duration-300',
        'group-hover:border-[color-mix(in_srgb,var(--row-color)_42%,var(--border))] group-hover:bg-[color-mix(in_srgb,var(--row-color)_12%,var(--background))] motion-reduce:group-hover:border-border/80 motion-reduce:group-hover:bg-background',
      )}
      style={rowColorStyle(useCase.color, { transitionDelay: `${index * 70}ms` })}
    >
      <span
        className={cn(
          'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md border border-border bg-background transition-[border-color,background-color] duration-300',
          'group-hover:border-[color-mix(in_srgb,var(--row-color)_38%,var(--border))] group-hover:bg-[color-mix(in_srgb,var(--row-color)_14%,var(--background))] motion-reduce:group-hover:border-border motion-reduce:group-hover:bg-background',
        )}
      >
        {useCase.iconSrc ? (
          <img src={assetUrl(useCase.iconSrc)} alt="" className={cn('size-3.5', productBentoIdle.providerIcon)} aria-hidden />
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
          {USE_CASES.map((useCase, index) => (
            <UseCaseRow key={useCase.id} useCase={useCase} index={index} />
          ))}
        </div>
      </div>
    </div>
  )
}
