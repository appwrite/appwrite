import { useState, useEffect, useMemo, useRef } from 'react'
import { useParams, useNavigate, useSearch, Link } from '@tanstack/react-router'
import {
  BillingPlanTier,
  type BillingPlanTier as BillingPlanTierType,
} from '@/lib/constants/billing-plan'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertTriangle } from '@/lib/icons'
import { toast } from 'sonner'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  useOrganizationById,
  useOrganizationPlan,
  useOrganizationMemberships,
  useBillingPlans,
  useCouponAccount,
  useOrganizationUsage,
  useOrganizationProjects,
  useEstimationUpdatePlan,
  useUpdateOrganizationPlan,
  useUpdateSelectedProjects,
  useValidateOrganization,
  useCreateDowngradeFeedback,
  usePaymentMethods,
  useOrganizations,
} from '@/lib/react-query/hooks'
import { useSmartNavigation } from '@/lib/hooks/useSmartNavigation'
import { PlanSelection } from './change-plan/PlanSelection'
import { SelectPaymentMethod } from './change-plan/SelectPaymentMethod'
import { EstimatedTotalBox } from './change-plan/EstimatedTotalBox'
import { PlanComparisonBox } from './change-plan/PlanComparisonBox'
import { OrganizationUsageLimits } from './change-plan/OrganizationUsageLimits'
import { ValidateCreditModal } from './change-plan/ValidateCredit'
import { PaymentModal } from './Payment'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { confirmPayment } from '@/lib/utils/stripe'
import type { Models } from '@appwrite.io/console'

/**
 * ChangePlanWizardFullscreen Component
 *
 * Fullscreen wizard for upgrading or downgrading organization billing plans.
 * Handles plan selection, payment methods, project selection (for downgrades),
 * coupon codes, and member invites.
 */

export function ChangePlanWizardFullscreen() {
  const params = useParams({ strict: false })
  const navigate = useNavigate()
  const search = useSearch({ strict: false })
  const orgId = params.orgId as string | undefined

  // Smart navigation for cancel/close actions
  // No fallbackPath - uses internal console history or root
  const handleCancel = useSmartNavigation()

  // Fetch data using hooks (data is already prefetched by route loader)
  const { organization } = useOrganizationById(orgId)
  const { plan } = useOrganizationPlan(orgId)
  const { memberships, total: membersTotal } = useOrganizationMemberships(
    orgId,
    0,
    25,
    '',
  )
  const { organizations } = useOrganizations()
  const { plans: billingPlans, isLoading: plansLoading } = useBillingPlans()

  // Create members object for compatibility
  const members = useMemo(
    () => ({
      memberships: memberships || [],
      total: membersTotal || 0,
    }),
    [memberships, membersTotal],
  )

  // Check if user has a free organization.
  // org.plan is derived from billingPlan via getPlanNameFromTier() in useOrganizations(),
  // so 'free' already covers tier-0 / Tier0 variants - no need for additional checks.
  const hasFreeOrgs = useMemo(() => {
    return organizations.some((org) => org.plan === 'free')
  }, [organizations])

  // Determine default plan (Pro or Scale based on current plan)
  const defaultPlan = useMemo(() => {
    const currentPlanTier = organization?.billingPlan || 'tier-0'
    return currentPlanTier === 'tier-0' ? 'tier-1' : currentPlanTier
  }, [organization?.billingPlan])

  // Check if self-service is allowed (defaults to true)
  const selfService = plan?.selfService !== false

  // State management
  const [selectedPlan, setSelectedPlan] = useState<BillingPlanTierType | null>(
    null,
  )
  const [selectedCoupon, setSelectedCoupon] = useState<Models.Coupon | null>(
    null,
  )
  const [paymentMethodId, setPaymentMethodId] = useState<string | undefined>(
    undefined,
  )
  const [taxId, setTaxId] = useState<string>('')
  const [billingBudget, setBillingBudget] = useState<number | undefined>(
    undefined,
  )
  const [feedbackDowngradeReason, setFeedbackDowngradeReason] =
    useState<string>('')
  const [feedbackMessage, setFeedbackMessage] = useState<string>('')
  const [couponModalOpen, setCouponModalOpen] = useState(false)
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)
  const [usageLimitsComponentRef, setUsageLimitsComponentRef] = useState<{
    getSelectedProjects?: () => string[]
  } | null>(null)
  const handleUsageLimitsRef = (ref: unknown) =>
    setUsageLimitsComponentRef(
      ref as { getSelectedProjects?: () => string[] } | null,
    )

  // Fetch additional data
  const { paymentMethods } = usePaymentMethods()
  const { usage: orgUsage } = useOrganizationUsage(orgId)
  const { projects: allProjects } = useOrganizationProjects(orgId)

  // Get current plan tier
  const currentPlanTier = organization?.billingPlan || 'tier-0'
  const currentPlanEnum = useMemo(() => {
    try {
      return currentPlanTier as BillingPlanTierType
    } catch {
      return BillingPlanTier.Tier0
    }
  }, [currentPlanTier])

  // Mutations (defined early for use in useEffect)
  const updatePlanMutation = useUpdateOrganizationPlan()
  const updateSelectedProjectsMutation = useUpdateSelectedProjects()
  const validateOrganizationMutation = useValidateOrganization()
  const createDowngradeFeedbackMutation = useCreateDowngradeFeedback()

  // Handle payment confirmation redirect (only once)
  const paymentConfirmedHandled = useRef(false)
  useEffect(() => {
    const paymentType = search?.type as string
    if (
      paymentType === 'payment_confirmed' &&
      orgId &&
      !paymentConfirmedHandled.current
    ) {
      paymentConfirmedHandled.current = true
      const invites = (search?.invites as string)?.split(',') || []
      const handlePaymentConfirmation = async (
        organizationId: string,
        invites: string[],
      ) => {
        try {
          await validateOrganizationMutation.mutateAsync({
            organizationId,
            invites,
          })
          toast.success('Payment confirmed successfully')
          navigate({
            to: '/organizations/$orgId/settings/billing',
            params: { orgId: organizationId },
          })
        } catch (error) {
          toast.error(
            error instanceof Error
              ? error.message
              : 'Failed to validate payment',
          )
        }
      }
      handlePaymentConfirmation(orgId, invites)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search?.type, orgId, validateOrganizationMutation, navigate])

  // Initialize selected plan from URL or default (only once)
  const [planInitialized, setPlanInitialized] = useState(false)
  useEffect(() => {
    if (planInitialized) return // Only initialize once

    const planParam = search?.plan as string
    if (
      planParam &&
      Object.values(BillingPlanTier).includes(planParam as BillingPlanTierType)
    ) {
      setSelectedPlan(planParam as BillingPlanTierType)
      setPlanInitialized(true)
    } else if (
      defaultPlan &&
      Object.values(BillingPlanTier).includes(
        defaultPlan as BillingPlanTierType,
      )
    ) {
      setSelectedPlan(defaultPlan as BillingPlanTierType)
      setPlanInitialized(true)
    }
  }, [search?.plan, defaultPlan, planInitialized])

  // Get coupon from URL if provided
  const couponCodeFromUrl = search?.code as string | undefined
  const { coupon: couponFromUrl } = useCouponAccount(couponCodeFromUrl || null)

  // Update selected coupon when fetched from URL
  useEffect(() => {
    if (couponFromUrl) {
      setSelectedCoupon(couponFromUrl)
    }
  }, [couponFromUrl])

  // Set default payment method
  useEffect(() => {
    if (paymentMethodId) return // Already set, don't override

    if (organization?.paymentMethodId) {
      setPaymentMethodId(organization.paymentMethodId)
    } else if (paymentMethods.length > 0) {
      // Find first payment method with last4 (completed card)
      const completedMethod = paymentMethods.find(
        (pm: Models.PaymentMethod) => pm.last4,
      )
      if (completedMethod) {
        setPaymentMethodId(completedMethod.$id)
      }
    }
  }, [organization?.paymentMethodId, paymentMethods, paymentMethodId])

  // Determine if upgrade or downgrade
  const isUpgrade = useMemo(() => {
    if (!selectedPlan || !currentPlanEnum) return false
    const currentTier =
      parseInt(currentPlanEnum.replace('tier-', '').replace('Tier', '')) || 0
    const selectedTier =
      parseInt(selectedPlan.replace('tier-', '').replace('Tier', '')) || 0
    return selectedTier > currentTier
  }, [selectedPlan, currentPlanEnum])

  const isDowngrade = useMemo(() => {
    if (!selectedPlan || !currentPlanEnum) return false
    const currentTier =
      parseInt(currentPlanEnum.replace('tier-', '').replace('Tier', '')) || 0
    const selectedTier =
      parseInt(selectedPlan.replace('tier-', '').replace('Tier', '')) || 0
    return selectedTier < currentTier
  }, [selectedPlan, currentPlanEnum])

  // Get estimation for selected plan (only when plan is selected and not free)
  // Only fetch estimation when we have a selected plan and it's not the current plan
  const shouldFetchEstimation =
    selectedPlan &&
    selectedPlan !== currentPlanEnum &&
    selectedPlan !== BillingPlanTier.Tier0 &&
    orgId

  // Validate coupon ID - must be a non-empty string that's a valid UID
  const validCouponId = useMemo(() => {
    if (!selectedCoupon) return undefined

    // Try to get the ID - could be $id or code, but must be a string
    const couponIdValue = selectedCoupon.$id || selectedCoupon.code

    // Ensure it's a string (not object, array, null, etc.)
    if (typeof couponIdValue !== 'string') {
      console.warn(
        'Invalid coupon ID type:',
        typeof couponIdValue,
        couponIdValue,
      )
      return undefined
    }

    const couponId = couponIdValue.trim()

    // UID validation: non-empty, max 36 chars, valid chars only, can't start with underscore
    if (
      couponId &&
      couponId.length > 0 &&
      couponId.length <= 36 &&
      /^[a-zA-Z0-9][a-zA-Z0-9_]*$/.test(couponId) &&
      !couponId.startsWith('_')
    ) {
      return couponId
    }
    return undefined
  }, [selectedCoupon])

  const estimation = useEstimationUpdatePlan(
    shouldFetchEstimation ? orgId : null,
    shouldFetchEstimation ? selectedPlan : null,
    shouldFetchEstimation ? validCouponId : undefined,
    [],
  )

  // Get target plan info
  const targetPlanInfo = selectedPlan ? billingPlans[selectedPlan] : null
  const targetProjectsLimit = targetPlanInfo?.projects ?? 0
  const needsProjectSelection =
    isDowngrade &&
    targetProjectsLimit > 0 &&
    allProjects.length > targetProjectsLimit

  // Check if submit button should be disabled
  const isButtonDisabled = useMemo(() => {
    if (!selfService) return true
    if (!selectedPlan || selectedPlan === currentPlanEnum) return true
    if (updatePlanMutation.isPending) return true

    // For upgrades: payment method required
    if (isUpgrade) {
      if (!paymentMethodId) return true
      const selectedMethod = paymentMethods.find(
        (pm: Models.PaymentMethod) => pm.$id === paymentMethodId,
      )
      if (!selectedMethod?.last4) return true // Must be a completed card
    }

    // For downgrades: check project selection if needed
    if (isDowngrade) {
      if (needsProjectSelection) {
        if (!usageLimitsComponentRef) return true
        const selected = usageLimitsComponentRef.getSelectedProjects?.()
        if (!selected || selected.length !== targetProjectsLimit) return true
      }

      // For free plan: feedback required (message only, like old console)
      if (selectedPlan === BillingPlanTier.Tier0 && !hasFreeOrgs) {
        if (!feedbackMessage.trim()) return true
      }

      // One free org per account: cannot downgrade to Free if user has another free org
      if (selectedPlan === BillingPlanTier.Tier0 && hasFreeOrgs) return true
    }

    return false
  }, [
    selfService,
    selectedPlan,
    currentPlanEnum,
    isUpgrade,
    isDowngrade,
    paymentMethodId,
    paymentMethods,
    needsProjectSelection,
    usageLimitsComponentRef,
    targetProjectsLimit,
    feedbackMessage,
    hasFreeOrgs,
    updatePlanMutation.isPending,
  ])

  // Handle upgrade
  const handleUpgrade = async () => {
    if (!orgId || !selectedPlan || !paymentMethodId) return

    try {
      const result = await updatePlanMutation.mutateAsync({
        organizationId: orgId,
        billingPlan: selectedPlan,
        paymentMethodId,
        billingAddressId: undefined,
        couponId: selectedCoupon?.code ?? selectedCoupon?.$id,
        invites: [],
        budget: billingBudget,
        taxId: taxId || null,
      })

      // Payment authentication required (e.g. 3DS): handle inline via handleNextAction
      const resultObj = result as { clientSecret?: string; status?: number }
      if (resultObj?.clientSecret) {
        await confirmPayment({
          clientSecret: resultObj.clientSecret,
        })
      }

      // Validate the organization after payment (needed regardless of 3DS)
      await validateOrganizationMutation.mutateAsync({
        organizationId: orgId,
        invites: [],
      })

      toast.success('Plan updated successfully')
      navigate({
        to: '/organizations/$orgId/settings/billing',
        params: { orgId },
      })
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to update plan',
      )
    }
  }

  // Handle downgrade
  const handleDowngrade = async () => {
    if (!orgId || !selectedPlan) return

    try {
      // Update the plan
      await updatePlanMutation.mutateAsync({
        organizationId: orgId,
        billingPlan: selectedPlan,
        paymentMethodId,
      })

      // Update selected projects if plan has project limit
      if (needsProjectSelection && usageLimitsComponentRef) {
        const selected = usageLimitsComponentRef.getSelectedProjects?.()
        if (selected && selected.length > 0) {
          await updateSelectedProjectsMutation.mutateAsync({
            organizationId: orgId,
            projectIds: selected,
          })
        }
      }

      // Track feedback if downgrading to Free (reason optional, message required per old console)
      if (selectedPlan === BillingPlanTier.Tier0 && !hasFreeOrgs) {
        await createDowngradeFeedbackMutation.mutateAsync({
          organizationId: orgId,
          reason: feedbackDowngradeReason || 'other',
          message: feedbackMessage,
          fromPlanId: currentPlanEnum,
          toPlanId: selectedPlan,
        })
      }

      toast.success('Plan updated successfully')
      navigate({
        to: '/organizations/$orgId/settings/billing',
        params: { orgId },
      })
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to update plan',
      )
    }
  }

  // Handle submit
  const handleSubmit = () => {
    if (isUpgrade) {
      handleUpgrade()
    } else if (isDowngrade) {
      handleDowngrade()
    }
  }

  // Handle payment method added
  const handlePaymentMethodAdded = () => {
    setPaymentModalOpen(false)
    // Payment methods will be refetched automatically by the hook
  }

  // Show estimated total box conditions
  // Cast to string for defensive checks - the API may return plan tiers outside the known enum
  const currentTierStr = currentPlanEnum as string
  const selectedTierStr = selectedPlan as string | null
  const showEstimatedTotal =
    selectedTierStr &&
    selectedTierStr !== currentTierStr &&
    selectedTierStr !== BillingPlanTier.Tier0 &&
    currentTierStr !== 'custom' &&
    currentTierStr !== 'Custom'

  // Show plan comparison box conditions
  const showPlanComparison =
    !showEstimatedTotal ||
    selectedTierStr === BillingPlanTier.Tier0 ||
    selectedTierStr === currentTierStr ||
    currentTierStr === 'custom' ||
    currentTierStr === 'Custom'

  // Early return if critical data is missing
  if (!orgId) {
    return (
      <div className="flex h-full flex-col items-center justify-center">
        <p className="text-[13px] text-muted-foreground">
          Organization ID is required
        </p>
        <Button variant="outline" onClick={handleCancel} className="mt-4">
          Go Back
        </Button>
      </div>
    )
  }

  return (
    <WizardLayout
      title="Change plan"
      fullscreen
      fallbackPath={
        orgId ? `/organizations/${orgId}/settings/billing` : undefined
      }
      footerAlign="right"
      sidebar={
        <>
          {showEstimatedTotal && (
            <EstimatedTotalBox
              estimation={estimation.estimation}
              isLoading={estimation.isLoading}
              selectedPlan={selectedPlan}
              billingPlans={billingPlans}
              coupon={selectedCoupon}
              onCouponRemove={() => setSelectedCoupon(null)}
              budget={billingBudget}
              onBudgetChange={setBillingBudget}
            />
          )}

          {showPlanComparison && (
            <PlanComparisonBox
              currentPlan={currentPlanEnum}
              selectedPlan={selectedPlan}
              plans={billingPlans}
            />
          )}
        </>
      }
      footer={
        <>
          <Button
            variant="outline"
            onClick={handleCancel}
            disabled={updatePlanMutation.isPending}
          >
            Cancel
          </Button>
          <Button onClick={handleSubmit} disabled={isButtonDisabled}>
            Change plan
          </Button>
        </>
      }
    >
      {/* Select Plan Section */}
      <div>
        <h2 className="text-lg font-semibold text-foreground mb-2">
          Select plan
        </h2>
        {!selfService ? (
          <Alert className="mt-2">
            <AlertTriangle className="h-4 w-4" />
            <AlertTitle>Custom plan</AlertTitle>
            <AlertDescription className="mt-2">
              You are on a custom plan. To change your plan, contact your
              customer success manager or{' '}
              {orgId ? (
                <Link
                  to="/organizations/$orgId/support"
                  params={{ orgId }}
                  className="underline hover:text-foreground"
                >
                  contact support
                </Link>
              ) : (
                'contact support'
              )}
              .
            </AlertDescription>
          </Alert>
        ) : (
          <>
            <p className="text-[13px] text-muted-foreground mb-4">
              For more details on our plans, visit our{' '}
              <a
                href="https://appwrite.io/pricing"
                target="_blank"
                rel="noopener noreferrer"
                className="underline hover:text-foreground"
              >
                pricing page
              </a>
              .
            </p>
            {plansLoading ? (
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">
                    Loading plans...
                  </p>
                </div>
              </div>
            ) : billingPlans &&
              typeof billingPlans === 'object' &&
              Object.keys(billingPlans).length > 0 ? (
              <PlanSelection
                plans={billingPlans}
                currentPlan={currentPlanEnum}
                selectedPlan={selectedPlan}
                onPlanSelect={setSelectedPlan}
                selfService={selfService}
                hasFreeOrgs={hasFreeOrgs}
                variant="inline"
              />
            ) : (
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">
                    No plans available. Please try refreshing the page.
                  </p>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* Upgrade-specific sections */}
      {isUpgrade && selectedPlan && (
        <>
          <SelectPaymentMethod
            paymentMethods={paymentMethods}
            selectedPaymentMethodId={paymentMethodId}
            onPaymentMethodSelect={setPaymentMethodId}
            onAddPaymentMethod={() => setPaymentModalOpen(true)}
            taxId={taxId}
            onTaxIdChange={setTaxId}
            onAddCredits={() => setCouponModalOpen(true)}
            organizationId={orgId}
            onPaymentMethodAdded={handlePaymentMethodAdded}
          />
        </>
      )}

      {/* Downgrade-specific sections */}
      {isDowngrade && selectedPlan && (
        <>
          {/* One free org per account */}
          {selectedPlan === BillingPlanTier.Tier0 && hasFreeOrgs && (
            <Alert variant="destructive">
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>
                You can only have one free organization per account
              </AlertTitle>
              <AlertDescription className="mt-2">
                To downgrade this organization, first migrate or delete your
                existing free organization.{' '}
                <a
                  href="https://appwrite.io/docs/advanced/migrations/cloud"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline"
                >
                  Migration guide
                </a>
              </AlertDescription>
            </Alert>
          )}

          {/* Project Selection */}
          {needsProjectSelection && (
            <OrganizationUsageLimits
              projects={allProjects}
              orgUsage={orgUsage}
              members={members}
              organization={organization}
              targetLimit={targetProjectsLimit}
              onRef={handleUsageLimitsRef}
            />
          )}

          {/* Downgrade Alerts */}
          {selectedPlan === BillingPlanTier.Tier1 && (
            <Alert>
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>
                Monthly Charges for Extra Organization Members
              </AlertTitle>
              <AlertDescription className="mt-2">
                {targetPlanInfo?.addons?.seats?.price
                  ? `You will be charged $${targetPlanInfo.addons.seats.price} per month for each organization member beyond the plan limit.`
                  : 'You will be charged for each organization member beyond the plan limit.'}
              </AlertDescription>
            </Alert>
          )}

          {selectedPlan === BillingPlanTier.Tier0 && (
            <Alert variant="destructive">
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>Downgrading to Free Plan</AlertTitle>
              <AlertDescription className="mt-2">
                Your plan will change on{' '}
                {organization?.billingPlanDowngrade ||
                  'the end of your billing period'}
                . You will lose access to premium features and organization
                members beyond the free limit will be removed.
                <a
                  href="https://appwrite.io/docs/migration"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="ml-1 underline"
                >
                  Learn more about migration
                </a>
              </AlertDescription>
            </Alert>
          )}

          {/* Feedback Form for Free Plan (matches old console: "What wasn't working for you?" required) */}
          {selectedPlan === BillingPlanTier.Tier0 && !hasFreeOrgs && (
            <div>
              <h2 className="text-lg font-semibold text-foreground mb-2">
                Feedback
              </h2>
              <p className="text-[13px] text-muted-foreground mb-4">
                What wasn&apos;t working for you? Please share anything that
                influenced your decision to downgrade. This feedback helps us
                improve the platform.
              </p>
              <div className="space-y-4">
                <div>
                  <Label
                    htmlFor="downgrade-message"
                    className="text-[13px] font-medium"
                  >
                    Your feedback <span className="text-red-500">*</span>
                  </Label>
                  <Textarea
                    id="downgrade-message"
                    value={feedbackMessage}
                    onChange={(e) => setFeedbackMessage(e.target.value)}
                    placeholder="Please share anything that influenced your decision to downgrade..."
                    className="mt-2 min-h-[100px]"
                    required
                  />
                </div>
                <div>
                  <Label
                    htmlFor="downgrade-reason"
                    className="text-[13px] font-medium"
                  >
                    Reason (optional)
                  </Label>
                  <Select
                    value={feedbackDowngradeReason}
                    onValueChange={setFeedbackDowngradeReason}
                  >
                    <SelectTrigger id="downgrade-reason" className="mt-2 h-9">
                      <SelectValue placeholder="Select a reason" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="too-expensive">
                        Too expensive
                      </SelectItem>
                      <SelectItem value="not-enough-features">
                        Not enough features
                      </SelectItem>
                      <SelectItem value="switching-platform">
                        Switching to another platform
                      </SelectItem>
                      <SelectItem value="project-ended">
                        Project ended
                      </SelectItem>
                      <SelectItem value="other">Other</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>
          )}
        </>
      )}

      {/* Payment Modal */}
      <PaymentModal
        open={paymentModalOpen}
        onOpenChange={setPaymentModalOpen}
        organizationId={orgId}
        onSuccess={handlePaymentMethodAdded}
        elevatedForWizard
      />

      {/* Coupon Modal */}
      <ValidateCreditModal
        open={couponModalOpen}
        onOpenChange={setCouponModalOpen}
        onCouponApply={(coupon) => {
          setSelectedCoupon(coupon)
          setCouponModalOpen(false)
        }}
        elevatedForWizard
      />
    </WizardLayout>
  )
}
