import { Check, CircleArrowUp, Cloud, Server, Zap } from 'lucide-react'
import type { CSSProperties } from 'react'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Cells of the shared pool; indexes listed here belong to this project. */
const POOL_CELLS = 24
const OWN_CELLS = new Set([5, 6, 13])

const DEDICATED_SPECS = [
  { label: 'CPU', value: '2 vCPU', fill: 0.42 },
  { label: 'Memory', value: '4 GB', fill: 0.61 },
  { label: 'Connections', value: '48 / 200', fill: 0.24 },
] as const

const DEDICATED_HIGHLIGHTS = ['Isolated resources', 'Read replicas & HA', 'Point-in-time recovery'] as const

export function DatabasesServerlessVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-4">
      <ArtPanel className="relative z-[1] w-full sm:w-[290px]" innerClassName="p-3.5" delayMs={60} float>
        <div className="flex items-center gap-2.5">
          <ArtIconBadge icon={Cloud} tone="secondary" />
          <p className="text-[13px] font-semibold text-foreground">{t('Serverless')}</p>
          <Badge variant="info" className="ms-auto shrink-0 text-[10px]">
            {t('Default')}
          </Badge>
        </div>
        <p className="mt-2 text-[11px] leading-5 text-muted-foreground">
          {t('Shared pool. Fast to create, no capacity planning.')}
        </p>
        <p className="mt-3 text-[10px] font-medium text-muted-foreground">{t('Shared compute pool')}</p>
        <div className="mt-1.5 grid grid-cols-8 gap-1" aria-hidden>
          {Array.from({ length: POOL_CELLS }, (_, index) => (
            <span
              key={index}
              className={cn(
                'product-hero-rise aspect-square rounded-[4px]',
                OWN_CELLS.has(index)
                  ? 'bg-[rgb(var(--tone2-rgb)/0.55)] shadow-[0_0_10px_rgb(var(--tone2-rgb)/0.45)]'
                  : 'bg-foreground/[0.07]',
              )}
              style={riseStyle(200 + index * 25)}
            />
          ))}
        </div>
      </ArtPanel>

      <div className="relative h-16">
        <span
          className="absolute start-1/2 top-0 h-full border-s border-dashed border-foreground/20 sm:hidden"
          aria-hidden
        />
        <span
          className="absolute start-[27%] top-0 hidden h-1/2 border-s border-dashed border-foreground/20 sm:block"
          aria-hidden
        />
        <ArtConnector travel className="absolute start-[27%] end-[30%] top-1/2 hidden w-auto sm:block" />
        <span
          className="absolute end-[30%] top-1/2 hidden h-1/2 border-e border-dashed border-foreground/20 sm:block"
          aria-hidden
        />
        <ArtChip className="left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2" delayMs={700} floatDelayMs={400}>
          <span className="flex items-center gap-1.5 whitespace-nowrap text-[11px] font-medium text-foreground">
            <CircleArrowUp className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
            {t('Upgrade compute')}
          </span>
        </ArtChip>
      </div>

      <ArtPanel
        className="relative z-[1] w-full sm:ms-auto sm:w-[310px]"
        innerClassName="product-tone-shadow border-[rgb(var(--tone-rgb)/0.4)] p-3.5 dark:border-[rgb(var(--tone-rgb)/0.4)]"
        delayMs={450}
      >
        <div className="flex items-center gap-2.5">
          <ArtIconBadge icon={Server} />
          <p className="text-[13px] font-semibold text-foreground">{t('Dedicated')}</p>
          <Badge variant="success" className="ms-auto shrink-0 text-[10px]">
            {t('Production')}
          </Badge>
        </div>
        <p className="mt-2 text-[11px] leading-5 text-muted-foreground">
          {t('Isolated compute with replicas, HA, and PITR.')}
        </p>
        <div className="mt-3 space-y-2">
          {DEDICATED_SPECS.map((spec, index) => (
            <div key={spec.label}>
              <div className="flex items-center justify-between gap-2 text-[10px]">
                <span className="text-muted-foreground">{t(spec.label)}</span>
                <span dir="ltr" className="font-mono text-foreground">
                  {spec.value}
                </span>
              </div>
              <div className="mt-1 h-1 overflow-hidden rounded-full bg-foreground/[0.07]">
                <div className="h-full" style={{ width: `${spec.fill * 100}%` }}>
                  <div
                    className="product-hero-fill h-full w-full rounded-full bg-[var(--tone-ink)]"
                    style={{ '--fill-delay': `${700 + index * 150}ms` } as CSSProperties}
                  />
                </div>
              </div>
            </div>
          ))}
        </div>
        <ul className="mt-3 flex flex-wrap gap-1.5">
          {DEDICATED_HIGHLIGHTS.map((item) => (
            <li
              key={item}
              className="inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[10px] text-muted-foreground"
            >
              <Check className="size-2.5 text-[var(--tone-ink)]" strokeWidth={3} aria-hidden />
              {t(item)}
            </li>
          ))}
        </ul>
      </ArtPanel>

      <ArtChip className="bottom-[16%] start-0 hidden sm:block" delayMs={1000} floatDelayMs={1200}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Zap} tone="secondary" />
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Instant provisioning')}</p>
            <p className="text-[10px] text-muted-foreground">{t('Ideal for prototypes')}</p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
