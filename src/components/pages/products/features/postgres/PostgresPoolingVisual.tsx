import { Waypoints } from 'lucide-react'
import { Fragment, type CSSProperties } from 'react'
import {
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const CALLERS = ['fn-01', 'fn-02', 'fn-03'] as const

export function PostgresPoolingVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] py-6 text-start">
      <div
        className="pointer-events-none absolute left-1/2 top-1/2 size-[240px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.18),transparent)] blur-2xl"
        aria-hidden
      />

      <div
        className="grid grid-cols-[auto_minmax(2.75rem,1fr)_auto] gap-x-0 gap-y-2"
        style={{ gridTemplateRows: 'auto repeat(3, minmax(1.75rem, 1fr))' }}
      >
        <p className="col-span-2 mb-1 self-end text-[10px] uppercase tracking-wider text-muted-foreground">
          {t('Clients')}
        </p>

        {CALLERS.map((caller, index) => (
          <Fragment key={caller}>
            <div
              className="product-hero-rise col-start-1 flex h-7 w-[7.25rem] items-center gap-1.5 self-center rounded-lg border border-border bg-background/95 px-2 dark:bg-card"
              style={{ ...riseStyle(140 + index * 110), gridRow: index + 2 }}
            >
              <span
                className="size-1.5 shrink-0 rounded-full bg-[var(--tone-ink)]"
                aria-hidden
              />
              <span
                dir="ltr"
                className="truncate font-mono text-[10.5px] text-foreground"
              >
                {caller}
              </span>
            </div>
            <div
              className="relative col-start-2 self-stretch"
              style={{ gridRow: index + 2 }}
            >
              <ArtConnector
                travel={index === 1}
                travelDelayMs={400}
                className={
                  index === 1
                    ? 'absolute inset-x-0 top-1/2 border-[rgb(var(--tone-rgb)/0.5)]'
                    : 'absolute inset-x-0 top-1/2'
                }
              />
            </div>
          </Fragment>
        ))}

        <ArtPanel
          className="relative z-[1] col-start-3 row-start-2 row-span-3 h-full w-[11.5rem] self-stretch sm:w-[200px]"
          innerClassName="flex h-full flex-col justify-center product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] px-3 py-3 dark:border-[rgb(var(--tone-rgb)/0.45)]"
          delayMs={80}
        >
          <div className="flex items-center gap-2">
            <ArtIconBadge icon={Waypoints} />
            <p className="min-w-0 text-[12px] font-semibold text-foreground">
              {t('Pooler')}
            </p>
          </div>

          <div className="mt-3 border-t border-border pt-2.5">
            <div className="flex items-center justify-between gap-2 text-[10px]">
              <span className="text-muted-foreground">{t('Pool')}</span>
              <span
                dir="ltr"
                className="font-mono tabular-nums text-foreground"
              >
                18 / 25
              </span>
            </div>
            <div className="mt-1 h-1 overflow-hidden rounded-full bg-foreground/[0.07]">
              <div className="h-full w-[72%]">
                <div
                  className="product-hero-fill h-full w-full rounded-full bg-[var(--tone-ink)]"
                  style={{ '--fill-delay': '600ms' } as CSSProperties}
                />
              </div>
            </div>
          </div>
        </ArtPanel>
      </div>
    </div>
  )
}
