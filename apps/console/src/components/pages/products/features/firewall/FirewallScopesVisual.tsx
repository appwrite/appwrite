import { Code2, Globe, ShieldBan, Zap, type LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtConnector, ArtIconBadge, ArtPanel } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SCOPES: {
  id: string
  title: string
  description: string
  resource: string
  icon: LucideIcon
  status: string
  active: boolean
}[] = [
  {
    id: 'api',
    title: 'API',
    description: 'Project REST and GraphQL endpoints.',
    resource: 'project',
    icon: Code2,
    status: '403',
    active: true,
  },
  {
    id: 'functions',
    title: 'Functions',
    description: 'A specific Function execution endpoint.',
    resource: 'fn_checkout',
    icon: Zap,
    status: '200',
    active: false,
  },
  {
    id: 'sites',
    title: 'Sites',
    description: 'A specific Site deployment hostname.',
    resource: 'site_storefront',
    icon: Globe,
    status: '200',
    active: false,
  },
]

/** Fixed row height so the branch spine can start and end on the first and last row centers. */
const ROW_HEIGHT_CLASS = 'h-[88px]'
const SPINE_INSET_CLASS = 'top-[44px] bottom-[44px]'

export function FirewallScopesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[560px] flex-col py-2 sm:flex-row sm:items-center">
      <ArtPanel
        className="z-[1] w-full shrink-0 sm:w-[200px]"
        innerClassName="product-tone-shadow px-3.5 py-3"
        delayMs={60}
      >
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={ShieldBan} />
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Resource scope')}
          </p>
        </div>
        <p className="mt-2.5 text-[13px] font-semibold text-foreground">{t('Deny account mutations')}</p>
        <p className="mt-1 truncate font-mono text-[10.5px] text-muted-foreground">
          <code dir="ltr">POST /v1/account/*</code>
        </p>
        <div className="mt-2.5 flex items-center gap-1.5">
          <Badge variant="error" className="text-[10px]">
            {t('Deny')}
          </Badge>
          <Badge variant="inactive" className="text-[10px]">
            {t('API')}
          </Badge>
        </div>
        <p className="mt-2.5 border-t border-border/70 pt-2.5 text-[11px] leading-4 text-muted-foreground">
          {t('Choose where the rule evaluates matching traffic.')}
        </p>
      </ArtPanel>

      <div className="mx-auto h-6 w-0 border-s border-dashed border-foreground/25 sm:hidden" aria-hidden />
      <ArtConnector className="hidden w-8 shrink-0 sm:block" travel travelDelayMs={600} />

      <div className="relative min-w-0 flex-1 space-y-3">
        <span
          className={cn(
            'absolute start-0 hidden border-s border-dashed border-foreground/25 sm:block',
            SPINE_INSET_CLASS,
          )}
          aria-hidden
        />
        {SCOPES.map((scope, index) => (
          <div key={scope.id} className="flex items-center">
            <ArtConnector
              className={cn('hidden w-6 shrink-0 sm:block', scope.active && 'border-[rgb(var(--tone-rgb)/0.6)]')}
              travel={scope.active}
              travelDelayMs={1100}
            />
            <ArtPanel
              className="min-w-0 flex-1"
              innerClassName={cn(
                'flex flex-col justify-center px-3 py-2.5',
                ROW_HEIGHT_CLASS,
                scope.active &&
                  'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.05)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
                !scope.active && 'border-dashed opacity-75 shadow-none',
              )}
              delayMs={300 + index * 150}
              float
              floatDelayMs={index * 480}
            >
              <div className="flex items-center gap-2.5">
                <ArtIconBadge icon={scope.icon} tone={scope.active ? 'primary' : 'neutral'} />
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <p className="text-[12px] font-semibold text-foreground">{t(scope.title)}</p>
                    {scope.active ? (
                      <Badge variant="success" className="text-[10px]">
                        {t('Selected')}
                      </Badge>
                    ) : null}
                  </div>
                  <p className="truncate text-[11px] text-muted-foreground">{t(scope.description)}</p>
                </div>
                <span
                  dir="ltr"
                  className={cn(
                    'inline-flex shrink-0 items-center gap-1 rounded-full border px-1.5 py-px font-mono text-[10px]',
                    scope.active
                      ? 'border-red-500/25 text-red-600 dark:text-red-400'
                      : 'border-emerald-500/25 text-emerald-600 dark:text-emerald-400',
                  )}
                >
                  <span
                    className={cn('size-1.5 rounded-full', scope.active ? 'bg-red-500' : 'bg-emerald-500')}
                    aria-hidden
                  />
                  {scope.status}
                </span>
              </div>
              <p className="mt-1.5 truncate ps-[38px] font-mono text-[10px] text-muted-foreground">
                <code dir="ltr">{scope.resource}</code>
              </p>
            </ArtPanel>
          </div>
        ))}
      </div>
    </div>
  )
}
