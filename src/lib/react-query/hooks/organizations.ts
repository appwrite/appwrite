/**
 * React Query hooks for Organizations
 * 
 * Handles organizations, plans, and invoices.
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, ID, BillingPlan } from '@appwrite.io/console'
import type { Organization } from '@/lib/utils/mock-data'
import { sdk } from '@/lib/appwrite/sdk'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { DEFAULT_STALE_TIME, LONG_STALE_TIME, DEFAULT_PAGE_SIZE, keepPreviousData } from './constants'

/**
 * Convert a billing plan string to BillingPlan enum value
 * The enum values are strings, so we can use the string directly if it's valid
 */
function getBillingPlanEnum(planString: string): BillingPlan {
  if (!planString) {
    throw new Error('Billing plan is required')
  }
  
  // The enum values are strings like 'tier-0', 'tier-1', etc.
  // Check if the string matches any enum value
  const enumValues = Object.values(BillingPlan) as string[]
  const normalized = planString.trim()
  
  if (enumValues.includes(normalized)) {
    return normalized as BillingPlan
  }
  
  // If not found, throw an error rather than defaulting
  // This prevents accidentally changing plans or using invalid values
  throw new Error(`Invalid billing plan: ${planString}. Valid plans: ${enumValues.join(', ')}`)
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
  const response = await sdk.forConsole.organizations.list(
    [Query.equal('platform', 'appwrite')],
  )
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
  const response = await sdk.forConsole.organizations.list([
    Query.equal('$id', orgId),
  ])
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
  const finalQueries = queries ? [...defaultQueries, ...queries] : defaultQueries

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
  } catch (error: any) {
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


// ============================================================================
// MUTATION FUNCTIONS
// ============================================================================

/**
 * Mutation function to create an organization
 * 
 * @param orgData - Organization data (organizationId, name)
 * @returns Created organization
 */
export async function createOrganization(
  orgData: {
    organizationId?: string
    name: string
  },
) {
  if (!orgData.name.trim()) {
    throw new Error('Organization name is required')
  }
  
  const organizationId = orgData.organizationId || ID.unique()

  return await sdk.forConsole.organizations.create({
    organizationId,
    name: orgData.name.trim(),
    billingPlan: BillingPlan.Tier0, // Free tier by default
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
  // Use updatePlan to update payment method
  // Note: backupPaymentMethodId is not supported in updatePlan API
  // For now, we only update the primary payment method
  const org = await fetchOrganizationById(params.organizationId)
  if (!org) {
    throw new Error('Organization not found')
  }
  
  // Convert string billingPlan to BillingPlan enum
  const billingPlan = getBillingPlanEnum(org.billingPlan)
  
  return await sdk.forConsole.organizations.updatePlan({
    organizationId: params.organizationId,
    billingPlan,
    paymentMethodId: params.paymentMethodId,
  })
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
  } = useQuery({
    queryKey: ['organizations', 'console'],
    queryFn: fetchOrganizations,
    staleTime: LONG_STALE_TIME,
  })

  // Map the API response to our Organization type
  const organizations = useMemo(() => {
    if (!organizationsData?.teams) return []
    
    return organizationsData.teams.map((org: any) => {
      // Map billingPlan to plan name using the filter
      const planName = getPlanNameFromTier(org.billingPlan)
      // Map 'custom' to 'enterprise' for compatibility with Organization type
      const plan = (planName === 'custom' ? 'enterprise' : planName) as Organization['plan']
      
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
  } = useQuery({
    queryKey: ['organization', orgId],
    queryFn: () => fetchOrganizationById(orgId!),
    enabled: !!orgId,
    staleTime: LONG_STALE_TIME,
  })

  // Map the API response to include plan information
  const organization = useMemo(() => {
    if (!orgData) return null
    
    const planName = getPlanNameFromTier(orgData.billingPlan)
    const plan = (planName === 'custom' ? 'enterprise' : planName) as Organization['plan']
    
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
 * @returns Organization plan details with loading state
 */
export function useOrganizationPlan(orgId: string | null | undefined) {
  const {
    data: planData,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['organization', 'plan', orgId],
    queryFn: () => fetchOrganizationPlan(orgId!),
    enabled: !!orgId,
    staleTime: LONG_STALE_TIME,
  })

  return {
    plan: planData,
    isLoading,
    error,
    refetch,
  }
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
  const {
    data,
    isLoading,
    isFetching,
    isPending,
    error,
    refetch,
  } = useQuery({
    queryKey: ['invoices', 'organization', organizationId, page, limit, queries ?? null],
    queryFn: () => fetchOrganizationInvoices(organizationId!, page, limit, queries),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
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
  limit: number = 10,
  offset: number = 0,
) {
  const {
    data,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['billing-aggregation', 'organization', organizationId, aggregationId, limit, offset],
    queryFn: () => fetchOrganizationBillingAggregation(organizationId!, aggregationId, limit, offset),
    enabled: !!organizationId && !!aggregationId,
    staleTime: DEFAULT_STALE_TIME,
  })

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
  const {
    data,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['credits', 'organization', organizationId, page, limit],
    queryFn: () => fetchOrganizationCredits(organizationId!, page, limit),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })

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
  const {
    data,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['payment-methods', 'account'],
    queryFn: fetchPaymentMethods,
    staleTime: DEFAULT_STALE_TIME,
  })

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
  const {
    data,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['payment-method', paymentMethodId],
    queryFn: () => fetchPaymentMethod(paymentMethodId!),
    enabled: !!paymentMethodId,
    staleTime: DEFAULT_STALE_TIME,
  })

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
  const {
    data,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['billing-addresses', 'account'],
    queryFn: fetchBillingAddresses,
    staleTime: DEFAULT_STALE_TIME,
  })

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
  const {
    data,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['billing-address', billingAddressId],
    queryFn: () => fetchBillingAddress(billingAddressId!),
    enabled: !!billingAddressId,
    staleTime: DEFAULT_STALE_TIME,
  })

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
        queryKey: ['billing-aggregation', 'organization', variables.organizationId],
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


