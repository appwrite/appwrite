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
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, ID } from '@appwrite.io/console'
import {
  BillingPlanTier,
  type BillingPlanTier as BillingPlanTierType,
} from '@/lib/constants/billing-plan'
import type { Organization } from '@/lib/utils/mock-data'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DEFAULT_ROLES,
  DEFAULT_SCOPES,
  deriveAccessFromRolesScopes,
  type OrganizationRolesScopes,
  type ConsoleAccess,
  FULL_ACCESS,
} from '@/lib/console-roles'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import {
  DEFAULT_STALE_TIME,
  LONG_STALE_TIME,
  DEFAULT_PAGE_SIZE,
} from './constants'

/**
 * Convert a billing plan string to BillingPlanTier value
 * The values are strings like 'tier-0', 'tier-1', etc.
 */
function getBillingPlanEnum(planString: string): BillingPlanTierType {
  if (!planString) {
    throw new Error('Billing plan is required')
  }

  const enumValues = Object.values(BillingPlanTier) as string[]
  const normalized = planString.trim()

  if (enumValues.includes(normalized)) {
    return normalized as BillingPlanTierType
  }

  throw new Error(
    `Invalid billing plan: ${planString}. Valid plans: ${enumValues.join(', ')}`,
  )
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
  const response = await sdk.forConsole.organizations.list({
    queries: [Query.equal('platform', 'appwrite')],
  })
  return response
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
  // Use list with filter to get organization by ID
  const response = await sdk.forConsole.organizations.list({
    queries: [Query.equal('$id', orgId)],
  })
  return response.teams?.[0] || null
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
  const response = await sdk.forConsole.organizations.getPlan(orgId)
  return response
}

/**
 * Query function to fetch current user's roles and scopes for an organization.
 * Use in organization context (orgId) or project context (project's teamId).
 * When API is unavailable or fails, returns defaultRoles and defaultScopes (full access).
 */
export async function fetchOrganizationScopes(
  organizationId: string,
): Promise<OrganizationRolesScopes> {
  if (!organizationId) {
    return { roles: [...DEFAULT_ROLES], scopes: [...DEFAULT_SCOPES] }
  }
  try {
    const orgService = sdk.forConsole.organizations as unknown as {
      getScopes?(params: {
        organizationId: string
      }): Promise<{ roles?: string[]; scopes?: string[] }>
    }
    if (typeof orgService.getScopes !== 'function') {
      return { roles: [...DEFAULT_ROLES], scopes: [...DEFAULT_SCOPES] }
    }
    const response = await orgService.getScopes({ organizationId })
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
  limit: number = 10,
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
    const plansObject: Record<string, unknown> = {}

    if (response.plans && Array.isArray(response.plans)) {
      response.plans.forEach((plan: unknown) => {
        if (plan.$id) {
          plansObject[plan.$id] = plan
        }
      })
    }

    return {
      plans: plansObject,
      total: response.total || 0,
    }
  } catch {
    return { plans: {}, total: 0 }
  }
}

/**
 * Query function to get coupon account information
 *
 * @param couponCode - The coupon code to validate
 * @returns Coupon details from the API
 */
export async function fetchCouponAccount(couponCode: string) {
  if (!couponCode) {
    return null
  }
  try {
    // Try billing service first (if it exists)
    if ((sdk.forConsole as unknown).billing?.getCouponAccount) {
      return await (sdk.forConsole as unknown).billing.getCouponAccount(
        couponCode,
      )
    }
    // Fallback to organizations service
    if ((sdk.forConsole.organizations as unknown).getCouponAccount) {
      return await (sdk.forConsole.organizations as unknown).getCouponAccount(
        couponCode,
      )
    }
    return null
  } catch {
    return null
  }
}

/**
 * Query function to fetch organization usage
 *
 * @param organizationId - The organization ID
 * @returns Organization usage data
 */
export async function fetchOrganizationUsage(organizationId: string) {
  if (!organizationId) {
    return null
  }
  try {
    // Try billing service first (if it exists)
    if ((sdk.forConsole as unknown).billing?.listUsage) {
      return await (sdk.forConsole as unknown).billing.listUsage(organizationId)
    }
    // Fallback to organizations service
    if ((sdk.forConsole.organizations as unknown).listUsage) {
      return await (sdk.forConsole.organizations as unknown).listUsage(
        organizationId,
      )
    }
    return null
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
export async function fetchOrganizationProjects(organizationId: string) {
  if (!organizationId) {
    return { projects: [] }
  }
  try {
    const response = await sdk.forConsole.projects.list({
      queries: [Query.equal('teamId', organizationId), Query.limit(1000)],
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
  couponId: string | null,
  collaborators: string[],
) {
  try {
    // Try billing service first (if it exists)
    if ((sdk.forConsole as unknown).billing?.estimationCreateOrganization) {
      return await (
        sdk.forConsole as unknown
      ).billing.estimationCreateOrganization(
        billingPlan,
        couponId || undefined,
        collaborators,
      )
    }
    // Fallback to organizations service
    if (
      (sdk.forConsole.organizations as unknown).estimationCreateOrganization
    ) {
      return await (
        sdk.forConsole.organizations as unknown
      ).estimationCreateOrganization(
        billingPlan,
        couponId || undefined,
        collaborators,
      )
    }
    return null
  } catch {
    return null
  }
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
  try {
    // Only pass couponId if it's a valid UID string
    // UID validation: non-empty, max 36 chars, valid chars only (a-z, A-Z, 0-9, _), can't start with _
    let couponParam: string | undefined = undefined
    if (couponId) {
      // Ensure it's a string, not an object or array
      if (typeof couponId !== 'string') {
        couponParam = undefined
      } else {
        const trimmed = couponId.trim()
        if (
          trimmed.length > 0 &&
          trimmed.length <= 36 &&
          /^[a-zA-Z0-9][a-zA-Z0-9_]*$/.test(trimmed) &&
          !trimmed.startsWith('_')
        ) {
          couponParam = trimmed
        }
      }
    }

    // Try billing service first (if it exists)
    if ((sdk.forConsole as unknown).billing?.estimationUpdatePlan) {
      return await (sdk.forConsole as unknown).billing.estimationUpdatePlan({
        organizationId,
        billingPlan,
        invites: collaborators,
        couponId: couponParam,
      })
    }
    // Fallback to organizations service
    if ((sdk.forConsole.organizations as unknown).estimationUpdatePlan) {
      const orgService = sdk.forConsole.organizations as unknown
      return await orgService.estimationUpdatePlan({
        organizationId,
        billingPlan,
        invites: collaborators,
        couponId: couponParam,
      })
    }
    return null
  } catch {
    return null
  }
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
}) {
  if (!orgData.name.trim()) {
    throw new Error('Organization name is required')
  }

  const organizationId = orgData.organizationId || ID.unique()

  return await sdk.forConsole.organizations.create({
    organizationId,
    name: orgData.name.trim(),
    billingPlan: BillingPlanTier.Tier0, // Free tier by default
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
  paymentMethodId?: string
  backupPaymentMethodId?: string
}) {
  // Use dedicated SDK methods for setting payment methods
  if (params.backupPaymentMethodId !== undefined) {
    if (params.backupPaymentMethodId) {
      // Set backup payment method
      return await sdk.forConsole.organizations.setBackupPaymentMethod({
        organizationId: params.organizationId,
        paymentMethodId: params.backupPaymentMethodId,
      })
    } else {
      // Remove backup payment method
      return await sdk.forConsole.organizations.deleteBackupPaymentMethod({
        organizationId: params.organizationId,
      })
    }
  }

  if (params.paymentMethodId !== undefined) {
    if (params.paymentMethodId) {
      // Set default payment method
      return await sdk.forConsole.organizations.setDefaultPaymentMethod({
        organizationId: params.organizationId,
        paymentMethodId: params.paymentMethodId,
      })
    } else {
      // Remove default payment method
      return await sdk.forConsole.organizations.deleteDefaultPaymentMethod({
        organizationId: params.organizationId,
      })
    }
  }

  throw new Error(
    'Either paymentMethodId or backupPaymentMethodId must be provided',
  )
}

/**
 * Mutation function to update organization billing address
 *
 * @param params - Billing address update parameters
 * @returns Updated organization
 */
export async function updateOrganizationBillingAddress(params: {
  organizationId: string
  billingAddressId?: string
}) {
  // Use updatePlan to update billing address
  const org = await fetchOrganizationById(params.organizationId)
  if (!org) {
    throw new Error('Organization not found')
  }

  // Convert string billingPlan to BillingPlan enum
  const billingPlan = getBillingPlanEnum(org.billingPlan)

  return await sdk.forConsole.organizations.updatePlan({
    organizationId: params.organizationId,
    billingPlan,
    billingAddressId: params.billingAddressId,
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
  try {
    // Try billing service first (if it exists)
    if ((sdk.forConsole as unknown).billing?.validateOrganization) {
      return await (sdk.forConsole as unknown).billing.validateOrganization(
        organizationId,
        invites,
      )
    }
    // Fallback to organizations service
    if ((sdk.forConsole.organizations as unknown).validateOrganization) {
      return await (
        sdk.forConsole.organizations as unknown
      ).validateOrganization(organizationId, invites)
    }
    throw new Error('validateOrganization method not available')
  } catch (error) {
    throw error
  }
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
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
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
) {
  const features = getActiveProfileFeatures()
  const enabled = !!organizationId && !!features.orgRoles
  return queryOptions({
    queryKey: ['organization', 'scopes', organizationId],
    queryFn: () => fetchOrganizationScopes(organizationId!),
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
 */
export function organizationUsageQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['organization-usage', organizationId],
    queryFn: () => fetchOrganizationUsage(organizationId!),
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

  // Map the API response to our Organization type
  const organizations = useMemo(() => {
    if (!organizationsData?.teams) return []

    return organizationsData.teams.map((org: unknown) => {
      // Map billingPlan to plan name using the filter
      const plan = getPlanNameFromTier(org.billingPlan) as Organization['plan']

      return {
        $id: org.$id,
        name: org.name,
        slug: org.name.toLowerCase().replace(/\s+/g, '-'),
        avatar: undefined, // Organizations from SDK don't have avatar
        plan,
        members: org.total || 0,
      }
    }) as Organization[]
  }, [organizationsData])

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

  // Map the API response to include plan information
  const organization = useMemo(() => {
    if (!orgData) return null

    const planName = getPlanNameFromTier(orgData.billingPlan)
    const plan = planName as Organization['plan']

    return {
      ...orgData,
      plan,
      planName,
      billingPlan: orgData.billingPlan,
    }
  }, [orgData])

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
    error,
    refetch,
  }
}

/**
 * Hook to get current user's roles, scopes, and derived access for an organization.
 * Use in organization context (orgId) or project context (project's teamId).
 * When profile does not support roles (orgRoles: false), returns full access without fetching.
 *
 * @param initialData - Optional data from route loader to avoid layout shift on first paint
 */
export function useOrganizationScopes(
  organizationId: string | null | undefined,
  initialData?: Awaited<ReturnType<typeof fetchOrganizationScopes>>,
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

  const { data, isLoading, error, refetch } = useQuery({
    ...organizationScopesQueryOptions(organizationId),
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
  limit: number = 10,
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
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: organizationId && aggregationId ? 5 * 60 * 1000 : 0,
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
  const { data, isLoading, isFetching, isPending, error, refetch } = useQuery(
    organizationInvoicesQueryOptions(organizationId, page, limit, queries),
  )

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
  limit: number = 10,
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
export function usePaymentMethods() {
  const { data, isLoading, error, refetch } = useQuery(
    paymentMethodsQueryOptions(),
  )

  return {
    paymentMethods: data?.paymentMethods || [],
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
    onSuccess: (_, variables) => {
      // Invalidate organization and aggregation queries
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: [
          'billing-aggregation',
          'organization',
          variables.organizationId,
        ],
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
    onSuccess: (_, variables) => {
      // Invalidate organization query
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
      })
    },
  })
}

/**
 * Hook to update organization billing address
 *
 * @returns Mutation object with mutate function
 */
export function useUpdateOrganizationBillingAddress() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: updateOrganizationBillingAddress,
    onSuccess: (_, variables) => {
      // Invalidate organization query
      queryClient.invalidateQueries({
        queryKey: ['organization', variables.organizationId],
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
    plans: data?.plans || {},
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
 * @returns Organization usage data with loading state
 */
export function useOrganizationUsage(
  organizationId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery(
    organizationUsageQueryOptions(organizationId),
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
  collaborators: string[],
) {
  // Serialize collaborators array to avoid reference equality issues
  // Sort and join to create a stable key - use JSON.stringify for more reliable comparison
  const collaboratorsKey = useMemo(() => {
    if (collaborators.length === 0) return ''
    return JSON.stringify([...collaborators].sort())
  }, [collaborators])

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: [
      'estimation-create-org',
      billingPlan,
      couponId,
      collaboratorsKey,
    ],
    queryFn: () =>
      fetchEstimationCreateOrganization(
        billingPlan!,
        couponId || null,
        collaborators,
      ),
    enabled: !!billingPlan,
    staleTime: 30 * 1000, // 30 seconds
    // Prevent refetch on window focus to avoid loops
    refetchOnWindowFocus: false,
    refetchOnMount: false,
    refetchOnReconnect: false,
    retry: false, // Don't retry on error
    gcTime: 5 * 60 * 1000, // Keep in cache for 5 minutes
  })

  return {
    estimation: data,
    isLoading,
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
  collaborators: string[],
) {
  // Serialize collaborators array to avoid reference equality issues
  // Sort and join to create a stable key - use JSON.stringify for more reliable comparison
  const collaboratorsKey = useMemo(() => {
    if (collaborators.length === 0) return ''
    return JSON.stringify([...collaborators].sort())
  }, [collaborators])

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: [
      'estimation-update-plan',
      organizationId,
      billingPlan,
      couponId,
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
    staleTime: 30 * 1000, // 30 seconds
    // Prevent refetch on window focus to avoid loops
    refetchOnWindowFocus: false,
    refetchOnMount: false,
    refetchOnReconnect: false,
    retry: false, // Don't retry on error
    gcTime: 5 * 60 * 1000, // Keep in cache for 5 minutes
  })

  return {
    estimation: data,
    isLoading,
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
    onSuccess: (_, variables) => {
      // Invalidate organization and plan queries
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
