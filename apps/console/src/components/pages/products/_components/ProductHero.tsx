import type { ComponentType, ReactNode } from 'react'
import type { MarketingStatItem } from '@/components/pages/marketing/MarketingSections'
import { ProductToneBackdrop } from '@/components/pages/products/_components/ProductTone'
import { useT } from '@/lib/i18n/translate'
import type { ProductHeroLayout } from '@/lib/products/theme'
import { cn } from '@/lib/utils'

type ProductHeroProps = {
  layout: ProductHeroLayout
  badge: ReactNode
  title: string
  description: string
  art?: ComponentType
  /** Decorative layer around the copy and art (centered layout only). */
  scatter?: ComponentType
  stats?: MarketingStatItem[]
  footer?: ReactNode
  children: ReactNode
}

export function ProductHero({
  layout,
  badge,
  title,
  description,
  art: Art,
  scatter: Scatter,
  stats,
  footer,
  children,
}: ProductHeroProps) {
  const t = useT()
  const split = layout === 'split' && Art

  const heading = (
    <h1
      className={cn(
        'font-aeonik-pro text-balance font-normal tracking-tight text-foreground',
        'text-[36px] sm:text-[48px]',
        split ? 'lg:text-[52px]' : 'mx-auto max-w-4xl lg:text-[60px]',
        'leading-none',
      )}
    >
      {t(title)}
      <span className="text-[var(--brand-cta)]">_</span>
    </h1>
  )

  const intro = (
    <div className={cn(split ? 'text-center lg:text-start' : 'text-center')}>
      <div className={cn('flex justify-center', split && 'lg:justify-start')}>
        {badge}
      </div>
      <div className="mt-7">{heading}</div>
      <p
        className={cn(
          'mt-5 max-w-2xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8',
          split ? 'mx-auto lg:mx-0 lg:max-w-xl' : 'mx-auto',
        )}
      >
        {t(description)}
      </p>
      <div
        className={cn(
          'mt-8 flex flex-wrap items-center justify-center gap-2',
          split && 'lg:justify-start',
        )}
      >
        {children}
      </div>
    </div>
  )

  return (
    <section className="relative isolate overflow-hidden border-b border-border bg-background">
      <ProductToneBackdrop variant="hero" />
      <div className="relative z-[1] mx-auto max-w-7xl px-4 pb-14 pt-14 sm:px-6 sm:pb-16 sm:pt-20 lg:pt-24">
        {split ? (
          <div className="grid grid-cols-1 items-center gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:gap-14">
            {intro}
            <div className="relative mx-auto w-full max-w-xl lg:max-w-none">
              <Art />
            </div>
          </div>
        ) : (
          <div className="relative">
            {Scatter ? <Scatter /> : null}
            <div className="relative z-[1]">{intro}</div>
            {Art ? (
              <div className="relative z-[1] mx-auto mt-14 w-full max-w-5xl sm:mt-16">
                <Art />
              </div>
            ) : null}
          </div>
        )}

        {stats?.length ? <ProductHeroStats items={stats} /> : null}
        {footer}
      </div>
      <div
        className="product-tone-hairline absolute inset-x-0 bottom-0 h-px opacity-50"
        aria-hidden
      />
    </section>
  )
}

function ProductHeroStats({ items }: { items: MarketingStatItem[] }) {
  const t = useT()
  const odd = items.length % 2 === 1

  return (
    <dl
      className={cn(
        'mt-14 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-border/70 bg-border/70 sm:mt-16',
        items.length === 5 && 'lg:grid-cols-5',
        items.length === 4 && 'lg:grid-cols-4',
        items.length === 3 && 'lg:grid-cols-3',
      )}
    >
      {items.map((item, index) => (
        <div
          key={item.label}
          className={cn(
            'relative flex flex-col-reverse justify-end gap-1 bg-background/85 px-5 py-5 sm:px-6',
            odd && index === items.length - 1 && 'col-span-2 lg:col-span-1',
          )}
        >
          <span
            className="absolute start-5 top-0 h-px w-8 bg-[var(--tone-ink)] opacity-70 sm:start-6"
            aria-hidden
          />
          <dt className="text-[12px] leading-5 text-muted-foreground sm:text-[13px]">
            {t(item.label)}
          </dt>
          <dd className="font-aeonik-pro text-[22px] font-normal tracking-tight text-foreground tabular-nums sm:text-[26px]">
            <bdi>{item.value}</bdi>
          </dd>
        </div>
      ))}
    </dl>
  )
}
