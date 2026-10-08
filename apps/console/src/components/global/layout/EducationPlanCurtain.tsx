import { useEffect, useLayoutEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { Check, ChevronDown, ExternalLink } from 'lucide-react'
import { EducationJoinPartnerHeader } from '@/components/pages/education/join/EducationJoinPartnerHeader'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { pricingPlans } from '@/lib/pricing/plans'
import {
  getProPlanBadgeLabelForPlans,
  startPricingPlan,
} from '@/lib/pricing/start-plan'
import type { PricingPlan } from '@/lib/pricing/types'
import { useSelectableBillingPlans } from '@/hooks/use-selectable-billing-plans'
import {
  analyticsAttrs,
  getUpgradePlanSelectAnalyticsAction,
} from '@/lib/analytics-actions'
import { BillingPlanTier } from '@/lib/constants/billing-plan'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canSnoozeEducationPlanReminder,
  daysUntilEducationPlanEnds,
  EDUCATION_PLAN_DISMISS_MS,
  getEducationPlanEndsAt,
  isEducationPlanEnded,
  isEducationPlanReminderDue,
  isEducationPlanReminderSnoozed,
  listEducationTransitionPlans,
  readEducationPlanDismissedUntil,
  writeEducationPlanDismissedUntil,
} from '@/lib/education/plan-expiry'
import { useT } from '@/lib/i18n/translate'
import { useOrganizationById, useOrganizations } from '@/lib/react-query/hooks'
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
const emphasisClassName = 'font-semibold text-foreground'

function marketingPlanForCanonical(
  canonical: CanonicalPlanId,
): PricingPlan | null {
  if (canonical === 'start') return startPricingPlan
  return pricingPlans.find((plan) => plan.id === canonical) ?? null
}

type EducationPlanCurtainProps = {
  orgId?: string | null
}

/**
 * The Education plan covers an organization for its first 6 months, and never
 * ends before November 1, 2026. During
 * the last 5 weeks the curtain is a reminder the team can dismiss for 24
 * hours. Once the plan ends it blocks the organization until the team picks
 * another plan.
 */
export function EducationPlanCurtain({ orgId }: EducationPlanCurtainProps) {
  const { features } = useConsoleProfile()
  const { organization } = useOrganizationById(
    features.billing ? orgId : undefined,
  )
  const endsAt = getEducationPlanEndsAt(organization?.$createdAt)
  if (
    !features.billing ||
    !orgId ||
    organization?.plan !== 'education' ||
    endsAt == null
  ) {
    return null
  }
  return (
    <EducationPlanCurtainBody
      orgId={orgId}
      organizationName={organization.name}
      endsAt={endsAt}
    />
  )
}

function EducationPlanCurtainBody({
  orgId,
  organizationName,
  endsAt,
}: {
  orgId: string
  organizationName: string
  endsAt: number
}) {
  const navigate = useNavigate()
  const [now, setNow] = useState(() => Date.now())
  const [dismissedUntil, setDismissedUntil] = useState<number | null>(null)
  const [storageReady, setStorageReady] = useState(false)
  const { organizations } = useOrganizations()

  useLayoutEffect(() => {
    setDismissedUntil(readEducationPlanDismissedUntil(orgId))
    setStorageReady(true)
    setNow(Date.now())
  }, [orgId])

  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 60_000)
    return () => window.clearInterval(id)
  }, [])

  const ended = isEducationPlanEnded(endsAt, now)
  const reminderDue = isEducationPlanReminderDue(endsAt, now)
  const snoozed = isEducationPlanReminderSnoozed(endsAt, dismissedUntil, now)
  const visible = ended || (reminderDue && storageReady && !snoozed)

  if (!visible) return null

  const canSnooze = canSnoozeEducationPlanReminder(endsAt, now)

  function dismiss() {
    if (!canSnooze) return
    const until = Date.now() + EDUCATION_PLAN_DISMISS_MS
    writeEducationPlanDismissedUntil(orgId, until)
    setDismissedUntil(until)
    setNow(Date.now())
  }

  return (
    <EducationPlanCurtainView
      ended={ended}
      daysLeft={daysUntilEducationPlanEnds(endsAt, now)}
      organizationName={organizationName}
      otherOrganizations={organizations
        .filter((org) => org.$id !== orgId)
        .map((org) => ({ id: org.$id, name: org.name }))}
      onDismiss={canSnooze ? dismiss : undefined}
      onContinue={(plan) =>
        navigate({ to: '/upgrade', search: { orgId, plan } })
      }
    />
  )
}

type EducationPlanCurtainViewProps = {
  ended: boolean
  daysLeft: number
  organizationName: string
  otherOrganizations: Array<{ id: string; name: string }>
  /** Omit to hide "Remind me later". */
  onDismiss?: () => void
  onContinue: (planId: string) => void
}

/** Curtain UI without organization data, so debug previews can render it. */
export function EducationPlanCurtainView({
  ended,
  daysLeft,
  organizationName,
  otherOrganizations,
  onDismiss,
  onContinue,
}: EducationPlanCurtainViewProps) {
  const t = useT()
  const [selectedPlan, setSelectedPlan] = useState<string | null>(null)
  const {
    billingPlans,
    selectablePlans,
    isLoading: plansLoading,
  } = useSelectableBillingPlans()

  const transitionPlans = useMemo(
    () => listEducationTransitionPlans(selectablePlans),
    [selectablePlans],
  )
  const plansReady = !plansLoading
  const proBadgeLabel = getProPlanBadgeLabelForPlans(transitionPlans)
  const threeUp = plansReady && transitionPlans.length >= 3
  const featureLimit = threeUp ? 3 : PLAN_FEATURE_LIMIT

  function continueWithPlan() {
    if (!selectedPlan) return
    onContinue(selectedPlan)
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="education-plan-title"
      className="fixed inset-0 z-[120] overflow-y-auto bg-background/95 backdrop-blur-sm"
    >
      <div className="flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
        <div className="w-full max-w-3xl">
          <div className="flex flex-col items-center text-center">
            <EducationJoinPartnerHeader />
            <Badge variant="processing" className="mt-5 text-[10px] shrink-0">
              {t('Action required')}
            </Badge>
            <h1
              id="education-plan-title"
              className="mt-3 text-[22px] font-semibold text-foreground"
            >
              {ended
                ? t(
                    'Congratulations on graduating from the Appwrite Education program!',
                  )
                : t(
                    'You are about to graduate from the Appwrite Education program!',
                  )}
            </h1>
          </div>

          <div className="mt-4 space-y-3 text-start text-[15px] leading-relaxed text-muted-foreground">
            <p>
              {ended
                ? t(
                    'We hope it helped you learn, build, and bring your first projects to life on Appwrite Cloud.',
                  )
                : t(
                    'We hope it has helped you learn, build, and bring your first projects to life on Appwrite Cloud.',
                  )}
            </p>
            {ended ? (
              <p>
                <strong className={emphasisClassName}>
                  {t(
                    'Your organization is now disabled until you choose a new plan.',
                  )}
                </strong>{' '}
                {t(
                  'Your projects and data are still here for now, but they will be deleted if you don’t choose a plan.',
                )}
              </p>
            ) : (
              <p>
                <strong className={emphasisClassName}>
                  {t('Choose a new plan to keep your projects and data.')}
                </strong>{' '}
                {t(
                  'When the Education plan ends, your organization will be disabled and its projects will later be deleted. Pick a plan now and everything stays exactly where you left it.',
                )}
              </p>
            )}
            {ended ? null : (
              <p className="text-[13px]">
                <span className="font-medium text-foreground">{daysLeft}</span>{' '}
                {t(daysLeft === 1 ? 'day left' : 'days left')}
              </p>
            )}
          </div>

          <div className={cn('mt-8', threeUp && 'lg:-mx-20')}>
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
                          id={`education-plan-${planId}`}
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
                                {t(proBadgeLabel)}
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
            {onDismiss ? (
              <Button variant="outline" onClick={onDismiss}>
                {t('Remind me later')}
              </Button>
            ) : null}
            <Button
              variant="brandCta"
              disabled={!selectedPlan}
              onClick={continueWithPlan}
            >
              {t('Continue')}
            </Button>
          </div>

          {otherOrganizations.length > 0 ? (
            <div className="mt-8 flex justify-center border-t border-border pt-5">
              <OrganizationSwitcher
                organizationName={organizationName}
                otherOrganizations={otherOrganizations}
              />
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}

function organizationInitial(name: string) {
  return (name.trim() || '?').charAt(0).toUpperCase()
}

/** Same chip-and-dropdown pattern as `AuthAccountChip`, for organizations. */
function OrganizationSwitcher({
  organizationName,
  otherOrganizations,
}: {
  organizationName: string
  otherOrganizations: Array<{ id: string; name: string }>
}) {
  const t = useT()
  const currentInitial = organizationInitial(organizationName)

  return (
    <div className="flex max-w-full flex-wrap items-center justify-center gap-x-2 gap-y-1.5">
      <p className="shrink-0 text-[12px] text-muted-foreground">
        {t('Switch organization')}
      </p>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button
            type="button"
            className="flex max-w-full cursor-pointer items-center gap-1.5 rounded-lg border border-border bg-muted/30 px-2.5 py-1.5 text-[12px] font-medium text-foreground transition hover:bg-muted/50"
          >
            <span className="flex size-5 items-center justify-center rounded-md bg-muted text-[10px] font-semibold text-muted-foreground">
              {currentInitial}
            </span>
            <span className="truncate">{organizationName}</span>
            <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="center" className="z-[130] w-72">
          <div className="flex items-center gap-2 px-2 py-1.5">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-semibold text-muted-foreground">
              {currentInitial}
            </span>
            <span className="min-w-0 flex-1 truncate text-start text-[13px]">
              {organizationName}
            </span>
            <Check className="size-4 shrink-0 text-muted-foreground" />
          </div>
          <DropdownMenuSeparator />
          {otherOrganizations.map((org) => (
            <DropdownMenuItem key={org.id} asChild>
              <Link
                to="/organizations/$orgId"
                params={{ orgId: org.id }}
                className="flex items-center gap-2"
              >
                <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-semibold text-muted-foreground">
                  {organizationInitial(org.name)}
                </span>
                <span className="min-w-0 flex-1 truncate text-start text-[13px]">
                  {org.name}
                </span>
              </Link>
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
