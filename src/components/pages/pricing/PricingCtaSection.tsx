import { Link } from '@tanstack/react-router'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import {
  analyticsAttrs,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { getVisiblePricingPlans } from '@/lib/pricing/start-plan'
import type { PlanId, PricingPlan } from '@/lib/pricing/types'
import { useStartPlanVisibility } from '@/hooks/use-start-plan-visibility'
import {
  outlineTierButtonClassName,
  pricingGlassSurfaceClassName,
  getProPlanBadgeLabel,
  getPricingPlanCardsContainerClassName,
  getPricingPlanCardsGridClassName,
  pricingCompactCardsReserveClassName,
  PricingPlanCardWrapper,
  PricingPlanCardsShell,
} from './_components/PricingShared'
import { PricingSectionHeading } from './_components/PricingSectionHeading'
import { cn } from '@/lib/utils'

const PRICING_PROMO_CTA_ACTIONS: Record<PlanId, AnalyticsActionId> = {
  free: 'pricing-promo-start-free',
  start: 'pricing-promo-start-start',
  pro: 'pricing-promo-start-pro',
  enterprise: 'pricing-promo-contact-enterprise',
}

function getPlanPromoCtaLabel(planId: PlanId): string {
  switch (planId) {
    case 'free':
      return 'Start for free'
    case 'start':
      return 'Get started'
    case 'pro':
      return 'Start on Pro'
    case 'enterprise':
      return 'Contact us'
  }
}

function PricingPromoPlanCard({
  plan,
  fourPlanGrid,
}: {
  plan: PricingPlan
  fourPlanGrid: boolean
}) {
  const t = useT()
  const isPro = plan.id === 'pro'
  const badgeLabel = isPro ? getProPlanBadgeLabel(fourPlanGrid) : undefined

  return (
    <article
      className={cn(
        pricingGlassSurfaceClassName,
        'flex h-full flex-col',
        isPro ? 'p-5 shadow-md sm:p-6 lg:p-8' : 'p-5 sm:p-6',
      )}
    >
      <div className="flex flex-wrap items-center gap-2">
        <h3
          className={cn(
            'font-semibold text-foreground',
            isPro ? 'text-[16px]' : 'text-[15px]',
          )}
        >
          {t(plan.name)}
        </h3>
        {badgeLabel ? (
          <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
            {t(badgeLabel)}
          </span>
        ) : null}
      </div>

      <p className="mt-3 flex flex-wrap items-baseline gap-x-1.5">
        {plan.pricePrefix ? (
          <span className="text-[12px] text-muted-foreground">{t(plan.pricePrefix)}</span>
        ) : null}
        <span
          className={cn(
            'font-aeonik-pro font-normal leading-none tracking-tight text-foreground',
            isPro ? 'text-[36px] lg:text-[40px]' : 'text-[32px]',
          )}
        >
          {t(plan.price)}
        </span>
        {plan.priceSuffix ? (
          <span className="text-[14px] text-muted-foreground">{t(plan.priceSuffix)}</span>
        ) : null}
      </p>

      <p className="mt-3 flex-1 text-[13px] leading-6 text-muted-foreground">
        {t(plan.description)}
      </p>

      <div className="mt-5">
        <Button
          variant={plan.ctaVariant}
          className={cn(
            'h-10 w-full text-[13px]',
            plan.ctaVariant === 'outline' && outlineTierButtonClassName,
          )}
          asChild
        >
          {plan.id === 'enterprise' ? (
            <ContactSalesLink
              {...analyticsAttrs(PRICING_PROMO_CTA_ACTIONS[plan.id])}
            >
              {t(getPlanPromoCtaLabel(plan.id))}
            </ContactSalesLink>
          ) : plan.internal ? (
            <Link
              to={plan.href}
              search={{ redirect: '/' }}
              {...analyticsAttrs(PRICING_PROMO_CTA_ACTIONS[plan.id])}
            >
              {t(getPlanPromoCtaLabel(plan.id))}
            </Link>
          ) : (
            <a
              href={plan.href}
              target="_blank"
              rel="noopener noreferrer"
              {...analyticsAttrs(PRICING_PROMO_CTA_ACTIONS[plan.id])}
            >
              {t(getPlanPromoCtaLabel(plan.id))}
            </a>
          )}
        </Button>
      </div>
    </article>
  )
}

export function PricingCtaSection() {
  const t = useT()
  const { ready, showStartPlan } = useStartPlanVisibility()
  const plans = getVisiblePricingPlans(showStartPlan)

  return (
    <section className="relative overflow-hidden border-t border-border bg-background py-16 sm:py-20">
      <HomeSoftLights variant="testimonials" className="opacity-50" />
      <div
        className={cn(
          'relative',
          getPricingPlanCardsContainerClassName(ready, showStartPlan),
        )}
      >
        <PricingSectionHeading
          align="center"
          title={t('Ready to get started?')}
          description={t('Pick the plan that fits your stage. Upgrade anytime as your app grows.')}
          className="max-w-2xl"
        />

        <PricingPlanCardsShell
          ready={ready}
          reserveClassName={pricingCompactCardsReserveClassName}
          className="mt-10"
        >
          <div
            className={cn(
              getPricingPlanCardsGridClassName(showStartPlan),
              'lg:items-stretch lg:py-6',
            )}
          >
            {plans.map((plan) => {
              const featured = plan.id === 'pro'
              return (
                <PricingPlanCardWrapper key={plan.id} featured={featured}>
                  <PricingPromoPlanCard plan={plan} fourPlanGrid={showStartPlan} />
                </PricingPlanCardWrapper>
              )
            })}
          </div>
        </PricingPlanCardsShell>
      </div>
    </section>
  )
}
