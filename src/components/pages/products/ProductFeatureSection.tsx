import { ArrowUpRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { SectionDottedBackground } from '@/components/pages/home/HomeSoftLights'
import {
  ProductToneBackdrop,
  ProductVisualAura,
} from '@/components/pages/products/_components/ProductTone'
import { useRevealOnScroll } from '@/components/pages/products/_components/useRevealOnScroll'
import { useT } from '@/lib/i18n/translate'
import type { ProductFeatureContent } from '@/lib/products/features/types'
import type { ProductFeatureStyle } from '@/lib/products/theme'
import { cn } from '@/lib/utils'

const productFeatureDocsLinkClassName =
  'group/docs inline-flex items-center gap-1.5 text-[13px] font-medium text-foreground/80 transition-colors hover:text-[var(--tone-ink)]'

type ProductFeatureSectionProps = {
  feature: ProductFeatureContent
  visual?: ReactNode
  index: number
  companion?: ReactNode
  featureStyle: ProductFeatureStyle
}

export function ProductFeatureSection({
  feature,
  visual,
  index,
  companion,
  featureStyle,
}: ProductFeatureSectionProps) {
  const t = useT()
  const { ref, reveal } = useRevealOnScroll<HTMLElement>()
  const stacked = feature.layout === 'stacked'
  const rail = featureStyle === 'rail' && !feature.breakRail
  const aligned = featureStyle === 'aligned'
  const centerText = stacked && !rail
  const centered = stacked && feature.centered
  const hideVisual = feature.hideVisual
  const reversed = featureStyle === 'alternate' && !stacked && index % 2 === 1
  const visualSide = reversed ? 'start' : 'end'

  const hideDocsLink = feature.hideDocsLink
  const flushBottom = feature.flushBottom

  const docsLinks = [
    { href: feature.docsHref, label: feature.docsLabel },
    ...(feature.extraDocsLinks ?? []),
  ]

  const docsLink = (
    <span className="inline-flex flex-wrap items-center justify-center gap-x-6 gap-y-2">
      {docsLinks.map((link) => (
        <DocsRouteLink key={link.href} href={link.href} className={productFeatureDocsLinkClassName}>
          {t(link.label)}
          <ArrowUpRight
            className="size-3.5 transition-transform group-hover/docs:-translate-y-0.5 group-hover/docs:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover/docs:-translate-x-0.5"
            aria-hidden
          />
        </DocsRouteLink>
      ))}
    </span>
  )

  const title = (
    <h2 className="relative font-aeonik-pro text-balance text-[28px] font-normal tracking-tight text-foreground sm:text-[36px] leading-none">
      {rail ? (
        <span
          className="absolute -start-16 top-[0.55em] hidden size-6 -translate-y-1/2 items-center justify-center rounded-full border border-[rgb(var(--tone-rgb)/0.5)] bg-background lg:flex"
          aria-hidden
        >
          <span className="size-2 rounded-full bg-[var(--tone-ink)] shadow-[0_0_12px_rgb(var(--tone-rgb)/0.8)]" />
        </span>
      ) : null}
      {t(feature.title)}
    </h2>
  )

  const stage = (content: ReactNode) => (
    <ProductVisualAura side={visualSide}>{content}</ProductVisualAura>
  )

  const splitGridClassName = cn(
    'grid items-center gap-10 lg:gap-14 xl:gap-16',
    featureStyle === 'alternate' && 'lg:grid-cols-2',
    aligned && 'lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]',
    rail && 'lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]',
    reversed && '[&>*:first-child]:lg:order-2 [&>*:last-child]:lg:order-1',
  )

  return (
    <section
      ref={ref}
      data-reveal={reveal}
      className={cn(
        'relative isolate overflow-x-clip border-b border-border',
        flushBottom ? 'pb-0 pt-16 sm:pt-24' : 'py-16 sm:py-24',
      )}
    >
      {!stacked && index % 2 === 1 ? (
        <ProductToneBackdrop variant="section" side={visualSide} />
      ) : null}
      {feature.brandLight ? (
        <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
          <div
            className={cn(
              'product-tone-glow absolute left-1/2 h-[600px] w-[min(1200px,140%)] -translate-x-1/2',
              feature.brandLightPosition === 'bottom' ? 'bottom-[-34%]' : 'top-[-34%]',
            )}
          />
        </div>
      ) : null}
      {feature.dottedBackground ? <SectionDottedBackground className="product-dot-grid-fade" /> : null}
      {rail ? (
        <div className="pointer-events-none absolute inset-0 z-0 hidden lg:block" aria-hidden>
          <div className="relative mx-auto h-full max-w-7xl px-6">
            <div className="absolute inset-y-0 start-[calc(1.5rem+12px)] w-px bg-border" />
          </div>
        </div>
      ) : null}

      <div className={cn('relative z-[1] mx-auto max-w-7xl px-4 sm:px-6', rail && 'lg:ps-[5.5rem]')}>
        {stacked ? (
          <>
            <div className={cn('max-w-3xl', centerText ? 'mx-auto text-center' : 'text-start')}>
              {title}
              <p className="mt-4 text-[15px] leading-7 text-muted-foreground sm:text-[16px]">
                {t(feature.description)}
              </p>
              {!hideVisual && !companion ? <div className="mt-6">{docsLink}</div> : null}
            </div>

            {companion && !flushBottom ? (
              <div
                className={cn(
                  'relative z-[1] mt-10 sm:mt-12',
                  centerText && 'mx-auto',
                  feature.brandLight || feature.wideCompanion || rail
                    ? 'max-w-7xl'
                    : centered
                      ? 'max-w-5xl'
                      : 'max-w-4xl',
                )}
              >
                {companion}
              </div>
            ) : null}

            {!hideDocsLink && (hideVisual || companion) ? (
              <div className={cn('mt-8', centerText ? 'text-center' : 'text-start')}>{docsLink}</div>
            ) : null}
          </>
        ) : null}

        {stacked && !hideVisual && visual ? (
          <div
            className={cn(
              'group/visual relative mt-10 overflow-visible sm:mt-12',
              centered && 'mx-auto max-w-xl',
            )}
          >
            {stage(visual)}
          </div>
        ) : null}

        {!stacked ? (
          <div className={splitGridClassName}>
            <div className={cn(reversed ? 'lg:ps-4' : 'lg:pe-4')}>
              {title}
              <p className="mt-4 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px]">
                {t(feature.description)}
              </p>
              {companion}
              <div className="mt-7">{docsLink}</div>
            </div>

            {visual ? (
              <div className="group/visual relative min-h-[18rem] overflow-visible">
                {stage(visual)}
              </div>
            ) : null}
          </div>
        ) : null}
      </div>

      {stacked && companion && flushBottom ? (
        <div className="relative z-[1] mt-10 w-full sm:mt-12">{companion}</div>
      ) : null}
    </section>
  )
}
