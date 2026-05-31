import { useState, useEffect, useMemo, useRef } from 'react'
import { useNavigate, useSearch, Link } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { ID } from '@appwrite.io/console'
import {
  BillingPlanTier,
  type BillingPlanTier as BillingPlanTierType,
} from '@/lib/constants/billing-plan'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
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
  useEstimationCreateOrganization,
  useUpdateOrganizationPlan,
  useUpdateSelectedProjects,
  useValidateOrganization,
  useCreateDowngradeFeedback,
  useCreateOrganization,
  usePaymentMethods,
  useOrganizations,
} from '@/lib/react-query/hooks'
import { useSmartNavigation } from '@/lib/hooks/useSmartNavigation'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import { PlanSelection } from './change-plan/PlanSelection'
import { SelectPaymentMethod } from './change-plan/SelectPaymentMethod'
import { EstimatedTotalBox } from './change-plan/EstimatedTotalBox'
import { PlanComparisonBox } from './change-plan/PlanComparisonBox'
import { OrganizationUsageLimits } from './change-plan/OrganizationUsageLimits'
import { ValidateCreditModal } from './change-plan/ValidateCredit'
import { PaymentModal } from './Payment'
import {
  OrganizationSetupProgress,
  type OrganizationSetupProgressState,
} from './change-plan/OrganizationSetupProgress'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { confirmPayment } from '@/lib/utils/stripe'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import type { Models } from '@appwrite.io/console'

/**
 * ChangePlanWizardFullscreen Component
 *
 * Fullscreen wizard for upgrading or downgrading organization billing plans.
 * Handles plan selection, payment methods, project selection (for downgrades),
 * coupon codes, and member invites.
 */

const ESTIMATION_DEBOUNCE_MS = 500

export function ChangePlanWizardFullscreen() {
  const navigate = useNavigate()
  const search = useSearch({ from: '/_public/upgrade' })
  const orgId = search.orgId
  const isCreateMode = !orgId
  const queryClient = useQueryClient()

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

  // Another free org on this account blocks downgrading/creating a second free org.
  const hasFreeOrgs = useMemo(() => {
    return organizations.some(
      (org) => org.plan === 'free' && org.$id !== orgId,
    )
  }, [organizations, orgId])

  // Default selection: Pro for new orgs; first paid plan when upgrading from Free;
  // otherwise the org's current plan.
  const defaultPlan = useMemo(() => {
    if (isCreateMode) {
      return BillingPlanTier.Tier1
    }

    const current = organization?.billingPlan || BillingPlanTier.Tier0

    if (
      getPlanNameFromTier(current) === 'free' &&
      billingPlans &&
      Object.keys(billingPlans).length > 0
    ) {
      const firstPaidPlan = Object.keys(billingPlans)
        .filter((tier) => getPlanNameFromTier(tier) !== 'free')
        .sort((a, b) => {
          const tierNumber = (id: string) =>
            parseInt(id.match(/tier-(\d+)/i)?.[1] ?? '999', 10)
          return tierNumber(a) - tierNumber(b)
        })[0]

      if (firstPaidPlan) {
        return firstPaidPlan as BillingPlanTierType
      }
    }

    return current as BillingPlanTierType
  }, [isCreateMode, organization?.billingPlan, billingPlans])

  // Check if self-service is allowed (defaults to true)
  const selfService = isCreateMode ? true : plan?.selfService !== false

  // State management
  const [organizationName, setOrganizationName] = useState('')
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
  const [setupProgress, setSetupProgress] =
    useState<OrganizationSetupProgressState | null>(null)
  const [usageLimitsComponentRef, setUsageLimitsComponentRef] = useState<{
    getSelectedProjects?: () => string[]
  } | null>(null)
  const handleUsageLimitsRef = (ref: unknown) =>
    setUsageLimitsComponentRef(
      ref as { getSelectedProjects?: () => string[] } | null,
    )

  const { usage: orgUsage } = useOrganizationUsage(orgId)
  const { projects: allProjects } = useOrganizationProjects(orgId)

  // Get current plan tier
  const currentPlanTier = isCreateMode
    ? BillingPlanTier.Tier0
    : organization?.billingPlan || 'tier-0'
  const currentPlanEnum = useMemo(() => {
    if (isCreateMode) {
      return BillingPlanTier.Tier0
    }
    try {
      return currentPlanTier as BillingPlanTierType
    } catch {
      return BillingPlanTier.Tier0
    }
  }, [currentPlanTier, isCreateMode])

  // Mutations (defined early for use in useEffect)
  const updatePlanMutation = useUpdateOrganizationPlan()
  const createOrgMutation = useCreateOrganization()
  const updateSelectedProjectsMutation = useUpdateSelectedProjects()
  const validateOrganizationMutation = useValidateOrganization()
  const createDowngradeFeedbackMutation = useCreateDowngradeFeedback()
  const isSubmitting =
    updatePlanMutation.isPending ||
    createOrgMutation.isPending ||
    setupProgress !== null

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
    if (planInitialized) return
    if (!billingPlans || Object.keys(billingPlans).length === 0) return
    if (!isCreateMode && orgId && !organization) return

    const isValidPlan = (plan: string) => plan in billingPlans

    const planParam = search?.plan as string | undefined
    if (planParam && isValidPlan(planParam)) {
      setSelectedPlan(planParam as BillingPlanTierType)
      setPlanInitialized(true)
      return
    }

    if (defaultPlan && isValidPlan(defaultPlan)) {
      setSelectedPlan(defaultPlan as BillingPlanTierType)
    }

    setPlanInitialized(true)
  }, [
    search?.plan,
    defaultPlan,
    planInitialized,
    isCreateMode,
    orgId,
    organization,
    billingPlans,
  ])

  // Apply coupon from URL with full details (including expiration)
  const couponCodeFromUrl = search?.code as string | undefined
  const { coupon: couponFromUrl } = useCouponAccount(
    couponCodeFromUrl?.trim() || null,
  )
  useEffect(() => {
    if (couponFromUrl) {
      setSelectedCoupon(couponFromUrl)
    }
  }, [couponFromUrl])

  // Determine if upgrade or downgrade
  const isUpgrade = useMemo(() => {
    if (!selectedPlan || !currentPlanEnum) return false
    if (isCreateMode) {
      return selectedPlan !== BillingPlanTier.Tier0
    }
    const currentTier =
      parseInt(currentPlanEnum.replace('tier-', '').replace('Tier', '')) || 0
    const selectedTier =
      parseInt(selectedPlan.replace('tier-', '').replace('Tier', '')) || 0
    return selectedTier > currentTier
  }, [selectedPlan, currentPlanEnum, isCreateMode])

  const isDowngrade = useMemo(() => {
    if (isCreateMode) return false
    if (!selectedPlan || !currentPlanEnum) return false
    const currentTier =
      parseInt(currentPlanEnum.replace('tier-', '').replace('Tier', '')) || 0
    const selectedTier =
      parseInt(selectedPlan.replace('tier-', '').replace('Tier', '')) || 0
    return selectedTier < currentTier
  }, [selectedPlan, currentPlanEnum, isCreateMode])

  // Clear coupon when downgrading an existing organization
  useEffect(() => {
    if (isDowngrade) {
      setSelectedCoupon(null)
    }
  }, [isDowngrade, selectedPlan])

  const needsPaymentMethods =
    !!selectedPlan &&
    selectedPlan !== BillingPlanTier.Tier0 &&
    isUpgrade

  // Fetch saved cards only for paid-plan flows, or when the add-card modal is open.
  const { paymentMethods, isLoading: paymentMethodsLoading } = usePaymentMethods(
    {
      enabled: needsPaymentMethods || paymentModalOpen,
    },
  )

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

  // Get estimation for selected plan (only when plan is selected and not free)
  const estimationPaymentMethodId = useMemo(() => {
    if (paymentMethodId) return paymentMethodId
    if (organization?.paymentMethodId) return organization.paymentMethodId
    const completedMethod = paymentMethods.find(
      (pm: Models.PaymentMethod) => pm.last4,
    )
    return completedMethod?.$id
  }, [paymentMethodId, organization?.paymentMethodId, paymentMethods])

  const estimationCouponId = useMemo(() => {
    if (!isUpgrade || !selectedCoupon) return undefined
    const value = selectedCoupon.code ?? selectedCoupon.$id
    if (typeof value !== 'string') return undefined
    const trimmed = value.trim()
    return trimmed.length > 0 ? trimmed : undefined
  }, [selectedCoupon, isUpgrade])

  const debouncedEstimationPlan = useDebouncedValue(
    selectedPlan && selectedPlan !== BillingPlanTier.Tier0 ? selectedPlan : null,
    ESTIMATION_DEBOUNCE_MS,
  )
  const debouncedEstimationPaymentMethodId = useDebouncedValue(
    estimationPaymentMethodId ?? null,
    ESTIMATION_DEBOUNCE_MS,
  )

  const shouldFetchUpdateEstimationDebounced =
    !isCreateMode &&
    debouncedEstimationPlan &&
    debouncedEstimationPlan !== currentPlanEnum &&
    orgId

  const shouldFetchCreateEstimationDebounced =
    isCreateMode &&
    debouncedEstimationPlan &&
    !!debouncedEstimationPaymentMethodId

  const updateEstimation = useEstimationUpdatePlan(
    shouldFetchUpdateEstimationDebounced ? orgId : null,
    shouldFetchUpdateEstimationDebounced ? debouncedEstimationPlan : null,
    shouldFetchUpdateEstimationDebounced ? estimationCouponId : undefined,
  )

  const createEstimation = useEstimationCreateOrganization(
    shouldFetchCreateEstimationDebounced ? debouncedEstimationPlan : null,
    shouldFetchCreateEstimationDebounced ? estimationCouponId : undefined,
    undefined,
    shouldFetchCreateEstimationDebounced
      ? debouncedEstimationPaymentMethodId
      : null,
  )

  const estimation = isCreateMode ? createEstimation : updateEstimation

  const estimationInputsDebouncing =
    (selectedPlan && selectedPlan !== BillingPlanTier.Tier0
      ? selectedPlan
      : null) !== debouncedEstimationPlan ||
    (isCreateMode &&
      (estimationPaymentMethodId ?? null) !==
        debouncedEstimationPaymentMethodId)

  const awaitingEstimationPaymentMethod =
    isCreateMode &&
    !!selectedPlan &&
    selectedPlan !== BillingPlanTier.Tier0 &&
    !paymentMethodsLoading &&
    !estimationPaymentMethodId

  const estimationBoxLoading =
    estimation.isLoading ||
    estimation.isFetching ||
    estimationInputsDebouncing ||
    (isCreateMode &&
      !!selectedPlan &&
      selectedPlan !== BillingPlanTier.Tier0 &&
      paymentMethodsLoading)

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
    if (!selectedPlan) return true
    if (isSubmitting) return true

    if (isCreateMode) {
      if (!organizationName.trim()) return true
      if (selectedPlan === BillingPlanTier.Tier0 && hasFreeOrgs) return true
      if (isUpgrade) {
        if (!paymentMethodId) return true
        const selectedMethod = paymentMethods.find(
          (pm: Models.PaymentMethod) => pm.$id === paymentMethodId,
        )
        if (!selectedMethod?.last4) return true
      }
      return false
    }

    if (selectedPlan === currentPlanEnum) return true

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
    isCreateMode,
    organizationName,
    isSubmitting,
  ])

  // Handle upgrade
  const handleUpgrade = async () => {
    if (!orgId || !selectedPlan || !paymentMethodId) return

    const planLabel = getPlanNameFromTier(selectedPlan)
    const showActivationStep = selectedPlan !== BillingPlanTier.Tier0

    setSetupProgress({
      mode: 'upgrade',
      phase: 'submitting',
      planLabel,
      showPaymentStep: false,
      showActivationStep,
    })

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

      // 3DS / authentication required: backend signals it by including a
      // clientSecret on the otherwise-success response. If the response says
      // requires_action without a clientSecret we have nothing to drive
      // client-side, so surface that as an explicit error instead of
      // silently succeeding.
      const resultObj = result as {
        clientSecret?: string
        status?: string | number
      }
      const statusRequiresAction =
        typeof resultObj?.status === 'string' &&
        (resultObj.status === 'requires_action' ||
          resultObj.status === 'requires_authentication')
      if (statusRequiresAction && !resultObj?.clientSecret) {
        throw new Error(
          'Payment authentication is required but the server did not return a client secret.',
        )
      }

      if (resultObj?.clientSecret) {
        // Grab the Stripe provider id so confirmPayment can attach the card
        // if the PaymentIntent still needs a payment method.
        setSetupProgress((prev) =>
          prev
            ? {
                ...prev,
                showPaymentStep: true,
                phase: 'confirming-payment',
              }
            : prev,
        )
        const selectedMethod = paymentMethods.find(
          (pm) => pm.$id === paymentMethodId,
        )
        await confirmPayment({
          clientSecret: resultObj.clientSecret,
          paymentMethod: selectedMethod?.providerMethodId || undefined,
        })
        // Refresh org + invoice state now that the payment intent has been
        // authenticated; otherwise subsequent reads see the stale
        // requires_action state.
        await queryClient.invalidateQueries({
          queryKey: ['organization', orgId],
        })
        await queryClient.invalidateQueries({
          queryKey: ['invoices', 'organization', orgId],
        })
      }

      // Validate the organization after payment (needed regardless of 3DS)
      if (showActivationStep) {
        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'activating' } : prev,
        )
      }
      await validateOrganizationMutation.mutateAsync({
        organizationId: orgId,
        invites: [],
      })

      setSetupProgress((prev) =>
        prev ? { ...prev, phase: 'complete' } : prev,
      )

      toast.success('Plan updated successfully')
      navigate({
        to: '/organizations/$orgId/settings/billing',
        params: { orgId },
      })
    } catch (error) {
      setSetupProgress(null)
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

  // Handle create organization with selected plan
  const handleCreateOrganization = async () => {
    if (!selectedPlan || !organizationName.trim()) return

    const organizationId = ID.unique()
    const requiresPayment =
      selectedPlan !== BillingPlanTier.Tier0 && isUpgrade && paymentMethodId

    if (selectedPlan !== BillingPlanTier.Tier0 && !paymentMethodId) {
      return
    }

    const planLabel = getPlanNameFromTier(selectedPlan)
    const showActivationStep = selectedPlan !== BillingPlanTier.Tier0

    setSetupProgress({
      mode: 'create',
      phase: 'submitting',
      organizationName: organizationName.trim(),
      planLabel,
      showPaymentStep: false,
      showActivationStep,
    })

    try {
      const result = await createOrgMutation.mutateAsync({
        organizationId,
        name: organizationName.trim(),
        billingPlan: selectedPlan,
        paymentMethodId: requiresPayment ? paymentMethodId : undefined,
        couponId: isUpgrade
          ? (selectedCoupon?.code ?? selectedCoupon?.$id)
          : undefined,
        budget: billingBudget,
        taxId: taxId || null,
      })

      const resultObj = result as {
        clientSecret?: string
        status?: string | number
        $id?: string
      }
      const createdOrgId = resultObj?.$id || organizationId
      const statusRequiresAction =
        typeof resultObj?.status === 'string' &&
        (resultObj.status === 'requires_action' ||
          resultObj.status === 'requires_authentication')
      if (statusRequiresAction && !resultObj?.clientSecret) {
        throw new Error(
          'Payment authentication is required but the server did not return a client secret.',
        )
      }

      if (resultObj?.clientSecret && paymentMethodId) {
        setSetupProgress((prev) =>
          prev
            ? {
                ...prev,
                showPaymentStep: true,
                phase: 'confirming-payment',
              }
            : prev,
        )
        const selectedMethod = paymentMethods.find(
          (pm) => pm.$id === paymentMethodId,
        )
        await confirmPayment({
          clientSecret: resultObj.clientSecret,
          paymentMethod: selectedMethod?.providerMethodId || undefined,
        })
        await queryClient.invalidateQueries({
          queryKey: ['organization', createdOrgId],
        })
      }

      if (showActivationStep) {
        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'activating' } : prev,
        )
        await validateOrganizationMutation.mutateAsync({
          organizationId: createdOrgId,
          invites: [],
        })
      }

      setSetupProgress((prev) =>
        prev ? { ...prev, phase: 'complete' } : prev,
      )

      toast.success('Organization created successfully')
      navigate({
        to: '/organizations/$orgId',
        params: { orgId: createdOrgId },
      })
    } catch (error) {
      setSetupProgress(null)
      toast.error(
        error instanceof Error
          ? error.message
          : 'Failed to create organization',
      )
    }
  }

  // Handle submit
  const handleSubmit = () => {
    if (isCreateMode) {
      handleCreateOrganization()
      return
    }
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

  const wizardTitle = isCreateMode ? 'Create organization' : 'Change plan'
  const submitLabel = isCreateMode ? 'Create organization' : 'Change plan'

  if (setupProgress) {
    return (
      <WizardLayout
        title={wizardTitle}
        fullscreen
        useSidebar={false}
        skipInitialFieldFocus
        fallbackPath={
          orgId ? `/organizations/${orgId}/settings/billing` : undefined
        }
      >
        <OrganizationSetupProgress progress={setupProgress} />
      </WizardLayout>
    )
  }

  return (
    <WizardLayout
      title={wizardTitle}
      fullscreen
      skipInitialFieldFocus={!isCreateMode}
      initialFocusKey={
        isCreateMode
          ? plansLoading
            ? 'plans-loading'
            : 'plans-ready'
          : undefined
      }
      fallbackPath={
        orgId ? `/organizations/${orgId}/settings/billing` : undefined
      }
      footerAlign="right"
      sidebar={
        <>
          {showEstimatedTotal && (
            <EstimatedTotalBox
              estimation={estimation.estimation}
              isLoading={estimationBoxLoading}
              awaitingPaymentMethod={awaitingEstimationPaymentMethod}
              error={estimation.error}
              onRetry={() => {
                void estimation.refetch()
              }}
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
            disabled={isSubmitting}
          >
            Cancel
          </Button>
          <Button onClick={handleSubmit} disabled={isButtonDisabled}>
            {submitLabel}
          </Button>
        </>
      }
    >
      {isCreateMode && (
        <div className="space-y-2">
          <Label htmlFor="organization-name">
            Name <span className="text-destructive">*</span>
          </Label>
          <Input
            id="organization-name"
            type="text"
            placeholder="My Organization"
            value={organizationName}
            onChange={(e) => setOrganizationName(e.target.value)}
            disabled={isSubmitting}
            maxLength={128}
            required
          />
        </div>
      )}

      {/* Select Plan Section */}
      <div>
        <Label className="mb-2 block">
          Select plan <span className="text-destructive">*</span>
        </Label>
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
                isCreateMode={isCreateMode}
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
            showApplyCoupon={isUpgrade}
            onAddCredits={() => setCouponModalOpen(true)}
          />
        </>
      )}

      {/* Downgrade-specific sections */}
      {isDowngrade && selectedPlan && (
        <>
          {/* One free org per account */}
          {selectedPlan === BillingPlanTier.Tier0 && hasFreeOrgs && (
            <WarningAlert title="You can only have one free organization per account">
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
            </WarningAlert>
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
            <WarningAlert title="Downgrading to Free Plan">
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
            </WarningAlert>
          )}

          {/* Feedback Form for Free Plan (matches old console: "What wasn't working for you?" required) */}
          {selectedPlan === BillingPlanTier.Tier0 && !hasFreeOrgs && (
            <div>
              <Label className="mb-2 block">Feedback</Label>
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
                    Your feedback <span className="text-destructive">*</span>
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
        }}
        elevatedForWizard
      />
    </WizardLayout>
  )
}
