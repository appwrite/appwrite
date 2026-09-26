import { useEffect, useLayoutEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { Check, ExternalLink } from 'lucide-react'
import { EducationJoinPartnerHeader } from '@/components/pages/education/join/EducationJoinPartnerHeader'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { pricingPlans } from '@/lib/pricing/plans'
import { startPricingPlan } from '@/lib/pricing/start-plan'
import type { PricingPlan } from '@/lib/pricing/types'
import {
  useVisitorCountryCode,
  useVisitorCountryResolutionComplete,
} from '@/hooks/use-visitor-country'
import {
  analyticsAttrs,
  getUpgradePlanSelectAnalyticsAction,
} from '@/lib/analytics-actions'
import { BillingPlanTier } from '@/lib/constants/billing-plan'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  daysUntilEducationProgramEnds,
  EDUCATION_SUNSET_DISMISS_MS,
  isEducationProgramEnded,
  isEducationSunsetSnoozed,
  listEducationTransitionPlans,
  readEducationSunsetDismissedUntil,
  writeEducationSunsetDismissedUntil,
} from '@/lib/education/sunset'
import { useT } from '@/lib/i18n/translate'
import {
  useBillingPlans,
  useOrganizationById,
  useOrganizations,
} from '@/lib/react-query/hooks'
import {
  getPlanCanonicalFromRecord,
  resolveOrganizationPlanDisplayLabel,
  type CanonicalPlanId,
} from '@/lib/utils/plan-filter'
import { cn } from '@/lib/utils'

const PLAN_FEATURE_LIMIT = 4
const PRO_CURTAIN_HIGHLIGHTS = [
  '2TB bandwidth',
  '150GB storage',
  '3.5M executions',
  'Daily backups, email support, and more',
] as const
const EDUCATION_SUNSET_POST =
  '/blog/post/sunsetting-appwrite-education-program'
const emphasisClassName = 'font-semibold text-foreground'

function marketingPlanForCanonical(
  canonical: CanonicalPlanId,
): PricingPlan | null {
  if (canonical === 'start') return startPricingPlan
  return pricingPlans.find((plan) => plan.id === canonical) ?? null
}

type EducationSunsetCurtainProps = {
  orgId?: string | null
}

/**
 * Blocks an Education organization until the team picks a replacement plan.
 * Dismiss lasts 24 hours. From November 1, 2026 the curtain stays up.
 */
export function EducationSunsetCurtain({ orgId }: EducationSunsetCurtainProps) {
  const { features } = useConsoleProfile()
  const { organization } = useOrganizationById(
    features.billing ? orgId : undefined,
  )
  if (!features.billing || !orgId || organization?.plan !== 'education') {
    return null
  }
  return <EducationSunsetCurtainBody orgId={orgId} />
}

function EducationSunsetCurtainBody({ orgId }: { orgId: string }) {
  const t = useT()
  const navigate = useNavigate()
  const [now, setNow] = useState(() => Date.now())
  const [dismissedUntil, setDismissedUntil] = useState<number | null>(null)
  const [storageReady, setStorageReady] = useState(false)
  const [selectedPlan, setSelectedPlan] = useState<string | null>(null)

  useLayoutEffect(() => {
    setDismissedUntil(readEducationSunsetDismissedUntil(orgId))
    setStorageReady(true)
    setNow(Date.now())
  }, [orgId])

  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 60_000)
    return () => window.clearInterval(id)
  }, [])

  const ended = isEducationProgramEnded(now)
  const snoozed = isEducationSunsetSnoozed(dismissedUntil, now)
  const visible = ended || (storageReady && !snoozed)

  const { plans: billingPlans, isLoading: billingPlansLoading } =
    useBillingPlans()
  const visitorCountryCode = useVisitorCountryCode()
  const visitorCountryReady = useVisitorCountryResolutionComplete()
  const { organizations } = useOrganizations()

  const transitionPlans = useMemo(
    () => listEducationTransitionPlans(billingPlans, visitorCountryCode),
    [billingPlans, visitorCountryCode],
  )
  const plansReady = visitorCountryReady && !billingPlansLoading
  const threeUp = plansReady && transitionPlans.length >= 3
  const featureLimit = threeUp ? 3 : PLAN_FEATURE_LIMIT
  const otherOrganizations = organizations.filter((org) => org.$id !== orgId)

  if (!visible) return null

  const daysLeft = daysUntilEducationProgramEnds(now)

  function dismiss() {
    if (ended) return
    const until = Date.now() + EDUCATION_SUNSET_DISMISS_MS
    writeEducationSunsetDismissedUntil(orgId, until)
    setDismissedUntil(until)
    setNow(Date.now())
  }

  function continueWithPlan() {
    if (!selectedPlan) return
    navigate({
      to: '/upgrade',
      search: { orgId, plan: selectedPlan },
    })
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="education-sunset-title"
      className="fixed inset-0 z-[120] overflow-y-auto bg-background/95 backdrop-blur-sm"
    >
      <div className="flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
        <div className={cn('w-full', threeUp ? 'max-w-4xl' : 'max-w-3xl')}>
          <div className="flex flex-col items-center text-center">
            <EducationJoinPartnerHeader />
            <Badge variant="warning" className="mt-5 text-[10px] shrink-0">
              {t('Action required')}
            </Badge>
            <h1
              id="education-sunset-title"
              className="mt-3 text-[22px] font-semibold text-foreground"
            >
              {ended
                ? t('The Education plan has ended')
                : t(
                    'Appwrite is leaving the GitHub Student Developer Pack',
                  )}
            </h1>
          </div>

          <div className="mt-4 space-y-3 text-start text-[15px] leading-relaxed text-muted-foreground">
            {ended ? (
              <>
                <p>
                  {t(
                    'Many of you used the Education plan to learn and build your first projects on Appwrite Cloud. Closing it was a hard call, and we are grateful to the GitHub team for making it possible.',
                  )}
                </p>
                <p>
                  <strong className={emphasisClassName}>
                    {t('Choose a plan to keep this organization.')}
                  </strong>{' '}
                  {t('Your projects are exactly where you left them.')}
                </p>
              </>
            ) : (
              <>
                <p>
                  <strong className={emphasisClassName}>
                    {t('The Education plan ends on November 1, 2026.')}
                  </strong>{' '}
                  {t(
                    'Many of you used it to learn, finish coursework, and ship your first projects on Appwrite Cloud. That is exactly what we hoped for, and we are grateful to the GitHub team for making it possible.',
                  )}
                </p>
                <p>
                  {t(
                    'The plan also became the main path for abuse on Appwrite Cloud, and we can no longer keep it open. If you followed the terms, we are sorry this affects you.',
                  )}{' '}
                  <strong className={emphasisClassName}>
                    {t('Nothing changes until November 1.')}
                  </strong>{' '}
                  {t(
                    'Your projects stay where they are. When you are ready, pick the plan that fits what you are building next.',
                  )}
                </p>
              </>
            )}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px]">
              <a
                href={EDUCATION_SUNSET_POST}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 font-medium text-foreground underline underline-offset-2"
              >
                {t('Read the full announcement')}
                <ExternalLink className="size-3.5" aria-hidden />
              </a>
              {ended ? null : (
                <span>
                  <span className="font-medium text-foreground">
                    {daysLeft}
                  </span>{' '}
                  {t(
                    daysLeft === 1
                      ? 'day left before November 1'
                      : 'days left before November 1',
                  )}
                </span>
              )}
            </div>
          </div>

          <div className="mt-8">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <h2 className="text-[15px] font-semibold text-foreground">
                {t('Choose a new plan')}
              </h2>
              <Button
                variant="outline"
                size="sm"
                className="h-8 w-fit gap-1.5 text-[13px]"
                asChild
              >
                <a
                  href="/pricing"
                  target="_blank"
                  rel="noopener noreferrer"
                  {...analyticsAttrs('upgrade-view-pricing')}
                >
                  {t('Compare plans')}
                  <ExternalLink className="size-3.5" aria-hidden />
                </a>
              </Button>
            </div>
            {plansReady && transitionPlans.length === 0 ? (
              <p className="mt-3 text-[13px] text-muted-foreground">
                {t('Plans could not be loaded. Refresh and try again.')}
              </p>
            ) : null}
            {plansReady && transitionPlans.length > 0 ? (
              <RadioGroup
                value={selectedPlan ?? undefined}
                onValueChange={setSelectedPlan}
                className={cn(
                  'mt-4 grid gap-3',
                  threeUp ? 'md:grid-cols-3' : 'sm:grid-cols-2',
                )}
              >
                {transitionPlans.map(([planId, plan]) => {
                  const planName = resolveOrganizationPlanDisplayLabel({
                    billingPlan: planId,
                    planName: plan.name ?? null,
                    planId: plan.$id,
                  })
                  const canonical = getPlanCanonicalFromRecord(
                    planId,
                    billingPlans,
                  )
                  const marketing = marketingPlanForCanonical(canonical)
                  const price = plan.price ?? 0
                  const highlights =
                    canonical === 'pro'
                      ? [
                          ...PRO_CURTAIN_HIGHLIGHTS.slice(0, featureLimit - 1),
                          PRO_CURTAIN_HIGHLIGHTS[
                            PRO_CURTAIN_HIGHLIGHTS.length - 1
                          ],
                        ]
                      : marketing?.features.slice(0, featureLimit)
                  const isSelected = selectedPlan === planId
                  const isRecommended =
                    (plan.$id ?? planId) === BillingPlanTier.Tier1 ||
                    planId === BillingPlanTier.Tier1
                  const planSelectAnalytics =
                    getUpgradePlanSelectAnalyticsAction(planId) ??
                    getUpgradePlanSelectAnalyticsAction(planName)

                  return (
                    <div
                      key={planId}
                      onClick={() => setSelectedPlan(planId)}
                      className={cn(
                        'flex h-full cursor-pointer flex-col rounded-xl border bg-card p-5 text-start transition-colors',
                        isSelected
                          ? 'border-primary ring-1 ring-primary'
                          : 'border-border hover:border-primary/30',
                      )}
                      {...(planSelectAnalytics
                        ? analyticsAttrs(planSelectAnalytics)
                        : {})}
                    >
                      <div className="flex items-start gap-2.5">
                        <RadioGroupItem
                          value={planId}
                          id={`education-sunset-${planId}`}
                          aria-label={marketing?.name ?? planName}
                          className="pointer-events-none mt-0.5"
                        />
                        <div className="min-w-0 flex-1">
                        <div className="flex min-h-5 flex-wrap items-center gap-2">
                          <span className="text-[15px] font-semibold text-foreground">
                            {marketing?.name ?? planName}
                          </span>
                          {isRecommended ? (
                            <Badge
                              variant="success"
                              className="h-5 shrink-0 px-2 py-0.5 text-[10px] font-medium"
                            >
                              {t('Recommended')}
                            </Badge>
                          ) : null}
                        </div>
                        <p className="mt-3 flex min-h-8 flex-wrap items-baseline gap-x-1.5">
                          {marketing?.pricePrefix ? (
                            <span className="text-[12px] text-muted-foreground">
                              {t(marketing.pricePrefix)}
                            </span>
                          ) : null}
                          <span className="text-[32px] font-normal leading-none tracking-tight text-foreground">
                            {marketing
                              ? marketing.price
                              : price > 0
                                ? `$${Number.isInteger(price) ? price : price.toFixed(2)}`
                                : '$0'}
                          </span>
                          {marketing?.priceSuffix ? (
                            <span className="text-[14px] text-muted-foreground">
                              {t(marketing.priceSuffix)}
                            </span>
                          ) : price > 0 ? (
                            <span className="text-[14px] text-muted-foreground">
                              {t('per month')}
                            </span>
                          ) : null}
                        </p>
                        <p
                          className={cn(
                            'mt-3 text-[13px] leading-5 text-muted-foreground',
                            threeUp ? 'min-h-15' : 'min-h-10',
                          )}
                        >
                          {canonical === 'pro'
                            ? t(
                                'Production resources for the projects you want to keep.',
                              )
                            : marketing
                              ? t(marketing.description)
                              : plan.desc}
                        </p>
                        {highlights && highlights.length > 0 ? (
                          <div className="mt-4 border-t border-border pt-4">
                            <ul className="space-y-2">
                              {highlights.map((feature) => (
                                <li
                                  key={feature}
                                  className="flex items-start gap-2 text-[13px] text-muted-foreground"
                                >
                                  <Check
                                    className="mt-0.5 size-3.5 shrink-0 text-[var(--brand-cta)]"
                                    aria-hidden
                                  />
                                  <span>{t(feature)}</span>
                                </li>
                              ))}
                            </ul>
                          </div>
                        ) : null}
                        {marketing?.footnote ? (
                          <p className="mt-3 text-[12px] leading-5 text-muted-foreground">
                            {t(marketing.footnote)}
                          </p>
                        ) : null}
                        </div>
                      </div>
                    </div>
                  )
                })}
              </RadioGroup>
            ) : null}
          </div>

          <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            {ended ? null : (
              <Button variant="outline" onClick={dismiss}>
                {t('Remind me later')}
              </Button>
            )}
            <Button
              variant="brandCta"
              disabled={!selectedPlan}
              onClick={continueWithPlan}
            >
              {t('Continue')}
            </Button>
          </div>

          {otherOrganizations.length > 0 ? (
            <div className="mt-8 border-t border-border pt-4 text-center">
              <p className="text-[13px] text-muted-foreground">
                {t('Switch organization')}
              </p>
              <div className="mt-2 flex flex-col items-center gap-1">
                {otherOrganizations.map((org) => (
                  <Button key={org.$id} variant="ghost" asChild>
                    <Link
                      to="/organizations/$orgId"
                      params={{ orgId: org.$id }}
                    >
                      {org.name}
                    </Link>
                  </Button>
                ))}
              </div>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}
