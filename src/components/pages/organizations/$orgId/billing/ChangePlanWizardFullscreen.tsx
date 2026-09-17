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
  usePlanEstimation,
  useEstimationCreateOrganization,
  useUpdateOrganizationPlan,
  useValidateOrganization,
  useCreateDowngradeFeedback,
  useCreateOrganization,
  usePaymentMethods,
  useOrganizations,
  deleteOrganization,
  organizationsQueryOptions,
  organizationQueryOptions,
  organizationPlanQueryOptions,
  billingPlansQueryOptions,
} from '@/lib/react-query/hooks'
import { prefetchOrganizationOverviewData } from '@/lib/organization-overview-prefetch'
import { useSmartNavigation } from '@/lib/hooks/useSmartNavigation'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import { PlanSelection } from './change-plan/PlanSelection'
import { FreePlanConflictResolution } from './change-plan/FreePlanConflictResolution'
import { SelectPaymentMethod } from './change-plan/SelectPaymentMethod'
import { EstimatedTotalBox } from './change-plan/EstimatedTotalBox'
import { PlanComparisonBox } from './change-plan/PlanComparisonBox'
import { DowngradeValidation } from './change-plan/DowngradeValidation'
import type {
  DowngradeValidationHandle,
  PendingDowngradeDeletions,
} from './change-plan/DowngradeValidation'
import { DowngradeImpactSummary } from './change-plan/DowngradeImpactSummary'
import { ConfirmPlanChange } from './change-plan/ConfirmPlanChange'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { DowngradeAddonWarning } from './change-plan/DowngradeAddonWarning'
import { DowngradeUsageWarning } from './change-plan/DowngradeUsageWarning'
import { DowngradePlanLossWarning } from './change-plan/DowngradePlanLossWarning'
import { resolveOrgToDelete } from '@/lib/billing/free-plan-conflict'
import {
  fetchDeletedOrganizationImpact,
  type DeletedOrganizationImpact,
} from '@/lib/billing/fetch-deleted-org-impact'
import { fetchProjectDowngradeResources } from '@/lib/billing/fetch-project-downgrade-resources'
import {
  fetchAllDowngradeDomains,
  fetchAllDowngradeMemberships,
  fetchAllDowngradeProjects,
  findCurrentUserMembership,
} from '@/lib/billing/fetch-downgrade-org-resources'
import {
  deleteDowngradeAddons,
  deleteDowngradeDomains,
  deleteDowngradeMemberships,
} from '@/lib/billing/delete-downgrade-org-resources'
import {
  getUnresolvedUnsupportedAddons,
  isDisableableDowngradeAddon,
} from '@/lib/billing/downgrade-addons'
import {
  deleteDowngradeResources,
  narrowResourcesToType,
} from '@/lib/billing/delete-downgrade-resources'
import { deleteProject } from '@/lib/react-query/hooks/projects'
import {
  DOWNGRADE_RESOURCE_TYPES,
  type DowngradeResourceImpact,
} from '@/lib/billing/downgrade-plan-limits'
import {
  buildDowngradeDeletionSteps,
  type DowngradeDeletionStep,
} from '@/lib/billing/downgrade-deletion-steps'
import {
  getComplianceErrors,
  getNonCompliantProjectIds,
  getOrganizationViolations,
  getUnresolvableResources,
  type PlanChangeLimits,
} from '@/lib/billing/plan-change-compliance'
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
import { isPaymentAuthentication } from '@/lib/billing/addons'
import {
  compareBillingPlanRefs,
  getBillingPlanDisplayLabel,
  getPlanCanonicalFromRecord,
  isFreePlanRef,
  resolveBillingPlanRecord,
} from '@/lib/utils/plan-filter'
import type { Models } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

/**
 * ChangePlanWizardFullscreen Component
 *
 * Fullscreen wizard for upgrading or downgrading organization billing plans.
 * Handles plan selection, payment methods, project selection (for downgrades),
 * coupon codes, and member invites.
 */

const ESTIMATION_DEBOUNCE_MS = 500
const DELETED_ORG_LIST_LIMIT = 1000

function usesFreeOrganizationSlot(org: {
  plan?: string
  billingPlanDowngrade?: unknown
}) {
  return org.plan === 'free' || !!org.billingPlanDowngrade
}

function isOrganizationWriteResult(
  value: unknown,
): value is Models.Organization {
  return (
    !!value &&
    typeof value === 'object' &&
    typeof (value as { $id?: unknown }).$id === 'string' &&
    !isPaymentAuthentication(value)
  )
}

function catalogPlanForId(
  queryClient: ReturnType<typeof useQueryClient>,
  planId: string | undefined,
): Models.BillingPlan | undefined {
  if (!planId) return undefined
  const catalog = queryClient.getQueryData<{
    plans?: Record<string, Models.BillingPlan>
  }>(billingPlansQueryOptions().queryKey)
  return resolveBillingPlanRecord(planId, catalog?.plans) as
    | Models.BillingPlan
    | undefined
}

function getInitialDowngradeProgressPhase({
  hasStagedDeletions,
  showOrganizationDeletionStep,
  showPlanUpdateStep,
}: {
  hasStagedDeletions: boolean
  showOrganizationDeletionStep: boolean
  showPlanUpdateStep: boolean
}): OrganizationSetupPhase {
  if (hasStagedDeletions) return 'deleting-resources'
  if (showOrganizationDeletionStep) return 'deleting-organization'
  if (showPlanUpdateStep) return 'updating-plan'
  return 'complete'
}

async function runDowngradeDeletionStep(
  organizationId: string,
  pending: PendingDowngradeDeletions,
  step: DowngradeDeletionStep,
  account: Models.User | undefined,
) {
  if (step.id === 'projects') {
    const ids = new Set(pending.projects.map((item) => item.id))
    const allProjects = await fetchAllDowngradeProjects(organizationId)
    for (const project of allProjects.filter((item) => ids.has(item.$id))) {
      await deleteProject(project.$id, project.region)
    }
    return
  }

  if (step.id === 'members') {
    const ids = new Set(pending.memberships.map((item) => item.id))
    const memberships = await fetchAllDowngradeMemberships(organizationId)
    // The selection UI can only lock the acting user's row when it is on the
    // loaded page, so refuse it again here against the full list.
    const self = findCurrentUserMembership(memberships, account)
    await deleteDowngradeMemberships(
      organizationId,
      memberships
        .filter((item) => ids.has(item.$id) && item.$id !== self?.$id)
        .map((item) => item.$id),
    )
    return
  }

  if (step.id === 'domains') {
    const ids = new Set(pending.domains.map((item) => item.id))
    const domains = await fetchAllDowngradeDomains(organizationId)
    await deleteDowngradeDomains(
      domains.filter((item) => ids.has(item.$id)).map((item) => item.$id),
    )
    return
  }

  if (step.id === 'addons') {
    await deleteDowngradeAddons(pending.addons)
    return
  }

  const resourceType = DOWNGRADE_RESOURCE_TYPES.find(({ id }) => id === step.id)
  if (!resourceType) return

  await deleteDowngradeResources(
    narrowResourcesToType(pending.resources, resourceType.id),
  )
}

const ORG_DELETION_STEP_IDS = new Set(['projects', 'members', 'domains'])

/**
 * Compliance gate. Still fails closed on anything the run cannot account for,
 * but an addon it just disabled is not a real block: the server reads
 * `currentValue` and ignores the `nextValue = 0` that marks it for removal.
 *
 * `strict` is false once the deletions have run: they cannot be given back, so
 * only an explicit block may abort the plan update.
 */
async function isPlanChangeStillBlocked(
  organizationId: string,
  limits: PlanChangeLimits | null | undefined,
  { strict }: { strict: boolean },
): Promise<boolean> {
  // A response we could read but that carries no limits is not permission.
  if (!limits) return true
  if (limits.canChangePlan === true) return false

  const reportedAddons = limits.unsupportedAddons ?? []
  if (
    reportedAddons.length === 0 ||
    getOrganizationViolations(limits).length > 0 ||
    getNonCompliantProjectIds(limits).length > 0 ||
    getComplianceErrors(limits).length > 0
  ) {
    return true
  }

  try {
    const unresolved = await getUnresolvedUnsupportedAddons(
      organizationId,
      reportedAddons,
    )
    return unresolved.length > 0
  } catch {
    // Same asymmetry as the caller: this read failing must not strand a run
    // whose deletions already happened.
    return strict
  }
}

async function runDowngradeDeletions(
  organizationId: string,
  pending: PendingDowngradeDeletions,
  steps: DowngradeDeletionStep[],
  account: Models.User | undefined,
  onStepStatus: (id: string, status: 'running' | 'done') => void,
) {
  // Resources before the organization-level deletes: a resource delete queued
  // inside a project would 404 once that project is gone. Display order stays
  // as built.
  const ordered = [...steps].sort(
    (a, b) =>
      Number(ORG_DELETION_STEP_IDS.has(a.id)) -
      Number(ORG_DELETION_STEP_IDS.has(b.id)),
  )

  for (const step of ordered) {
    onStepStatus(step.id, 'running')
    await runDowngradeDeletionStep(organizationId, pending, step, account)
    onStepStatus(step.id, 'done')
  }
}

export function ChangePlanWizardFullscreen() {
  const t = useT()
  const { account } = useAuth()
  const accountModel = account as Models.User | undefined
  const navigate = useNavigate()
  const search = useSearch({ from: '/_public/upgrade' })
  const orgId = search.orgId
  const isCreateMode = !orgId
  const queryClient = useQueryClient()
  const refreshOrganizationBillingResources = useCallback(
    async (
      organizationId: string,
      seedOrganization?: Models.Organization,
      targetPlanId?: string,
    ) => {
      // Drop in-flight org/plan refetches started by mutation onSuccess
      // (those often still see Free while 3DS / validate is in progress).
      await Promise.all([
        queryClient.cancelQueries({
          queryKey: ['organization', organizationId],
        }),
        queryClient.cancelQueries({
          queryKey: ['organization', 'plan', organizationId],
        }),
        queryClient.cancelQueries({
          queryKey: ['organizations', 'console'],
        }),
      ])

      if (seedOrganization?.$id) {
        queryClient.setQueryData(
          ['organization', seedOrganization.$id],
          seedOrganization,
        )
      }

      // Member/project limits read this query. Seed from the plan catalog so
      // seats unlock even if getPlan still returns the previous Free plan.
      const planId = targetPlanId || seedOrganization?.billingPlan
      const catalogPlan = catalogPlanForId(queryClient, planId)
      if (catalogPlan) {
        queryClient.setQueryData(
          ['organization', 'plan', organizationId],
          catalogPlan,
        )
      }

      // Critical path: always populate/refresh these so the destination org page
      // never mounts without the new org (avoids a bounce back to /upgrade).
      // fetchQuery is required for organization detail after create: refetchQueries
      // is a no-op when that query has never been observed.
      const [fetchedOrganization, , fetchedPlan] = await Promise.all([
        queryClient.fetchQuery(organizationQueryOptions(organizationId)),
        queryClient.fetchQuery(organizationsQueryOptions()),
        queryClient
          .fetchQuery(organizationPlanQueryOptions(organizationId))
          .catch(() => undefined),
      ])

      // List/get can lag the plan write. Keep the validate/update payload when
      // it already has the new billingPlan so billing settings doesn't flash Free.
      if (
        seedOrganization?.billingPlan &&
        fetchedOrganization?.billingPlan !== seedOrganization.billingPlan
      ) {
        queryClient.setQueryData(
          ['organization', organizationId],
          seedOrganization,
        )
        queryClient.setQueryData(
          ['organizations', 'console'],
          (
            previous:
              | { teams?: Array<{ $id: string }>; total?: number }
              | undefined,
          ) => {
            if (!previous?.teams) return previous
            return {
              ...previous,
              teams: previous.teams.map((team) =>
                team.$id === organizationId
                  ? { ...team, ...seedOrganization }
                  : team,
              ),
            }
          },
        )
      }

      // getPlan can lag the same way. Keep catalog seats/limits so invite
      // and member-limit UI unlock without a hard reload.
      if (catalogPlan && fetchedPlan?.$id !== catalogPlan.$id) {
        queryClient.setQueryData(
          ['organization', 'plan', organizationId],
          catalogPlan,
        )
      }

      // Supporting billing data: never fail the setup flow if these error.
      await Promise.allSettled([
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

  const seedCreatedOrganizationCache = useCallback(
    (createdOrg: { $id: string; name?: string; [key: string]: unknown }) => {
      queryClient.setQueryData(['organization', createdOrg.$id], createdOrg)
      queryClient.setQueryData(
        ['organizations', 'console'],
        (
          previous:
            | { teams?: Array<{ $id: string }>; total?: number }
            | undefined,
        ) => {
          const teams = previous?.teams ?? []
          if (teams.some((team) => team.$id === createdOrg.$id)) {
            return previous
          }
          return {
            ...previous,
            teams: [...teams, createdOrg],
            total: (previous?.total ?? teams.length) + 1,
          }
        },
      )
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
      ) ?? null
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
          const orderA = resolveBillingPlanRecord(a, billingPlans)?.order ?? 999
          const orderB = resolveBillingPlanRecord(b, billingPlans)?.order ?? 999
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
  const [confirmPlanChangeOpen, setConfirmPlanChangeOpen] = useState(false)
  const [confirmDeletions, setConfirmDeletions] =
    useState<PendingDowngradeDeletions | null>(null)
  const [setupProgress, setSetupProgress] =
    useState<OrganizationSetupProgressState | null>(null)
  const downgradeValidationRef = useRef<DowngradeValidationHandle | null>(null)
  const [downgradeValidationValid, setDowngradeValidationValid] = useState(true)
  const [downgradeBlockReason, setDowngradeBlockReason] = useState<
    string | null
  >(null)
  const [freePlanDeleteChoiceId, setFreePlanDeleteChoiceId] = useState<
    string | null
  >(null)
  const [freePlanDeleteConfirmed, setFreePlanDeleteConfirmed] = useState(false)
  // Warnings must describe the projects that survive, not the ones being deleted.
  const [keptProjects, setKeptProjects] = useState<Models.Project[] | null>(
    null,
  )

  const handleDowngradeValidationRef = useCallback(
    (ref: DowngradeValidationHandle | null) => {
      downgradeValidationRef.current = ref
    },
    [],
  )

  const handleDowngradeValidationValid = useCallback(
    (valid: boolean, reason?: string | null) => {
      setDowngradeValidationValid((prev) => (prev === valid ? prev : valid))
      setDowngradeBlockReason((prev) => {
        const next = reason ?? null
        return prev === next ? prev : next
      })
    },
    [],
  )

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
          const validatedOrganization =
            await validateOrganizationMutation.mutateAsync({
              organizationId,
              invites,
            })
          await refreshOrganizationBillingResources(
            organizationId,
            isOrganizationWriteResult(validatedOrganization)
              ? validatedOrganization
              : undefined,
            (search?.plan as string | undefined) ||
              (isOrganizationWriteResult(validatedOrganization)
                ? validatedOrganization.billingPlan
                : undefined),
          )
          toast.success(t('Payment confirmed successfully'))
          navigate({
            to: '/organizations/$orgId/settings/billing',
            params: { orgId: organizationId },
          })
        } catch (error) {
          toast.error(
            error instanceof Error
              ? error.message
              : t('Failed to validate payment'),
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
    t,
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

  // Private plans (such as the Student Pack) are absent from the selectable
  // catalog. Include the current plan's price and order when comparing plans.
  const comparisonPlans = useMemo(
    () =>
      plan?.$id === currentPlanEnum
        ? { ...billingPlans, [plan.$id]: plan }
        : billingPlans,
    [billingPlans, currentPlanEnum, plan],
  )

  // Determine if upgrade or downgrade
  const isUpgrade = useMemo(() => {
    if (!selectedPlan) return false
    if (isCreateMode) return !selectedPlanIsFree
    if (!currentPlanEnum) return false
    return (
      compareBillingPlanRefs(currentPlanEnum, selectedPlan, comparisonPlans) ===
      'upgrade'
    )
  }, [
    selectedPlan,
    currentPlanEnum,
    isCreateMode,
    comparisonPlans,
    selectedPlanIsFree,
  ])

  const isDowngrade = useMemo(() => {
    if (isCreateMode || !selectedPlan || !currentPlanEnum) return false
    if (selectedPlanIsFree && !currentPlanIsFree) return true
    return (
      compareBillingPlanRefs(currentPlanEnum, selectedPlan, comparisonPlans) ===
      'downgrade'
    )
  }, [
    selectedPlan,
    currentPlanEnum,
    isCreateMode,
    comparisonPlans,
    selectedPlanIsFree,
    currentPlanIsFree,
  ])

  const showFreePlanConflict =
    selectedPlanIsFree && hasFreeOrgs && !!otherFreeOrg && isDowngrade

  const orgToDelete = useMemo(() => {
    if (!showFreePlanConflict || !otherFreeOrg || isCreateMode) return null

    const currentOrg = organization
      ? { $id: organization.$id, name: organization.name }
      : null

    return resolveOrgToDelete(
      freePlanDeleteChoiceId ?? otherFreeOrg.$id,
      otherFreeOrg,
      currentOrg,
    )
  }, [
    showFreePlanConflict,
    otherFreeOrg,
    organization,
    isCreateMode,
    freePlanDeleteChoiceId,
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
    queryKey: ['billing', 'current-deleted-organization-memberships', orgId],
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

  const currentDeletedOrganizationImpact =
    useMemo<DeletedOrganizationImpact | null>(() => {
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
    if (!showFreePlanConflict || !otherFreeOrg || isCreateMode) {
      setFreePlanDeleteChoiceId(null)
      setFreePlanDeleteConfirmed(false)
      return
    }
    setFreePlanDeleteChoiceId((prev) => prev ?? otherFreeOrg.$id)
  }, [showFreePlanConflict, otherFreeOrg, isCreateMode])

  // Clear coupon when downgrading an existing organization
  useEffect(() => {
    if (isDowngrade) {
      setSelectedCoupon(null)
    }
  }, [isDowngrade, selectedPlan])

  const needsPaymentMethods = !!selectedPlan && !selectedPlanIsFree && isUpgrade

  // Fetch saved cards only for paid-plan flows, or when the add-card modal is open.
  const { paymentMethods, isLoading: paymentMethodsLoading } =
    usePaymentMethods({
      enabled: needsPaymentMethods || paymentModalOpen,
    })

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

  // The plan-change estimation also carries downgrade compliance, so unlike the
  // create-org estimation it must run for free target plans too - that is the
  // downgrade case we need the limits for.
  const debouncedPlanChangePlan = useDebouncedValue(
    selectedPlan,
    ESTIMATION_DEBOUNCE_MS,
  )

  const shouldFetchUpdateEstimationDebounced =
    !isCreateMode &&
    debouncedPlanChangePlan &&
    debouncedPlanChangePlan !== currentPlanEnum &&
    orgId

  const shouldFetchCreateEstimationDebounced =
    isCreateMode &&
    debouncedEstimationPlan &&
    !!debouncedEstimationPaymentMethodId

  const updateEstimation = usePlanEstimation(
    shouldFetchUpdateEstimationDebounced ? orgId : null,
    shouldFetchUpdateEstimationDebounced ? debouncedPlanChangePlan : null,
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

  const estimationInputsDebouncing = isCreateMode
    ? (selectedPlan && !selectedPlanIsFree ? selectedPlan : null) !==
        debouncedEstimationPlan ||
      (estimationPaymentMethodId ?? null) !== debouncedEstimationPaymentMethodId
    : selectedPlan !== debouncedPlanChangePlan

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
  const targetBillingPlan = useMemo(() => {
    return resolveBillingPlanRecord(
      selectedPlan,
      billingPlans,
    ) as Models.BillingPlan | null
  }, [billingPlans, selectedPlan])
  const targetPlanInfo = targetBillingPlan as Record<string, unknown> | null

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
  const shouldCollectDowngradeFeedback = isDowngrade && selectedPlanIsFree

  // Server-side compliance for the target plan. `canChangePlan` being false is
  // expected while the user still has resources to delete - the selection UI
  // below resolves that before submit. Only the issues the console cannot
  // resolve are hard blockers.
  const planChangeLimits = isCreateMode ? null : updateEstimation.limits
  const unsupportedAddons = useMemo(
    () => planChangeLimits?.unsupportedAddons ?? [],
    [planChangeLimits],
  )
  // Addons this flow disables as part of the run are covered by the warning
  // card and the confirmation manifest, so only the rest stay hard blockers.
  const unresolvableAddons = useMemo(
    () =>
      unsupportedAddons.filter((addon) => !isDisableableDowngradeAddon(addon)),
    [unsupportedAddons],
  )
  const unresolvableResources = useMemo(
    () => getUnresolvableResources(planChangeLimits),
    [planChangeLimits],
  )
  const complianceErrors = useMemo(
    () => getComplianceErrors(planChangeLimits),
    [planChangeLimits],
  )
  const hasPlanChangeBlockers =
    unresolvableAddons.length > 0 ||
    unresolvableResources.length > 0 ||
    complianceErrors.length > 0

  useEffect(() => {
    if (!needsDowngradeValidation) {
      downgradeValidationRef.current = null
      setDowngradeValidationValid(true)
    }
  }, [needsDowngradeValidation])

  // Why submit is unavailable, as a sentence to show the user. Returning a
  // reason rather than a bare boolean is the point: the button was previously
  // disabled with nothing indicating which section was holding it.
  const submitBlockReason = useMemo<string | null>(() => {
    if (!selfService) {
      return t(
        'This organization is managed by Appwrite. Contact support to change plans.',
      )
    }
    if (!selectedPlan) return t('Select a plan to continue.')
    if (isSubmitting) return null

    const missingCard = () => {
      if (!paymentMethodId) return t('Add a payment method.')
      const selectedMethod = paymentMethods.find(
        (pm: Models.PaymentMethod) => pm.$id === paymentMethodId,
      )
      if (!selectedMethod?.last4) {
        return t('Finish adding your payment method.')
      }
      return null
    }

    if (isCreateMode) {
      if (!organizationName.trim()) return t('Enter an organization name.')
      if (selectedPlanIsFree && hasFreeOrgs) {
        return t('You already have a free organization.')
      }
      if (isUpgrade) return missingCard()
      return null
    }

    if (selectedPlan === currentPlanEnum) {
      return t('Select a different plan to continue.')
    }

    if (!isUpgrade && !isDowngrade) {
      return t('This plan change is unavailable. Please contact support.')
    }

    // Issues the console cannot resolve on the user's behalf (unsupported
    // addons, resource types with no selection UI, projects the server could
    // not evaluate). The server fails closed on these, so we do too.
    if (hasPlanChangeBlockers) {
      return t('Resolve the issues listed above to continue.')
    }

    if (isUpgrade) {
      const cardIssue = missingCard()
      if (cardIssue) return cardIssue
    }

    if (isDowngrade) {
      if (needsDowngradeValidation && !downgradeValidationValid) {
        return (
          downgradeBlockReason ??
          t('Finish adjusting your organization for the target plan.')
        )
      }

      if (
        needsDowngradeValidation &&
        (updateEstimation.isFetching || estimationInputsDebouncing)
      ) {
        return t('Checking whether the plan can be changed...')
      }

      // For free plan: feedback required (message only, like old console)
      if (shouldCollectDowngradeFeedback && !feedbackMessage.trim()) {
        return t('Tell us why you are downgrading.')
      }

      if (selectedPlanIsFree && hasFreeOrgs && !orgToDelete) {
        return t('Choose which organization to delete.')
      }

      // Deleting a whole organization must never ride on an untouched default.
      if (showFreePlanConflict && !freePlanDeleteConfirmed) {
        return t('Confirm which organization to delete.')
      }
    }

    return null
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
    hasPlanChangeBlockers,
    downgradeBlockReason,
    updateEstimation.isFetching,
    estimationInputsDebouncing,
    showFreePlanConflict,
    freePlanDeleteConfirmed,
    t,
  ])

  const isButtonDisabled = isSubmitting || submitBlockReason !== null

  // Handle upgrade
  const handleUpgrade = async () => {
    if (!orgId || !selectedPlan || !paymentMethodId) return

    const planLabel = getBillingPlanDisplayLabel(selectedPlan)
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
        // Don't refetch the org here: it is still Free until validatePayment.
        // A refetch would cache Free and win a race against the post-validate
        // refresh (billing settings then keeps showing the Free plan).
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
      const validatedOrganization =
        await validateOrganizationMutation.mutateAsync({
          organizationId: orgId,
          invites: [],
        })
      const seedOrganization = isOrganizationWriteResult(validatedOrganization)
        ? validatedOrganization
        : isOrganizationWriteResult(result)
          ? result
          : undefined
      await refreshOrganizationBillingResources(
        orgId,
        seedOrganization,
        selectedPlan,
      )

      setSetupProgress((prev) => (prev ? { ...prev, phase: 'complete' } : prev))

      toast.success(t('Plan updated successfully'))
      await navigate({
        to: '/organizations/$orgId/settings/billing',
        params: { orgId },
        replace: true,
      })
    } catch (error) {
      setSetupProgress(null)
      toast.error(
        error instanceof Error ? error.message : t('Failed to update plan'),
      )
    }
  }

  const invalidateAfterDowngradeDeletes = useCallback(
    async (organizationId: string) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['projects', 'organization', organizationId],
        }),
        queryClient.invalidateQueries({
          queryKey: ['memberships', 'organization', organizationId],
        }),
        queryClient.invalidateQueries({
          queryKey: ['domains', 'organization', organizationId],
        }),
        queryClient.invalidateQueries({
          queryKey: ['plan-estimation', organizationId],
        }),
        queryClient.invalidateQueries({ queryKey: ['downgrade-resources'] }),
        queryClient.invalidateQueries({ queryKey: ['downgrade-addons'] }),
        queryClient.invalidateQueries({ queryKey: ['addons'] }),
      ])
    },
    [queryClient],
  )

  // Handle downgrade
  // Takes the snapshot the user confirmed rather than re-reading the handle:
  // the resource queries stay live behind the dialog, so a refetch between
  // confirming and running would delete a different set than was reviewed.
  const handleDowngrade = async (
    pendingDeletions?: PendingDowngradeDeletions,
  ) => {
    if (!orgId || !selectedPlan) return

    const planLabel = getBillingPlanDisplayLabel(selectedPlan)
    const showOrganizationDeletionStep = !!orgToDelete
    const showPlanUpdateStep = orgToDelete?.$id !== orgId
    const deletionSteps = buildDowngradeDeletionSteps(pendingDeletions)
    const hasStagedDeletions = deletionSteps.length > 0

    setSetupProgress({
      mode: 'downgrade',
      phase: getInitialDowngradeProgressPhase({
        hasStagedDeletions,
        showOrganizationDeletionStep,
        showPlanUpdateStep,
      }),
      planLabel,
      showPaymentStep: false,
      showActivationStep: false,
      showPlanUpdateStep,
      showOrganizationDeletionStep,
      showResourceDeletionStep: hasStagedDeletions,
      deletionSummary: deletionSteps.map((step) => ({
        ...step,
        status: 'pending' as const,
      })),
      deletedOrganizationName: orgToDelete?.name,
    })

    let deletionsRan = false

    try {
      if (pendingDeletions && hasStagedDeletions) {
        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'deleting-resources' } : prev,
        )
        deletionsRan = true
        await runDowngradeDeletions(
          orgId,
          pendingDeletions,
          deletionSteps,
          accountModel,
          (stepId, status) => {
            setSetupProgress((prev) =>
              prev
                ? {
                    ...prev,
                    deletionSummary: prev.deletionSummary?.map((step) =>
                      step.id === stepId ? { ...step, status } : step,
                    ),
                  }
                : prev,
            )
          },
        )
        await invalidateAfterDowngradeDeletes(orgId)
      }

      if (needsDowngradeValidation) {
        // The query does not retry itself, so one more attempt separates a
        // blip from an outage before either verdict is acted on.
        let estimationResult = await updateEstimation.refetch()
        if (estimationResult.isError) {
          estimationResult = await updateEstimation.refetch()
        }

        // Only an unreachable estimation is treated permissively, and only
        // once the deletions are irreversible: aborting then would strand the
        // user without their resources and still on the old plan, and
        // `PATCH /plan` does not enforce `canChangePlan` anyway. Any response
        // we can actually read still decides, in both phases.
        const blocked = estimationResult.isError
          ? !deletionsRan
          : await isPlanChangeStillBlocked(
              orgId,
              estimationResult.data?.limits,
              { strict: !deletionsRan },
            )
        if (blocked) {
          setSetupProgress(null)
          toast.error(
            t(
              'Could not change plan. Remaining usage still exceeds the selected plan.',
            ),
          )
          return
        }
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
        toast.success(t('Organization deleted successfully'))
        navigate({
          to: '/organizations/$orgId',
          params: { orgId: otherFreeOrg?.$id ?? orgId },
        })
        return
      }

      setSetupProgress((prev) =>
        prev ? { ...prev, phase: 'updating-plan' } : prev,
      )
      const downgradeResult = await updatePlanMutation.mutateAsync({
        organizationId: orgId,
        billingPlan: selectedPlan,
        paymentMethodId,
      })

      if (shouldCollectDowngradeFeedback) {
        await createDowngradeFeedbackMutation.mutateAsync({
          organizationId: orgId,
          reason: 'other',
          message: feedbackMessage,
          fromPlanId: currentPlanEnum,
          toPlanId: selectedPlan,
        })
      }
      await refreshOrganizationBillingResources(
        orgId,
        isOrganizationWriteResult(downgradeResult)
          ? downgradeResult
          : undefined,
        selectedPlan,
      )

      setSetupProgress((prev) => (prev ? { ...prev, phase: 'complete' } : prev))
      toast.success(t('Plan updated successfully'))
      navigate({
        to: '/organizations/$orgId/settings/billing',
        params: { orgId },
      })
    } catch (error) {
      setSetupProgress(null)
      // Whatever was deleted before the failure is gone; re-render the
      // selection against fresh data so the user re-confirms what is left.
      await invalidateAfterDowngradeDeletes(orgId)
      toast.error(
        error instanceof Error ? error.message : t('Failed to update plan'),
      )
    }
  }

  // Handle create organization with selected plan
  const handleCreateOrganization = async () => {
    if (!selectedPlan || !organizationName.trim()) return

    const organizationId = ID.unique()
    const requiresPayment = !selectedPlanIsFree && isUpgrade && paymentMethodId

    if (!selectedPlanIsFree && !paymentMethodId) {
      return
    }

    const planLabel = getBillingPlanDisplayLabel(selectedPlan)
    const showActivationStep = !selectedPlanIsFree
    let createdOrgId: string | null = null

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
        name?: string
        [key: string]: unknown
      }
      createdOrgId = resultObj?.$id || organizationId
      if (resultObj?.$id) {
        seedCreatedOrganizationCache(
          resultObj as { $id: string; name?: string; [key: string]: unknown },
        )
      } else {
        seedCreatedOrganizationCache({
          $id: createdOrgId,
          name: organizationName.trim(),
        })
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
      }

      let createdSeedOrganization: Models.Organization | undefined
      if (isOrganizationWriteResult(result)) {
        createdSeedOrganization = result
      }
      if (showActivationStep) {
        setSetupProgress((prev) =>
          prev ? { ...prev, phase: 'activating' } : prev,
        )
        const validatedOrganization =
          await validateOrganizationMutation.mutateAsync({
            organizationId: createdOrgId,
            invites: [],
          })
        if (isOrganizationWriteResult(validatedOrganization)) {
          createdSeedOrganization = validatedOrganization
        }
      }
      await refreshOrganizationBillingResources(
        createdOrgId,
        createdSeedOrganization,
        selectedPlan,
      )
      // Warm the org overview cache before leaving /upgrade so the destination
      // loader is a cache hit and the progress UI is not replaced by a remount
      // of the create form (or a bounce back to /upgrade).
      await prefetchOrganizationOverviewData(queryClient, createdOrgId)

      setSetupProgress((prev) => (prev ? { ...prev, phase: 'complete' } : prev))

      toast.success(t('Organization created successfully'))
      await navigate({
        to: '/organizations/$orgId',
        params: { orgId: createdOrgId },
        replace: true,
      })
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to create organization'),
      )
      // Org may already exist (payment/activation/refresh failed after create).
      // Navigate there instead of clearing progress back to the create form.
      if (createdOrgId) {
        try {
          await prefetchOrganizationOverviewData(queryClient, createdOrgId)
          await navigate({
            to: '/organizations/$orgId',
            params: { orgId: createdOrgId },
            replace: true,
          })
          return
        } catch {
          // Fall through to showing the form again.
        }
      }
      setSetupProgress(null)
    }
  }

  // Handle submit
  const handleSubmit = () => {
    if (isButtonDisabled) return
    if (isCreateMode) {
      handleCreateOrganization()
      return
    }
    if (isUpgrade) {
      handleUpgrade()
    } else if (isDowngrade) {
      const pendingDeletions = needsDowngradeValidation
        ? downgradeValidationRef.current?.getPendingDeletions()
        : undefined
      const steps = buildDowngradeDeletionSteps(pendingDeletions)
      // Only the destructive path needs a last confirmation.
      if (steps.length === 0 && !orgToDelete) {
        handleDowngrade(pendingDeletions)
        return
      }
      setConfirmDeletions(pendingDeletions ?? null)
      setConfirmPlanChangeOpen(true)
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

  const wizardTitle = isCreateMode ? t('Create organization') : t('Change plan')
  const submitLabel = isCreateMode ? t('Create organization') : t('Change plan')

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
          {submitBlockReason && !isSubmitting ? (
            <p
              className="text-[13px] text-muted-foreground me-auto text-start"
              role="status"
            >
              {submitBlockReason}
            </p>
          ) : null}
          <Button
            variant="outline"
            onClick={handleCancel}
            disabled={isSubmitting}
            {...analyticsAttrs('upgrade-cancel')}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={isButtonDisabled}
            title={submitBlockReason ?? undefined}
            {...analyticsAttrs(
              isCreateMode ? 'upgrade-create-org' : 'upgrade-submit',
            )}
          >
            {submitLabel}
          </Button>
        </>
      }
    >
      {isCreateMode && (
        <div className="space-y-2">
          <Label htmlFor="organization-name">
            {t('Name')} <span className="text-destructive">*</span>
          </Label>
          <Input
            id="organization-name"
            type="text"
            placeholder={t('My Organization')}
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
          {t('Select plan')} <span className="text-destructive">*</span>
        </Label>
        {!selfService ? (
          <Alert className="mt-2">
            <AlertTriangle className="h-4 w-4" />
            <AlertTitle>{t('Custom plan')}</AlertTitle>
            <AlertDescription className="mt-2">
              {t(
                'You are on a custom plan. To change your plan, contact your customer success manager or',
              )}{' '}
              {orgId ? (
                <Link
                  to="/organizations/$orgId/support"
                  params={{ orgId }}
                  className="underline hover:text-foreground"
                >
                  {t('contact support')}
                </Link>
              ) : (
                t('contact support')
              )}
              .
            </AlertDescription>
          </Alert>
        ) : (
          <>
            <p className="text-[13px] text-muted-foreground mb-4">
              {t('For more details on our plans, visit our')}{' '}
              <MarketingSiteLink
                className="underline hover:text-foreground"
                href="/pricing"
              >
                {t('pricing page')}
              </MarketingSiteLink>
              .
            </p>
            {plansLoading ? (
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">
                    {t('Loading plans...')}
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
                    {t('No plans available. Please try refreshing the page.')}
                  </p>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* Plan change blockers reported by the server that the console cannot
          resolve through the resource selection below. */}
      {hasPlanChangeBlockers && (
        <WarningAlert title={t('This plan change is blocked')}>
          <ul className="list-disc space-y-1 ps-4">
            {unresolvableAddons.map((addon) => (
              <li key={`addon-${addon}`}>
                {t('The selected plan does not support the')} {addon}{' '}
                {t('addon. Remove it before changing plans.')}
              </li>
            ))}
            {unresolvableResources.map(
              ({ projectId, projectName, resource }) => (
                <li key={`resource-${projectId}-${resource.type}`}>
                  <span className="font-medium">{projectName}</span>:{' '}
                  {resource.resolutionHint}
                </li>
              ),
            )}
            {complianceErrors.map(({ projectId, projectName, error }) => (
              <li key={`error-${projectId}`}>
                <span className="font-medium">{projectName}</span>:{' '}
                {t('could not be checked against the new plan limits.')} {error}
              </li>
            ))}
          </ul>
        </WarningAlert>
      )}

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
          deleteChoiceId={freePlanDeleteChoiceId ?? otherFreeOrg.$id}
          onDeleteChoiceChange={(id) => {
            setFreePlanDeleteChoiceId(id)
            setFreePlanDeleteConfirmed(false)
          }}
          confirmed={freePlanDeleteConfirmed}
          onConfirmedChange={setFreePlanDeleteConfirmed}
        />
      )}

      {/* Downgrade-specific sections */}
      {isDowngrade && selectedPlan && (
        <>
          {needsDowngradeValidation && orgId ? (
            <DowngradeValidation
              key={`${orgId}-${freePlanDeleteChoiceId ?? 'default'}`}
              organizationId={orgId}
              organizationName={organization?.name}
              projects={allProjects}
              projectsTotal={allProjectsTotal}
              targetPlan={targetPlanInfo}
              planChangeLimits={planChangeLimits}
              planChangeLimitsLoading={
                updateEstimation.isLoading || estimationInputsDebouncing
              }
              onRef={handleDowngradeValidationRef}
              onValidityChange={handleDowngradeValidationValid}
              onKeptProjectsChange={setKeptProjects}
              deletedOrganizationImpact={effectiveDeletedOrganizationImpact}
              deletedOrganizationLoading={effectiveDeletedOrganizationLoading}
              expectDeletedOrganizationImpact={!!orgToDelete}
            />
          ) : orgToDelete ? (
            <DowngradeImpactSummary
              key={`deleted-org-impact-${orgToDelete.$id}`}
              resourceImpact={{}}
              deletedOrganizationImpact={effectiveDeletedOrganizationImpact}
              deletedOrganizationLoading={effectiveDeletedOrganizationLoading}
              expectDeletedOrganizationImpact
              keptOrganizationImpactReady={false}
            />
          ) : null}

          {orgId && orgToDelete?.$id !== orgId ? (
            <DowngradeAddonWarning
              organizationId={orgId}
              targetPlan={targetPlanInfo}
            />
          ) : null}

          {orgId && orgToDelete?.$id !== orgId ? (
            <DowngradeUsageWarning
              organizationId={orgId}
              targetPlan={targetBillingPlan}
            />
          ) : null}

          {orgId && orgToDelete?.$id !== orgId ? (
            <DowngradePlanLossWarning
              organizationId={orgId}
              projects={keptProjects ?? allProjects}
              targetPlan={targetBillingPlan}
            />
          ) : null}

          {/* Downgrade Alerts */}
          {selectedPlanIsPro && (
            <Alert>
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>
                {t('Monthly Charges for Extra Organization Members')}
              </AlertTitle>
              <AlertDescription className="mt-2">
                {extraSeatPrice
                  ? `${t('You will be charged')} $${extraSeatPrice} ${t('per month for each organization member beyond the plan limit.')}`
                  : t(
                      'You will be charged for each organization member beyond the plan limit.',
                    )}
              </AlertDescription>
            </Alert>
          )}

          {selectedPlanIsFree && (
            <WarningAlert title={t('Downgrading to Free Plan')}>
              {t('Your plan changes immediately.')}{' '}
              {t(
                'You will lose access to premium features and organization members beyond the free limit will be removed.',
              )}
              <DocsRouteLink className="ms-1 underline" href="/docs/migration">
                {t('Learn more about migration')}
              </DocsRouteLink>
            </WarningAlert>
          )}

          {/* Feedback Form for Free Plan (matches old console: "What wasn't working for you?" required) */}
          {shouldCollectDowngradeFeedback && (
            <div>
              <Label className="mb-2 block">{t('Feedback')}</Label>
              <p className="text-[13px] text-muted-foreground mb-4">
                {t(
                  "What wasn't working for you? Please share anything that influenced your decision to downgrade. This feedback helps us improve the platform.",
                )}
              </p>
              <Label
                htmlFor="downgrade-message"
                className="text-[13px] font-medium"
              >
                {t('Your feedback')} <span className="text-destructive">*</span>
              </Label>
              <Textarea
                id="downgrade-message"
                value={feedbackMessage}
                onChange={(e) => setFeedbackMessage(e.target.value)}
                placeholder={t(
                  'Please share anything that influenced your decision to downgrade...',
                )}
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

      {/* Final downgrade confirmation */}
      <ConfirmPlanChange
        open={confirmPlanChangeOpen}
        onOpenChange={setConfirmPlanChangeOpen}
        planLabel={
          // Deleting the organization being downgraded leaves no plan to move to.
          selectedPlan && orgToDelete?.$id !== orgId
            ? getBillingPlanDisplayLabel(selectedPlan)
            : undefined
        }
        deletions={confirmDeletions}
        deletedOrganizationName={orgToDelete?.name}
        confirming={isSubmitting}
        onConfirm={() => {
          setConfirmPlanChangeOpen(false)
          handleDowngrade(confirmDeletions ?? undefined)
        }}
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
