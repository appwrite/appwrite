import { useState, useEffect, useMemo, useRef } from 'react'
import { useParams, useNavigate, useSearch } from '@tanstack/react-router'
import { BillingPlan } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertTriangle, Info, X } from 'lucide-react'
import { toast } from 'sonner'
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
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { PlanSelection } from './change-plan/PlanSelection'
import { EstimatedTotalBox } from './change-plan/EstimatedTotalBox'
import { PlanComparisonBox } from './change-plan/PlanComparisonBox'
import { OrganizationUsageLimits } from './change-plan/OrganizationUsageLimits'
import { SelectPaymentMethod } from './change-plan/SelectPaymentMethod'
import { ValidateCreditModal } from './change-plan/ValidateCredit'
import { InputTags } from '@/components/ui/input-tags'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import type { Models } from '@appwrite.io/console'

/**
 * ChangePlanWizard Component
 *
 * Multi-step wizard for upgrading or downgrading organization billing plans.
 * Handles plan selection, payment methods, project selection (for downgrades),
 * coupon codes, and member invites.
 */

export function ChangePlanWizard() {
  const params = useParams({ strict: false })
  const navigate = useNavigate()
  const search = useSearch({ strict: false })
  const orgId = params.orgId as string | undefined

  // Fetch data using hooks (data is already prefetched by route loader)
  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const { plan, isLoading: planLoading } = useOrganizationPlan(orgId)
  const { memberships, total: membersTotal, isLoading: membersLoading } = useOrganizationMemberships(orgId, 0, 25, '')
  const { organizations, isLoading: orgsLoading } = useOrganizations()
  const { plans: billingPlans, isLoading: plansLoading } = useBillingPlans()

  // Create members object for compatibility
  const members = useMemo(() => ({
    memberships: memberships || [],
    total: membersTotal || 0,
  }), [memberships, membersTotal])

  // Check if user has a free organization
  const hasFreeOrgs = useMemo(() => {
    return organizations.some(
      (org) => org.plan === 'free' || org.billingPlan === 'tier-0' || org.billingPlan === 'Tier0',
    )
  }, [organizations])

  // Determine default plan (Pro or Scale based on current plan)
  const defaultPlan = useMemo(() => {
    const currentPlanTier = organization?.billingPlan || 'tier-0'
    return currentPlanTier === 'tier-0' ? 'tier-1' : currentPlanTier
  }, [organization?.billingPlan])

  // Check if self-service is allowed (defaults to true)
  const selfService = plan?.selfService !== false

  // State management
  const [selectedPlan, setSelectedPlan] = useState<BillingPlan | null>(null)
  const [selectedCoupon, setSelectedCoupon] = useState<Models.Coupon | null>(null)
  const [paymentMethodId, setPaymentMethodId] = useState<string | undefined>(undefined)
  const [collaborators, setCollaborators] = useState<string[]>([])
  const [taxId, setTaxId] = useState<string>('')
  const [billingBudget, setBillingBudget] = useState<number | undefined>(undefined)
  const [feedbackDowngradeReason, setFeedbackDowngradeReason] = useState<string>('')
  const [feedbackMessage, setFeedbackMessage] = useState<string>('')
  const [couponModalOpen, setCouponModalOpen] = useState(false)
  const [usageLimitsComponentRef, setUsageLimitsComponentRef] = useState<any>(null)

  // Fetch additional data
  const { paymentMethods } = usePaymentMethods()
  const { usage: orgUsage } = useOrganizationUsage(orgId)
  const { projects: allProjects } = useOrganizationProjects(orgId)

  // Get current plan tier
  const currentPlanTier = organization?.billingPlan || 'tier-0'
  const currentPlanEnum = useMemo(() => {
    try {
      return currentPlanTier as BillingPlan
    } catch {
      return BillingPlan.Tier0
    }
  }, [currentPlanTier])

  // Initialize selected plan from URL or default (only once)
  const [planInitialized, setPlanInitialized] = useState(false)
  useEffect(() => {
    if (planInitialized) return // Only initialize once
    
    const planParam = search?.plan as string
    if (planParam && Object.values(BillingPlan).includes(planParam as BillingPlan)) {
      setSelectedPlan(planParam as BillingPlan)
      setPlanInitialized(true)
    } else if (defaultPlan && Object.values(BillingPlan).includes(defaultPlan as BillingPlan)) {
      setSelectedPlan(defaultPlan as BillingPlan)
      setPlanInitialized(true)
    }
  }, [search?.plan, defaultPlan, planInitialized])

  // Coupon code from URL is handled by useCouponAccount hook below

  // Mutations (defined early for use in useEffect)
  const updatePlanMutation = useUpdateOrganizationPlan()
  const updateSelectedProjectsMutation = useUpdateSelectedProjects()
  const validateOrganizationMutation = useValidateOrganization()
  const createDowngradeFeedbackMutation = useCreateDowngradeFeedback()

  // Handle payment confirmation redirect (only once)
  const paymentConfirmedHandled = useRef(false)
  useEffect(() => {
    const paymentType = search?.type as string
    if (paymentType === 'payment_confirmed' && orgId && !paymentConfirmedHandled.current) {
      paymentConfirmedHandled.current = true
      const invites = (search?.invites as string)?.split(',') || []
      const handlePaymentConfirmation = async (organizationId: string, invites: string[]) => {
        try {
          await validateOrganizationMutation.mutateAsync({
            organizationId,
            invites,
          })
          toast.success('Payment confirmed successfully')
          navigate({ to: '/organizations/$orgId/billing', params: { orgId: organizationId } })
        } catch (error: any) {
          toast.error(error.message || 'Failed to validate payment')
        }
      }
      handlePaymentConfirmation(orgId, invites)
    }
  }, [search?.type, orgId, validateOrganizationMutation, navigate])

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
      const completedMethod = paymentMethods.find((pm: Models.PaymentMethod) => pm.last4)
      if (completedMethod) {
        setPaymentMethodId(completedMethod.$id)
      }
    }
  }, [organization?.paymentMethodId, paymentMethods])

  // Pre-fill collaborators with existing members (excluding current user) - only once
  const collaboratorsInitializedRef = useRef(false)
  useEffect(() => {
    if (collaboratorsInitializedRef.current || collaborators.length > 0) return
    
    if (members?.memberships && members.memberships.length > 0) {
      const memberEmails = members.memberships
        .map((m: any) => m.userEmail)
        .filter((email: string) => email && email !== organization?.email)
      if (memberEmails.length > 0) {
        setCollaborators(memberEmails)
        collaboratorsInitializedRef.current = true
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [members?.memberships?.length, organization?.email])

  // Determine if upgrade or downgrade
  const isUpgrade = useMemo(() => {
    if (!selectedPlan || !currentPlanEnum) return false
    const currentTier = parseInt(currentPlanEnum.replace('tier-', '').replace('Tier', '')) || 0
    const selectedTier = parseInt(selectedPlan.replace('tier-', '').replace('Tier', '')) || 0
    return selectedTier > currentTier
  }, [selectedPlan, currentPlanEnum])

  const isDowngrade = useMemo(() => {
    if (!selectedPlan || !currentPlanEnum) return false
    const currentTier = parseInt(currentPlanEnum.replace('tier-', '').replace('Tier', '')) || 0
    const selectedTier = parseInt(selectedPlan.replace('tier-', '').replace('Tier', '')) || 0
    return selectedTier < currentTier
  }, [selectedPlan, currentPlanEnum])

  // Get estimation for selected plan (only when plan is selected and not free)
  // Memoize collaborators array reference to prevent unnecessary refetches
  const stableCollaborators = useMemo(() => {
    if (collaborators.length === 0) return []
    return [...collaborators].sort() // Sort to ensure stable reference
  }, [collaborators.join(',')])
  
  // Only fetch estimation when we have a selected plan and it's not the current plan
  const shouldFetchEstimation = selectedPlan && 
    selectedPlan !== currentPlanEnum && 
    selectedPlan !== BillingPlan.Tier0 &&
    orgId
  
  const estimation = useEstimationUpdatePlan(
    shouldFetchEstimation ? orgId : null, // Disable query if conditions not met
    shouldFetchEstimation ? selectedPlan : null,
    shouldFetchEstimation ? (selectedCoupon?.$id || null) : null,
    shouldFetchEstimation ? stableCollaborators : [],
  )

  // Check if submit button should be disabled
  const isButtonDisabled = useMemo(() => {
    if (!selectedPlan || selectedPlan === currentPlanEnum) return true
    if (updatePlanMutation.isPending) return true

    // For upgrades: payment method required
    if (isUpgrade) {
      if (!paymentMethodId) return true
      const selectedMethod = paymentMethods.find((pm: Models.PaymentMethod) => pm.$id === paymentMethodId)
      if (!selectedMethod?.last4) return true // Must be a completed card
    }

    // For downgrades: check project selection if needed
    if (isDowngrade) {
      const targetPlanInfo = billingPlans[selectedPlan]
      const targetProjectsLimit = targetPlanInfo?.projects ?? 0
      if (targetProjectsLimit > 0 && allProjects.length > targetProjectsLimit) {
        if (!usageLimitsComponentRef) return true
        const selected = usageLimitsComponentRef.getSelectedProjects?.()
        if (!selected || selected.length !== targetProjectsLimit) return true
      }

      // For free plan: feedback required
      if (selectedPlan === BillingPlan.Tier0 && !hasFreeOrgs) {
        if (!feedbackDowngradeReason || !feedbackMessage.trim()) return true
      }
    }

    return false
  }, [
    selectedPlan,
    currentPlanEnum,
    isUpgrade,
    isDowngrade,
    paymentMethodId,
    paymentMethods,
    billingPlans,
    allProjects,
    usageLimitsComponentRef,
    feedbackDowngradeReason,
    feedbackMessage,
    hasFreeOrgs,
    updatePlanMutation.isPending,
  ])

  // Handle upgrade
  const handleUpgrade = async () => {
    if (!orgId || !selectedPlan || !paymentMethodId) return

    try {
      // Filter out existing members from collaborators
      const existingMemberEmails = members?.memberships?.map((m: any) => m.userEmail) || []
      const newCollaborators = collaborators.filter(
        (email) => !existingMemberEmails.includes(email),
      )

      const result = await updatePlanMutation.mutateAsync({
        organizationId: orgId,
        billingPlan: selectedPlan,
        paymentMethodId,
        billingAddressId: undefined,
        couponId: selectedCoupon?.code,
        invites: newCollaborators,
        budget: billingBudget,
        taxId: taxId || null,
      })

      // Check if payment confirmation is needed (status 402)
      if (result && typeof result === 'object' && 'status' in result && (result as any).status === 402) {
        const clientSecret = (result as any).clientSecret
        if (clientSecret) {
          // Redirect to Stripe payment confirmation
          // This would typically use a Stripe confirmation flow
          // For now, we'll show an error and let the user know
          toast.error('Payment confirmation required. Please complete the payment process.')
          return
        }
      }

      // If successful, invalidate and navigate
      toast.success('Plan updated successfully')
      navigate({ to: '/organizations/$orgId/billing', params: { orgId } })
    } catch (error: any) {
      toast.error(error.message || 'Failed to update plan')
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
      const targetPlanInfo = billingPlans[selectedPlan]
      const targetProjectsLimit = targetPlanInfo?.projects ?? 0
      if (targetProjectsLimit > 0 && usageLimitsComponentRef) {
        const selected = usageLimitsComponentRef.getSelectedProjects?.()
        if (selected && selected.length > 0) {
          await updateSelectedProjectsMutation.mutateAsync({
            organizationId: orgId,
            projectIds: selected,
          })
        }
      }

      // Track feedback if downgrading to Free
      if (selectedPlan === BillingPlan.Tier0 && !hasFreeOrgs) {
        await createDowngradeFeedbackMutation.mutateAsync({
          organizationId: orgId,
          reason: feedbackDowngradeReason,
          message: feedbackMessage,
          fromPlanId: currentPlanEnum,
          toPlanId: selectedPlan,
        })
      }

      toast.success('Plan updated successfully')
      navigate({ to: '/organizations/$orgId/billing', params: { orgId } })
    } catch (error: any) {
      toast.error(error.message || 'Failed to update plan')
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

  // Handle cancel
  const handleCancel = () => {
    navigate({ to: '/organizations/$orgId/billing', params: { orgId: orgId! } })
  }

  // Get target plan info
  const targetPlanInfo = selectedPlan ? billingPlans[selectedPlan] : null
  const targetProjectsLimit = targetPlanInfo?.projects ?? 0
  const needsProjectSelection = isDowngrade && targetProjectsLimit > 0 && allProjects.length > targetProjectsLimit

  // Show estimated total box conditions
  const showEstimatedTotal = selectedPlan &&
    selectedPlan !== currentPlanEnum &&
    selectedPlan !== BillingPlan.Tier0 &&
    currentPlanEnum !== 'custom' &&
    currentPlanEnum !== 'Custom'

  // Show plan comparison box conditions
  const showPlanComparison = !showEstimatedTotal ||
    selectedPlan === BillingPlan.Tier0 ||
    selectedPlan === currentPlanEnum ||
    currentPlanEnum === 'custom' ||
    currentPlanEnum === 'Custom'

  // Don't block rendering - show content even while loading
  // The component will gracefully handle missing data

  // Early return if critical data is missing (but don't block forever)
  if (!orgId) {
    return (
      <div className="flex h-full flex-col items-center justify-center">
        <p className="text-[13px] text-muted-foreground">Organization ID is required</p>
        <Button variant="outline" onClick={handleCancel} className="mt-4">
          Go Back
        </Button>
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col">
      {/* Header */}
      <div className="border-b border-border bg-background">
        <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-xl font-semibold text-foreground">Change Plan</h1>
              <p className="text-[13px] text-muted-foreground mt-1">
                Upgrade or downgrade your organization's billing plan
              </p>
            </div>
            <Button variant="ghost" size="sm" onClick={handleCancel}>
              <X className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </div>

      {/* Main Content */}
      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        <div className="grid gap-6 lg:grid-cols-3 pt-6">
          {/* Main Form Content */}
          <div className="lg:col-span-2 space-y-6">
            {/* Plan Selection */}
            {Object.keys(billingPlans || {}).length > 0 ? (
              <PlanSelection
                plans={billingPlans}
                currentPlan={currentPlanEnum}
                selectedPlan={selectedPlan}
                onPlanSelect={setSelectedPlan}
                selfService={selfService}
                hasFreeOrgs={hasFreeOrgs}
              />
            ) : (
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">Loading plans...</p>
                </div>
              </div>
            )}

            {/* Upgrade-specific sections */}
            {isUpgrade && selectedPlan && (
              <>
                {/* Payment Method Selection */}
                <SelectPaymentMethod
                  paymentMethods={paymentMethods}
                  selectedPaymentMethodId={paymentMethodId}
                  onPaymentMethodSelect={setPaymentMethodId}
                  onAddPaymentMethod={() => {
                    // Open payment modal - would need to implement
                    toast.info('Payment method creation not yet implemented in wizard')
                  }}
                  taxId={taxId}
                  onTaxIdChange={setTaxId}
                  onAddCredits={() => setCouponModalOpen(true)}
                />

                {/* Member Invites */}
                <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                  <div className="px-6 py-4">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      Invite Team Members
                    </h3>
                    <p className="text-[13px] text-muted-foreground mt-2">
                      Invite team members by email address. They will receive an invitation to join your organization.
                    </p>
                  </div>
                  <div className="border-t border-border" />
                  <div className="px-6 py-4">
                    <InputTags
                      value={collaborators}
                      onChange={setCollaborators}
                      placeholder="Enter email addresses"
                      validateEmail={true}
                    />
                  </div>
                </div>
              </>
            )}

            {/* Downgrade-specific sections */}
            {isDowngrade && selectedPlan && (
              <>
                {/* Project Selection */}
                {needsProjectSelection && (
                  <OrganizationUsageLimits
                    projects={allProjects}
                    orgUsage={orgUsage}
                    members={members}
                    organization={organization}
                    targetLimit={targetProjectsLimit}
                    onRef={setUsageLimitsComponentRef}
                  />
                )}

                {/* Downgrade Alerts */}
                {selectedPlan === BillingPlan.Tier1 && (
                  <Alert>
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>Monthly Charges for Extra Team Members</AlertTitle>
                    <AlertDescription className="mt-2">
                      {targetPlanInfo?.addons?.seats?.price
                        ? `You will be charged $${targetPlanInfo.addons.seats.price} per month for each team member beyond the plan limit.`
                        : 'You will be charged for each team member beyond the plan limit.'}
                    </AlertDescription>
                  </Alert>
                )}

                {selectedPlan === BillingPlan.Tier0 && (
                  <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>Downgrading to Free Plan</AlertTitle>
                    <AlertDescription className="mt-2">
                      Your plan will change on {organization?.billingPlanDowngrade?.date || 'the end of your billing period'}.
                      You will lose access to premium features and team members beyond the free limit will be removed.
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

                {/* Feedback Form for Free Plan */}
                {selectedPlan === BillingPlan.Tier0 && !hasFreeOrgs && (
                  <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                    <div className="px-6 py-4">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        Why are you downgrading?
                      </h3>
                      <p className="text-[13px] text-muted-foreground mt-2">
                        Help us improve by sharing your feedback.
                      </p>
                    </div>
                    <div className="border-t border-border" />
                    <div className="px-6 py-4 space-y-4">
                      <div>
                        <Label htmlFor="downgrade-reason" className="text-[13px] font-medium">
                          Reason
                        </Label>
                        <Select
                          value={feedbackDowngradeReason}
                          onValueChange={setFeedbackDowngradeReason}
                        >
                          <SelectTrigger id="downgrade-reason" className="mt-2 h-9">
                            <SelectValue placeholder="Select a reason" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="too-expensive">Too expensive</SelectItem>
                            <SelectItem value="not-enough-features">Not enough features</SelectItem>
                            <SelectItem value="switching-platform">Switching to another platform</SelectItem>
                            <SelectItem value="project-ended">Project ended</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>
                      <div>
                        <Label htmlFor="downgrade-message" className="text-[13px] font-medium">
                          Additional details (optional)
                        </Label>
                        <Textarea
                          id="downgrade-message"
                          value={feedbackMessage}
                          onChange={(e) => setFeedbackMessage(e.target.value)}
                          placeholder="Tell us more about your decision..."
                          className="mt-2 min-h-[100px]"
                        />
                      </div>
                    </div>
                  </div>
                )}
              </>
            )}
          </div>

          {/* Aside */}
          <div className="lg:col-span-1">
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
          </div>
        </div>
      </div>

      {/* Footer */}
      <div className="border-t border-border bg-muted/30 px-4 py-4 sm:px-6">
        <div className="mx-auto w-full max-w-7xl flex items-center justify-end gap-3">
          <Button variant="outline" onClick={handleCancel} disabled={updatePlanMutation.isPending}>
            Cancel
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={isButtonDisabled}
          >
            {isUpgrade ? 'Upgrade' : isDowngrade ? 'Downgrade' : 'Update Plan'}
          </Button>
        </div>
      </div>

      {/* Coupon Modal */}
      <ValidateCreditModal
        open={couponModalOpen}
        onOpenChange={setCouponModalOpen}
        onCouponApply={(coupon) => {
          setSelectedCoupon(coupon)
          setCouponModalOpen(false)
        }}
      />
    </div>
  )
}
