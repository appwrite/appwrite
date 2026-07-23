import { useMemo } from 'react'
import { Check } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { TrustedByLogo } from '@/components/global/shared/TrustedByLogo'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { SecurityComplianceSection } from '@/components/pages/enterprise/SecurityComplianceSection'
import { HomeSoftLights, SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { MarketingApplicationForm } from '@/components/pages/marketing/MarketingApplicationForm'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import { MarketingProductPills } from '@/components/pages/marketing/MarketingProductPills'
import {
  MarketingBentoFeatureCard,
  MarketingFeatureGrid,
  MarketingHeroSection,
  MarketingHeroStats,
  MarketingSectionHeading,
  marketingSplitLayoutClassName,
} from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { allCustomerLogos } from '@/lib/home/customer-logos'
import {
  ENTERPRISE_FORM_ID,
  enterpriseCompanySizeOptions,
  enterpriseDeploymentOptions,
  enterpriseDeploymentSection,
  enterpriseDeploymentSharedBenefits,
  enterpriseFaqItems,
  enterpriseFormBullets,
  enterpriseHero,
  enterprisePlanCapabilities,
  enterprisePlatformSection,
  enterprisePreferredDeploymentOptions,
  enterpriseStats,
  enterpriseTimelineOptions,
  enterpriseValueProps,
} from '@/lib/enterprise/content'
import { useT } from '@/lib/i18n/translate'
import { submitEnterpriseApplication } from '@/lib/marketing/growth-forms'
import { marketingProductToolkit } from '@/lib/marketing/product-toolkit'
import { trackEvent } from '@/lib/analytics'

const ENTERPRISE_FORM_FIELDS = [
  { name: 'firstName', label: 'First name', type: 'text' as const, placeholder: 'Walter' },
  { name: 'lastName', label: 'Last name', type: 'text' as const, placeholder: "O'Brien" },
  {
    name: 'email',
    label: 'Work email address',
    type: 'email' as const,
    placeholder: 'walter@company.com',
  },
  {
    name: 'companyName',
    label: 'Company name',
    type: 'text' as const,
    placeholder: 'Acme Corp',
  },
  {
    name: 'companySize',
    label: 'Company size',
    type: 'select' as const,
    placeholder: 'Select size',
    required: false,
    options: [...enterpriseCompanySizeOptions],
  },
  {
    name: 'companyWebsite',
    label: 'Company website',
    type: 'text' as const,
    placeholder: 'appwrite.io or https://appwrite.io',
  },
  {
    name: 'preferredDeployment',
    label: 'Preferred deployment',
    type: 'select' as const,
    placeholder: 'Select deployment',
    required: false,
    options: [...enterprisePreferredDeploymentOptions],
  },
  {
    name: 'timeline',
    label: 'Timeline',
    type: 'select' as const,
    placeholder: 'Select timeline',
    required: false,
    options: [...enterpriseTimelineOptions],
  },
  {
    name: 'useCase',
    label: 'Please share more information about your use case',
    type: 'textarea' as const,
    placeholder: 'Describe your use case and how our Enterprise plan can support it',
    colSpan: 2 as const,
  },
]

function scrollToForm() {
  document.getElementById(ENTERPRISE_FORM_ID)?.scrollIntoView({ behavior: 'smooth' })
}

export function View() {
  const t = useT()
  const { account, isAuthenticated } = useAuth()

  const formDefaultValues = useMemo(() => {
    if (!isAuthenticated || !account) return undefined

    const nameParts = account.name?.trim().split(/\s+/) ?? []
    const firstName = nameParts[0] ?? ''
    const lastName = nameParts.slice(1).join(' ')

    return {
      ...(firstName ? { firstName } : {}),
      ...(lastName ? { lastName } : {}),
      ...(account.email ? { email: account.email } : {}),
    }
  }, [account, isAuthenticated])

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
          onClick={scrollToForm}
          {...analyticsAttrs('enterprise-contact-sales')}
        >
          {t('Contact sales')}
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

      <SecurityComplianceSection onContactSales={scrollToForm} />

      <MarketingFaqSection
        title={t('Enterprise FAQ')}
        description={t(
          'Common questions about pricing, support, compliance, and getting started.',
        )}
        items={enterpriseFaqItems}
      />

      <section id={ENTERPRISE_FORM_ID} className="relative scroll-mt-28 border-b border-border">
        <HomeSoftLights variant="testimonials" className="opacity-40" />
        <div className="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
          <div className={marketingSplitLayoutClassName({ align: 'start' })}>
            <div>
              <MarketingSectionHeading
                align="left"
                size="md"
                title={t('Talk to our enterprise team')}
                description={t(
                  'Ready to explore a custom plan? Share your requirements and one of our experts will follow up with a tailored proposal.',
                )}
              />
              <ul className="mt-6 space-y-3">
                {enterpriseFormBullets.map((item) => (
                  <li key={item} className="flex items-center gap-2 text-[13px] text-foreground">
                    <Check className="size-4 shrink-0 text-[var(--brand-cta)]" aria-hidden />
                    {t(item)}
                  </li>
                ))}
              </ul>
            </div>

            <div className="rounded-xl border border-border bg-card/50 p-6 sm:p-8">
              <MarketingApplicationForm
                fields={ENTERPRISE_FORM_FIELDS}
                defaultValues={formDefaultValues}
                submitLabel={t('Submit')}
                submitAnalyticsAction="enterprise-form-submit"
                successTitle={t('Thank you for your submission')}
                successDescription={t(
                  'Your details have been sent successfully. Our team will get back to you as soon as possible.',
                )}
                onSubmit={async (values) => {
                  await submitEnterpriseApplication({
                    firstName: values.firstName ?? '',
                    lastName: values.lastName ?? '',
                    email: values.email ?? '',
                    companyName: values.companyName ?? '',
                    companySize: values.companySize || undefined,
                    companyWebsite: values.companyWebsite ?? '',
                    preferredDeployment: values.preferredDeployment || undefined,
                    timeline: values.timeline || undefined,
                    useCase: values.useCase ?? '',
                    cloudEmail: isAuthenticated ? account?.email : undefined,
                  })
                  trackEvent('Form Submitted', { form: 'enterprise' })
                }}
              />
            </div>
          </div>
        </div>
      </section>
    </div>
  )
}
