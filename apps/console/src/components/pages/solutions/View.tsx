import { Link } from '@tanstack/react-router'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import {
  AppwriteMark,
  ComparisonHeading,
  ComparisonHeroBackdrop,
  ComparisonHeroTitle,
  ComparisonSection,
  comparisonHeroCopyClassName,
  comparisonHeroGridClassName,
} from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductToneBackdrop } from '@/components/pages/products/_components/ProductTone'
import { HeroVisual } from '@/components/pages/solutions/HeroVisual'
import {
  CapabilityGroups,
  CapabilityPathSection,
  CapabilityStages,
  Consolidation,
  SolutionLogoCloud,
  ValueProps,
} from '@/components/pages/solutions/Sections'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { productToneAttrs } from '@/lib/products/theme'
import type { SolutionCta, SolutionPageContent } from '@/lib/solutions/content'
import { cn } from '@/lib/utils'

function SolutionCtaButton({
  cta,
  variant,
}: {
  cta: SolutionCta
  variant: 'brandCta' | 'outline'
}) {
  const t = useT()
  const className = cn('h-10 text-[14px]', variant === 'outline' && 'bg-background/60')

  if (cta.kind === 'sales') {
    return (
      <Button variant={variant} size="lg" className={className} asChild>
        <ContactSalesLink {...analyticsAttrs(cta.analytics)}>{t(cta.label)}</ContactSalesLink>
      </Button>
    )
  }

  if (cta.kind === 'pricing') {
    return (
      <Button variant={variant} size="lg" className={className} asChild>
        <Link to="/pricing" {...analyticsAttrs(cta.analytics)}>
          {t(cta.label)}
        </Link>
      </Button>
    )
  }

  return (
    <Button variant={variant} size="lg" className={className} asChild>
      <Link to="/sign-up" search={{ redirect: '/' }} {...analyticsAttrs(cta.analytics)}>
        {t(cta.label)}
      </Link>
    </Button>
  )
}

function StatsRow({ stats }: { stats: SolutionPageContent['stats'] }) {
  const t = useT()
  return (
    <dl className="mx-auto grid max-w-7xl grid-cols-2 gap-x-6 gap-y-8 px-4 pb-14 sm:grid-cols-3 sm:px-6 lg:grid-cols-5">
      {stats.map((stat, index) => (
        <div
          key={stat.label}
          className="product-hero-rise relative flex flex-col-reverse gap-2 border-t border-foreground/15 pt-4"
          style={riseStyle(400 + index * 80)}
        >
          <span className="absolute -top-px start-0 h-px w-10 bg-[var(--tone-ink)]" aria-hidden />
          <dt className="text-[12px] leading-5 text-muted-foreground">{t(stat.label)}</dt>
          <dd className="font-aeonik-pro text-[32px] leading-none tracking-tight text-foreground sm:text-[36px]">
            {stat.value}
          </dd>
        </div>
      ))}
    </dl>
  )
}

export function View({ page }: { page: SolutionPageContent }) {
  const t = useT()

  return (
    <div
      className="relative min-w-0 overflow-x-clip bg-background"
      {...productToneAttrs({ tone: page.tone, secondaryTone: page.secondaryTone })}
    >
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={cn(comparisonHeroGridClassName, 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]')}>
          <div className={comparisonHeroCopyClassName}>
            <div className="inline-flex items-center gap-2 rounded-full border border-border bg-background/75 py-1 ps-1 pe-3 text-[12px] shadow-sm backdrop-blur-sm">
              <span className="flex size-6 items-center justify-center rounded-full bg-[var(--brand-cta)]/10">
                <AppwriteMark className="size-3.5" />
              </span>
              <span className="font-medium text-foreground">Appwrite</span>
              <span className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">
                {t(page.hero.eyebrow)}
              </span>
            </div>
            <ComparisonHeroTitle className="mt-7" title={page.hero.title} accent={page.hero.accent} />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t(page.hero.description)}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <SolutionCtaButton cta={page.primaryCta} variant="brandCta" />
              <SolutionCtaButton cta={page.secondaryCta} variant="outline" />
            </div>
          </div>
          <div className="min-w-0">
            <HeroVisual hero={page.hero} />
          </div>
        </div>
        <div className="relative z-[1]">
          <StatsRow stats={page.stats} />
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-60" aria-hidden />}
      >
        <ComparisonHeading
          align="center"
          eyebrow={page.customers.eyebrow}
          title={page.customers.title}
          description={page.customers.description}
        />
        <div className="mt-12">
          <SolutionLogoCloud />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<ProductToneBackdrop variant="section" side="start" />}>
        <div className="grid min-w-0 gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.3fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow={page.why.eyebrow}
            title={page.why.title}
            description={page.why.description}
          />
          <ValueProps items={page.why.items} />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<ProductToneBackdrop variant="section" side="end" />}>
        <ComparisonHeading
          eyebrow={page.platform.eyebrow}
          title={page.platform.title}
          description={page.platform.description}
        />
        <div className="mt-14">
          <Consolidation platformLabel={page.platform.platformLabel} outcomes={page.platform.outcomes} />
        </div>
      </ComparisonSection>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-60" aria-hidden />}
      >
        <ComparisonHeading
          eyebrow={page.capabilities.eyebrow}
          title={page.capabilities.title}
          description={page.capabilities.description}
        />
        <div className="mt-14">
          {page.capabilities.layout === 'stages' ? (
            <CapabilityStages groups={page.capabilities.groups} />
          ) : page.capabilities.layout === 'path' ? (
            <CapabilityPathSection groups={page.capabilities.groups} />
          ) : (
            <CapabilityGroups groups={page.capabilities.groups} />
          )}
        </div>
      </ComparisonSection>

      <MarketingFaqSection
        title={t(page.faq.title)}
        description={t(page.faq.description)}
        items={[...page.faq.items]}
      />

      <section className="relative border-t border-border px-4 py-16 sm:px-6 sm:py-24">
        <div className="relative isolate mx-auto max-w-5xl overflow-hidden rounded-3xl border border-border bg-card/40 px-6 py-14 text-center sm:px-12 sm:py-20">
          <ProductToneBackdrop variant="cta" />
          <div className="product-tone-hairline absolute inset-x-12 top-0 h-px" aria-hidden />
          <div className="relative z-[1]">
            <div className="flex justify-center">
              <span className="relative flex size-14 shrink-0 items-center justify-center rounded-2xl border border-border bg-gradient-to-b from-card to-muted/40 shadow-sm dark:from-muted/30 dark:to-background">
                <AppwriteMark className="size-6" />
              </span>
            </div>
            <h2 className="mx-auto mt-7 max-w-2xl text-balance font-aeonik-pro text-[32px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
              {t(page.closing.title)}
              <span className="text-[var(--brand-cta)]">_</span>
            </h2>
            <p className="mx-auto mt-4 max-w-xl text-[15px] leading-7 text-muted-foreground">
              {t(page.closing.description)}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2">
              <SolutionCtaButton cta={page.primaryCta} variant="brandCta" />
              <SolutionCtaButton cta={page.secondaryCta} variant="outline" />
            </div>
          </div>
        </div>
      </section>
    </div>
  )
}
