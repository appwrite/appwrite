import { ArrowDown01, Ban, ShieldOff, Timer, type LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtConnector, ArtIconBadge, ArtLiveDot, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const RULES: {
  id: string
  priority: number
  name: string
  condition: string
  action: string
  actionVariant: 'processing' | 'error' | 'warning'
  icon: LucideIcon
  match: boolean
}[] = [
  {
    id: 'bypass-office',
    priority: -10,
    name: 'Office IP allowlist',
    condition: 'ip == 10.0.4.21',
    action: 'Bypass',
    actionVariant: 'processing',
    icon: ShieldOff,
    match: true,
  },
  {
    id: 'deny-account',
    priority: 0,
    name: 'Deny account mutations',
    condition: 'path starts_with /v1/account',
    action: 'Deny',
    actionVariant: 'error',
    icon: Ban,
    match: false,
  },
  {
    id: 'rate-api',
    priority: 10,
    name: 'Rate limit public API',
    condition: 'path starts_with /v1',
    action: 'Rate limit',
    actionVariant: 'warning',
    icon: Timer,
    match: false,
  },
]

/** Node column width; the spine runs down its center. */
const NODE_COLUMN_CLASS = 'w-11 shrink-0'

function Spine({ half, tone }: { half?: 'top' | 'bottom'; tone?: boolean }) {
  return (
    <span
      className={cn(
        'absolute start-1/2 w-0 border-s',
        half === 'top' ? 'top-0 h-1/2' : half === 'bottom' ? 'bottom-0 h-1/2' : 'inset-y-0',
        tone ? 'border-[rgb(var(--tone-rgb)/0.7)]' : 'border-dashed border-foreground/20',
      )}
      aria-hidden
    />
  )
}

export function FirewallPriorityVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-2">
      <div className="flex items-stretch">
        <div className={cn('relative flex items-center justify-center', NODE_COLUMN_CLASS)}>
          <Spine half="bottom" tone />
          <span
            className="product-hero-rise relative flex size-3 items-center justify-center rounded-full bg-[var(--tone-ink)] shadow-[0_0_12px_rgb(var(--tone-rgb))]"
            style={riseStyle(0)}
            aria-hidden
          >
            <span className="absolute inset-0 animate-ping rounded-full bg-[rgb(var(--tone-rgb)/0.5)] motion-reduce:animate-none" />
          </span>
        </div>
        <ArtPanel className="py-2" innerClassName="flex items-center gap-2.5 px-3 py-2" delayMs={60}>
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{t('Incoming')}</p>
          <p dir="ltr" className="font-mono text-[11px] text-foreground">
            GET /v1/databases <span className="text-[var(--tone-ink)]">10.0.4.21</span>
          </p>
        </ArtPanel>
      </div>

      {RULES.map((rule, index) => {
        const isLast = index === RULES.length - 1
        return (
          <div key={rule.id} className="flex items-stretch">
            <div className={cn('relative flex items-center justify-center', NODE_COLUMN_CLASS)}>
              {rule.match ? (
                <>
                  <Spine half="top" tone />
                  {!isLast ? <Spine half="bottom" /> : null}
                </>
              ) : (
                <Spine half={isLast ? 'top' : undefined} />
              )}
              <span
                dir="ltr"
                className={cn(
                  'product-hero-rise relative flex h-6 min-w-9 items-center justify-center rounded-full border px-1.5 font-mono text-[10px] font-semibold tabular-nums',
                  rule.match
                    ? 'border-[rgb(var(--tone-rgb)/0.55)] bg-background text-[var(--tone-ink)] shadow-[0_0_14px_rgb(var(--tone-rgb)/0.35)] dark:bg-card'
                    : 'border-border bg-background text-muted-foreground dark:bg-card',
                )}
                style={riseStyle(220 + index * 160)}
              >
                {rule.priority}
              </span>
            </div>

            <div className="flex min-w-0 flex-1 items-center py-2.5">
              <ArtPanel
                className="min-w-0 flex-1 sm:w-[300px] sm:flex-none"
                innerClassName={cn(
                  'flex items-center gap-2.5 px-3 py-2.5',
                  rule.match
                    ? 'product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]'
                    : 'border-dashed opacity-60 shadow-none',
                )}
                delayMs={260 + index * 160}
                float={rule.match}
              >
                <ArtIconBadge icon={rule.icon} tone={rule.match ? 'primary' : 'neutral'} />
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="text-[12px] font-medium text-foreground">{t(rule.name)}</p>
                    <Badge variant={rule.actionVariant} className="text-[10px]">
                      {t(rule.action)}
                    </Badge>
                  </div>
                  <p className="mt-0.5 truncate font-mono text-[10.5px] text-muted-foreground">
                    <code dir="ltr">{rule.condition}</code>
                  </p>
                </div>
                <Badge variant={rule.match ? 'success' : 'inactive'} className="shrink-0 text-[10px]">
                  {t(rule.match ? 'First match' : 'Skipped')}
                </Badge>
              </ArtPanel>

              {rule.match ? (
                <div className="hidden min-w-0 flex-1 items-center sm:flex">
                  <ArtConnector className="min-w-6 flex-1" travel travelDelayMs={900} />
                  <ArtPanel
                    className="shrink-0"
                    innerClassName="flex items-center gap-2 px-2.5 py-2"
                    delayMs={700}
                    float
                    floatDelayMs={600}
                  >
                    <ArtLiveDot />
                    <span className="text-[11px] font-medium text-foreground">{t('Your app')}</span>
                  </ArtPanel>
                </div>
              ) : null}
            </div>
          </div>
        )
      })}

      <div
        className="product-hero-rise mt-3 flex items-center gap-2 ps-11 text-[11px] text-muted-foreground"
        style={riseStyle(900)}
      >
        <ArrowDown01 className="size-3.5 shrink-0" aria-hidden />
        {t('Lower numbers evaluate first. The first match stops the chain.')}
      </div>
    </div>
  )
}
