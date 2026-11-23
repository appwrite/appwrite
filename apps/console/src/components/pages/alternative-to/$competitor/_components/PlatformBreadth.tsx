import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { PLATFORM_COVERAGE, PLATFORM_PRODUCTS } from '@/lib/alternatives/platform'
import { ALTERNATIVE_REGISTRY } from '@/lib/alternatives/registry'
import type { AlternativeId } from '@/lib/alternatives/types'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
import { cn } from '@/lib/utils'
import { ComparisonHeading, ComparisonSection, CompetitorMonogram } from './ComparisonParts'

/**
 * Every Appwrite product laid out in the open, with what the other platform covers.
 * Products the competitor does not offer glow in the page tone.
 */
export function PlatformBreadthSection({
  id,
  eyebrow = 'One platform',
  title,
  description,
}: {
  id: AlternativeId
  eyebrow?: string
  title: string
  description?: string
}) {
  const t = useT()
  const meta = ALTERNATIVE_REGISTRY[id]
  const coverage = PLATFORM_COVERAGE[id]
  const missing = PLATFORM_PRODUCTS.filter((product) => coverage[product.id] === 'no').length

  return (
    <ComparisonSection
      backdrop={
        <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
          <div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" />
          <div className="product-tone-glow absolute -end-[20%] top-1/2 h-[700px] w-[1000px] -translate-y-1/2" />
        </div>
      }
    >
      <div className="mx-auto max-w-3xl text-center">
        <ComparisonHeading eyebrow={eyebrow} title={title} description={description} align="center" />
      </div>

      <dl className="mx-auto mt-8 grid max-w-sm grid-cols-2 gap-x-6 sm:mt-10 sm:max-w-md sm:gap-x-14">
        <div className="flex flex-col items-center text-center">
          <dd className="font-aeonik-pro text-[40px] leading-none tracking-tight text-foreground tabular-nums sm:text-[52px] lg:text-[64px]">
            {PLATFORM_PRODUCTS.length}
          </dd>
          <dt className="mt-2 flex min-h-10 max-w-[9.5rem] items-start justify-center text-[13px] leading-5 text-muted-foreground">
            {t('Appwrite products in one project')}
          </dt>
        </div>
        <div className="flex flex-col items-center text-center">
          <dd className="font-aeonik-pro text-[40px] leading-none tracking-tight text-[var(--tone-ink)] tabular-nums sm:text-[52px] lg:text-[64px]">
            {missing}
          </dd>
          <dt className="mt-2 flex min-h-10 max-w-[9.5rem] items-start justify-center text-[13px] leading-5 text-muted-foreground">
            {t(`Not offered by ${meta.name}`)}
          </dt>
        </div>
      </dl>

      <ul className="mt-12 grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 sm:gap-x-6 lg:mt-14 lg:grid-cols-5 lg:gap-y-10">
        {PLATFORM_PRODUCTS.map((product, index) => {
          const nav = PRODUCT_NAV_REGISTRY[product.id]
          const Icon = nav.icon
          const status = coverage[product.id]
          const exclusive = status === 'no'
          return (
            <li key={product.id} className="product-hero-rise text-center" style={riseStyle(80 + index * 55)}>
              <MarketingSiteLink href={nav.href} className="link-unstyled group inline-flex flex-col items-center">
                <span className="relative flex size-11 items-center justify-center sm:size-12">
                  {exclusive ? (
                    <span
                      className="absolute -inset-3 rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.35),transparent_70%)]"
                      aria-hidden
                    />
                  ) : null}
                  <span
                    className={cn(
                      'relative flex size-11 items-center justify-center rounded-2xl border transition-transform duration-300 group-hover:-translate-y-0.5 sm:size-12',
                      exclusive
                        ? 'border-[rgb(var(--tone-rgb)/0.5)] bg-background text-[var(--tone-ink)] shadow-[0_10px_30px_-12px_rgb(var(--tone-rgb)/0.6)] dark:bg-card'
                        : 'border-border bg-muted/30 text-muted-foreground',
                    )}
                  >
                    <Icon className="size-5" strokeWidth={1.75} aria-hidden />
                  </span>
                </span>
                <span className="mt-3 block text-[13px] font-medium text-foreground transition-colors group-hover:text-[var(--tone-ink)] sm:text-[14px]">
                  {t(product.name)}
                </span>
                {exclusive || status === 'partial' ? (
                  <span
                    className={cn(
                      'mt-2 inline-flex items-center gap-1.5 text-[11px] font-medium',
                      exclusive ? 'text-[var(--tone-ink)]' : 'text-muted-foreground',
                    )}
                  >
                    {exclusive ? (
                      <span className="size-1.5 rounded-full bg-[var(--tone-ink)]" aria-hidden />
                    ) : (
                      <CompetitorMonogram name={meta.name} className="size-4 text-[9px]" />
                    )}
                    {exclusive ? t('Only on Appwrite') : t('Limited')}
                  </span>
                ) : null}
              </MarketingSiteLink>
            </li>
          )
        })}
      </ul>
    </ComparisonSection>
  )
}
