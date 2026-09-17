'use client'

import { Link } from '@tanstack/react-router'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import {
  analyticsAttrs,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { SALES_FORM_ROUTE } from '@/lib/sales/contact-sales'
import { cn } from '@/lib/utils'
import { startPricingPlan } from '@/lib/pricing/start-plan'
import { useStartPlanVisibility } from '@/hooks/use-start-plan-visibility'
import {
  getProPlanBadgeLabel,
  getPricingPlanCardsContainerClassName,
  pricingCompactCardsReserveClassName,
  PricingPlanCardsShell,
} from '@/components/pages/pricing/_components/PricingShared'

type PricingTier = {
  id: string
  name: string
  price: string
  priceSuffix?: string
  description: string
  cta: string
  ctaVariant: 'brandCta' | 'outline'
  href: string
  contactSales?: boolean
  marketingAware?: boolean
  popular?: boolean
}

const HOME_PRICING_CTA_ACTIONS: Record<string, AnalyticsActionId> = {
  free: 'home-pricing-start-free',
  start: 'home-pricing-start-start',
  pro: 'home-pricing-start-pro',
  enterprise: 'home-pricing-contact-enterprise',
}

const pricingTiers: PricingTier[] = [
  {
    id: 'free',
    name: 'Free',
    price: '$0',
    description: 'A great fit for passion projects and small applications.',
    cta: 'Start project',
    ctaVariant: 'outline',
    href: '/sign-up',
  },
  {
    id: 'pro',
    name: 'Pro',
    price: '$25',
    priceSuffix: '/month',
    description:
      'For production applications that scale with included resources and pay as you go.',
    cta: 'Start project',
    ctaVariant: 'brandCta',
    href: '/sign-up',
    popular: true,
  },
  {
    id: 'enterprise',
    name: 'Enterprise',
    price: 'Custom',
    description: 'For enterprises that need more power and premium support.',
    cta: 'Contact us',
    ctaVariant: 'outline',
    href: SALES_FORM_ROUTE,
    contactSales: true,
  },
]

const outlineTierButtonClassName =
  'border-[var(--brand-cta)]/30 text-foreground hover:bg-[var(--brand-cta)]/10 hover:text-foreground'

function PricingTierCta({ tier }: { tier: PricingTier }) {
  const t = useT()
  const buttonClassName = cn(
    'h-10 w-full text-[13px]',
    tier.ctaVariant === 'outline' && outlineTierButtonClassName,
  )
  const action = HOME_PRICING_CTA_ACTIONS[tier.id]
  const analytics = action ? analyticsAttrs(action) : undefined

  if (tier.contactSales) {
    return (
      <Button variant={tier.ctaVariant} className={buttonClassName} asChild>
        <ContactSalesLink {...analytics}>
          {t(tier.cta)}
        </ContactSalesLink>
      </Button>
    )
  }

  if (tier.marketingAware) {
    return (
      <Button variant={tier.ctaVariant} className={buttonClassName} asChild>
        <MarketingSiteLink href={tier.href} {...analytics}>
          {t(tier.cta)}
        </MarketingSiteLink>
      </Button>
    )
  }

  return (
    <Button variant={tier.ctaVariant} className={buttonClassName} asChild>
      <Link to={tier.href} search={{ redirect: '/' }} {...analytics}>
        {t(tier.cta)}
      </Link>
    </Button>
  )
}

function PricingTierCard({
  tier,
  columnCount,
  fourPlanGrid,
}: {
  tier: PricingTier
  columnCount: number
  fourPlanGrid: boolean
}) {
  const t = useT()
  const badgeLabel =
    tier.popular || tier.id === 'pro'
      ? getProPlanBadgeLabel(fourPlanGrid)
      : undefined
  return (
    <article
      className={cn(
        'group flex min-h-[280px] flex-col border-b border-border p-6 transition-colors last:border-b-0 hover:bg-accent/15 sm:min-h-[300px] sm:border-e sm:border-b-0 sm:p-7',
        columnCount === 4
          ? 'sm:[&:nth-child(2n)]:border-e-0 lg:[&:nth-child(2n)]:border-e lg:[&:nth-child(4n)]:border-e-0'
          : 'sm:[&:nth-child(3n)]:border-e-0',
      )}
    >
      <div className="flex flex-1 flex-col gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <h3 className="text-[14px] font-semibold text-foreground">{t(tier.name)}</h3>
          {badgeLabel ? (
            <span className="rounded-full bg-[var(--brand-cta)]/10 px-2 py-0.5 text-[10px] font-medium text-[var(--brand-cta)]">
              {t(badgeLabel)}
            </span>
          ) : null}
        </div>

        <p className="flex flex-wrap items-baseline gap-x-1.5">
          <span className="font-aeonik-pro text-[40px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
            {t(tier.price)}
          </span>
          {tier.priceSuffix ? (
            <span className="text-[14px] text-muted-foreground">{t(tier.priceSuffix)}</span>
          ) : null}
        </p>

        <p className="text-[13px] leading-5 text-muted-foreground">{t(tier.description)}</p>
      </div>

      <div className="mt-8">
        <PricingTierCta tier={tier} />
      </div>
    </article>
  )
}

export function PricingSection() {
  const t = useT()
  const { ready, showStartPlan } = useStartPlanVisibility()
  const startHomeTier: PricingTier = {
    id: startPricingPlan.id,
    name: startPricingPlan.name,
    price: startPricingPlan.price,
    priceSuffix: startPricingPlan.priceSuffix,
    description: startPricingPlan.description,
    cta: startPricingPlan.cta,
    ctaVariant: startPricingPlan.ctaVariant,
    href: startPricingPlan.href,
  }
  const tiers = showStartPlan
    ? [pricingTiers[0], startHomeTier, ...pricingTiers.slice(1)]
    : pricingTiers

  return (
    <section className="border-t border-border bg-background py-16 sm:py-20">
      <div
        className={getPricingPlanCardsContainerClassName(
          ready,
          showStartPlan,
        )}
      >
        <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between lg:gap-10">
          <h2 className="font-aeonik-pro max-w-xl text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
            {t('Start building like a team of hundreds today')}
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>

          <div className="flex shrink-0 flex-wrap items-center gap-2">
            <Button variant="brandCta" className="h-10 text-[13px]" asChild>
              <Link
                to="/sign-up"
                search={{ redirect: '/' }}
                {...analyticsAttrs('home-start-building')}
              >
                {t('Start building')}
              </Link>
            </Button>
            <Button variant="outline" className="h-10 text-[13px]" asChild>
              <MarketingSiteLink
                href="/pricing"
                {...analyticsAttrs('home-view-pricing')}
              >
                {t('View pricing plans')}
              </MarketingSiteLink>
            </Button>
          </div>
        </div>

        <PricingPlanCardsShell
          ready={ready}
          reserveClassName={pricingCompactCardsReserveClassName}
          className="mt-10"
        >
          <div
            className={cn(
              'grid overflow-hidden rounded-xl border border-border bg-card/45',
              showStartPlan ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-3',
            )}
          >
            {tiers.map((tier) => (
              <PricingTierCard
                key={tier.id}
                tier={tier}
                columnCount={tiers.length}
                fourPlanGrid={showStartPlan}
              />
            ))}
          </div>
        </PricingPlanCardsShell>
      </div>
    </section>
  )
}
