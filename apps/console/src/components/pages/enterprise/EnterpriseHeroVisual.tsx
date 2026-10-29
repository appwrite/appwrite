import { Headphones, History, Server } from 'lucide-react'
import { ArtChip, ArtIconBadge, ArtLiveDot, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { enterpriseComplianceFrameworks, enterpriseHeroActivity } from '@/lib/enterprise/content'
import { useT } from '@/lib/i18n/translate'

/** Deterministic bar heights so the strip looks organic without random values on each render. */
const UPTIME_BARS = Array.from({ length: 48 }, (_, index) => 72 + ((index * 37) % 28))

/**
 * Open "organization control" view: the uptime commitment, a live-looking activity log,
 * and the compliance frameworks, all from Enterprise plan features.
 */
export function EnterpriseHeroVisual() {
  const t = useT()
  return (
    <div className="relative mx-auto w-full max-w-xl pb-10 pt-10 text-start">
      <ProductVisualAura>
        <div className="product-hero-rise flex flex-wrap items-center justify-between gap-3" style={riseStyle(80)}>
          <p dir="ltr" className="font-mono text-[12px] text-muted-foreground">
            acme-corp · Enterprise
          </p>
          <span className="flex items-center gap-2 text-[11px] text-muted-foreground">
            <ArtLiveDot className="size-1.5" />
            {t('Production organization')}
          </span>
        </div>

        <div className="mt-6">
          <div className="flex items-baseline justify-between gap-3">
            <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">{t('Uptime SLA')}</p>
            <p className="font-aeonik-pro text-[28px] leading-none tracking-tight text-[var(--tone-ink)]">99.99%</p>
          </div>
          <div className="mt-3 flex h-10 items-end gap-[3px]" dir="ltr" aria-hidden>
            {UPTIME_BARS.map((height, index) => (
              <span
                key={index}
                className="product-hero-rise min-w-0 flex-1 rounded-sm bg-[var(--tone-ink)] opacity-80 shadow-[0_0_6px_rgb(var(--tone-rgb)/0.4)]"
                style={riseStyle(160 + index * 14, { height: `${height}%` })}
              />
            ))}
          </div>
        </div>

        <div className="mt-8">
          <p className="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">
            <History className="size-3.5" aria-hidden />
            {t('Activity log')}
          </p>
          <ol className="mt-3 divide-y divide-foreground/10 border-y border-foreground/15">
            {enterpriseHeroActivity.map((entry, index) => (
              <li
                key={entry.action}
                className="product-hero-rise grid grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] items-baseline gap-3 py-2.5"
                style={riseStyle(820 + index * 120)}
              >
                <span className="truncate text-[12px] font-medium text-foreground/85">{t(entry.action)}</span>
                <span className="truncate text-end text-[11px] text-muted-foreground">{t(entry.detail)}</span>
              </li>
            ))}
          </ol>
        </div>

        <ul className="mt-7 flex flex-wrap gap-2" aria-label={t('Compliance')}>
          {enterpriseComplianceFrameworks.map((framework, index) => (
            <li
              key={framework.name}
              className="product-hero-rise rounded-full border border-[rgb(var(--tone-rgb)/0.4)] bg-[rgb(var(--tone-rgb)/0.08)] px-3 py-1 text-[11px] font-medium text-foreground"
              style={riseStyle(1500 + index * 90)}
            >
              {framework.name}
            </li>
          ))}
        </ul>
      </ProductVisualAura>

      <ArtChip className="end-0 top-0 sm:-end-8" delayMs={1100} floatDelayMs={200}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Headphones} />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('24/7 support on Slack')}</p>
        </div>
      </ArtChip>
      <ArtChip className="bottom-0 start-0 sm:-start-8" delayMs={1700} floatDelayMs={900}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Server} tone="secondary" />
          <p className="text-[11px] font-medium leading-4 text-foreground">{t('Cloud or self-hosted')}</p>
        </div>
      </ArtChip>
    </div>
  )
}
