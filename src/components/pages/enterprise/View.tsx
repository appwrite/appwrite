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
import { EnterpriseContactCta } from '@/components/pages/enterprise/EnterpriseContactCta'
import { EnterpriseHeroVisual } from '@/components/pages/enterprise/EnterpriseHeroVisual'
import {
  EnterpriseCapabilityGroups,
  EnterpriseConsolidation,
  EnterpriseDeploymentModels,
  EnterpriseLogoCloud,
  EnterpriseSharedBenefits,
  EnterpriseValueProps,
} from '@/components/pages/enterprise/EnterpriseSections'
import { SecurityComplianceSection } from '@/components/pages/enterprise/SecurityComplianceSection'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductToneBackdrop } from '@/components/pages/products/_components/ProductTone'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  enterpriseDeploymentSection,
  enterpriseFaqItems,
  enterpriseHero,
  enterprisePlatformSection,
  enterpriseStats,
} from '@/lib/enterprise/content'
import { useT } from '@/lib/i18n/translate'
import { productToneAttrs } from '@/lib/products/theme'
import { cn } from '@/lib/utils'

function EnterprisePill() {
  const t = useT()
  return (
    <div className="inline-flex items-center gap-2 rounded-full border border-border bg-background/75 py-1 ps-1 pe-3 text-[12px] shadow-sm backdrop-blur-sm">
      <span className="flex size-6 items-center justify-center rounded-full bg-[var(--brand-cta)]/10">
        <AppwriteMark className="size-3.5" />
      </span>
      <span className="font-medium text-foreground">Appwrite</span>
      <span className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">{t(enterpriseHero.eyebrow)}</span>
    </div>
  )
}

function EnterpriseStatsRow() {
  const t = useT()
  return (
    <dl className="mx-auto grid max-w-7xl grid-cols-2 gap-x-6 gap-y-8 px-4 pb-14 sm:grid-cols-3 sm:px-6 lg:grid-cols-5">
      {enterpriseStats.map((stat, index) => (
        <div
          key={stat.label}
          className="product-hero-rise relative flex flex-col-reverse gap-2 border-t border-foreground/15 pt-4"
          style={riseStyle(400 + index * 80)}
        >
          <span className="absolute -top-px start-0 h-px w-10 bg-[var(--tone-ink)]" aria-hidden />
          <dt className="text-[12px] leading-5 text-muted-foreground">{t(stat.label)}</dt>
          <dd className="font-aeonik-pro text-[32px] leading-none tracking-tight text-foreground sm:text-[36px]">{stat.value}</dd>
        </div>
      ))}
    </dl>
  )
}

export function View() {
  const t = useT()

  return (
    <div
      className="relative min-w-0 overflow-x-clip bg-background"
      {...productToneAttrs({ tone: 'purple', secondaryTone: 'mint' })}
    >
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div className={cn(comparisonHeroGridClassName, 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]')}>
          <div className={comparisonHeroCopyClassName}>
            <EnterprisePill />
            <ComparisonHeroTitle className="mt-7" title={enterpriseHero.title} accent={enterpriseHero.accent} />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t(enterpriseHero.description)}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
                <ContactSalesLink {...analyticsAttrs('enterprise-contact-sales')}>{t('Contact sales')}</ContactSalesLink>
              </Button>
              <Button variant="outline" size="lg" className="h-10 bg-background/60 text-[14px]" asChild>
                <Link to="/pricing" {...analyticsAttrs('enterprise-compare-plans')}>
                  {t('Compare plans')}
                </Link>
              </Button>
            </div>
          </div>
          <div className="min-w-0">
            <EnterpriseHeroVisual />
          </div>
        </div>
        <div className="relative z-[1]">
          <EnterpriseStatsRow />
        </div>
      </section>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-60" aria-hidden />}
      >
        <ComparisonHeading
          align="center"
          eyebrow="Customers"
          title="Trusted by teams at scale"
          description="From global enterprises to fast-growing product companies, teams rely on Appwrite to ship secure applications."
        />
        <div className="mt-14">
          <EnterpriseLogoCloud />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<ProductToneBackdrop variant="section" side="start" />}>
        <div className="grid min-w-0 gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.3fr)] lg:items-center lg:gap-16">
          <ComparisonHeading
            eyebrow="Why Appwrite"
            title="Why enterprise teams choose Appwrite"
            description="Give your developers a complete backend platform so they can focus on product innovation instead of infrastructure glue code."
          />
          <EnterpriseValueProps />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<ProductToneBackdrop variant="section" side="end" />}>
        <ComparisonHeading
          eyebrow="One platform"
          title={enterprisePlatformSection.title}
          description={enterprisePlatformSection.description}
        />
        <div className="mt-14">
          <EnterpriseConsolidation />
        </div>
      </ComparisonSection>

      <ComparisonSection
        backdrop={<div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-60" aria-hidden />}
      >
        <ComparisonHeading
          eyebrow="Enterprise plan"
          title="Everything in Pro, plus enterprise capabilities"
          description="Operational and pricing features for teams that need more than standard Pro limits."
        />
        <div className="mt-14">
          <EnterpriseCapabilityGroups />
        </div>
      </ComparisonSection>

      <ComparisonSection backdrop={<ProductToneBackdrop variant="section" side="start" />}>
        <ComparisonHeading
          align="center"
          eyebrow="Deployment"
          title={enterpriseDeploymentSection.title}
          description={enterpriseDeploymentSection.description}
        />
        <div className="mt-14">
          <EnterpriseDeploymentModels />
        </div>
        <div className="mt-16 border-t border-foreground/10 pt-12">
          <EnterpriseSharedBenefits title={enterpriseDeploymentSection.sharedBenefitsTitle} />
        </div>
      </ComparisonSection>

      <SecurityComplianceSection />

      <MarketingFaqSection
        title={t('Enterprise FAQ')}
        description={t('Common questions about pricing, support, compliance, and getting started.')}
        items={enterpriseFaqItems}
      />

      <EnterpriseContactCta />
    </div>
  )
}
