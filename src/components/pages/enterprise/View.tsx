import { Link } from '@tanstack/react-router'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { SecurityComplianceSection } from '@/components/pages/enterprise/SecurityComplianceSection'
import { EnterpriseContactCta } from '@/components/pages/enterprise/EnterpriseContactCta'
import { HomeSoftLights, SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import { MarketingProductPills } from '@/components/pages/marketing/MarketingProductPills'
import {
  MarketingBentoFeatureCard,
  MarketingFeatureGrid,
  MarketingHeroSection,
  MarketingHeroStats,
  MarketingSectionHeading,
} from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
import { TrustedByLogo } from '@/components/global/shared/TrustedByLogo'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { allCustomerLogos } from '@/lib/home/customer-logos'
import {
  enterpriseDeploymentSection,
  enterpriseDeploymentOptions,
  enterpriseDeploymentSharedBenefits,
  enterpriseFaqItems,
  enterpriseHero,
  enterprisePlanCapabilities,
  enterprisePlatformSection,
  enterpriseStats,
  enterpriseValueProps,
} from '@/lib/enterprise/content'
import { useT } from '@/lib/i18n/translate'
import { marketingProductToolkit } from '@/lib/marketing/product-toolkit'

export function View() {
  const t = useT()

  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        eyebrow={enterpriseHero.eyebrow}
        title={enterpriseHero.title}
        description={enterpriseHero.description}
        gradientTitle
        wideFooter={enterpriseStats.length === 5}
        footer={<MarketingHeroStats items={[...enterpriseStats]} />}
      >
        <Button
          variant="brandCta"
          size="lg"
          className="h-10 text-[14px]"
          asChild
        >
          <ContactSalesLink {...analyticsAttrs('enterprise-contact-sales')}>
            {t('Contact sales')}
          </ContactSalesLink>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/pricing" {...analyticsAttrs('enterprise-compare-plans')}>
            {t('Compare plans')}
          </Link>
        </Button>
      </MarketingHeroSection>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={t('Why enterprise teams choose Appwrite') /* pragma: allowlist secret */}
            description={t(
              'Give your developers a complete backend platform so they can focus on product innovation instead of infrastructure glue code.',
            )}
            size="md"
          />
          <div className="mt-10">
            <MarketingFeatureGrid items={enterpriseValueProps} columns={4} />
          </div>
        </div>
      </section>

      <section className="border-b border-border bg-muted/20 py-14 sm:py-16">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={t('Trusted by teams at scale')}
            description={t(
              'From global enterprises to fast-growing product companies, teams rely on Appwrite to ship secure applications.', // pragma: allowlist secret
            )}
            size="md"
          />
          <div className="mt-10 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
            {allCustomerLogos.map((logo) => (
              <div
                key={logo.src}
                className="flex min-h-14 items-center justify-center rounded-lg border border-border bg-card/45 px-3 py-3"
              >
                <TrustedByLogo
                  src={logo.src}
                  alt={logo.alt}
                  width={logo.width}
                  height={logo.height}
                  mask={logo.mask}
                  maskSrc={logo.maskSrc}
                  inverseMask={logo.inverseMask}
                  interactive={false}
                  className={
                    logo.size === 'lg'
                      ? 'max-h-5 w-auto opacity-90 sm:max-h-6'
                      : 'max-h-4 w-auto opacity-90 sm:max-h-5'
                  }
                />
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="relative isolate overflow-hidden border-b border-border py-16 sm:py-20">
        <SectionSoftLight tone="purple" position="left" />
        <div className="relative z-[1] mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={enterprisePlatformSection.title}
            description={enterprisePlatformSection.description}
            size="md"
          />
          <div className="mt-10">
            <MarketingProductPills
              build={marketingProductToolkit.build}
              deploy={marketingProductToolkit.deploy}
              protect={marketingProductToolkit.protect}
            />
          </div>
        </div>
      </section>

      <section className="relative isolate overflow-hidden border-b border-border py-16 sm:py-20">
        <SectionSoftLight tone="teal" position="right" />
        <div className="relative z-[1] mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={t('Everything in Pro, plus enterprise capabilities')}
            description={t(
              'Operational and pricing features for teams that need more than standard Pro limits.',
            )}
            size="md"
          />
          <div className="mt-10">
            <MarketingFeatureGrid items={enterprisePlanCapabilities} columns={3} />
          </div>
        </div>
      </section>

      <section className="relative isolate overflow-hidden border-b border-border bg-muted/20 py-16 sm:py-20">
        <SectionSoftLight tone="orange" position="left" align="top" />
        <div className="relative z-[1] mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={enterpriseDeploymentSection.title}
            description={enterpriseDeploymentSection.description}
            size="md"
          />
          <div className="mt-10">
            <MarketingFeatureGrid items={enterpriseDeploymentOptions} columns={2} />
          </div>

          <div className="mt-12 border-t border-border pt-10">
            <div className="mx-auto max-w-3xl sm:max-w-4xl lg:max-w-5xl">
              <MarketingBentoFeatureCard
                title={enterpriseDeploymentSection.sharedBenefitsTitle}
                items={enterpriseDeploymentSharedBenefits}
              />
            </div>
          </div>
        </div>
      </section>

      <SecurityComplianceSection />

      <MarketingFaqSection
        title={t('Enterprise FAQ')}
        description={t(
          'Common questions about pricing, support, compliance, and getting started.',
        )}
        items={enterpriseFaqItems}
      />

      <EnterpriseContactCta />
    </div>
  )
}
