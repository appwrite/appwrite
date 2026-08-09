import { Link } from '@tanstack/react-router'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import {
  analyticsAttrs,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { pricingPlans } from '@/lib/pricing/plans'
import type { PlanId, PricingPlan } from '@/lib/pricing/types'
import {
  outlineTierButtonClassName,
  pricingGlassSurfaceClassName,
} from './_components/PricingShared'
import { PricingSectionHeading } from './_components/PricingSectionHeading'
import { cn } from '@/lib/utils'

const PRICING_PROMO_CTA_ACTIONS: Record<PlanId, AnalyticsActionId> = {
  free: 'pricing-promo-start-free',
  pro: 'pricing-promo-start-pro',
  enterprise: 'pricing-promo-contact-enterprise',
}

function getPlanPromoCtaLabel(planId: PlanId): string {
  switch (planId) {
    case 'free':
      return 'Start for free'
    case 'pro':
      return 'Start on Pro'
    case 'enterprise':
      return 'Contact us'
  }
}

function PricingPromoPlanCard({ plan }: { plan: PricingPlan }) {
  const t = useT()
  const isPro = plan.id === 'pro'

  return (
    <article
      className={cn(
        pricingGlassSurfaceClassName,
        'flex h-full flex-col p-5 sm:p-6',
        isPro && 'lg:shadow-md',
      )}
    >
      <div className="flex flex-wrap items-center gap-2">
        <h3 className="text-[15px] font-semibold text-foreground">{t(plan.name)}</h3>
        {plan.popular ? (
          <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
            {t('Popular')}
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
            isPro ? 'text-[36px]' : 'text-[32px]',
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
          {plan.internal ? (
            <Link
              to={plan.href}
              {...(plan.id === 'enterprise' ? {} : { search: { redirect: '/' } })}
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
  return (
    <section className="relative overflow-hidden border-t border-border bg-background py-16 sm:py-20">
      <HomeSoftLights variant="testimonials" className="opacity-50" />
      <div className="relative mx-auto w-full max-w-7xl px-4 sm:px-6">
        <PricingSectionHeading
          align="center"
          title={t('Ready to get started?')}
          description={t('Pick the plan that fits your stage. Upgrade anytime as your app grows.')}
          className="max-w-2xl"
        />

        <div className="mt-10 grid gap-4 sm:grid-cols-3 sm:gap-6">
          {pricingPlans.map((plan) => (
            <PricingPromoPlanCard key={plan.id} plan={plan} />
          ))}
        </div>
      </div>
    </section>
  )
}
