import { ArrowUpRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { SectionBrandLight, SectionDottedBackground, SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { useT } from '@/lib/i18n/translate'
import type { ProductFeatureContent } from '@/lib/products/features/types'
import { cn } from '@/lib/utils'

const productFeatureDocsLinkClassName =
  'inline-flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground transition-colors hover:text-foreground'

type ProductFeatureSectionProps = {
  feature: ProductFeatureContent
  visual?: ReactNode
  index: number
  companion?: ReactNode
}

export function ProductFeatureSection({
  feature,
  visual,
  index,
  companion,
}: ProductFeatureSectionProps) {
  const t = useT()
  const stacked = feature.layout === 'stacked'
  const centered = stacked && feature.centered
  const hideVisual = feature.hideVisual
  const reversed = !stacked && index % 2 === 1
  const muted = index % 2 === 1

  const hideDocsLink = feature.hideDocsLink
  const flushBottom = feature.flushBottom

  const docsLink = (
    <DocsRouteLink href={feature.docsHref} className={productFeatureDocsLinkClassName}>
      {t(feature.docsLabel)}
      <ArrowUpRight className="size-3.5" aria-hidden />
    </DocsRouteLink>
  )

  return (
    <section
      className={cn(
        'relative isolate border-b border-border',
        flushBottom ? 'pt-16 pb-0 sm:pt-20' : 'py-16 sm:py-20',
        muted && 'bg-muted/15',
      )}
    >
      {muted ? <SectionSoftLight tone="purple" position="left" align="top" /> : null}
      {feature.brandLight ? (
        <SectionBrandLight tone={feature.brandLight} position={feature.brandLightPosition ?? 'top'} />
      ) : null}
      {feature.dottedBackground ? <SectionDottedBackground /> : null}
      <div className="relative z-[1] mx-auto max-w-7xl px-4 sm:px-6">
        {stacked ? (
          <>
            <div className={cn('mx-auto text-center', centered ? 'max-w-3xl' : 'max-w-3xl')}>
              <h2 className="font-aeonik-pro text-balance text-[28px] font-normal leading-tight tracking-tight text-foreground sm:text-[32px]">
                {t(feature.title)}
              </h2>
              <p className="mt-4 text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
                {t(feature.description)}
              </p>
              {!hideVisual && !companion ? <div className="mt-6">{docsLink}</div> : null}
            </div>

            {companion && !flushBottom ? (
              <div
                className={cn(
                  'relative z-[1] mx-auto mt-10 sm:mt-12',
                  feature.brandLight || feature.wideCompanion
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
              <div className="mt-8 text-center">{docsLink}</div>
            ) : null}
          </>
        ) : null}

        {!stacked || (!hideVisual && visual) ? (
          <div
            className={cn(
              stacked
                ? cn(
                    'group/visual relative mt-10 overflow-visible sm:mt-12',
                    centered && 'mx-auto max-w-lg',
                    hideVisual && 'hidden',
                  )
                : cn(
                    'grid items-center gap-10 lg:grid-cols-2 lg:gap-14 xl:gap-16',
                    reversed && '[&>*:first-child]:lg:order-2 [&>*:last-child]:lg:order-1',
                  ),
            )}
          >
            {!stacked ? (
              <div className={cn(reversed ? 'lg:ps-4' : 'lg:pe-4')}>
                <h2 className="font-aeonik-pro text-balance text-[28px] font-normal leading-tight tracking-tight text-foreground sm:text-[32px]">
                  {t(feature.title)}
                </h2>
                <p className="mt-4 max-w-xl text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
                  {t(feature.description)}
                </p>
                {companion}
                <div className="mt-6">{docsLink}</div>
              </div>
            ) : null}

            {visual ? (
              <div
                className={cn(!stacked && 'group/visual relative min-h-[18rem] overflow-visible')}
              >
                {visual}
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
