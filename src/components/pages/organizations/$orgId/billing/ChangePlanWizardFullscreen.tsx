import { useState, useEffect, useMemo, useRef, useCallback } from 'react'
import { useNavigate, useSearch, Link } from '@tanstack/react-router'
import { useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
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
  useBillingPlans,
  useCouponAccount,
  useOrganizationProjects,
  useEstimationUpdatePlan,
  useEstimationCreateOrganization,
  useUpdateOrganizationPlan,
  useValidateOrganization,
  useCreateDowngradeFeedback,
  useCreateOrganization,
  usePaymentMethods,
  useOrganizations,
  deleteOrganization,
  fetchOrganizationProjects,
} from '@/lib/react-query/hooks'
import { deleteProject } from '@/lib/react-query/hooks/projects'
import { useSmartNavigation } from '@/lib/hooks/useSmartNavigation'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import { PlanSelection } from './change-plan/PlanSelection'
import { FreePlanConflictResolution } from './change-plan/FreePlanConflictResolution'
import { SelectPaymentMethod } from './change-plan/SelectPaymentMethod'
import { EstimatedTotalBox } from './change-plan/EstimatedTotalBox'
import { PlanComparisonBox } from './change-plan/PlanComparisonBox'
import { DowngradeValidation } from './change-plan/DowngradeValidation'
import type { DowngradeValidationHandle } from './change-plan/DowngradeValidation'
import { DowngradeImpactSummary } from './change-plan/DowngradeImpactSummary'
import { resolveOrgToDelete } from '@/lib/billing/free-plan-conflict'
import {
  fetchDeletedOrganizationImpact,
  type DeletedOrganizationImpact,
} from '@/lib/billing/fetch-deleted-org-impact'
import { fetchProjectDowngradeResources } from '@/lib/billing/fetch-project-downgrade-resources'
import {
  DOWNGRADE_RESOURCE_TYPES,
  type DowngradeResourceImpact,
} from '@/lib/billing/downgrade-plan-limits'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
import { fetchOrganizationMemberships } from '@/lib/react-query/hooks/teams'
import { ValidateCreditModal } from './change-plan/ValidateCredit'
import { PaymentModal } from './Payment'
import {
  OrganizationSetupProgress,
  type OrganizationSetupPhase,
  type OrganizationSetupProgressState,
} from './change-plan/OrganizationSetupProgress'
import { Textarea } from '@/components/ui/textarea'
import { confirmPayment } from '@/lib/utils/stripe'
import {
  compareBillingPlanRefs,
  getPlanCanonicalFromRecord,
  getPlanNameFromTier,
  isFreePlanRef,
  resolveBillingPlanRecord,
} from '@/lib/utils/plan-filter'
import type { Models } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'

/**
 * ChangePlanWizardFullscreen Component
 *
 * Fullscreen wizard for upgrading or downgrading organization billing plans.
 * Handles plan selection, payment methods, project selection (for downgrades),
 * coupon codes, and member invites.
 */

const ESTIMATION_DEBOUNCE_MS = 500
const DELETED_ORG_LIST_LIMIT = 1000
const DOWNGRADE_PROJECT_DELETE_PAGE_SIZE = 100

function usesFreeOrganizationSlot(org: { plan?: string; billingPlanDowngrade?: unknown }) {
  return org.plan === 'free' || !!org.billingPlanDowngrade
}

function getInitialDowngradeProgressPhase({
  showResourceDeletionStep,
  showOrganizationDeletionStep,
  showProjectDeletionStep,
  showPlanUpdateStep,
  showMembershipDeletionStep,
}: {
  showResourceDeletionStep: boolean
  showOrganizationDeletionStep: boolean
  showProjectDeletionStep: boolean
  showPlanUpdateStep: boolean
  showMembershipDeletionStep: boolean
}): OrganizationSetupPhase {
  if (showResourceDeletionStep) return 'deleting-resources'
  if (showOrganizationDeletionStep) return 'deleting-organization'
  if (showProjectDeletionStep) return 'deleting-projects'
  if (showPlanUpdateStep) return 'updating-plan'
  if (showMembershipDeletionStep) return 'deleting-memberships'
  return 'complete'
}

async function fetchAllDowngradeProjects(organizationId: string) {
  const all: Models.Project[] = []
  let page = 0
  let total = 0

  do {
    const data = await fetchOrganizationProjects(
      organizationId,
      page,
      DOWNGRADE_PROJECT_DELETE_PAGE_SIZE,
    )
    all.push(...(data.projects ?? []))
    total = data.total ?? all.length
    page += 1
  } while (all.length < total)

  return all
}

export function ChangePlanWizardFullscreen() {
  const navigate = useNavigate()
  const search = useSearch({ from: '/_public/upgrade' })
  const orgId = search.orgId
  const isCreateMode = !orgId
  const queryClient = useQueryClient()
  const refreshOrganizationBillingResources = useCallback(
    async (organizationId: string) => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['organizations', 'console'],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['organization', organizationId],
          exact: true,
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['organization', 'plan', organizationId],
          exact: true,
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['organization-usage', organizationId],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['organization-projects', organizationId],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['projects', 'active', organizationId],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['projects', 'pinned', organizationId],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['billing-aggregation', 'organization', organizationId],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['invoices', 'organization', organizationId],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['credits', 'organization', organizationId],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['payment-method', 'organization', organizationId],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['payment-methods', 'account'],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['billing-addresses', 'account'],
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['billing-address'],
          type: 'all',
        }),
      ])
    },
    [queryClient],
  )

  // Smart navigation for cancel/close actions
  // No fallbackPath - uses internal console history or root
  const handleCancel = useSmartNavigation()

  // Fetch data using hooks (data is already prefetched by route loader)
  const { organization } = useOrganizationById(orgId)
  const { plan } = useOrganizationPlan(orgId)
  const { organizations } = useOrganizations()
  const { plans: billingPlans, isLoading: plansLoading } = useBillingPlans()

  // A free org, including one with a scheduled downgrade to Free, occupies
  // the account's single free-organization slot.
  const hasFreeOrgs = useMemo(() => {
    return organizations.some(
      (org) => usesFreeOrganizationSlot(org) && org.$id !== orgId,
    )
  }, [organizations, orgId])

  const otherFreeOrg = useMemo(() => {
    return (
      organizations.find(
        (org) => usesFreeOrganizationSlot(org) && org.$id !== orgId,
      ) ??
      null
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
      getPlanCanonicalFromRecord(current, billingPlans) === 'free' &&
      billingPlans &&
      Object.keys(billingPlans).length > 0
    ) {
      const firstPaidPlan = Object.keys(billingPlans)
        .filter(
          (planId) =>
            getPlanCanonicalFromRecord(planId, billingPlans) !== 'free',
        )
        .sort((a, b) => {
          const orderA =
            resolveBillingPlanRecord(a, billingPlans)?.order ?? 999
          const orderB =
            resolveBillingPlanRecord(b, billingPlans)?.order ?? 999
          return orderA - orderB
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
  const [feedbackMessage, setFeedbackMessage] = useState<string>('')
  const [couponModalOpen, setCouponModalOpen] = useState(false)
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)
  const [setupProgress, setSetupProgress] =
    useState<OrganizationSetupProgressState | null>(null)
  const downgradeValidationRef = useRef<DowngradeValidationHandle | null>(null)
  const [downgradeValidationValid, setDowngradeValidationValid] = useState(true)
  const [freePlanKeepChoiceId, setFreePlanKeepChoiceId] = useState<
    string | null
  >(null)

  const handleDowngradeValidationRef = useCallback(
    (ref: DowngradeValidationHandle | null) => {
      downgradeValidationRef.current = ref
    },
    [],
  )

  const handleDowngradeValidationValid = useCallback((valid: boolean) => {
    setDowngradeValidationValid((prev) => (prev === valid ? prev : valid))
  }, [])

  const {
    projects: allProjects,
    total: allProjectsTotal,
    isLoading: allProjectsLoading,
  } = useOrganizationProjects(orgId)

  const selectedPlanIsFree = useMemo(
    () => isFreePlanRef(selectedPlan, billingPlans),
    [selectedPlan, billingPlans],
  )

  const selectedPlanIsPro = useMemo(
    () => getPlanCanonicalFromRecord(selectedPlan, billingPlans) === 'pro',
    [selectedPlan, billingPlans],
  )

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

  const currentPlanIsFree = useMemo(
    () => isFreePlanRef(currentPlanEnum, billingPlans),
    [currentPlanEnum, billingPlans],
  )

  // Mutations (defined early for use in useEffect)
  const updatePlanMutation = useUpdateOrganizationPlan()
  const createOrgMutation = useCreateOrganization()
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
          await refreshOrganizationBillingResources(organizationId)
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
  }, [
    search?.type,
    orgId,
    validateOrganizationMutation,
    refreshOrganizationBillingResources,
    navigate,
  ])

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
    if (!selectedPlan) return false
    if (isCreateMode) return !selectedPlanIsFree
    if (!currentPlanEnum) return false
    return (
      compareBillingPlanRefs(currentPlanEnum, selectedPlan, billingPlans) ===
      'upgrade'
    )
  }, [
    selectedPlan,
    currentPlanEnum,
    isCreateMode,
    billingPlans,
    selectedPlanIsFree,
  ])

  const isDowngrade = useMemo(() => {
    if (isCreateMode || !selectedPlan || !currentPlanEnum) return false
    if (selectedPlanIsFree && !currentPlanIsFree) return true
    return (
      compareBillingPlanRefs(currentPlanEnum, selectedPlan, billingPlans) ===
      'downgrade'
    )
  }, [
    selectedPlan,
    currentPlanEnum,
    isCreateMode,
    billingPlans,
    selectedPlanIsFree,
    currentPlanIsFree,
  ])

  const showFreePlanConflict =
    selectedPlanIsFree &&
    hasFreeOrgs &&
    !!otherFreeOrg &&
    isDowngrade

  const orgToDelete = useMemo(() => {
    if (!showFreePlanConflict || !otherFreeOrg || isCreateMode) return null

    const currentOrg = organization
      ? { $id: organization.$id, name: organization.name }
      : null
    const keepChoiceId = freePlanKeepChoiceId ?? organization?.$id

    if (!keepChoiceId) return null

    return resolveOrgToDelete(
      keepChoiceId,
      otherFreeOrg,
      currentOrg,
      !isCreateMode,
    )
  }, [
    showFreePlanConflict,
    otherFreeOrg,
    organization,
    isCreateMode,
    freePlanKeepChoiceId,
  ])

  const deletedOrganizationFallbackProjects = useMemo(() => {
    if (orgToDelete?.$id !== orgId) return []
    return allProjects
  }, [allProjects, orgId, orgToDelete?.$id])

  const deletedOrganizationFallbackProjectSignature = useMemo(
    () =>
      deletedOrganizationFallbackProjects
        .map((project) => project.$id)
        .sort()
        .join(','),
    [deletedOrganizationFallbackProjects],
  )

  const {
    data: deletedOrganizationImpact,
    isLoading: deletedOrganizationImpactLoading,
    isPending: deletedOrganizationImpactPending,
  } = useQuery({
    queryKey: [
      'billing',
      'deleted-organization-impact',
      'with-project-resource-breakdown',
      orgToDelete?.$id,
      deletedOrganizationFallbackProjectSignature,
    ],
    queryFn: () =>
      fetchDeletedOrganizationImpact(
        orgToDelete!.$id,
        orgToDelete!.name,
        deletedOrganizationFallbackProjects,
      ),
    enabled:
      !!orgToDelete && (orgToDelete.$id !== orgId || !allProjectsLoading),
    staleTime: 30 * 1000,
  })

  const isDeletingCurrentOrganization = orgToDelete?.$id === orgId
  const currentDeletedMembershipsQuery = useQuery({
    queryKey: [
      'billing',
      'current-deleted-organization-memberships',
      orgId,
    ],
    queryFn: () =>
      fetchOrganizationMemberships(orgId!, 0, DELETED_ORG_LIST_LIMIT),
    enabled: !!orgId && isDeletingCurrentOrganization,
    staleTime: 30 * 1000,
  })

  const currentDeletedDomainsQuery = useQuery({
    queryKey: ['billing', 'current-deleted-organization-domains', orgId],
    queryFn: () => fetchOrganizationDomains(orgId!, 0, DELETED_ORG_LIST_LIMIT),
    enabled: !!orgId && isDeletingCurrentOrganization,
    staleTime: 30 * 1000,
  })

  const currentDeletedResourceQueries = useQueries({
    queries: allProjects.map((project) => ({
      queryKey: [
        'billing',
        'current-deleted-organization-project-resources',
        project.$id,
      ],
      queryFn: () => fetchProjectDowngradeResources(project.$id),
      enabled:
        isDeletingCurrentOrganization && !allProjectsLoading && !!project.$id,
      staleTime: 30 * 1000,
    })),
  })

  const currentDeletedOrganizationImpact = useMemo<DeletedOrganizationImpact | null>(() => {
    if (!isDeletingCurrentOrganization || !orgToDelete || !orgId) return null
    if (allProjectsLoading) return null

    const resourceImpact: DowngradeResourceImpact = {}
    const projectResourceImpacts = allProjects.map((project, index) => {
      const resources = currentDeletedResourceQueries[index]?.data
      const projectImpact: DowngradeResourceImpact = {}

      for (const { id } of DOWNGRADE_RESOURCE_TYPES) {
        const total = resources?.[id]?.total ?? 0
        resourceImpact[id] = (resourceImpact[id] ?? 0) + total
        projectImpact[id] = total
      }

      return {
        projectId: project.$id,
        projectName: project.name || project.$id,
        resourceImpact: projectImpact,
      }
    })

    return {
      organizationId: orgId,
      organizationName: orgToDelete.name,
      projects: allProjects,
      memberships: currentDeletedMembershipsQuery.data?.memberships ?? [],
      domains: currentDeletedDomainsQuery.data?.domains ?? [],
      resourceImpact,
      projectResourceImpacts,
    }
  }, [
    allProjects,
    allProjectsLoading,
    currentDeletedDomainsQuery.data?.domains,
    currentDeletedMembershipsQuery.data?.memberships,
    currentDeletedResourceQueries,
    isDeletingCurrentOrganization,
    orgId,
    orgToDelete,
  ])

  const currentDeletedOrganizationLoading =
    isDeletingCurrentOrganization &&
    (allProjectsLoading ||
      currentDeletedMembershipsQuery.isLoading ||
      currentDeletedDomainsQuery.isLoading ||
      currentDeletedResourceQueries.some((query) => query.isLoading))

  const effectiveDeletedOrganizationImpact =
    currentDeletedOrganizationImpact ?? deletedOrganizationImpact ?? null
  const effectiveDeletedOrganizationLoading =
    currentDeletedOrganizationLoading ||
    deletedOrganizationImpactLoading ||
    deletedOrganizationImpactPending

  useEffect(() => {
    if (!showFreePlanConflict || !organization || isCreateMode) {
      setFreePlanKeepChoiceId(null)
      return
    }
    setFreePlanKeepChoiceId((prev) => prev ?? organization.$id)
  }, [showFreePlanConflict, organization?.$id, isCreateMode])

  // Clear coupon when downgrading an existing organization
  useEffect(() => {
    if (isDowngrade) {
      setSelectedCoupon(null)
    }
  }, [isDowngrade, selectedPlan])

  const needsPaymentMethods =
    !!selectedPlan && !selectedPlanIsFree && isUpgrade

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
    selectedPlan && !selectedPlanIsFree ? selectedPlan : null,
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
    (selectedPlan && !selectedPlanIsFree ? selectedPlan : null) !==
      debouncedEstimationPlan ||
    (isCreateMode &&
      (estimationPaymentMethodId ?? null) !==
        debouncedEstimationPaymentMethodId)

  const awaitingEstimationPaymentMethod =
    isCreateMode &&
    !!selectedPlan &&
    !selectedPlanIsFree &&
    !paymentMethodsLoading &&
    !estimationPaymentMethodId

  const estimationBoxLoading =
    estimation.isLoading ||
    estimation.isFetching ||
    estimationInputsDebouncing ||
    (isCreateMode &&
      !!selectedPlan &&
      !selectedPlanIsFree &&
      paymentMethodsLoading)

  // Get target plan info
  const targetPlanInfo = useMemo(() => {
    return resolveBillingPlanRecord(selectedPlan, billingPlans) as
      | Record<string, unknown>
      | null
  }, [billingPlans, selectedPlan])

  const targetProjectsLimit =
    typeof targetPlanInfo?.projects === 'number' ? targetPlanInfo.projects : 0
  const extraSeatPrice =
    typeof targetPlanInfo?.addons === 'object' &&
    targetPlanInfo.addons !== null &&
    'seats' in targetPlanInfo.addons &&
    typeof targetPlanInfo.addons.seats === 'object' &&
    targetPlanInfo.addons.seats !== null &&
    'price' in targetPlanInfo.addons.seats
      ? targetPlanInfo.addons.seats.price
      : undefined
  const needsDowngradeValidation =
    isDowngrade && !!selectedPlan && orgToDelete?.$id !== orgId
  const shouldCollectDowngradeFeedback =
    isDowngrade && selectedPlanIsFree

  useEffect(() => {
    if (!needsDowngradeValidation) {
      downgradeValidationRef.current = null
      setDowngradeValidationValid(true)
    }
  }, [needsDowngradeValidation])

  // Check if submit button should be disabled
  const isButtonDisabled = useMemo(() => {
    if (!selfService) return true
    if (!selectedPlan) return true
    if (isSubmitting) return true

    if (isCreateMode) {
      if (!organizationName.trim()) return true
      if (selectedPlanIsFree && hasFreeOrgs) return true
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

    // For downgrades: validate project/resource selection
    if (isDowngrade) {
      if (needsDowngradeValidation && !downgradeValidationValid) return true

      // For free plan: feedback required (message only, like old console)
      if (shouldCollectDowngradeFeedback) {
        if (!feedbackMessage.trim()) return true
      }

      if (selectedPlanIsFree && hasFreeOrgs && !orgToDelete) return true
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
    needsDowngradeValidation,
    downgradeValidationValid,
    shouldCollectDowngradeFeedback,
    feedbackMessage,
    selectedPlanIsFree,
    hasFreeOrgs,
    orgToDelete,
    isCreateMode,
    organizationName,
    isSubmitting,
  ])

  // Handle upgrade
  const handleUpgrade = async () => {
    if (!orgId || !selectedPlan || !paymentMethodId) return

    const planLabel = getPlanNameFromTier(selectedPlan)
    const showActivationStep = !selectedPlanIsFree

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
      await refreshOrganizationBillingResources(orgId)

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

    const planLabel = getPlanNameFromTier(selectedPlan)
    const selectedProjects =
      downgradeValidationRef.current?.getSelectedProjects?.()
    const shouldDeleteUnkeptProjects =
      orgToDelete?.$id !== orgId &&
      targetProjectsLimit > 0 &&
      allProjectsTotal > targetProjectsLimit &&
      !!selectedProjects &&
      selectedProjects.length > 0
    const showResourceDeletionStep =
      !!downgradeValidationRef.current?.deleteMarkedResources
    const showOrganizationDeletionStep = !!orgToDelete
    const showPlanUpdateStep = orgToDelete?.$id !== orgId
    const showMembershipDeletionStep =
      !!downgradeValidationRef.current?.deleteMarkedMemberships &&
      orgToDelete?.$id !== orgId

    setSetupProgress({
      mode: 'downgrade',
      phase: getInitialDowngradeProgressPhase({
        showResourceDeletionStep,
        showOrganizationDeletionStep,
        showProjectDeletionStep: shouldDeleteUnkeptProjects,
        showPlanUpdateStep,
        showMembershipDeletionStep,
      }),
      planLabel,
      showPaymentStep: false,
      showActivationStep: false,
      showPlanUpdateStep,
      showProjectDeletionStep: shouldDeleteUnkeptProjects,
      showResourceDeletionStep,
      showMembershipDeletionStep,
      showOrganizationDeletionStep,
    })

    try {
      if (downgradeValidationRef.current?.deleteMarkedResources) {
        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'deleting-resources' } : prev,
        )
        await downgradeValidationRef.current.deleteMarkedResources()
      }

      if (orgToDelete && orgToDelete.$id !== orgId) {
        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'deleting-organization' } : prev,
        )
        await deleteOrganization(orgToDelete.$id)
        await queryClient.invalidateQueries({
          queryKey: ['organizations', 'console'],
        })
      }

      if (shouldDeleteUnkeptProjects) {
        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'deleting-projects' } : prev,
        )
        const selectedProjectIds = new Set(selectedProjects)
        const allDowngradeProjects = await fetchAllDowngradeProjects(orgId)
        const projectsToDelete = allDowngradeProjects.filter(
          (project) => !selectedProjectIds.has(project.$id),
        )

        for (const project of projectsToDelete) {
          await deleteProject(project.$id, project.region)
        }
      }

      if (orgToDelete?.$id === orgId) {
        if (shouldCollectDowngradeFeedback) {
          await createDowngradeFeedbackMutation.mutateAsync({
            organizationId: orgId,
            reason: 'other',
            message: feedbackMessage,
            fromPlanId: currentPlanEnum,
            toPlanId: selectedPlan,
          })
        }

        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'deleting-organization' } : prev,
        )
        await deleteOrganization(orgId)
        await queryClient.refetchQueries({
          queryKey: ['organizations', 'console'],
          type: 'all',
        })
        if (otherFreeOrg?.$id) {
          await refreshOrganizationBillingResources(otherFreeOrg.$id)
        }
        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'complete' } : prev,
        )
        toast.success('Organization deleted successfully')
        navigate({
          to: '/organizations/$orgId',
          params: { orgId: otherFreeOrg?.$id ?? orgId },
        })
        return
      }

      setSetupProgress((prev) =>
        prev ? { ...prev, phase: 'updating-plan' } : prev,
      )
      await updatePlanMutation.mutateAsync({
        organizationId: orgId,
        billingPlan: selectedPlan,
        paymentMethodId,
      })

      if (downgradeValidationRef.current?.deleteMarkedMemberships) {
        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'deleting-memberships' } : prev,
        )
        await downgradeValidationRef.current.deleteMarkedMemberships()
      }

      // Track feedback if downgrading to Free.
      if (shouldCollectDowngradeFeedback) {
        await createDowngradeFeedbackMutation.mutateAsync({
          organizationId: orgId,
          reason: 'other',
          message: feedbackMessage,
          fromPlanId: currentPlanEnum,
          toPlanId: selectedPlan,
        })
      }
      await refreshOrganizationBillingResources(orgId)

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

  // Handle create organization with selected plan
  const handleCreateOrganization = async () => {
    if (!selectedPlan || !organizationName.trim()) return

    const organizationId = ID.unique()
    const requiresPayment =
      !selectedPlanIsFree && isUpgrade && paymentMethodId

    if (!selectedPlanIsFree && !paymentMethodId) {
      return
    }

    const planLabel = getPlanNameFromTier(selectedPlan)
    const showActivationStep = !selectedPlanIsFree

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
      await refreshOrganizationBillingResources(createdOrgId)

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
    !selectedPlanIsFree &&
    currentTierStr !== 'custom' &&
    currentTierStr !== 'Custom'

  // Show plan comparison box conditions
  const showPlanComparison =
    !showEstimatedTotal ||
    selectedPlanIsFree ||
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
              <MarketingSiteLink className="underline hover:text-foreground" href="/pricing">
                pricing page
              </MarketingSiteLink>
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

      {showFreePlanConflict && otherFreeOrg && (
        <FreePlanConflictResolution
          otherFreeOrg={otherFreeOrg}
          currentOrg={
            organization
              ? { $id: organization.$id, name: organization.name }
              : null
          }
          pendingOrgName={organizationName}
          showCurrentOrgOption={!isCreateMode}
          keepChoiceId={
            !isCreateMode && organization
              ? (freePlanKeepChoiceId ?? organization.$id)
              : undefined
          }
          onKeepChoiceChange={
            !isCreateMode ? setFreePlanKeepChoiceId : undefined
          }
        />
      )}

      {/* Downgrade-specific sections */}
      {isDowngrade && selectedPlan && (
        <>
          {needsDowngradeValidation && orgId ? (
            <DowngradeValidation
              key={`${orgId}-${freePlanKeepChoiceId ?? 'default'}`}
              organizationId={orgId}
              organizationName={organization?.name}
              projects={allProjects}
              projectsTotal={allProjectsTotal}
              targetPlan={targetPlanInfo}
              onRef={handleDowngradeValidationRef}
              onValidityChange={handleDowngradeValidationValid}
              deletedOrganizationImpact={effectiveDeletedOrganizationImpact}
              deletedOrganizationLoading={effectiveDeletedOrganizationLoading}
              expectDeletedOrganizationImpact={!!orgToDelete}
            />
          ) : orgToDelete ? (
            <DowngradeImpactSummary
              key={`deleted-org-impact-${orgToDelete.$id}`}
              allProjects={[]}
              keptProjects={[]}
              resourceImpact={{}}
              deletedOrganizationImpact={effectiveDeletedOrganizationImpact}
              deletedOrganizationLoading={effectiveDeletedOrganizationLoading}
              expectDeletedOrganizationImpact
              keptOrganizationImpactReady={false}
            />
          ) : null}

          {/* Downgrade Alerts */}
          {selectedPlanIsPro && (
            <Alert>
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>
                Monthly Charges for Extra Organization Members
              </AlertTitle>
              <AlertDescription className="mt-2">
                {extraSeatPrice
                  ? `You will be charged $${extraSeatPrice} per month for each organization member beyond the plan limit.`
                  : 'You will be charged for each organization member beyond the plan limit.'}
              </AlertDescription>
            </Alert>
          )}

          {selectedPlanIsFree && (
            <WarningAlert title="Downgrading to Free Plan">
              Your plan will change on{' '}
              {organization?.billingPlanDowngrade ||
                'the end of your billing period'}
              . You will lose access to premium features and organization
              members beyond the free limit will be removed.
              <DocsRouteLink className="ml-1 underline" href="/docs/migration">
                Learn more about migration
              </DocsRouteLink>
            </WarningAlert>
          )}

          {/* Feedback Form for Free Plan (matches old console: "What wasn't working for you?" required) */}
          {shouldCollectDowngradeFeedback && (
            <div>
              <Label className="mb-2 block">Feedback</Label>
              <p className="text-[13px] text-muted-foreground mb-4">
                What wasn&apos;t working for you? Please share anything that
                influenced your decision to downgrade. This feedback helps us
                improve the platform.
              </p>
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
