import { Ban, Bot, Braces, Check, Fingerprint, Globe, Network, Search, type LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtIconBadge, ArtPanel, riseStyle, floatStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const REQUEST_FIELDS = [
  { label: 'IP address', value: '203.0.113.10', matched: false },
  { label: 'Country', value: 'DE', matched: false },
  { label: 'User agent', value: 'python-requests/2.31', matched: true },
] as const

const CONDITIONS = [
  { label: 'Path', operator: 'starts with', value: '/v1/account', offset: 'sm:ms-6' },
  { label: 'Method', operator: 'equals', value: 'POST', offset: 'sm:ms-10' },
  { label: 'User agent', operator: 'contains', value: 'python-requests', offset: 'sm:ms-4' },
] as const

/** Other attributes a rule can match on, scattered beside the chain. */
const ATTRIBUTES: { label: string; icon: LucideIcon; className: string; fade?: boolean }[] = [
  { label: 'Country', icon: Globe, className: 'end-[6%] top-[2%]' },
  { label: 'ASN', icon: Network, className: 'end-0 top-[20%]', fade: true },
  { label: 'Header', icon: Braces, className: 'end-[10%] top-[38%]' },
  { label: 'Bot score', icon: Bot, className: 'end-[2%] top-[56%]' },
  { label: 'JA4 fingerprint', icon: Fingerprint, className: 'end-[8%] top-[74%]', fade: true },
  { label: 'Query parameter', icon: Search, className: 'end-0 bottom-[2%]', fade: true },
]

function AndJoint({ delayMs }: { delayMs: number }) {
  return (
    <div className="product-hero-rise relative flex h-7 items-center ps-14" style={riseStyle(delayMs)} aria-hidden>
      <span className="absolute inset-y-0 start-14 border-s border-dashed border-foreground/25" />
      <span
        dir="ltr"
        className="relative -translate-x-1/2 rounded-full border border-border bg-background px-1.5 py-px font-mono text-[9px] font-semibold tracking-wider text-muted-foreground rtl:translate-x-1/2 dark:bg-card"
      >
        AND
      </span>
    </div>
  )
}

export function FirewallConditionsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-2">
      {ATTRIBUTES.map((attribute, index) => {
        const Icon = attribute.icon
        return (
          <span
            key={attribute.label}
            className={cn('product-hero-rise absolute hidden sm:block', attribute.className)}
            style={riseStyle(900 + index * 90)}
            aria-hidden
          >
            <span
              className={cn(
                'product-hero-float inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] text-foreground shadow-sm dark:bg-card',
                attribute.fade && 'opacity-55',
              )}
              style={floatStyle(index * 420)}
            >
              <Icon className="size-3 text-muted-foreground" aria-hidden />
              {t(attribute.label)}
            </span>
          </span>
        )
      })}

      <div className="relative z-[1] w-full sm:w-[330px]">
        <ArtPanel className="w-full sm:w-[290px]" innerClassName="product-tone-shadow px-3.5 py-3" delayMs={60}>
          <div className="flex items-center justify-between gap-2">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{t('Incoming')}</p>
            <span className="size-1.5 rounded-full bg-[var(--tone-ink)] shadow-[0_0_8px_rgb(var(--tone-rgb))]" aria-hidden />
          </div>
          <p dir="ltr" className="mt-1.5 flex items-center gap-2 font-mono text-[12px] text-foreground">
            <span className="rounded bg-[rgb(var(--tone-rgb)/0.14)] px-1.5 py-px text-[10px] font-semibold text-[var(--tone-ink)]">
              POST
            </span>
            <span className="truncate">
              <span className="text-[var(--tone-ink)]">/v1/account</span>/sessions
            </span>
          </p>
          <dl className="mt-2.5 space-y-1 border-t border-border/70 pt-2.5">
            {REQUEST_FIELDS.map((field) => (
              <div key={field.label} className="flex items-center justify-between gap-3 text-[11px]">
                <dt className="shrink-0 text-muted-foreground">{t(field.label)}</dt>
                <dd
                  dir="ltr"
                  className={cn(
                    'truncate font-mono text-[11px]',
                    field.matched ? 'text-[var(--tone-ink)]' : 'text-foreground',
                  )}
                >
                  {field.value}
                </dd>
              </div>
            ))}
          </dl>
        </ArtPanel>

        <div className="ms-14 h-5 border-s border-dashed border-foreground/25" aria-hidden />

        {CONDITIONS.map((condition, index) => (
          <div key={condition.label}>
            {index > 0 ? <AndJoint delayMs={420 + index * 160} /> : null}
            <ArtPanel
              className={cn('w-full sm:w-[290px]', condition.offset)}
              innerClassName="flex items-center gap-2 px-3 py-2.5"
              delayMs={300 + index * 160}
              float
              floatDelayMs={index * 520}
            >
              <span className="shrink-0 text-[12px] font-medium text-foreground">{t(condition.label)}</span>
              <span className="shrink-0 rounded bg-muted px-1.5 py-px text-[10px] text-muted-foreground">
                {t(condition.operator)}
              </span>
              <code dir="ltr" className="min-w-0 truncate font-mono text-[11px] text-[var(--tone-ink)]">
                {condition.value}
              </code>
              <span className="ms-auto flex size-4 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                <Check className="size-2.5" strokeWidth={3} aria-hidden />
              </span>
            </ArtPanel>
          </div>
        ))}

        <div className="ms-14 h-5 border-s border-dashed border-foreground/25" aria-hidden />

        <ArtPanel
          className="w-full sm:w-[300px]"
          innerClassName="flex items-start gap-2.5 border-[rgb(var(--tone-rgb)/0.45)] px-3 py-2.5 dark:border-[rgb(var(--tone-rgb)/0.45)]"
          delayMs={950}
        >
          <ArtIconBadge icon={Ban} />
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <p className="text-[12px] font-semibold text-foreground">{t('Deny')}</p>
              <Badge variant="error" className="text-[10px]">
                <span dir="ltr">403</span>
              </Badge>
            </div>
            <p className="mt-0.5 text-[11px] leading-4 text-muted-foreground">
              {t('Every condition on a rule must match before the action runs.')}
            </p>
          </div>
        </ArtPanel>
      </div>
    </div>
  )
}
