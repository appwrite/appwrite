import { Link } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { DashboardPreview } from '@/components/pages/affiliates/DashboardPreview'
import { SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import {
  MarketingCtaSection,
  MarketingFeatureGrid,
  MarketingHeroSection,
  MarketingSectionHeading,
} from '@/components/pages/marketing/MarketingSections'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  affiliatesCta,
  affiliatesDashboard,
  affiliatesFaqItems,
  affiliatesHero,
  affiliatesRewards,
  affiliatesSteps,
  affiliatesTopBenefits,
  affiliatesWhyJoin,
} from '@/lib/affiliates/content'
import { useT } from '@/lib/i18n/translate'

const AFFILIATES_ACCOUNT_PATH = '/account/affiliates'

export function View() {
  const t = useT()
  const { isAuthenticated, isFetched } = useAuth()
  // Same label for signed-in and signed-out so auth hydration does not flash copy.
  const joinCta = (
    <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
      {isFetched && isAuthenticated ? (
        <Link
          to={AFFILIATES_ACCOUNT_PATH}
          {...analyticsAttrs('affiliates-join')}
        >
          {t('Get started')}
        </Link>
      ) : (
        <Link
          to="/sign-up"
          search={{ redirect: AFFILIATES_ACCOUNT_PATH }}
          {...analyticsAttrs('affiliates-join')}
        >
          {t('Get started')}
        </Link>
      )}
    </Button>
  )

  const [rewardPrimary, ...rewardSecondary] = affiliatesRewards.highlights

  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        eyebrow={affiliatesHero.eyebrow}
        title={affiliatesHero.title}
        description={affiliatesHero.description}
        gradientTitle
        wideFooter
        footer={
          <MarketingFeatureGrid
            items={affiliatesTopBenefits}
            columns={4}
            className="mt-16 text-start sm:mt-20"
          />
        }
      >
        {joinCta}
      </MarketingHeroSection>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading title={t('Get started')} size="md" />
          <div className="mt-10 grid overflow-hidden rounded-xl border border-border bg-card/45 lg:grid-cols-3">
            {affiliatesSteps.map((step, index) => (
              <article
                key={step.title}
                className="flex h-full flex-col border-b border-border p-6 last:border-b-0 lg:border-b-0 lg:border-e lg:last:border-e-0"
              >
                <Badge variant="info" className="w-fit shrink-0 text-[10px]">
                  {t('Step')} {index + 1}
                </Badge>
                <h3 className="mt-4 text-[14px] font-semibold text-foreground">
                  {t(step.title)}
                </h3>
                <p className="mt-2 flex-1 text-[13px] leading-6 text-muted-foreground">
                  {t(step.description)}
                </p>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section className="relative isolate overflow-hidden border-b border-border bg-muted/20">
        <SectionSoftLight tone="teal" position="right" />
        <div className="relative z-[1] mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
          <MarketingSectionHeading
            title={affiliatesDashboard.title}
            description={affiliatesDashboard.description}
            size="md"
          />
          <div className="mx-auto mt-10 max-w-5xl lg:max-w-6xl">
            <DashboardPreview />
          </div>
        </div>
      </section>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={affiliatesRewards.title}
            description={affiliatesRewards.description}
            size="md"
          />
          <div className="mt-10 grid gap-3 lg:grid-cols-5 lg:gap-4">
            {rewardPrimary ? (
              <article className="relative overflow-hidden rounded-2xl border border-border bg-card/50 p-6 sm:p-8 lg:col-span-3 lg:min-h-[280px] lg:p-10">
                <div
                  className="pointer-events-none absolute -start-[20%] top-1/2 h-[140%] w-[90%] -translate-y-1/2 bg-[radial-gradient(ellipse_at_center,color-mix(in_srgb,var(--brand-cta)_16%,transparent)_0%,transparent_70%)] dark:bg-[radial-gradient(ellipse_at_center,color-mix(in_srgb,var(--brand-cta)_10%,transparent)_0%,transparent_70%)]"
                  aria-hidden
                />
                <div className="relative z-[1] flex h-full flex-col justify-between gap-8">
                  <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {t('Per Pro upgrade')}
                  </p>
                  <div>
                    <p className="font-aeonik-pro text-[64px] font-normal leading-none tracking-tight text-foreground sm:text-[80px]">
                      {rewardPrimary.value}
                    </p>
                    <p className="mt-4 max-w-md text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
                      {t(rewardPrimary.label)}
                    </p>
                  </div>
                </div>
              </article>
            ) : null}

            <div className="grid gap-3 sm:grid-cols-2 lg:col-span-2 lg:grid-cols-1 lg:gap-4">
              {rewardSecondary.map((item) => (
                <article
                  key={item.label}
                  className="flex flex-col justify-between rounded-2xl border border-border bg-muted/30 p-5 sm:p-6 lg:min-h-[132px]"
                >
                  <p className="font-aeonik-pro text-[32px] font-normal leading-none text-foreground sm:text-[36px]">
                    {item.value}
                  </p>
                  <p className="mt-3 text-[13px] leading-6 text-muted-foreground">
                    {t(item.label)}
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
            title={t('Why join Affiliates')}
            description={t(
              'Recommend Appwrite, help developers ship, and earn credits along the way.',
            )}
            size="md"
          />
          <div className="mt-10">
            <MarketingFeatureGrid items={affiliatesWhyJoin} columns={3} />
          </div>
        </div>
      </section>

      <MarketingFaqSection items={affiliatesFaqItems} />

      <MarketingCtaSection
        title={affiliatesCta.title}
        description={affiliatesCta.description}
      >
        {joinCta}
      </MarketingCtaSection>
    </div>
  )
}
