import { useState } from 'react'
import type { BillingPlanTier } from '@/lib/constants/billing-plan'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Label } from '@/components/ui/label'
import { ChevronDown, Info, ExternalLink } from '@/lib/icons'
import {
  getPlanCanonicalFromRecord,
  getPlanNameFromTier,
  isFreePlanRef,
  resolveBillingPlanRecord,
  resolveOrganizationPlanDisplayLabel,
} from '@/lib/utils/plan-filter'
import { cn } from '@/lib/utils'
import { CONTACT_ENTERPRISE_URL } from '@/lib/pricing/constants'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import {
  analyticsAttrs,
  getUpgradePlanSelectAnalyticsAction,
} from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL || CONTACT_ENTERPRISE_URL

const ENTERPRISE_INTRO =
  'Custom plans for teams that need negotiated limits, compliance, premium support, and tailored billing.'

const ENTERPRISE_WHO_SHOULD_REACH_OUT = [
  'Organizations with compliance or procurement requirements',
  'Companies needing annual contracts, custom SLAs, or dedicated support',
  'Teams that need custom resource limits or volume pricing',
]

const ENTERPRISE_WHEN_TO_REACH_OUT = [
  'When you need SOC 2, BAA, 24/7 support, or a success manager',
  'During vendor review, security assessment, or enterprise procurement',
  'When pay-as-you-go plans do not meet your support or billing needs',
]

const planCardShellClassName =
  'flex items-start gap-4 rounded-lg border p-4 transition-colors'
const planCardBodyClassName = 'flex-1 min-w-0'

const planCardContentClassName = 'space-y-1.5'
const planTitleRowClassName = 'flex items-center gap-2 flex-wrap'
const planListClassName = 'grid gap-3'

const ENTERPRISE_COLLAPSED_HINT =
  'Need compliance, custom SLAs, or volume pricing?'

const FREE_PLAN_CONFLICT_DESCRIPTION =
  'Only one free organization per account.'

interface PlanSelectionProps {
  plans: Record<string, unknown>
  currentPlan: BillingPlanTier | string
  selectedPlan: BillingPlanTier | null
  onPlanSelect: (plan: BillingPlanTier) => void
  selfService: boolean
  hasFreeOrgs: boolean
  isCreateMode?: boolean
  variant?: 'card' | 'inline'
}

export function PlanSelection({
  plans,
  currentPlan,
  selectedPlan,
  onPlanSelect,
  selfService,
  hasFreeOrgs,
  isCreateMode = false,
  variant = 'card',
}: PlanSelectionProps) {
  const t = useT()
  const [enterpriseOpen, setEnterpriseOpen] = useState(false)
  const availablePlans =
    plans && typeof plans === 'object' ? Object.entries(plans) : []

  const planCatalog = plans as Record<
    string,
    { $id?: string; name?: string; order?: number; price?: number }
>

  const isOrganizationOnFreePlan =
    !isCreateMode && isFreePlanRef(currentPlan as string, planCatalog)

  const isCurrentPlan = (planTier: string) => {
    if (isCreateMode) return false
    return (
      planTier === currentPlan ||
      getPlanCanonicalFromRecord(planTier, planCatalog) ===
        getPlanCanonicalFromRecord(currentPlan as string, planCatalog)
    )
  }

  const isFreePlan = (planTier: string) => {
    return isFreePlanRef(planTier, planCatalog)
  }

  const isFreeDisabledByAccountLimit = (planTier: string) =>
    selfService &&
    isFreePlan(planTier) &&
    hasFreeOrgs &&
    (isCreateMode || !isOrganizationOnFreePlan)

  const isDisabled = (planTier: string) => {
    if (!selfService) return true
    if (isCreateMode && isFreeDisabledByAccountLimit(planTier)) return true
    return false
  }

  const hasFreePlanConflict = (planTier: string) =>
    isFreeDisabledByAccountLimit(planTier)

  const selectedPlanIsFree =
    !!selectedPlan &&
    ((resolveBillingPlanRecord(selectedPlan, planCatalog)?.price ?? 0) === 0 ||
      isFreePlanRef(selectedPlan, planCatalog))

  const showEnterpriseSection = !!selectedPlan && !selectedPlanIsFree

  const currentCanonical = getPlanNameFromTier(currentPlan as string)
  const isEnterpriseCurrent =
    currentCanonical === 'custom' ||
    (currentPlan as string).toLowerCase() === 'enterprise' ||
    (currentPlan as string).toLowerCase() === 'ent-1'

  const enterpriseDetails = (
    <>
      <div className="border-t border-border" />
      <div className="px-6 py-5 grid gap-6 sm:grid-cols-2">
        <div>
          <p className="text-[13px] font-medium text-foreground">
            {t('Who should reach out')}
          </p>
          <ul className="mt-2 space-y-2 text-[13px] text-muted-foreground leading-relaxed list-disc ps-4">
            {ENTERPRISE_WHO_SHOULD_REACH_OUT.map((item) => (
              <li key={item}>{t(item)}</li>
            ))}
          </ul>
        </div>
        <div>
          <p className="text-[13px] font-medium text-foreground">
            {t('When to reach out')}
          </p>
          <ul className="mt-2 space-y-2 text-[13px] text-muted-foreground leading-relaxed list-disc ps-4">
            {ENTERPRISE_WHEN_TO_REACH_OUT.map((item) => (
              <li key={item}>{t(item)}</li>
            ))}
          </ul>
        </div>
      </div>
    </>
  )

  const contactSalesButton = (
    <Button
      variant="outline"
      size="sm"
      className="h-8 shrink-0 text-[13px]"
      asChild
    >
      <a
        href={CONTACT_SALES_URL}
        target="_blank"
        rel="noopener noreferrer"
        {...analyticsAttrs('upgrade-contact-sales')}
      >
        {t('Contact sales')}
      </a>
    </Button>
  )

  const enterpriseSection = isCreateMode ? (
    <Collapsible
      open={enterpriseOpen}
      onOpenChange={setEnterpriseOpen}
      className="mt-8 rounded-xl border border-border bg-card/50 overflow-hidden"
>
      <div className="px-6 py-5">
        <div className="flex items-start justify-between gap-6">
          <div className="min-w-0 flex-1 space-y-2">
            <span className="text-[15px] font-semibold text-foreground">
              {t('Enterprise')}
            </span>
            <p className="text-[13px] text-muted-foreground leading-relaxed">
              {t(ENTERPRISE_COLLAPSED_HINT)}
            </p>
          </div>
          {contactSalesButton}
        </div>
      </div>

      <CollapsibleContent>
        <div className="border-t border-border px-6 py-4">
          <p className="text-[13px] text-muted-foreground leading-relaxed">
            {t(ENTERPRISE_INTRO)}
          </p>
        </div>
        {enterpriseDetails}
      </CollapsibleContent>

      <CollapsibleTrigger asChild>
        <button
          type="button"
          className="flex w-full cursor-pointer items-center justify-between gap-3 border-t border-border px-6 py-3 text-[13px] text-muted-foreground transition-colors hover:bg-muted/30 hover:text-foreground"
          {...analyticsAttrs('upgrade-enterprise-learn-more')}
        >
          <span>
            {enterpriseOpen ? t('Show less') : t('Learn more')}
          </span>
          <ChevronDown
            className={cn(
              'h-4 w-4 shrink-0 transition-transform duration-200',
              enterpriseOpen && 'rotate-180',
            )}
          />
        </button>
      </CollapsibleTrigger>
    </Collapsible>
  ) : (
    <div className="mt-10 rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-5">
        <div className="flex items-start justify-between gap-6">
          <div className="min-w-0 flex-1 space-y-2">
            <div className={planTitleRowClassName}>
              <span className="text-[15px] font-semibold text-foreground">
                {t('Enterprise')}
              </span>
              {isEnterpriseCurrent && (
                <Badge
                  variant="info"
                  className="text-[10px] font-medium px-2 py-0.5 h-5 shrink-0"
>
                  {t('Current plan')}
                </Badge>
              )}
            </div>
            <p className="text-[13px] text-muted-foreground leading-relaxed">
              {t(ENTERPRISE_INTRO)}
            </p>
          </div>
          {contactSalesButton}
        </div>
      </div>
      {enterpriseDetails}
    </div>
  )

  const radioGroupContent = (
    <>
      {/* Self-service restriction alert */}
      {!selfService && (
        <Alert className="mb-4">
          <Info className="h-4 w-4" />
          <AlertTitle>{t('Plan Changes Restricted')}</AlertTitle>
          <AlertDescription className="mt-2">
            {t('Plan changes are not available for self-service. Please contact support to change your plan.')}
          </AlertDescription>
        </Alert>
      )}

      <RadioGroup
        value={selectedPlan || undefined}
        onValueChange={(value) => onPlanSelect(value as BillingPlanTier)}
        className={planListClassName}
>
        {availablePlans.map(([planTier, planData]) => {
            // Use plan name from API response, fallback to derived name
            const planName = resolveOrganizationPlanDisplayLabel({
              billingPlan: planTier,
              planName:
                (planData as { name?: string } | undefined)?.name ?? null,
              planId: (planData as { $id?: string } | undefined)?.$id,
            })
            const disabled = isDisabled(planTier)
            const isCurrent = isCurrentPlan(planTier)
            const price = planData?.price || 0
            // API uses 'desc' not 'description'
            const description = planData?.desc || planData?.description
            const planDescription = hasFreePlanConflict(planTier)
              ? t(FREE_PLAN_CONFLICT_DESCRIPTION)
              : description
            const isSelected = selectedPlan === planTier
            const isRecommendedPlan =
              getPlanCanonicalFromRecord(
                planTier,
                plans as Record<string, { $id?: string; name?: string; order?: number; price?: number }>,
              ) === 'pro'

            const handleSelect = () => {
              if (disabled) return
              onPlanSelect(planTier as BillingPlanTier)
            }

            const planSelectAnalytics =
              getUpgradePlanSelectAnalyticsAction(planTier) ??
              getUpgradePlanSelectAnalyticsAction(planName)

            return (
              <div
                key={planTier}
                role="radio"
                aria-checked={isSelected}
                aria-disabled={disabled}
                tabIndex={disabled ? -1 : 0}
                onClick={handleSelect}
                onKeyDown={(event) => {
                  if (disabled) return
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault()
                    handleSelect()
                  }
                }}
                className={cn(
                  planCardShellClassName,
                  isSelected
                    ? 'border-primary bg-card'
                    : 'border-border bg-card/50 hover:border-primary/30',
                  disabled
                    ? 'cursor-not-allowed opacity-50'
                    : 'cursor-pointer',
                )}
                {...(planSelectAnalytics
                  ? analyticsAttrs(planSelectAnalytics)
                  : {})}
              >
                <RadioGroupItem
                  value={planTier}
                  id={planTier}
                  disabled={disabled}
                  className="mt-0.5 shrink-0 pointer-events-none"
                />
                <Label
                  htmlFor={planTier}
                  className={cn(
                    planCardBodyClassName,
                    'pointer-events-none',
                    disabled ? 'cursor-not-allowed' : 'cursor-pointer',
                  )}
>
                  <div className={planCardContentClassName}>
                    <div className={planTitleRowClassName}>
                      <span className="text-[15px] font-semibold text-foreground">
                        {planName}
                      </span>
                      {isRecommendedPlan && !isCurrent && (
                        <Badge
                          variant="success"
                          className="text-[10px] font-medium px-2 py-0.5 h-5 shrink-0"
>
                          {t('Recommended')}
                        </Badge>
                      )}
                      {isCurrent && (
                        <Badge
                          variant="info"
                          className="text-[10px] font-medium px-2 py-0.5 h-5 shrink-0"
>
                          {t('Current plan')}
                        </Badge>
                      )}
                    </div>

                    {planDescription && (
                      <p className="text-[13px] text-muted-foreground leading-snug">
                        {planDescription}
                      </p>
                    )}

                    <div className="text-[13px] font-medium text-foreground">
                      {price > 0 ? (
                        <span>${price.toFixed(2)} {t('per month')}</span>
                      ) : (
                        <span>$0.00</span>
                      )}
                    </div>
                  </div>
                </Label>
              </div>
            )
          })}
      </RadioGroup>

      {showEnterpriseSection ? enterpriseSection : null}

      {variant === 'card' && (
        <div className="mt-4">
          <Button
            variant="ghost"
            size="sm"
            className="text-[13px] text-muted-foreground"
            asChild
>
            <MarketingSiteLink
              href="/pricing"
              {...analyticsAttrs('upgrade-view-pricing')}
            >
              {t('View detailed pricing')}
              <ExternalLink className="ms-1.5 h-3.5 w-3.5" />
            </MarketingSiteLink>
          </Button>
        </div>
      )}
    </>
  )

  if (variant === 'inline') {
    return <div>{radioGroupContent}</div>
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Select a plan')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Choose the plan that best fits your needs.')}
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-5">{radioGroupContent}</div>
    </div>
  )
}
