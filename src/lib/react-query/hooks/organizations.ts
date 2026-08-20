/**
 * React Query hooks for Organizations
 *
 * Handles organizations, plans, and invoices.
 */

import {
  useQuery,
  useMutation,
  useQueryClient,
  queryOptions,
  type QueryClient,
} from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Query, ID, type Models } from '@appwrite.io/console'
import {
  BillingPlanTier,
  type BillingPlanTier as BillingPlanTierType,
} from '@/lib/constants/billing-plan'
import type { Organization } from '@/lib/utils/mock-data'
import { listConsoleProjects } from '@/lib/appwrite/console-projects'
import { sdk } from '@/lib/appwrite/sdk'
import { fetchConsoleAccount } from '@/lib/console-account-get'
import {
  hasProjectSpecificRoles,
  projectIdsFromRoles,
} from '@/lib/console-project-roles'
import {
  DEFAULT_ROLES,
  DEFAULT_SCOPES,
  deriveAccessFromRolesScopes,
  type OrganizationRolesScopes,
  type ConsoleAccess,
  FULL_ACCESS,
} from '@/lib/console-roles'

const ESTIMATION_STALE_TIME = 5 * 60 * 1000
const EMPTY_ESTIMATION_INVITES: string[] = []
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  getPlanNameFromTier,
  type CanonicalPlanId,
} from '@/lib/utils/plan-filter'
import {
  DEFAULT_STALE_TIME,
  LONG_STALE_TIME,
  DEFAULT_PAGE_SIZE,
} from './constants'
import {
  applyScreenshotModeOrganizationName,
  subscribeScreenshotMode,
} from '@/lib/screenshot-mode'

type OrganizationListResponse = Models.TeamList
type OrganizationRecord = Models.Organization
type OrganizationPlan = Models.BillingPlan

/**
 * True when the organization's billing/team status is read-only (writes restricted).
 * Comparison is case-insensitive to tolerate API casing.
 */
export function isOrganizationBillingReadonlyStatus(
  status: string | null | undefined,
): boolean {
  return (status ?? '').toLowerCase() === 'readonly'
}

/**
 * True when a resource has hit its budget cap.
 * `billingLimits.budgetLimit` is a percentage of the configured budget (e.g. 139 = 139%).
 */
export function isBudgetLimitReached(
  resource:
    | {
        billingLimits?: {
          budgetLimit?: number | string | null
        } | null
      }
    | null
    | undefined,
): boolean {
  const raw = resource?.billingLimits?.budgetLimit
  if (raw == null || raw === '') return false
  const budgetLimit = typeof raw === 'number' ? raw : Number(raw)
  return Number.isFinite(budgetLimit) && budgetLimit >= 100
}

/**
 * True when plan usage limits are at or above 100% (e.g. free-plan GBHours).
 * See {@link isPlanUsageLimitReached} in `@/lib/billing/billing-limits`.
 */
export { isPlanUsageLimitReached } from '@/lib/billing/billing-limits'

/**
 * @deprecated Prefer {@link isBudgetLimitReached}. Same check for organization documents.
 */
export function isOrganizationBudgetLimitReached(
  organization:
    | {
        billingLimits?: {
          budgetLimit?: number | string | null
        } | null
      }
    | null
    | undefined,
): boolean {
  return isBudgetLimitReached(organization)
}

/**
 * Resolve a project's team/org id via the console projects API.
 * Used when project-scoped `project.get` returns 402 (budget limit) and the
 * full project payload is unavailable.
 */
export async function resolveProjectTeamIdFromConsole(
  projectId: string,
): Promise<string | null> {
  if (!projectId) return null
  try {
    const orgs = await fetchOrganizations()
    const { listConsoleProjects } = await import(
      '@/lib/appwrite/console-projects'
    )
    const { Query } = await import('@appwrite.io/console')
    for (const org of orgs.teams ?? []) {
      try {
        const list = await listConsoleProjects({
          organizationId: org.$id,
          queries: [
            Query.equal('teamId', org.$id),
            Query.equal('$id', projectId),
            Query.limit(1),
          ],
          total: false,
        })
        const teamId = list.projects?.[0]?.teamId
        if (teamId) return teamId
      } catch {
        // Try the next organization.
      }
    }
    return null
  } catch {
    return null
  }
}

function isBillingEnabled(): boolean {
  return getActiveProfileFeatures().billing
}

function isMultiTenancyEnabled(): boolean {
  return getActiveProfileFeatures().multiTenancy
}

function createSelfHostedOrganizationPlan(): OrganizationPlan {
  return {
    $id: 'self-hosted',
    name: 'Self-hosted',
    desc: 'Self-hosted Appwrite installation',
    order: 0,
    price: 0,
    trial: 0,
    bandwidth: Number.MAX_SAFE_INTEGER,
    storage: Number.MAX_SAFE_INTEGER,
    imageTransformations: Number.MAX_SAFE_INTEGER,
    screenshotsGenerated: Number.MAX_SAFE_INTEGER,
    members: Number.MAX_SAFE_INTEGER,
    webhooks: Number.MAX_SAFE_INTEGER,
    wafRules: Number.MAX_SAFE_INTEGER,
    projects: Number.MAX_SAFE_INTEGER,
    platforms: Number.MAX_SAFE_INTEGER,
    users: Number.MAX_SAFE_INTEGER,
    teams: Number.MAX_SAFE_INTEGER,
    databases: Number.MAX_SAFE_INTEGER,
    databasesReads: Number.MAX_SAFE_INTEGER,
    databasesWrites: Number.MAX_SAFE_INTEGER,
    databasesBatchSize: Number.MAX_SAFE_INTEGER,
    buckets: Number.MAX_SAFE_INTEGER,
    fileSize: Number.MAX_SAFE_INTEGER,
    functions: Number.MAX_SAFE_INTEGER,
    sites: Number.MAX_SAFE_INTEGER,
    executions: Number.MAX_SAFE_INTEGER,
    executionsRetentionCount: Number.MAX_SAFE_INTEGER,
    GBHours: Number.MAX_SAFE_INTEGER,
    realtime: Number.MAX_SAFE_INTEGER,
    realtimeMessages: Number.MAX_SAFE_INTEGER,
    messages: Number.MAX_SAFE_INTEGER,
    topics: Number.MAX_SAFE_INTEGER,
    authPhone: Number.MAX_SAFE_INTEGER,
    domains: 0,
    activityLogs: Number.MAX_SAFE_INTEGER,
    usageLogs: Number.MAX_SAFE_INTEGER,
    usageLogsIntervals: ['15m', '1h', '1d'],
    projectInactivityDays: 0,
    alertLimit: 0,
    usage: {} as Models.UsageBillingPlan,
    addons: {
      seats: {
        supported: true,
        planIncluded: Number.MAX_SAFE_INTEGER,
        limit: Number.MAX_SAFE_INTEGER,
        type: 'self-hosted',
        currency: 'USD',
        price: 0,
        value: 0,
        invoiceDesc: '',
      },
      projects: {
        supported: true,
        planIncluded: Number.MAX_SAFE_INTEGER,
        limit: Number.MAX_SAFE_INTEGER,
        type: 'self-hosted',
        currency: 'USD',
        price: 0,
        value: 0,
        invoiceDesc: '',
      },
    },
    budgetCapEnabled: false,
    customSmtp: true,
    emailBranding: true,
    requiresPaymentMethod: false,
    requiresBillingAddress: false,
    isAvailable: true,
    selfService: false,
    premiumSupport: false,
    budgeting: false,
    supportsMockNumbers: true,
    supportsOrganizationRoles: false,
    supportsProjectSpecificRoles: false,
    supportsCredits: false,
    supportsDedicatedDatabases: true,
    databaseComputeCredit: 0,
    supportsDisposableEmailValidation: false,
    supportsCanonicalEmailValidation: false,
    supportsFreeEmailValidation: false,
    supportsCorporateEmailValidation: false,
    backupsEnabled: false,
    usagePerProject: false,
    supportedAddons: { baa: false, premiumGeoDB: false, premiumGeoDBOrg: false },
    backupPolicies: 0,
    deploymentSize: Number.MAX_SAFE_INTEGER,
    buildSize: Number.MAX_SAFE_INTEGER,
    databasesAllowEncrypt: true,
    group: 'starter' as Models.BillingPlan['group'],
  }
}

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch all organizations (teams) from the console SDK
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @returns Organizations list response from the API
 */
export async function fetchOrganizations() {
  if (isBillingEnabled()) {
    const response = await sdk.forConsole.organizations.list({
      queries: [Query.equal('platform', 'appwrite')],
    })
    return response as OrganizationListResponse
  }

  const response = await sdk.forConsole.teams.list({
    total: true,
  })
  return response as OrganizationListResponse
}

/**
 * Query function to fetch a single organization by ID
 *
 * This fetches the full organization details including plan information.
 *
 * @param orgId - The organization ID to fetch
 * @returns Organization details from the API
 */
export async function fetchOrganizationById(orgId: string) {
  if (!orgId) {
    throw new Error('Organization ID is required')
  }

  if (isBillingEnabled()) {
    const response = await sdk.forConsole.organizations.list({
      queries: [Query.equal('$id', orgId)],
    })
    return (response.teams?.[0] || null) as OrganizationRecord | null
  }

  return (await sdk.forConsole.teams.get({
    teamId: orgId,
  })) as OrganizationRecord
}

/**
 * Query function to fetch organization plan details
 *
 * This fetches the plan information for a specific organization.
 *
 * @param orgId - The organization ID to fetch plan for
 * @returns Organization plan details from the API
 */
export async function fetchOrganizationPlan(orgId: string) {
  if (!orgId) {
    throw new Error('Organization ID is required')
  }

  if (!isBillingEnabled()) {
    return createSelfHostedOrganizationPlan()
  }

  try {
    const response = await sdk.forConsole.organizations.getPlan(orgId)
    return response as OrganizationPlan
  } catch {
    return createSelfHostedOrganizationPlan()
  }
}

/**
 * Query function to fetch current user's roles and scopes for an organization.
 * Use in organization context (orgId) or project context (project's teamId).
 * When API is unavailable or fails, returns defaultRoles and defaultScopes (full access).
 *
 * `projectId` resolves project-specific roles (`project-{id}-{role}`) for that
 * one project. Omitting it is what the org-wide view wants, but the backend then
 * downgrades every project-specific role to `analyst` — so any caller reasoning
 * about access *within* a project must pass the id. See `console-project-roles`.
 */
export async function fetchOrganizationScopes(
  organizationId: string,
  projectId?: string | null,
): Promise<OrganizationRolesScopes> {
  if (!organizationId) {
    return { roles: [...DEFAULT_ROLES], scopes: [...DEFAULT_SCOPES] }
  }
  try {
    const orgService = sdk.forConsole.organizations as unknown as {
      getScopes?(params: {
        organizationId: string
        projectId?: string
      }): Promise<{ roles?: string[]; scopes?: string[] }>
    }
    if (typeof orgService.getScopes !== 'function') {
      return { roles: [...DEFAULT_ROLES], scopes: [...DEFAULT_SCOPES] }
    }
    const response = await orgService.getScopes({
      organizationId,
      ...(projectId ? { projectId } : {}),
    })
    return {
      roles: response.roles ?? [...DEFAULT_ROLES],
      scopes: response.scopes ?? [...DEFAULT_SCOPES],
    }
  } catch {
    return { roles: [...DEFAULT_ROLES], scopes: [...DEFAULT_SCOPES] }
  }
}

/**
 * Query function to fetch invoices for an organization
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param organizationId - The organization ID to fetch invoices for
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param queries - Optional additional query strings for filtering/sorting
 * @returns Paginated invoice list response from the API
 */
export async function fetchOrganizationInvoices(
  organizationId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  if (!organizationId) {
    return { invoices: [], total: 0 }
  }

  // Default to sorting by creation date descending (newest first)
  const defaultQueries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]
  const finalQueries = queries
    ? [...defaultQueries, ...queries]
    : defaultQueries

  const response = await sdk.forConsole.organizations.listInvoices({
    organizationId,
    queries: finalQueries,
  })

  return {
    invoices: response.invoices || [],
    total: response.total || 0,
  }
}

/**
 * Whether the organization has at least one failed **subscription** invoice.
 * Uses a single filtered list request (limit 1) for efficiency.
 */
export async function fetchOrganizationHasFailedInvoice(
  organizationId: string,
): Promise<{ hasFailedInvoice: boolean }> {
  if (!organizationId) {
    return { hasFailedInvoice: false }
  }

  try {
    const response = await sdk.forConsole.organizations.listInvoices({
      organizationId,
      queries: [
        Query.equal('status', 'failed'),
        Query.equal('type', 'subscription'),
        Query.orderDesc('$createdAt'),
        Query.limit(1),
        Query.offset(0),
      ],
    })
    const total = response.total ?? 0
    const count = response.invoices?.length ?? 0
    return { hasFailedInvoice: total > 0 || count > 0 }
  } catch {
    return { hasFailedInvoice: false }
  }
}

/**
 * Query options for failed subscription-invoice presence (org-wide banner).
 */
export function organizationFailedInvoicePresenceQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'invoices',
      'organization',
      organizationId,
      'presence',
      'failed',
      'subscription',
    ],
    queryFn: () => fetchOrganizationHasFailedInvoice(organizationId!),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
    // Banner-only data fetched in the background during initial load; must not
    // hold the fullscreen loader open (see use-initial-loader).
    meta: {
      skipInitialLoader: true,
    },
  })
}

/**
 * Failed-invoice presence for an organization (use team / org id from project or route).
 * Shares cache with {@link organizationFailedInvoicePresenceQueryOptions}; does not fetch the org document.
 */
export function useOrganizationFailedInvoicePresence(
  organizationId: string | null | undefined,
) {
  // Billing is org-level, so resolve org-wide even when rendered inside a project.
  const { access } = useOrganizationScopes(organizationId, undefined, {
    projectId: null,
  })
  const canFetchInvoices = canSeeOrganizationBilling(access)
  return useQuery({
    ...organizationFailedInvoicePresenceQueryOptions(organizationId),
    enabled: !!organizationId && canFetchInvoices,
  })
}

/**
 * Query function to fetch billing aggregation for an organization
 *
 * @param organizationId - The organization ID to fetch aggregation for
 * @param aggregationId - The billing aggregation ID from the organization
 * @param limit - Limit for project pagination (default: 10)
 * @param offset - Offset for project pagination (default: 0)
 * @returns Billing aggregation data or null if not found (404 handled gracefully)
 */
export async function fetchOrganizationBillingAggregation(
  organizationId: string,
  aggregationId?: string | null,
  limit: number = DEFAULT_PAGE_SIZE,
  offset: number = 0,
) {
  if (!organizationId || !aggregationId) {
    return null
  }

  try {
    const response = await sdk.forConsole.organizations.getAggregation({
      organizationId,
      aggregationId,
      limit,
      offset,
    })
    return response
  } catch (error: unknown) {
    // Handle 404 gracefully - new organizations might not have aggregation yet
    if (error?.code === 404 || error?.response?.code === 404) {
      return null
    }
    throw error
  }
}

/**
 * Query function to fetch credits for an organization
 *
 * @param organizationId - The organization ID to fetch credits for
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Paginated credits list response from the API
 */
export async function fetchOrganizationCredits(
  organizationId: string,
  page: number = 0,
  limit: number = 5,
) {
  if (!organizationId) {
    return { credits: [], total: 0 }
  }

  // Fetch all credits without expiration-based sorting
  // Expiration logic is handled in the UI
  const queries = [
    Query.orderDesc('$createdAt'), // Sort by creation date descending (newest first)
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await sdk.forConsole.organizations.listCredits({
    organizationId,
    queries,
  })

  return {
    credits: response.credits || [],
    total: response.total || 0,
  }
}

/**
 * Mutation function to add credit to an organization using a coupon
 *
 * @param params - Organization ID and coupon ID (or coupon code)
 * @returns Created credit
 */
export async function addOrganizationCredit(params: {
  organizationId: string
  couponId: string
}) {
  return await sdk.forConsole.organizations.addCredit({
    organizationId: params.organizationId,
    couponId: params.couponId,
  })
}

/**
 * Query function to fetch payment methods for the current account
 *
 * @returns Payment methods list response from the API
 */
export async function fetchPaymentMethods() {
  const response = await sdk.forConsole.account.listPaymentMethods()
  return {
    paymentMethods: response.paymentMethods || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a specific payment method
 *
 * @param paymentMethodId - The payment method ID to fetch
 * @returns Payment method details from the API
 */
export async function fetchPaymentMethod(paymentMethodId: string) {
  if (!paymentMethodId) {
    return null
  }
  const response = await sdk.forConsole.account.getPaymentMethod({
    paymentMethodId,
  })
  return response
}

/**
 * Fetch a payment method linked to an organization (org-scoped).
 * Use this for org billing UI so all team members see the org's card, not only
 * the member who added it (account.getPaymentMethod is user-scoped).
 */
export async function fetchOrganizationPaymentMethod(
  organizationId: string,
  paymentMethodId: string,
) {
  if (!organizationId || !paymentMethodId) {
    return null
  }
  return await sdk.forConsole.organizations.getPaymentMethod({
    organizationId,
    paymentMethodId,
  })
}

/**
 * Query function to fetch billing addresses for the current account
 *
 * @returns Billing addresses list response from the API
 */
export async function fetchBillingAddresses() {
  const response = await sdk.forConsole.account.listBillingAddresses()
  return {
    addresses: response.billingAddresses || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a specific billing address
 *
 * @param billingAddressId - The billing address ID to fetch
 * @returns Billing address details from the API
 */
export async function fetchBillingAddress(billingAddressId: string) {
  if (!billingAddressId) {
    return null
  }
  const response = await sdk.forConsole.account.getBillingAddress({
    billingAddressId,
  })
  return response
}

/**
 * Query function to fetch all available billing plans
 *
 * @returns Plans list response from the API, transformed to object format
 */
export async function fetchBillingPlans() {
  try {
    // Use console service to fetch plans
    // Console service exposes getPlans() (not plans()) - SDK types may not include it in all builds
    const response = await (
      sdk.forConsole.console as unknown as {
        getPlans(): Promise<{ plans: unknown[]; total: number }>
      }
    ).getPlans()

    // Transform array response to object format keyed by plan $id
    // Response format: { total: number, plans: BillingPlan[] }
    // We need: { plans: { [planId]: planData } }
    const plansObject: Record<string, Models.BillingPlan> = {}

    if (response.plans && Array.isArray(response.plans)) {
      response.plans.forEach((plan: unknown) => {
        const p = plan as Models.BillingPlan
        if (p.$id) {
          plansObject[p.$id] = p
        }
      })
    }

    return {
      plans: plansObject,
      total: response.total || 0,
    }
  } catch {
    return { plans: {} as Record<string, Models.BillingPlan>, total: 0 }
  }
}

/**
 * Query function to get coupon account information
 *
 * @param couponCode - The coupon code to validate
 * @returns Coupon details from the API
 */
export async function fetchCouponAccount(couponCode: string) {
  const trimmed = couponCode?.trim()
  if (!trimmed) {
    return null
  }

  return await sdk.forConsole.account.getCoupon({ couponId: trimmed })
}

/**
 * Query function to fetch organization usage for the current (or given) billing cycle.
 *
 * Free / non-usagePerProject plans do not get `aggregation.breakdown`; use this
 * for per-project usage instead (`organizations.getUsage`).
 */
export async function fetchOrganizationUsage(
  organizationId: string,
  startDate?: string | null,
  endDate?: string | null,
) {
  if (!organizationId) {
    return null
  }
  try {
    return await sdk.forConsole.organizations.getUsage({
      organizationId,
      ...(startDate ? { startDate } : {}),
      ...(endDate ? { endDate } : {}),
    })
  } catch {
    return null
  }
}

/**
 * Query function to fetch all projects for an organization
 *
 * @param organizationId - The organization ID
 * @returns Projects list
 */
export async function fetchOrganizationProjects(
  organizationId: string,
  page: number = 0,
  limit: number = 1000,
) {
  if (!organizationId) {
    return { projects: [] }
  }
  try {
    const response = await listConsoleProjects({
      queries: [
        Query.equal('teamId', organizationId),
        Query.limit(limit),
        Query.offset(page * limit),
      ],
    })
    return {
      projects: response.projects || [],
      total: response.total || 0,
    }
  } catch {
    return { projects: [], total: 0 }
  }
}

/**
 * Query function to get cost estimation for creating a new organization
 *
 * @param billingPlan - The billing plan
 * @param couponId - Optional coupon ID
 * @param collaborators - Array of collaborator emails
 * @returns Estimation data
 */
export async function fetchEstimationCreateOrganization(
  billingPlan: BillingPlanTierType,
  couponId: string | null | undefined,
  collaborators: string[],
  paymentMethodId?: string,
) {
  const couponParam =
    typeof couponId === 'string' && couponId.trim().length > 0
      ? couponId.trim()
      : undefined

  return await sdk.forConsole.organizations.estimationCreateOrganization({
    billingPlan,
    paymentMethodId,
    invites: collaborators,
    couponId: couponParam,
  })
}

/**
 * Query function to get cost estimation for updating a plan
 *
 * @param organizationId - The organization ID
 * @param billingPlan - The billing plan
 * @param couponId - Optional coupon ID
 * @param collaborators - Array of collaborator emails
 * @returns Estimation data
 */
export async function fetchEstimationUpdatePlan(
  organizationId: string,
  billingPlan: BillingPlanTierType,
  couponId: string | null | undefined,
  collaborators: string[],
) {
  if (!organizationId) {
    return null
  }

  const couponParam =
    typeof couponId === 'string' && couponId.trim().length > 0
      ? couponId.trim()
      : undefined

  return await sdk.forConsole.organizations.estimationUpdatePlan({
    organizationId,
    billingPlan,
    invites: collaborators,
    couponId: couponParam,
  })
}

// ============================================================================
// MUTATION FUNCTIONS
// ============================================================================

/**
 * Mutation function to create an organization
 *
 * @param orgData - Organization data (organizationId, name)
 * @returns Created organization
 */
export async function createOrganization(orgData: {
  organizationId?: string
  name: string
  billingPlan?: BillingPlanTierType
  paymentMethodId?: string
  billingAddressId?: string
  couponId?: string
  invites?: string[]
  budget?: number
  taxId?: string | null
}) {
  if (!isMultiTenancyEnabled()) {
    // Single-tenant profiles allow exactly one organization: the personal-org
    // bootstrap may create the first, but creating another is blocked. This
    // check-then-create is not atomic (the API has no single-org constraint),
    // which is acceptable: the bootstrap is the only first-org path and runs
    // sequentially, and single-tenant profiles expose no create-org UI.
    const existing = await fetchOrganizations()
    if ((existing.total ?? existing.teams?.length ?? 0) > 0) {
      throw new Error('This console profile supports only one organization')
    }
  }

  if (!orgData.name.trim()) {
    throw new Error('Organization name is required')
  }

  const organizationId = orgData.organizationId || ID.unique()

  if (isBillingEnabled()) {
    return await sdk.forConsole.organizations.create({
      organizationId,
      name: orgData.name.trim(),
      billingPlan: orgData.billingPlan ?? BillingPlanTier.Tier0,
      paymentMethodId: orgData.paymentMethodId,
      billingAddressId: orgData.billingAddressId,
      couponId: orgData.couponId,
      invites: orgData.invites,
      budget: orgData.budget,
      taxId: orgData.taxId || undefined,
    })
  }

  return await sdk.forConsole.teams.create({
    teamId: organizationId,
    name: orgData.name.trim(),
  })
}

/**
 * Mutation function to delete an organization.
 *
 * In self-hosted mode this deletes the backing team.
 */
export async function deleteOrganization(organizationId: string) {
  if (!isMultiTenancyEnabled()) {
    throw new Error('This console profile requires one organization')
  }

  if (!organizationId) {
    throw new Error('Organization ID is required')
  }

  if (isBillingEnabled()) {
    return await sdk.forConsole.organizations.delete({
      organizationId,
    })
  }

  return await sdk.forConsole.teams.delete({
    teamId: organizationId,
  })
}

/**
 * Mutation function to update organization budget
 *
 * @param params - Budget update parameters
 * @returns Updated organization
 */
export async function updateOrganizationBudget(params: {
  organizationId: string
  budget?: number
  alerts?: number[]
}) {
  return await sdk.forConsole.organizations.updateBudget(params)
}

/**
 * Mutation function to update organization billing tax ID
 *
 * @param params - Tax ID update parameters
 * @returns Updated organization
 */
export async function updateOrganizationTaxId(params: {
  organizationId: string
  billingTaxId?: string
}) {
  // Use setBillingTaxId to update tax ID (dedicated method, doesn't require billingPlan)
  // If billingTaxId is undefined or empty, pass empty string to remove it
  const taxId = params.billingTaxId || ''

  return await sdk.forConsole.organizations.setBillingTaxId({
    organizationId: params.organizationId,
    taxId,
  })
}

/**
 * Mutation function to update organization payment method
 *
 * @param params - Payment method update parameters
 * @returns Updated organization
 */
export async function updateOrganizationPaymentMethod(params: {
  organizationId: string
  /** Set to a payment method id, or `null` to remove the org's primary method */
  paymentMethodId?: string | null
  /** Set to a payment method id, or `null` to remove the org's backup method */
  backupPaymentMethodId?: string | null
}) {
  const updatePrimary = params.paymentMethodId !== undefined
  const updateBackup = params.backupPaymentMethodId !== undefined

  if (!updatePrimary && !updateBackup) {
    throw new Error(
      'Either paymentMethodId or backupPaymentMethodId must be provided',
    )
  }

  let result: Awaited<
    ReturnType<typeof sdk.forConsole.organizations.setDefaultPaymentMethod>
  > | void

  // Apply primary first so promoting a backup card does not clear it before reassignment
  if (updatePrimary) {
    if (params.paymentMethodId) {
      result = await sdk.forConsole.organizations.setDefaultPaymentMethod({
        organizationId: params.organizationId,
        paymentMethodId: params.paymentMethodId,
      })
    } else {
      result = await sdk.forConsole.organizations.deleteDefaultPaymentMethod({
        organizationId: params.organizationId,
      })
    }
  }

  if (updateBackup) {
    if (params.backupPaymentMethodId) {
      result = await sdk.forConsole.organizations.setBackupPaymentMethod({
        organizationId: params.organizationId,
        paymentMethodId: params.backupPaymentMethodId,
      })
    } else {
      result = await sdk.forConsole.organizations.deleteBackupPaymentMethod({
        organizationId: params.organizationId,
      })
    }
  }

  return result
}

/**
 * Mutation function to set organization billing address (link an existing address to the org)
 *
 * @param params - Organization ID and billing address ID
 * @returns Updated organization
 */
export async function setOrganizationBillingAddress(params: {
  organizationId: string
  billingAddressId: string
}) {
  return await sdk.forConsole.organizations.setBillingAddress({
    organizationId: params.organizationId,
    billingAddressId: params.billingAddressId,
  })
}

/**
 * Mutation function to remove billing address from organization (unlink; does not delete the address from account)
 *
 * @param params - Organization ID
 * @returns Empty object
 */
export async function deleteOrganizationBillingAddress(params: {
  organizationId: string
}) {
  return await sdk.forConsole.organizations.deleteBillingAddress({
    organizationId: params.organizationId,
  })
}

/**
 * Mutation function to update organization billing address
 *
 * @param params - Billing address update parameters
 * @returns Updated organization
 * @deprecated Use setOrganizationBillingAddress or deleteOrganizationBillingAddress instead
 */
export async function updateOrganizationBillingAddress(params: {
  organizationId: string
  billingAddressId?: string
}) {
  if (params.billingAddressId) {
    return setOrganizationBillingAddress({
      organizationId: params.organizationId,
      billingAddressId: params.billingAddressId,
    })
  }
  return deleteOrganizationBillingAddress({
    organizationId: params.organizationId,
  })
}

/**
 * Mutation function to update organization billing plan
 *
 * @param params - Plan update parameters
 * @returns Updated organization or error response
 */
export async function updateOrganizationPlan(params: {
  organizationId: string
  billingPlan: BillingPlanTierType
  paymentMethodId?: string
  billingAddressId?: string
  couponId?: string
  invites?: string[]
  budget?: number
  taxId?: string | null
}) {
  try {
    // Try billing service first (if it exists)
    if ((sdk.forConsole as unknown).billing?.updatePlan) {
      return await (sdk.forConsole as unknown).billing.updatePlan(
        params.organizationId,
        params.billingPlan,
        params.paymentMethodId,
        params.billingAddressId,
        params.couponId,
        params.invites,
        params.budget,
        params.taxId,
      )
    }
    // Fallback to organizations service
    return await sdk.forConsole.organizations.updatePlan({
      organizationId: params.organizationId,
      billingPlan: params.billingPlan,
      paymentMethodId: params.paymentMethodId,
      billingAddressId: params.billingAddressId,
      couponId: params.couponId,
      invites: params.invites,
      budget: params.budget,
      taxId: params.taxId || undefined,
    })
  } catch (error) {
    throw error
  }
}

/**
 * Mutation function to update selected projects for an organization
 *
 * @param organizationId - The organization ID
 * @param projectIds - Array of project IDs to keep
 * @returns Updated organization
 */
export async function updateSelectedProjects(
  organizationId: string,
  projectIds: string[],
) {
  if (!organizationId) {
    throw new Error('Organization ID is required')
  }
  try {
    // Try billing service first (if it exists)
    if ((sdk.forConsole as unknown).billing?.updateSelectedProjects) {
      return await (sdk.forConsole as unknown).billing.updateSelectedProjects(
        organizationId,
        projectIds,
      )
    }
    // Fallback to organizations service
    if ((sdk.forConsole.organizations as unknown).updateSelectedProjects) {
      return await (
        sdk.forConsole.organizations as unknown
      ).updateSelectedProjects(organizationId, projectIds)
    }
    throw new Error('updateSelectedProjects method not available')
  } catch (error) {
    throw error
  }
}

/**
 * Mutation function to validate organization after payment
 *
 * @param organizationId - The organization ID
 * @param invites - Array of invite emails
 * @returns Validated organization
 */
export async function validateOrganization(
  organizationId: string,
  invites: string[],
) {
  if (!organizationId) {
    throw new Error('Organization ID is required')
  }
  return sdk.forConsole.organizations.validatePayment(organizationId, invites)
}

/**
 * Mutation function to create downgrade feedback
 *
 * @param params - Downgrade feedback parameters
 * @returns void
 */
export async function createDowngradeFeedback(params: {
  organizationId: string
  reason: string
  message: string
  fromPlanId: string
  toPlanId: string
}) {
  if (!params.organizationId) {
    throw new Error('Organization ID is required')
  }
  try {
    return await sdk.forConsole.organizations.createDowngradeFeedback({
      organizationId: params.organizationId,
      reason: params.reason,
      message: params.message,
      fromPlanId: params.fromPlanId,
      toPlanId: params.toPlanId,
    })
  } catch {
    // Don't throw - feedback is optional
  }
}

/**
 * Mutation function to retry invoice payment
 *
 * @param params - Invoice retry parameters
 * @returns Payment intent response
 */
export async function retryInvoicePayment(params: {
  organizationId: string
  invoiceId: string
  paymentMethodId: string
}) {
  return await sdk.forConsole.organizations.createInvoicePayment({
    organizationId: params.organizationId,
    invoiceId: params.invoiceId,
    paymentMethodId: params.paymentMethodId,
  })
}

/**
 * Resolves which payment method to use when retrying an invoice (primary, backup if primary failed, or first on the account).
 */
export async function resolvePaymentMethodIdForInvoiceRetry(params: {
  organization: {
    paymentMethodId?: string | null
    backupPaymentMethodId?: string | null
  }
  primaryPaymentMethodFailed: boolean
}): Promise<string | null> {
  let paymentMethodId = params.organization.paymentMethodId
  if (!paymentMethodId || params.primaryPaymentMethodFailed) {
    paymentMethodId = params.organization.backupPaymentMethodId
  }
  if (!paymentMethodId) {
    const paymentMethods = await sdk.forConsole.account.listPaymentMethods()
    if (
      paymentMethods.paymentMethods &&
      paymentMethods.paymentMethods.length > 0
    ) {
      paymentMethodId = paymentMethods.paymentMethods[0].$id
    }
  }
  return paymentMethodId ?? null
}

/**
 * Mutation function to create a payment method
 *
 * Creates an empty payment method record and returns it with a clientSecret for Stripe
 *
 * @returns Payment method with clientSecret
 */
export async function createPaymentMethod() {
  return await sdk.forConsole.account.createPaymentMethod()
}

/**
 * Mutation function to set payment method provider (link Stripe payment method)
 *
 * Links a Stripe payment method to an Appwrite payment method record
 *
 * @param params - Payment method provider parameters
 * @returns Updated payment method
 */
export async function setPaymentMethodProvider(params: {
  paymentMethodId: string
  providerMethodId: string
  name: string
  state?: string
}) {
  return await sdk.forConsole.account.updatePaymentMethodProvider({
    paymentMethodId: params.paymentMethodId,
    providerMethodId: params.providerMethodId,
    name: params.name,
    state: params.state,
  })
}

/**
 * Mutation function to set organization default payment method
 *
 * @param params - Payment method assignment parameters
 * @returns Updated organization
 */
export async function setOrganizationDefaultPaymentMethod(params: {
  organizationId: string
  paymentMethodId: string
}) {
  return await sdk.forConsole.organizations.setDefaultPaymentMethod({
    organizationId: params.organizationId,
    paymentMethodId: params.paymentMethodId,
  })
}

/**
 * Mutation function to set organization backup payment method
 *
 * @param params - Payment method assignment parameters
 * @returns Updated organization
 */
export async function setOrganizationBackupPaymentMethod(params: {
  organizationId: string
  paymentMethodId: string
}) {
  return await sdk.forConsole.organizations.setBackupPaymentMethod({
    organizationId: params.organizationId,
    paymentMethodId: params.paymentMethodId,
  })
}

/**
 * Mutation function to update payment method expiration
 *
 * @param params - Payment method update parameters
 * @returns Updated payment method
 */
export async function updatePaymentMethod(params: {
  paymentMethodId: string
  expiryMonth: number
  expiryYear: number
  state: string
}) {
  return await sdk.forConsole.account.updatePaymentMethod({
    paymentMethodId: params.paymentMethodId,
    expiryMonth: params.expiryMonth,
    expiryYear: params.expiryYear,
    state: params.state,
  })
}

/**
 * Mutation function to delete a payment method
 *
 * @param params - Payment method deletion parameters
 * @returns Empty object
 */
export async function deletePaymentMethod(params: { paymentMethodId: string }) {
  return await sdk.forConsole.account.deletePaymentMethod({
    paymentMethodId: params.paymentMethodId,
  })
}

/**
 * Mutation function to create a billing address
 *
 * @param params - Billing address creation parameters
 * @returns Created billing address
 */
export async function createBillingAddress(params: {
  country: string
  streetAddress: string
  city: string
  state: string
  postalCode?: string
  addressLine2?: string
}) {
  return await sdk.forConsole.account.createBillingAddress({
    country: params.country,
    streetAddress: params.streetAddress,
    city: params.city,
    state: params.state,
    postalCode: params.postalCode,
    addressLine2: params.addressLine2,
  })
}

/**
 * Mutation function to update a billing address
 *
 * @param params - Billing address update parameters
 * @returns Updated billing address
 */
export async function updateBillingAddress(params: {
  billingAddressId: string
  country: string
  streetAddress: string
  city: string
  state: string
  postalCode?: string
  addressLine2?: string
}) {
  return await sdk.forConsole.account.updateBillingAddress({
    billingAddressId: params.billingAddressId,
    country: params.country,
    streetAddress: params.streetAddress,
    city: params.city,
    state: params.state,
    postalCode: params.postalCode,
    addressLine2: params.addressLine2,
  })
}

/**
 * Mutation function to delete a billing address
 *
 * @param params - Billing address deletion parameters
 * @returns Empty object
 */
export async function deleteBillingAddress(params: {
  billingAddressId: string
}) {
  return await sdk.forConsole.account.deleteBillingAddress({
    billingAddressId: params.billingAddressId,
  })
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching all organizations (teams) from the console SDK
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function organizationsQueryOptions() {
  return queryOptions({
    queryKey: ['organizations', 'console'],
    queryFn: fetchOrganizations,
    staleTime: LONG_STALE_TIME,
    // Default QueryClient gcTime is 0. Without this, loader prefetch is
    // discarded before OrgOverview mounts and the list is fetched twice.
    gcTime: LONG_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
  })
}

/**
 * Organizations with billing fields (paymentMethodId, billingAddressId, etc.).
 * Used on account payments tab to show linked orgs per card/address.
 */
export async function fetchOrganizationsWithBillingFields() {
  const response = await sdk.forConsole.organizations.list({
    queries: [Query.equal('platform', 'appwrite')],
  })
  return response.teams || []
}

export function organizationsFullQueryOptions() {
  return queryOptions({
    queryKey: ['organizations', 'console', 'full'],
    queryFn: fetchOrganizationsWithBillingFields,
    staleTime: 30 * 1000,
    gcTime: LONG_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

/**
 * Query options for fetching a single organization by ID
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function organizationQueryOptions(orgId: string | null | undefined) {
  return queryOptions({
    queryKey: ['organization', orgId],
    queryFn: () => fetchOrganizationById(orgId!),
    enabled: !!orgId,
    staleTime: LONG_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: !!orgId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching organization plan details
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function organizationPlanQueryOptions(orgId: string | null | undefined) {
  return queryOptions({
    queryKey: ['organization', 'plan', orgId],
    queryFn: () => fetchOrganizationPlan(orgId!),
    enabled: !!orgId,
    staleTime: LONG_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: !!orgId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching current user's roles and scopes for an organization.
 * Only enabled when profile supports org roles (orgRoles) and organizationId is set.
 * Used in both organization context (orgId) and project context (project's teamId).
 */
export function organizationScopesQueryOptions(
  organizationId: string | null | undefined,
  projectId?: string | null,
) {
  const features = getActiveProfileFeatures()
  const enabled = !!organizationId && !!features.orgRoles
  return queryOptions({
    // projectId is part of the key: org-wide and project-scoped resolutions of
    // the same membership are genuinely different answers and must not share a
    // cache entry.
    queryKey: ['organization', 'scopes', organizationId, projectId ?? null],
    queryFn: () => fetchOrganizationScopes(organizationId!, projectId),
    enabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Project ids the current user may reach in this organization, or `null` when
 * they are an org-wide member and every project is visible.
 *
 * This cannot come from `getScopes`: called without a project id it reports a
 * project-scoped member as a bare `analyst` and discards which projects the
 * roles referred to. The raw membership is the only place those ids survive, so
 * it is read directly — the same approach the previous console took.
 *
 * An empty array is meaningful (access to no project) and must not be confused
 * with `null`.
 */
export async function fetchOrganizationProjectScope(
  organizationId: string,
): Promise<string[] | null> {
  if (!organizationId || !getActiveProfileFeatures().orgRoles) return null
  try {
    const account = await fetchConsoleAccount()
    if (!account?.$id) return null
    const response = await sdk.forConsole.teams.listMemberships({
      teamId: organizationId,
      queries: [Query.equal('userId', account.$id)],
    })
    // Matched on userId rather than taking the first row: if the query filter
    // is ever ignored the first membership is some other member, and their
    // project scope would then be applied to everyone.
    const memberships = (response?.memberships ?? []) as Array<{
      userId?: string
      roles?: string[]
    }>
    const mine = memberships.find((m) => m.userId === account.$id)
    if (!mine) return null
    const roles = mine.roles ?? []
    if (!hasProjectSpecificRoles(roles)) return null
    return projectIdsFromRoles(roles)
  } catch {
    // Never fail closed on a lookup error: fall back to the unrestricted list
    // and let the API reject anything this member cannot open.
    return null
  }
}

export function organizationProjectScopeQueryOptions(
  organizationId: string | null | undefined,
) {
  const features = getActiveProfileFeatures()
  const enabled = !!organizationId && !!features.orgRoles
  return queryOptions({
    queryKey: ['organization', 'project-scope', organizationId],
    queryFn: () => fetchOrganizationProjectScope(organizationId!),
    enabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

/** Invoice list APIs require `billing.read` when org roles are enabled. */
export function canSeeOrganizationBilling(access: ConsoleAccess): boolean {
  const features = getActiveProfileFeatures()
  return !!(features.billing && (!features.orgRoles || access.canSeeBilling))
}

export async function resolveOrganizationAccess(
  queryClient: QueryClient,
  organizationId: string,
): Promise<ConsoleAccess> {
  const features = getActiveProfileFeatures()
  if (!features.orgRoles) return FULL_ACCESS
  const data = await queryClient.ensureQueryData(
    organizationScopesQueryOptions(organizationId),
  )
  return deriveAccessFromRolesScopes(data.roles, data.scopes)
}

export async function prefetchOrganizationInvoiceDataIfAllowed(
  queryClient: QueryClient,
  organizationId: string,
): Promise<void> {
  if (!getActiveProfileFeatures().billing) return
  const access = await resolveOrganizationAccess(queryClient, organizationId)
  if (!canSeeOrganizationBilling(access)) return
  await queryClient
    .ensureQueryData(organizationFailedInvoicePresenceQueryOptions(organizationId))
    .catch(() => {})
}

/**
 * Query options for fetching all available billing plans
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function billingPlansQueryOptions() {
  return queryOptions({
    queryKey: ['billing-plans'],
    queryFn: fetchBillingPlans,
    staleTime: LONG_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    gcTime: Infinity, // Keep in cache forever
  })
}

/**
 * Query options for fetching organization usage
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 * Pass billing cycle dates when available so free-plan project breakdown matches the cycle.
 */
export function organizationUsageQueryOptions(
  organizationId: string | null | undefined,
  startDate?: string | null,
  endDate?: string | null,
) {
  return queryOptions({
    queryKey: [
      'organization-usage',
      organizationId,
      startDate ?? null,
      endDate ?? null,
    ],
    queryFn: () =>
      fetchOrganizationUsage(organizationId!, startDate, endDate),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
    meta: {
      // Slow usage must never keep the fullscreen initial loader up.
      skipInitialLoader: true,
    },
  })
}

/**
 * Query options for fetching all projects for an organization
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function organizationProjectsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['organization-projects', organizationId],
    queryFn: () => fetchOrganizationProjects(organizationId!),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch all organizations (teams) from the console SDK
 *
 * In Appwrite, organizations are represented as teams, so this fetches all teams
 * that the user has access to.
 *
 * @returns Organizations list with loading state
 */
export function useOrganizations() {
  const {
    data: organizationsData,
    isLoading,
    error,
    refetch,
  } = useQuery(organizationsQueryOptions())
  const [screenshotModeEpoch, setScreenshotModeEpoch] = useState(0)

  useEffect(() => {
    return subscribeScreenshotMode(() => {
      setScreenshotModeEpoch((epoch) => epoch + 1)
    })
  }, [])

  // Map the API response to our Organization type
  const organizations = useMemo(() => {
    if (!organizationsData?.teams) return []

    return organizationsData.teams.map((org: unknown) => {
      const o = org as {
        $id: string
        name: string
        total?: number
        billingPlan?: string
        billingPlanDowngrade?: unknown
        status?: string
      }
      const mocked = applyScreenshotModeOrganizationName(o)
      // Map billingPlan to plan name using the filter
      const plan = getPlanNameFromTier(o.billingPlan) as CanonicalPlanId

      return {
        $id: o.$id,
        name: mocked.name,
        slug: mocked.name.toLowerCase().replace(/\s+/g, '-'),
        avatar: undefined, // Organizations from SDK don't have avatar
        plan,
        members: o.total || 0,
        status: o.status,
        billingPlanDowngrade: o.billingPlanDowngrade,
      }
    }) as Organization[]
  }, [organizationsData, screenshotModeEpoch])

  return {
    organizations,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to create an organization
 *
 * @returns Mutation object with mutate function
 */
export function useCreateOrganization() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createOrganization,
    onSuccess: () => {
      // Invalidate organizations query to refetch the list
      queryClient.invalidateQueries({
        queryKey: ['organizations', 'console'],
      })
    },
  })
}

/**
 * Hook to fetch a single organization by ID with full details including plan
 *
 * @param orgId - The organization ID to fetch
 * @returns Organization details with loading state
 */
export function useOrganizationById(orgId: string | null | undefined) {
  const {
    data: orgData,
    isLoading,
    error,
    refetch,
  } = useQuery(organizationQueryOptions(orgId))
  const [screenshotModeEpoch, setScreenshotModeEpoch] = useState(0)

  useEffect(() => {
    return subscribeScreenshotMode(() => {
      setScreenshotModeEpoch((epoch) => epoch + 1)
    })
  }, [])

  // Map the API response to include plan information
  const organization = useMemo(() => {
    if (!orgData) return null

    const planName = getPlanNameFromTier(orgData.billingPlan)
    const plan = planName as CanonicalPlanId
    const mocked = applyScreenshotModeOrganizationName(orgData)

    return {
      ...mocked,
      plan,
      planName,
      billingPlan: orgData.billingPlan,
    }
  }, [orgData, screenshotModeEpoch])

  return {
    organization,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch organization plan details
 *
 * @param orgId - The organization ID to fetch plan for
 * @param initialData - Optional data from route loader to avoid layout shift on first paint
 * @returns Organization plan details with loading state
 */
export function useOrganizationPlan(
  orgId: string | null | undefined,
  initialData?: Awaited<ReturnType<typeof fetchOrganizationPlan>>,
) {
  const {
    data: planData,
    isLoading,
    isFetched,
    error,
    refetch,
  } = useQuery({
    ...organizationPlanQueryOptions(orgId),
    initialData,
    initialDataUpdatedAt: initialData ? 1 : 0,
  })

  return {
    plan: planData,
    isLoading,
    isFetched,
    error,
    refetch,
  }
}

/**
 * Hook to get current user's roles, scopes, and derived access for an organization.
 * Use in organization context (orgId) or project context (project's teamId).
 * When profile does not support roles (orgRoles: false), returns full access without fetching.
 *
 * Inside a project route the current `projectId` is picked up from the router
 * and used to resolve project-specific roles. That default is deliberate: the
 * alternative is passing the id explicitly at ~50 call sites, where a single
 * omission silently downgrades a member to read-only rather than failing loudly.
 * Pass `{ projectId: null }` to force the org-wide answer while inside a project
 * route (billing and org settings want that).
 *
 * @param initialData - Optional data from route loader to avoid layout shift on first paint
 */
export function useOrganizationScopes(
  organizationId: string | null | undefined,
  initialData?: Awaited<ReturnType<typeof fetchOrganizationScopes>>,
  options?: { projectId?: string | null },
): {
  roles: string[]
  scopes: string[]
  access: ConsoleAccess
  isLoading: boolean
  error: Error | null
  refetch: () => void
} {
  const features = getActiveProfileFeatures()
  const shouldFetch = !!organizationId && features.orgRoles

  const routeParams = useParams({ strict: false }) as { projectId?: string }
  const projectId =
    options && 'projectId' in options
      ? options.projectId
      : (routeParams?.projectId ?? null)

  const { data, isLoading, error, refetch } = useQuery({
    ...organizationScopesQueryOptions(organizationId, projectId),
    enabled: shouldFetch,
    initialData,
    initialDataUpdatedAt: initialData ? 1 : 0,
  })

  const roles = data?.roles ?? [...DEFAULT_ROLES]
  const scopes = data?.scopes ?? [...DEFAULT_SCOPES]
  const access = useMemo<ConsoleAccess>(
    () =>
      shouldFetch && data
        ? deriveAccessFromRolesScopes(data.roles, data.scopes)
        : FULL_ACCESS,
    [shouldFetch, data],
  )

  return {
    roles,
    scopes,
    access,
    isLoading: shouldFetch ? isLoading : false,
    error: error as Error | null,
    refetch,
  }
}

/**
 * Query options for fetching invoices for an organization
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function organizationInvoicesQueryOptions(
  organizationId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  return queryOptions({
    queryKey: [
      'invoices',
      'organization',
      organizationId,
      page,
      limit,
      queries ?? null,
    ],
    queryFn: () =>
      fetchOrganizationInvoices(organizationId!, page, limit, queries),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching billing aggregation for an organization
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function organizationBillingAggregationQueryOptions(
  organizationId: string | null | undefined,
  aggregationId?: string | null | undefined,
  limit: number = DEFAULT_PAGE_SIZE,
  offset: number = 0,
) {
  return queryOptions({
    queryKey: [
      'billing-aggregation',
      'organization',
      organizationId,
      aggregationId ?? null, // Normalize undefined to null for consistent query keys
      limit,
      offset,
    ],
    queryFn: () =>
      fetchOrganizationBillingAggregation(
        organizationId!,
        aggregationId,
        limit,
        offset,
      ),
    enabled: !!organizationId && !!aggregationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    // Aggregation updates as usage accrues and when addons are enabled.
    refetchOnMount: true,
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: organizationId && aggregationId ? 5 * 60 * 1000 : 0,
    meta: {
      // Slow usage aggregation must never keep the fullscreen initial loader up.
      skipInitialLoader: true,
    },
  })
}

/**
 * Query options for fetching credits for an organization
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function organizationCreditsQueryOptions(
  organizationId: string | null | undefined,
  page: number = 0,
  limit: number = 5,
) {
  return queryOptions({
    queryKey: ['credits', 'organization', organizationId, page, limit],
    queryFn: () => fetchOrganizationCredits(organizationId!, page, limit),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching payment methods
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function paymentMethodsQueryOptions() {
  return queryOptions({
    queryKey: ['payment-methods', 'account'],
    queryFn: fetchPaymentMethods,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
  })
}

/**
 * Query options for fetching billing addresses
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function billingAddressesQueryOptions() {
  return queryOptions({
    queryKey: ['billing-addresses', 'account'],
    queryFn: fetchBillingAddresses,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
  })
}

/**
 * Query options for fetching a specific payment method
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function paymentMethodQueryOptions(
  paymentMethodId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['payment-method', paymentMethodId],
    queryFn: () => fetchPaymentMethod(paymentMethodId!),
    enabled: !!paymentMethodId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: paymentMethodId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for an organization's primary/backup payment method (org API).
 */
export function organizationPaymentMethodQueryOptions(
  organizationId: string | null | undefined,
  paymentMethodId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'payment-method',
      'organization',
      organizationId,
      paymentMethodId,
    ],
    queryFn: () =>
      fetchOrganizationPaymentMethod(organizationId!, paymentMethodId!),
    enabled: !!organizationId && !!paymentMethodId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId && paymentMethodId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Seeds org-scoped payment method cache from the account list so UI updates
 * immediately after assign/replace (avoids empty state while org query refetches).
 */
export function seedOrganizationPaymentMethodFromAccountCache(
  queryClient: QueryClient,
  organizationId: string,
  paymentMethodId: string | null | undefined,
) {
  if (!paymentMethodId) return

  const accountData = queryClient.getQueryData<{
    paymentMethods: Models.PaymentMethod[]
  }>(paymentMethodsQueryOptions().queryKey)
  const method = accountData?.paymentMethods?.find(
    (pm) => pm.$id === paymentMethodId,
  )
  if (!method) return

  queryClient.setQueryData(
    organizationPaymentMethodQueryOptions(organizationId, paymentMethodId)
      .queryKey,
    method,
  )
}

/**
 * Query options for fetching a specific billing address
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function billingAddressQueryOptions(
  billingAddressId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['billing-address', billingAddressId],
    queryFn: () => fetchBillingAddress(billingAddressId!),
    enabled: !!billingAddressId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: billingAddressId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Hook to fetch invoices for an organization
 *
 * @param organizationId - The organization ID to fetch invoices for
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param queries - Optional additional query strings for filtering/sorting
 * @returns Paginated invoice list with loading state
 */
export function useOrganizationInvoices(
  organizationId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  queries?: string[],
) {
  // Billing is org-level, so resolve org-wide even when rendered inside a project.
  const { access } = useOrganizationScopes(organizationId, undefined, {
    projectId: null,
  })
  const canFetchInvoices = canSeeOrganizationBilling(access)
  const { data, isLoading, isFetching, isPending, error, refetch } = useQuery({
    ...organizationInvoicesQueryOptions(organizationId, page, limit, queries),
    enabled: !!organizationId && canFetchInvoices,
  })

  return {
    invoices: data?.invoices || [],
    total: data?.total || 0,
    data, // Expose data object to check if query has been executed
    isLoading,
    isFetching,
    isPending,
    error,
    refetch,
  }
}

/**
 * Hook to fetch billing aggregation for an organization
 *
 * @param organizationId - The organization ID to fetch aggregation for
 * @param aggregationId - The billing aggregation ID from the organization
 * @param limit - Limit for project pagination (default: 10)
 * @param offset - Offset for project pagination (default: 0)
 * @returns Billing aggregation data with loading state
 */
export function useOrganizationBillingAggregation(
  organizationId: string | null | undefined,
  aggregationId?: string | null | undefined,
  limit: number = DEFAULT_PAGE_SIZE,
  offset: number = 0,
) {
  const { data, isLoading, error, refetch } = useQuery(
    organizationBillingAggregationQueryOptions(
      organizationId,
      aggregationId,
      limit,
      offset,
    ),
  )

  return {
    aggregation: data,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch credits for an organization
 *
 * @param organizationId - The organization ID to fetch credits for
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Paginated credits list with loading state
 */
export function useOrganizationCredits(
  organizationId: string | null | undefined,
  page: number = 0,
  limit: number = 5,
) {
  const { data, isLoading, error, refetch } = useQuery(
    organizationCreditsQueryOptions(organizationId, page, limit),
  )

  return {
    credits: data?.credits || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch payment methods for the current account
 *
 * @returns Payment methods list with loading state
 */
export function usePaymentMethods(options?: { enabled?: boolean }) {
  const enabled = options?.enabled ?? true
  const { data, isLoading, error, refetch } = useQuery({
    ...paymentMethodsQueryOptions(),
    enabled,
  })

  const paymentMethods = useMemo(
    () => data?.paymentMethods ?? [],
    [data?.paymentMethods],
  )

  return {
    paymentMethods,
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a specific payment method
 *
 * @param paymentMethodId - The payment method ID to fetch
 * @returns Payment method details with loading state
 */
export function usePaymentMethod(paymentMethodId: string | null | undefined) {
  const { data, isLoading, error, refetch } = useQuery(
    paymentMethodQueryOptions(paymentMethodId),
  )

  return {
    paymentMethod: data,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a payment method as linked to an organization (all members can load it).
 */
export function useOrganizationPaymentMethod(
  organizationId: string | null | undefined,
  paymentMethodId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery(
    organizationPaymentMethodQueryOptions(organizationId, paymentMethodId),
  )

  return {
    paymentMethod: data,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch billing addresses for the current account
 *
 * @returns Billing addresses list with loading state
 */
export function useBillingAddresses() {
  const { data, isLoading, error, refetch } = useQuery(
    billingAddressesQueryOptions(),
  )

  return {
    addresses: data?.addresses || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a specific billing address
 *
 * @param billingAddressId - The billing address ID to fetch
 * @returns Billing address details with loading state
 */
export function useBillingAddress(billingAddressId: string | null | undefined) {
  const { data, isLoading, error, refetch } = useQuery(
    billingAddressQueryOptions(billingAddressId),
  )

  return {
    address: data,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to update organization budget
 *
 * @returns Mutation object with mutate function
 */
export function useUpdateOrganizationBudget() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: updateOrganizationBudget,
    onSuccess: async (_, variables) => {
      // Refetch so billingLimits (and budget curtain) update immediately
      await queryClient.refetchQueries({
        queryKey: ['organization', variables.organizationId],
      })
      await queryClient.refetchQueries({
        queryKey: [
          'billing-aggregation',
          'organization',
          variables.organizationId,
        ],
      })
      // Projects also carry billingLimits; refresh so project curtains clear
      await queryClient.refetchQueries({
        queryKey: ['project'],
      })
    },
  })
}

/**
 * Hook to update organization tax ID
 *
 * @returns Mutation object with mutate function
 */
export function useUpdateOrganizationTaxId() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: updateOrganizationTaxId,
    onSuccess: (_, variables) => {
      // Invalidate organization query
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
    },
  })
}

/**
 * Hook to update organization payment method
 *
 * @returns Mutation object with mutate function
 */
export function useUpdateOrganizationPaymentMethod() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: updateOrganizationPaymentMethod,
    onMutate: async (variables) => {
      const { organizationId } = variables
      await queryClient.cancelQueries({
        queryKey: ['organization', organizationId],
      })

      const previousOrg = queryClient.getQueryData<OrganizationRecord>([
        'organization',
        organizationId,
      ])

      if (previousOrg) {
        const nextOrg: OrganizationRecord = { ...previousOrg }
        if (variables.paymentMethodId !== undefined) {
          nextOrg.paymentMethodId = variables.paymentMethodId ?? undefined
          seedOrganizationPaymentMethodFromAccountCache(
            queryClient,
            organizationId,
            variables.paymentMethodId,
          )
        }
        if (variables.backupPaymentMethodId !== undefined) {
          nextOrg.backupPaymentMethodId =
            variables.backupPaymentMethodId ?? undefined
          seedOrganizationPaymentMethodFromAccountCache(
            queryClient,
            organizationId,
            variables.backupPaymentMethodId,
          )
        }
        queryClient.setQueryData(['organization', organizationId], nextOrg)
      }

      return { previousOrg }
    },
    onError: (_error, variables, context) => {
      if (context?.previousOrg) {
        queryClient.setQueryData(
          ['organization', variables.organizationId],
          context.previousOrg,
        )
      }
    },
    onSuccess: (data, variables) => {
      const { organizationId } = variables

      if (data && typeof data === 'object' && '$id' in data) {
        queryClient.setQueryData(['organization', organizationId], data)
      }

      if (variables.paymentMethodId) {
        seedOrganizationPaymentMethodFromAccountCache(
          queryClient,
          organizationId,
          variables.paymentMethodId,
        )
      }
      if (variables.backupPaymentMethodId) {
        seedOrganizationPaymentMethodFromAccountCache(
          queryClient,
          organizationId,
          variables.backupPaymentMethodId,
        )
      }
    },
  })
}

/**
 * Hook to set organization billing address (link address to org)
 *
 * @returns Mutation object with mutate function
 */
export function useSetOrganizationBillingAddress() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: setOrganizationBillingAddress,
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: ['billing-addresses', 'account'],
      })
    },
  })
}

/**
 * Hook to remove billing address from organization (unlink only)
 *
 * @returns Mutation object with mutate function
 */
export function useDeleteOrganizationBillingAddress() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: deleteOrganizationBillingAddress,
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: ['billing-addresses', 'account'],
      })
    },
  })
}

/**
 * Hook to update organization billing address (set or remove)
 *
 * @returns Mutation object with mutate function
 * @deprecated Prefer useSetOrganizationBillingAddress and useDeleteOrganizationBillingAddress
 */
export function useUpdateOrganizationBillingAddress() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: updateOrganizationBillingAddress,
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: ['billing-addresses', 'account'],
      })
    },
  })
}

/**
 * Hook to retry invoice payment
 *
 * @returns Mutation object with mutate function
 */
export function useRetryInvoicePayment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: retryInvoicePayment,
    onSuccess: (_, variables) => {
      // Invalidate invoices query
      queryClient.invalidateQueries({
        queryKey: ['invoices', 'organization', variables.organizationId],
      })
      // Invalidate organization query
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
    },
  })
}

/**
 * Hook to add credit to an organization (redeem coupon)
 *
 * @returns Mutation object with mutate function
 */
export function useAddOrganizationCredit() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: addOrganizationCredit,
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: ['credits', 'organization', variables.organizationId],
      })
    },
  })
}

/**
 * Hook to create a payment method
 *
 * @returns Mutation object with mutate function
 */
export function useCreatePaymentMethod() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createPaymentMethod,
    onSuccess: () => {
      // Invalidate payment methods query
      queryClient.invalidateQueries({
        queryKey: ['payment-methods', 'account'],
      })
    },
  })
}

/**
 * Hook to set payment method provider (link Stripe payment method)
 *
 * @returns Mutation object with mutate function
 */
export function useSetPaymentMethodProvider() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: setPaymentMethodProvider,
    onSuccess: () => {
      // Invalidate payment methods query
      queryClient.invalidateQueries({
        queryKey: ['payment-methods', 'account'],
      })
      // Invalidate individual payment method queries
      queryClient.invalidateQueries({
        queryKey: ['payment-method'],
      })
    },
  })
}

/**
 * Hook to set organization default payment method
 *
 * @returns Mutation object with mutate function
 */
export function useSetOrganizationDefaultPaymentMethod() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: setOrganizationDefaultPaymentMethod,
    onSuccess: (_, variables) => {
      // Invalidate organization query
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
      // Invalidate payment methods query
      queryClient.invalidateQueries({
        queryKey: ['payment-methods', 'account'],
      })
      queryClient.invalidateQueries({
        queryKey: ['payment-method', 'organization', variables.organizationId],
      })
    },
  })
}

/**
 * Hook to set organization backup payment method
 *
 * @returns Mutation object with mutate function
 */
export function useSetOrganizationBackupPaymentMethod() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: setOrganizationBackupPaymentMethod,
    onSuccess: (_, variables) => {
      // Invalidate organization query
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
      // Invalidate payment methods query
      queryClient.invalidateQueries({
        queryKey: ['payment-methods', 'account'],
      })
      queryClient.invalidateQueries({
        queryKey: ['payment-method', 'organization', variables.organizationId],
      })
    },
  })
}

/**
 * Hook to update payment method expiration
 *
 * @returns Mutation object with mutate function
 */
export function useUpdatePaymentMethod() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: updatePaymentMethod,
    onSuccess: (_, variables) => {
      // Invalidate payment methods query
      queryClient.invalidateQueries({
        queryKey: ['payment-methods', 'account'],
      })
      // Invalidate individual payment method query
      queryClient.invalidateQueries({
        queryKey: ['payment-method', variables.paymentMethodId],
      })
    },
  })
}

/**
 * Hook to delete a payment method
 *
 * @returns Mutation object with mutate function
 */
export function useDeletePaymentMethod() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: deletePaymentMethod,
    onSuccess: () => {
      // Invalidate payment methods query
      queryClient.invalidateQueries({
        queryKey: ['payment-methods', 'account'],
      })
      // Invalidate all individual payment method queries
      queryClient.invalidateQueries({
        queryKey: ['payment-method'],
      })
    },
  })
}

/**
 * Hook to create a billing address
 *
 * @returns Mutation object with mutate function
 */
export function useCreateBillingAddress() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createBillingAddress,
    onSuccess: () => {
      // Invalidate billing addresses query
      queryClient.invalidateQueries({
        queryKey: ['billing-addresses', 'account'],
      })
    },
  })
}

/**
 * Hook to update a billing address
 *
 * @returns Mutation object with mutate function
 */
export function useUpdateBillingAddress() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: updateBillingAddress,
    onSuccess: (_, variables) => {
      // Invalidate billing addresses query
      queryClient.invalidateQueries({
        queryKey: ['billing-addresses', 'account'],
      })
      // Invalidate individual billing address query
      queryClient.invalidateQueries({
        queryKey: ['billing-address', variables.billingAddressId],
      })
    },
  })
}

/**
 * Hook to delete a billing address
 *
 * @returns Mutation object with mutate function
 */
export function useDeleteBillingAddress() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: deleteBillingAddress,
    onSuccess: () => {
      // Invalidate billing addresses query
      queryClient.invalidateQueries({
        queryKey: ['billing-addresses', 'account'],
      })
      // Invalidate all individual billing address queries
      queryClient.invalidateQueries({
        queryKey: ['billing-address'],
      })
    },
  })
}

/**
 * Hook to fetch all available billing plans
 *
 * @returns Plans list with loading state
 */
export function useBillingPlans() {
  const { data, isLoading, error, refetch } = useQuery(
    billingPlansQueryOptions(),
  )

  return {
    plans: data?.plans || ({} as Record<string, Models.BillingPlan>),
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch coupon account information
 *
 * @param couponCode - The coupon code to validate
 * @returns Coupon details with loading state
 */
export function useCouponAccount(couponCode: string | null | undefined) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['coupon-account', couponCode],
    queryFn: () => fetchCouponAccount(couponCode!),
    enabled: !!couponCode,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: couponCode ? 5 * 60 * 1000 : 0,
  })

  return {
    coupon: data,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch organization usage
 *
 * @param organizationId - The organization ID
 * @param startDate - Optional billing cycle start (ISO)
 * @param endDate - Optional billing cycle end (ISO)
 * @returns Organization usage data with loading state
 */
export function useOrganizationUsage(
  organizationId: string | null | undefined,
  startDate?: string | null,
  endDate?: string | null,
) {
  const { data, isLoading, error, refetch } = useQuery(
    organizationUsageQueryOptions(organizationId, startDate, endDate),
  )

  return {
    usage: data,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch all projects for an organization
 *
 * @param organizationId - The organization ID
 * @returns Projects list with loading state
 */
export function useOrganizationProjects(
  organizationId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery(
    organizationProjectsQueryOptions(organizationId),
  )

  return {
    projects: data?.projects || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to get cost estimation for creating a new organization
 *
 * @param billingPlan - The billing plan
 * @param couponId - Optional coupon ID
 * @param collaborators - Array of collaborator emails
 * @returns Estimation data with loading state
 */
export function useEstimationCreateOrganization(
  billingPlan: BillingPlanTierType | null | undefined,
  couponId: string | null | undefined,
  collaborators: string[] = EMPTY_ESTIMATION_INVITES,
  paymentMethodId?: string | null,
) {
  // Serialize collaborators array to avoid reference equality issues
  // Sort and join to create a stable key - use JSON.stringify for more reliable comparison
  const collaboratorsKey = useMemo(() => {
    if (collaborators.length === 0) return ''
    return JSON.stringify([...collaborators].sort())
  }, [collaborators])

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: [
      'estimation-create-org',
      billingPlan,
      couponId ?? null,
      collaboratorsKey,
      paymentMethodId ?? null,
    ],
    queryFn: () =>
      fetchEstimationCreateOrganization(
        billingPlan!,
        couponId ?? null,
        collaborators,
        paymentMethodId ?? undefined,
      ),
    enabled: !!billingPlan && !!paymentMethodId,
    staleTime: ESTIMATION_STALE_TIME,
    refetchOnWindowFocus: false,
    refetchOnMount: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: ESTIMATION_STALE_TIME,
  })

  return {
    estimation: data,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to get cost estimation for updating a plan
 *
 * @param organizationId - The organization ID
 * @param billingPlan - The billing plan
 * @param couponId - Optional coupon ID
 * @param collaborators - Array of collaborator emails
 * @returns Estimation data with loading state
 */
export function useEstimationUpdatePlan(
  organizationId: string | null | undefined,
  billingPlan: BillingPlanTierType | null | undefined,
  couponId: string | null | undefined,
  collaborators: string[] = EMPTY_ESTIMATION_INVITES,
) {
  // Serialize collaborators array to avoid reference equality issues
  // Sort and join to create a stable key - use JSON.stringify for more reliable comparison
  const collaboratorsKey = useMemo(() => {
    if (collaborators.length === 0) return ''
    return JSON.stringify([...collaborators].sort())
  }, [collaborators])

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: [
      'estimation-update-plan',
      organizationId,
      billingPlan,
      couponId ?? null,
      collaboratorsKey,
    ],
    queryFn: () =>
      fetchEstimationUpdatePlan(
        organizationId!,
        billingPlan!,
        couponId ?? undefined,
        collaborators,
      ),
    enabled: !!organizationId && !!billingPlan,
    staleTime: ESTIMATION_STALE_TIME,
    refetchOnWindowFocus: false,
    refetchOnMount: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: ESTIMATION_STALE_TIME,
  })

  return {
    estimation: data,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to update organization billing plan
 *
 * @returns Mutation object with mutate function
 */
export function useUpdateOrganizationPlan() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: updateOrganizationPlan,
    onSuccess: (data, variables) => {
      // 402 / 3DS responses are not an upgraded organization yet. Invalidating
      // here refetches the still-Free org and can overwrite the post-validate
      // cache on the billing page.
      if (
        !data ||
        typeof data !== 'object' ||
        typeof (data as { $id?: unknown }).$id !== 'string'
      ) {
        return
      }
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: ['organization', 'plan', variables.organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: ['organizations', 'console'],
      })
    },
  })
}

/**
 * Hook to update selected projects for an organization
 *
 * @returns Mutation object with mutate function
 */
export function useUpdateSelectedProjects() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      organizationId,
      projectIds,
    }: {
      organizationId: string
      projectIds: string[]
    }) => updateSelectedProjects(organizationId, projectIds),
    onSuccess: (_, variables) => {
      // Invalidate organization query
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
    },
  })
}

/**
 * Hook to validate organization after payment
 *
 * @returns Mutation object with mutate function
 */
export function useValidateOrganization() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      organizationId,
      invites,
    }: {
      organizationId: string
      invites: string[]
    }) => validateOrganization(organizationId, invites),
    onSuccess: (_, variables) => {
      // Invalidate organization query
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: ['organizations', 'console'],
      })
    },
  })
}

/**
 * Hook to create downgrade feedback
 *
 * @returns Mutation object with mutate function
 */
export function useCreateDowngradeFeedback() {
  return useMutation({
    mutationFn: createDowngradeFeedback,
  })
}
