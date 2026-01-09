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
      // Map tier/billingPlan to plan name using the filter
      const planName = getPlanNameFromTier(org.billingPlan || org.tier)
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
    
    const planName = getPlanNameFromTier(orgData.billingPlan || orgData.tier)
    const plan = (planName === 'custom' ? 'enterprise' : planName) as Organization['plan']
    
    return {
      ...orgData,
      plan,
      planName,
      billingPlan: orgData.billingPlan,
      tier: orgData.tier,
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


