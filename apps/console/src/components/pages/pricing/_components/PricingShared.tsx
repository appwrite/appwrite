import { Link } from '@tanstack/react-router'
import { Check, Info } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import { SALES_FORM_ROUTE } from '@/lib/sales/contact-sales'
import { useStartPlanVisibility } from '@/hooks/use-start-plan-visibility'
import { getVisiblePricingPlans } from '@/lib/pricing/start-plan'
import { withAnalyticsPlanFeatures } from '@/lib/pricing/analytics'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { ComparisonCell, ComparisonLinkCell, PlanId, PricingPlan } from '@/lib/pricing/types'
import { cn } from '@/lib/utils'
import {
  analyticsAttrs,
  getPricingPlanCtaAnalyticsAction,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'

export const outlineTierButtonClassName =
  'border-[var(--brand-cta)]/30 text-foreground hover:bg-[var(--brand-cta)]/10 hover:text-foreground'

export const pricingGlassSurfaceClassName =
  'relative isolate overflow-hidden rounded-xl border border-muted-foreground/8 bg-muted-foreground/[0.035] shadow-sm backdrop-blur-sm dark:border-muted/30 dark:bg-muted/10 supports-[backdrop-filter]:bg-muted-foreground/[0.028] supports-[backdrop-filter]:dark:bg-muted/[0.08]'

/** Wider page gutter when Start is shown so four plan cards are not squeezed. */
export const pricingStartPlansContainerClassName =
  'mx-auto w-full max-w-[96rem] px-4 sm:px-6 xl:px-8'

export const pricingThreePlansContainerClassName =
  'mx-auto w-full max-w-7xl px-4 sm:px-6'

export const pricingHeroThreePlansContainerClassName =
  'mx-auto w-full max-w-7xl px-6 sm:px-10 lg:px-14'

/**
 * Keep the cards row on the wider gutter until country is known so 4-plan
 * never expands the container after first paint.
 */
export function getPricingPlanCardsContainerClassName(
  ready: boolean,
  showStartPlan: boolean,
  threePlanClassName: string = pricingThreePlansContainerClassName,
) {
  return !ready || showStartPlan
    ? pricingStartPlansContainerClassName
    : threePlanClassName
}

/** Uniform gap; Pro column wider so the featured card reads larger than neighbors. */
export function getPricingPlanCardsGridClassName(showStartPlan: boolean) {
  return cn(
    'grid gap-6 lg:gap-5 xl:gap-6',
    showStartPlan
      ? 'sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.12fr_1fr]'
      : 'sm:grid-cols-2 lg:grid-cols-[1fr_1.12fr_1fr]',
  )
}

/** Centers Pro scale in its grid cell without affecting sibling column widths. */
export function PricingPlanCardWrapper({
  featured = false,
  children,
}: {
  featured?: boolean
  children: ReactNode
}) {
  if (!featured) {
    return children
  }

  return (
    <div className="relative z-[1] flex h-full items-center justify-center lg:origin-center lg:scale-[1.06]">
      {children}
    </div>
  )
}

/**
 * Floor for the featured pricing cards row (not stacked mobile height).
 * Covers the taller of 3-col and 4-col at lg: stretched card ~52.7rem and
 * lg:py-6 on the grid (~3rem).
 */
export const pricingHeroCardsReserveClassName =
  'min-h-[42rem] sm:min-h-[54rem] lg:min-h-[54rem]'

/** Home / promo compact plan cards (one row, no feature lists). */
export const pricingCompactCardsReserveClassName =
  'min-h-[17.5rem] sm:min-h-[18.75rem]'

export const pricingPlanCardsFadeClassName =
  'animate-in fade-in-0 slide-in-from-bottom-1 duration-500 ease-out motion-reduce:animate-none'

export function PricingPlanCardsShell({
  ready,
  reserveClassName,
  className,
  children,
}: {
  ready: boolean
  reserveClassName: string
  className?: string
  children: ReactNode
}) {
  return (
    <div className={cn(reserveClassName, className)} aria-busy={!ready || undefined}>
      {ready ? (
        <div className={pricingPlanCardsFadeClassName}>{children}</div>
      ) : null}
    </div>
  )
}

const pricingPlanGlassClassName = pricingGlassSurfaceClassName

function isProPlan(plan: PricingPlan) {
  return plan.id === 'pro'
}

export function getProPlanBadgeLabel(fourPlanGrid: boolean) {
  return fourPlanGrid ? 'Best value' : 'Popular'
}

export function PricingPlanCta({
  plan,
  className,
  size = 'default',
  analyticsAction,
}: {
  plan: Pick<PricingPlan, 'id' | 'cta' | 'ctaVariant' | 'href' | 'internal'>
  className?: string
  size?: 'default' | 'sm'
  /** Override default plan-id mapping (e.g. promo / compare section CTAs). */
  analyticsAction?: AnalyticsActionId
}) {
  const t = useT()
  const buttonClassName = cn(
    size === 'sm' ? 'h-9 text-[12px]' : 'h-10 text-[13px]',
    'w-full',
    plan.ctaVariant === 'outline' && outlineTierButtonClassName,
    className,
  )
  const action =
    analyticsAction ?? getPricingPlanCtaAnalyticsAction(plan.id)
  const analytics = action ? analyticsAttrs(action) : undefined

  if (plan.internal) {
    return (
      <Button variant={plan.ctaVariant} className={buttonClassName} asChild>
        <Link to={plan.href} search={{ redirect: '/' }} {...analytics}>
          {t(plan.cta)}
        </Link>
      </Button>
    )
  }

  return (
    <Button variant={plan.ctaVariant} className={buttonClassName} asChild>
      <a href={plan.href} target="_blank" rel="noopener noreferrer" {...analytics}>
        {t(plan.cta)}
      </a>
    </Button>
  )
}

export function PricingPlanCard({
  plan,
  featured = false,
  badgeLabel,
}: {
  plan: PricingPlan
  featured?: boolean
  badgeLabel?: string
}) {
  const t = useT()
  const isFeatured = featured
  const label = badgeLabel ?? (plan.popular ? 'Popular' : undefined)

  return (
    <article
      className={cn(
        pricingPlanGlassClassName,
        'flex h-full w-full min-w-0 flex-col',
        isFeatured ? 'p-6 shadow-md sm:p-7 lg:p-9' : 'p-6 sm:p-7',
      )}
    >
      <header className="relative z-[1] flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <h2
            className={cn(
              'font-semibold text-foreground',
              isFeatured ? 'text-[16px]' : 'text-[14px]',
            )}
          >
            {t(plan.name)}
          </h2>
          {label ? (
            <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
              {t(label)}
            </span>
          ) : null}
        </div>

        <div className="flex flex-col gap-1">
          {plan.pricePrefix ? (
            <span className="text-[12px] text-muted-foreground">{t(plan.pricePrefix)}</span>
          ) : (
            <span className="hidden text-[12px] sm:block sm:min-h-[1.125rem]" aria-hidden />
          )}
          <p className="flex flex-wrap items-baseline gap-x-1.5">
            <span
              className={cn(
                'font-aeonik-pro font-normal leading-none tracking-tight text-foreground',
                isFeatured
                  ? 'text-[48px] sm:text-[52px] lg:text-[56px]'
                  : 'text-[36px] sm:text-[40px]',
              )}
            >
              {t(plan.price)}
            </span>
            {plan.priceSuffix ? (
              <span
                className={cn(
                  'text-muted-foreground',
                  isFeatured ? 'text-[16px]' : 'text-[14px]',
                )}
              >
                {t(plan.priceSuffix)}
              </span>
            ) : null}
          </p>
        </div>

        {plan.callout ? (
          <p className="rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[13px] leading-5 text-foreground">
            {t(plan.callout)}
          </p>
        ) : null}

        <p
          className={cn(
            'leading-5 text-muted-foreground',
            isFeatured ? 'min-h-[3.5rem] text-[14px]' : 'min-h-[3.75rem] text-[13px]',
          )}
        >
          {t(plan.description)}
        </p>

        <PricingPlanCta plan={plan} />
      </header>

      <div className="relative z-[1] mt-6 flex min-h-0 flex-1 flex-col gap-3 border-t border-muted-foreground/10 pt-6 dark:border-muted/20">
        {plan.featuresIntro ? (
          <p className="text-[13px] font-medium text-foreground">{t(plan.featuresIntro)}</p>
        ) : (
          <div className="min-h-[1.25rem]" aria-hidden />
        )}
        <ul className={cn('min-h-0 flex-1 space-y-2.5', isFeatured && 'lg:space-y-3')}>
          {plan.features.map((feature) => (
            <li
              key={feature}
              className={cn(
                'flex items-start gap-2.5 text-muted-foreground',
                isFeatured ? 'text-[14px]' : 'text-[13px]',
              )}
            >
              <Check
                className="mt-0.5 size-4 shrink-0 text-[var(--brand-cta)]"
                aria-hidden
              />
              <span>{t(feature)}</span>
            </li>
          ))}
        </ul>
        {plan.footnote ? (
          <p className="mt-auto pt-2 text-[12px] leading-5 text-muted-foreground/80">
            {t(plan.footnote)}
          </p>
        ) : null}
      </div>
    </article>
  )
}

export function PricingCardsGrid() {
  const { ready, showStartPlan } = useStartPlanVisibility()
  const { features } = useConsoleProfile()
  const plans = withAnalyticsPlanFeatures(getVisiblePricingPlans(showStartPlan), features.analytics)
  const proBadgeLabel = getProPlanBadgeLabel(showStartPlan)

  return (
    <PricingPlanCardsShell
      ready={ready}
      reserveClassName={pricingHeroCardsReserveClassName}
    >
      <div
        className={cn(
          getPricingPlanCardsGridClassName(showStartPlan),
          'lg:items-stretch lg:py-6',
        )}
      >
        {plans.map((plan) => {
          const featured = isProPlan(plan)
          return (
            <PricingPlanCardWrapper key={plan.id} featured={featured}>
              <PricingPlanCard
                plan={plan}
                featured={featured}
                badgeLabel={featured ? proBadgeLabel : undefined}
              />
            </PricingPlanCardWrapper>
          )
        })}
      </div>
    </PricingPlanCardsShell>
  )
}

function isLinkCell(value: ComparisonCell): value is ComparisonLinkCell {
  return typeof value === 'object' && value !== null && 'href' in value
}

export function ComparisonCellValue({ value }: { value: ComparisonCell }) {
  const t = useT()
  if (value === true) {
    return (
      <span className="inline-flex size-5 items-center justify-center rounded-full bg-muted">
        <Check className="size-3 text-foreground" aria-hidden />
        <span className="sr-only">{t('Included')}</span>
      </span>
    )
  }

  if (isLinkCell(value)) {
    return (
      <a
        href={value.href}
        className="text-[13px] link-neutral"
        target="_blank"
        rel="noopener noreferrer"
      >
        {t(value.text)}
      </a>
    )
  }

  return (
    <span className="inline-block max-w-full whitespace-normal break-words text-[13px] leading-5 text-muted-foreground">
      {t(value)}
    </span>
  )
}

export function ComparisonRowLabel({
  title,
  info,
}: {
  title: string
  info?: string
}) {
  const t = useT()
  return (
    <div className="flex items-center gap-1.5 text-start">
      <span className="text-[13px] font-medium text-foreground">{t(title)}</span>
      {info ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              className="inline-flex size-5 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              aria-label={`${t('More about')} ${t(title)}`}
            >
              <Info className="size-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent side="top" className="max-w-xs text-[12px] leading-5">
            {t(info)}
          </TooltipContent>
        </Tooltip>
      ) : null}
    </div>
  )
}

export function getPlanCtaHref(planId: PlanId) {
  if (planId === 'enterprise') return SALES_FORM_ROUTE
  return '/sign-up'
}

export function getPlanCtaLabel(planId: PlanId) {
  if (planId === 'enterprise') return 'Contact us'
  return 'Start project'
}
