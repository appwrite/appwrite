import { Check } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { TestimonialsSection } from '@/components/pages/home/TestimonialsSection'
import { MarketingApplicationForm } from '@/components/pages/marketing/MarketingApplicationForm'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import { MarketingProductPills } from '@/components/pages/marketing/MarketingProductPills'
import {
  MarketingFeatureGrid,
  MarketingHeroSection,
  MarketingSectionHeading,
  marketingSplitLayoutClassName,
} from '@/components/pages/marketing/MarketingSections'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { submitStartupsApplication } from '@/lib/marketing/growth-forms'
import {
  STARTUPS_FORM_ID,
  startupsApplySteps,
  startupsEligibility,
  startupsFaqItems,
  startupsFormBullets,
  startupsHero,
  startupsPlatformBenefits,
  startupsToolkit,
  startupsTopBenefits,
} from '@/lib/startups/content'

const STARTUPS_FORM_FIELDS = [
  { name: 'personName', label: 'Full name', type: 'text' as const, placeholder: "Walter O'Brien" },
  { name: 'personEmail', label: 'Email address', type: 'email' as const, placeholder: 'walter@company.com' },
  { name: 'companyName', label: 'Company name', type: 'text' as const, placeholder: 'Company Inc.' },
  { name: 'companyUrl', label: 'Company website', type: 'text' as const, placeholder: 'https://company.com' },
]

function scrollToForm() {
  document.getElementById(STARTUPS_FORM_ID)?.scrollIntoView({ behavior: 'smooth' })
}

export function View() {
  const t = useT()
  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        eyebrow={startupsHero.eyebrow}
        title={startupsHero.title}
        description={startupsHero.description}
        gradientTitle
        wideFooter
        footer={
          <MarketingFeatureGrid
            items={startupsTopBenefits}
            columns={4}
            className="mt-16 text-start sm:mt-20"
          />
        }
      >
        <Button
          variant="brandCta"
          size="lg"
          className="h-10 text-[14px]"
          onClick={scrollToForm}
          {...analyticsAttrs('startups-apply-now')}
        >
          {t('Apply now')}
        </Button>
      </MarketingHeroSection>

      <section className="border-b border-border bg-muted/20 py-14 sm:py-16">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={startupsEligibility.title}
            description={startupsEligibility.description}
            size="md"
          />
          <div className="mt-10">
            <MarketingFeatureGrid items={startupsEligibility.criteria} columns={3} />
          </div>
          <div className="mt-10 flex flex-col gap-4 rounded-xl border border-border bg-card/45 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
            <div className="max-w-2xl">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t(startupsEligibility.proPlanCallout.title)}
              </h3>
              <p className="mt-2 text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
                {t(startupsEligibility.proPlanCallout.description)}
              </p>
            </div>
            <Button variant="outline" className="w-fit shrink-0" asChild>
              <Link to={startupsEligibility.proPlanCallout.ctaHref}>
                {t(startupsEligibility.proPlanCallout.ctaLabel)}
              </Link>
            </Button>
          </div>
          <div className="mt-12 border-t border-border pt-10">
            <div className="max-w-2xl">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t(startupsEligibility.exclusions.title)}
              </h3>
              <p className="mt-2 text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
                {t(startupsEligibility.exclusions.description)}
              </p>
            </div>
            <div className="mt-8 grid overflow-hidden rounded-xl border border-border bg-card/45 sm:grid-cols-2">
              {startupsEligibility.exclusions.items.map((item, index) => (
                <article
                  key={item.title}
                  className={cn(
                    'p-5',
                    index < startupsEligibility.exclusions.items.length - 1 &&
                      'border-b border-border sm:border-b-0',
                    index % 2 === 0 && 'sm:border-e sm:border-border',
                    index < 2 && 'sm:border-b sm:border-border',
                  )}
                >
                  <h3 className="text-[14px] font-semibold text-foreground">{t(item.title)}</h3>
                  <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                    {t(item.description)}
                  </p>
                </article>
              ))}
            </div>
          </div>
        </div>
      </section>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={t('Your startups developer toolkit')}
            description={t(
              'Appwrite offers an all-in-one hosting platform for you to build and deploy your product from a single place.', // pragma: allowlist secret
            )}
            size="md"
          />
          <MarketingProductPills
            build={startupsToolkit.build}
            deploy={startupsToolkit.deploy}
            protect={startupsToolkit.protect}
          />
        </div>
      </section>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className={marketingSplitLayoutClassName({ align: 'start' })}>
            <MarketingSectionHeading
              align="left"
              size="md"
              title={t('Benefits of Appwrite for startups') /* pragma: allowlist secret */}
              description={t(
                "You don't need to have a team of engineers to develop, host, and scale applications. Appwrite gives you everything you need, including built-in security, AI, and open source.", // pragma: allowlist secret
              )}
            />
            <MarketingFeatureGrid items={startupsPlatformBenefits} columns={2} />
          </div>
        </div>
      </section>

      <TestimonialsSection />

      <section className="border-t border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading title={t('How to apply')} size="md" />
          <div className="mt-10 grid overflow-hidden rounded-xl border border-border bg-card/45 lg:grid-cols-3">
            {startupsApplySteps.map((step, index) => (
              <article
                key={step.title}
                className="flex h-full flex-col border-b border-border p-6 last:border-b-0 lg:border-b-0 lg:border-e lg:last:border-e-0"
              >
                <Badge variant="info" className="w-fit shrink-0 text-[10px]">
                  {t('Step')} {index + 1}
                </Badge>
                <h3 className="mt-4 text-[14px] font-semibold text-foreground">{t(step.title)}</h3>
                <p className="mt-2 flex-1 text-[13px] leading-6 text-muted-foreground">
                  {t(step.description)}
                </p>
                {step.href && step.label ? (
                  <Button variant="outline" className="mt-6 w-fit" asChild>
                    {step.external ? (
                      <a href={step.href} target="_blank" rel="noopener noreferrer">
                        {t(step.label)}
                      </a>
                    ) : (
                      <a href={step.href}>{t(step.label)}</a>
                    )}
                  </Button>
                ) : null}
              </article>
            ))}
          </div>
        </div>
      </section>

      <MarketingFaqSection items={startupsFaqItems} />

      <section id={STARTUPS_FORM_ID} className="relative scroll-mt-28 border-b border-border">
        <HomeSoftLights variant="testimonials" className="opacity-40" />
        <div className="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
          <div className={marketingSplitLayoutClassName({ align: 'start' })}>
            <div>
              <MarketingSectionHeading
                align="left"
                size="md"
                title={t('Join the Appwrite Startups program') /* pragma: allowlist secret */}
                description={t('Accepted startups receive:')}
              />
              <ul className="mt-6 space-y-3">
                {startupsFormBullets.map((item) => (
                  <li key={item} className="flex items-center gap-2 text-[13px] text-foreground">
                    <Check className="size-4 shrink-0 text-[var(--brand-cta)]" aria-hidden />
                    {t(item)}
                  </li>
                ))}
              </ul>
            </div>

            <div className="rounded-xl border border-border bg-card/50 p-6 sm:p-8">
              <MarketingApplicationForm
                fields={STARTUPS_FORM_FIELDS}
                submitLabel={t('Get Started')}
                submitAnalyticsAction="startups-form-submit"
                onSubmit={async (values) => {
                  await submitStartupsApplication({
                    personName: values.personName ?? '',
                    personEmail: values.personEmail ?? '',
                    companyName: values.companyName ?? '',
                    companyUrl: values.companyUrl ?? '',
                  })
                }}
              />
            </div>
          </div>
        </div>
      </section>
    </div>
  )
}
