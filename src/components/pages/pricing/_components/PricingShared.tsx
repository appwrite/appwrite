import { Link } from '@tanstack/react-router'
import { Check, Info } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import { CONTACT_ENTERPRISE_URL } from '@/lib/pricing/constants'
import { pricingPlans } from '@/lib/pricing/plans'
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

const pricingPlanGlassClassName = pricingGlassSurfaceClassName

function isProPlan(plan: PricingPlan) {
  return plan.id === 'pro'
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

export function PricingPlanCard({ plan }: { plan: PricingPlan }) {
  const t = useT()
  const isPro = isProPlan(plan)

  return (
    <article
      className={cn(
        pricingPlanGlassClassName,
        'flex h-full flex-col p-6 sm:p-7',
        isPro && 'sm:p-7 lg:p-9 lg:shadow-lg',
      )}
    >
      <header className="relative z-[1] flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <h2
            className={cn(
              'font-semibold text-foreground',
              isPro ? 'text-[16px]' : 'text-[14px]',
            )}
          >
            {t(plan.name)}
          </h2>
          {plan.popular ? (
            <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
              {t('Popular')}
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
                isPro
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
                  isPro ? 'text-[16px]' : 'text-[14px]',
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
            isPro ? 'min-h-[3.5rem] text-[14px]' : 'min-h-[3.75rem] text-[13px]',
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
        <ul className={cn('min-h-0 flex-1 space-y-2.5', isPro && 'lg:space-y-3')}>
          {plan.features.map((feature) => (
            <li
              key={feature}
              className={cn(
                'flex items-start gap-2.5 text-muted-foreground',
                isPro ? 'text-[14px]' : 'text-[13px]',
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
  return (
    <div className="grid gap-6 sm:gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.14fr)_minmax(0,1fr)] lg:items-stretch lg:gap-10">
      {pricingPlans.map((plan) => (
        <div
          key={plan.id}
          className={cn(
            'flex h-full min-h-0',
            isProPlan(plan) &&
              'relative z-[1] origin-center scale-[1.02] self-center sm:scale-[1.04] lg:scale-[1.1]',
          )}
        >
          <PricingPlanCard plan={plan} />
        </div>
      ))}
    </div>
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

  return <span className="text-[13px] text-muted-foreground">{t(value)}</span>
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
  if (planId === 'enterprise') return CONTACT_ENTERPRISE_URL
  return '/sign-up'
}

export function getPlanCtaLabel(planId: PlanId) {
  if (planId === 'enterprise') return 'Contact us'
  return 'Start project'
}
