import { AppwriteWordmark } from '@/components/global/shared/AppwriteWordmark'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import {
  ComparisonEyebrow,
  ComparisonSection,
} from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { Button } from '@/components/ui/button'
import { PLATFORM_PRODUCTS } from '@/lib/alternatives/platform'
import { analyticsAttrs } from '@/lib/analytics-actions'
import type { SecretVariantContent } from '@/lib/campaigns/secret/content'
import { useT } from '@/lib/i18n/translate'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
import { BrandTitle, SECRET_OUTLINE_BUTTON_CLASS, TryAppwriteButton } from './SecretParts'

/** The reveal: the fine print adds up to Appwrite, with the numbers and every product behind it. */
export function SecretReveal({ content }: { content: SecretVariantContent }) {
  const t = useT()
  const stats = [
    { value: String(PLATFORM_PRODUCTS.length), label: 'Products in one platform' },
    { value: '5', label: 'Database models' },
    { value: '13+', label: 'Function runtimes' },
    { value: MARKETING_SOCIAL_STATS.github.stat, label: 'GitHub stars' },
  ]

  return (
    <ComparisonSection
      id="the-secret"
      className="scroll-mt-16"
      backdrop={
        <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
          <div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" />
          <div className="product-tone-glow absolute left-1/2 top-0 h-[640px] w-[min(1100px,160%)] -translate-x-1/2 -translate-y-1/4" />
          <div className="product-tone2-glow absolute -bottom-[30%] left-1/2 h-[560px] w-[min(1000px,160%)] -translate-x-1/2" />
        </div>
      }
    >
      <div className="mx-auto max-w-4xl text-center">
        <div className="product-hero-rise flex justify-center" style={riseStyle(0)}>
          <ComparisonEyebrow>{t('The secret is out')}</ComparisonEyebrow>
        </div>
        <div className="product-hero-rise mt-8 flex justify-center" style={riseStyle(80)}>
          <AppwriteWordmark className="h-10 sm:h-14 lg:h-16" />
        </div>
        <h2
          className="product-hero-rise mt-10 text-balance font-aeonik-pro text-[30px] font-normal leading-[1.06] tracking-[-0.022em] text-foreground sm:text-[44px] lg:text-[56px]"
          style={riseStyle(160)}
        >
          <BrandTitle>{t(content.reveal.title)}</BrandTitle>
        </h2>
        <p
          className="product-hero-rise mx-auto mt-6 max-w-2xl text-pretty text-[15px] leading-7 text-muted-foreground sm:text-[17px] sm:leading-8"
          style={riseStyle(240)}
        >
          {t(content.reveal.description)}
        </p>
        <div className="product-hero-rise mt-8 flex flex-wrap items-center justify-center gap-2" style={riseStyle(300)}>
          <TryAppwriteButton />
          <Button variant="outline" size="lg" className={SECRET_OUTLINE_BUTTON_CLASS} asChild>
            <MarketingSiteLink href="/docs" {...analyticsAttrs('secret-read-docs')}>
              {t('Read the Appwrite docs')}
            </MarketingSiteLink>
          </Button>
        </div>
      </div>

      <dl className="mx-auto mt-14 grid max-w-4xl grid-cols-2 gap-x-6 gap-y-10 sm:mt-16 lg:grid-cols-4">
        {stats.map((stat, index) => (
          <div
            key={stat.label}
            className="product-hero-rise flex flex-col-reverse items-center gap-2 text-center"
            style={riseStyle(340 + index * 80)}
          >
            <dt className="text-[13px] text-muted-foreground">{t(stat.label)}</dt>
            <dd className="font-aeonik-pro text-[40px] leading-none tracking-tight text-foreground tabular-nums sm:text-[52px]">
              <bdi>{stat.value}</bdi>
            </dd>
          </div>
        ))}
      </dl>

      <ul className="mx-auto mt-14 grid max-w-5xl grid-cols-2 gap-x-4 gap-y-8 sm:mt-16 sm:grid-cols-3 sm:gap-x-6 lg:grid-cols-5 lg:gap-y-10">
        {PLATFORM_PRODUCTS.map((product, index) => {
          const nav = PRODUCT_NAV_REGISTRY[product.id]
          const Icon = nav.icon
          return (
            <li key={product.id} className="product-hero-rise text-center" style={riseStyle(420 + index * 45)}>
              <MarketingSiteLink href={nav.href} className="link-unstyled group inline-flex flex-col items-center">
                <span className="flex size-11 items-center justify-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.5)] bg-background text-[var(--tone-ink)] shadow-[0_10px_30px_-12px_rgb(var(--tone-rgb)/0.6)] transition-transform duration-300 group-hover:-translate-y-0.5 sm:size-12 dark:bg-card">
                  <Icon className="size-5" strokeWidth={1.75} aria-hidden />
                </span>
                <span className="mt-3 block text-[13px] font-medium text-foreground transition-colors group-hover:text-[var(--tone-ink)] sm:text-[14px]">
                  {t(product.name)}
                </span>
                <span className="mt-1 block max-w-[11rem] text-[12px] leading-5 text-muted-foreground">
                  {t(product.blurb)}
                </span>
              </MarketingSiteLink>
            </li>
          )
        })}
      </ul>
    </ComparisonSection>
  )
}
