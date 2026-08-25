/**
 * React Query hooks for Organization Domains
 *
 * Handles domain fetching, creation, deletion, and verification for organizations.
 * All operations use the Console SDK (sdk.forConsole.domains).
 */

import {
  useQueries,
  useQuery,
  useMutation,
  useQueryClient,
  queryOptions,
  keepPreviousData,
} from '@tanstack/react-query'
import { useEffect, useMemo } from 'react'
import { Query, DomainRegistrationType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { buildAttributePrefixSearchQueries } from '@/lib/appwrite-id'
import { sdk } from '@/lib/appwrite/sdk'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { isPendingDomainTransferStatus } from '@/lib/domains/transfer-status'
import { DEFAULT_STALE_TIME, DEFAULT_PAGE_SIZE } from './constants'
import { Dependencies } from './dependencies'

const DOMAIN_TRANSFER_STATUS_POLL_MS = 15_000

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch domains for an organization
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param organizationId - The organization/team ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated domains list response from the API
 */
export const DOMAINS_DEFAULT_SORT_BY = '$createdAt'
export const DOMAINS_DEFAULT_SORT_ORDER = 'desc' as const

export async function fetchOrganizationDomains(
  organizationId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = DOMAINS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = DOMAINS_DEFAULT_SORT_ORDER,
) {
  if (!organizationId) {
    return { domains: [], total: 0 }
  }

  const orderQuery =
    sortOrder === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy)
  const queries = [
    Query.equal('teamId', organizationId),
    ...(filterQueries ?? []),
    ...buildAttributePrefixSearchQueries(['domain', '$id'], search),
    orderQuery,
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await sdk.forConsole.domains.list({ queries })

  return {
    domains: response.domains || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single domain by ID
 *
 * @param domainId - The domain ID to fetch
 * @returns Domain details from the API
 */
export async function fetchDomain(domainId: string) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }
  const response = await sdk.forConsole.domains.get({ domainId })
  return response
}

/**
 * Registration quote from getPrice plus optional renewal quote (same period rules).
 * Renewal uses registrationType `renewal`; if the API returns an error, renewal fields are omitted.
 */
export type DomainPriceQuote = Models.DomainPrice & {
  renewalPrice?: number
  renewalPeriodYears?: number
}

/**
 * Query function to fetch new-registration and renewal prices (getPrice API).
 * For .ai TLD always requests 2-year price; otherwise uses API default (typically 1 year).
 */
export async function fetchDomainPrice(
  domain: string,
): Promise<DomainPriceQuote> {
  const normalized = domain.toLowerCase()
  const periodYears = normalized.endsWith('.ai') ? 2 : undefined
  const params = {
    domain: normalized,
    ...(periodYears != null && { periodYears }),
  }
  const [registration, renewal] = await Promise.all([
    sdk.forConsole.domains.getPrice({
      ...params,
      registrationType: DomainRegistrationType.New,
    }),
    sdk.forConsole.domains
      .getPrice({
        ...params,
        registrationType: DomainRegistrationType.Renewal,
      })
      .catch(() => null),
  ])

  return {
    ...registration,
    renewalPrice: renewal?.price,
    renewalPeriodYears: renewal?.periodYears,
  }
}

/**
 * Transfer-in quote from getPrice (registrationType transfer) plus optional renewal quote.
 */
export type DomainTransferPriceQuote = Models.DomainPrice & {
  renewalPrice?: number
  renewalPeriodYears?: number
}

/**
 * Fetches transfer and renewal prices for an inbound transfer (same period rules as registration).
 */
export async function fetchDomainTransferPriceQuote(
  domain: string,
): Promise<DomainTransferPriceQuote> {
  const normalized = domain.toLowerCase().trim()
  const periodYears = normalized.endsWith('.ai') ? 2 : undefined
  const params = {
    domain: normalized,
    ...(periodYears != null && { periodYears }),
  }
  const [transfer, renewal] = await Promise.all([
    sdk.forConsole.domains.getPrice({
      ...params,
      registrationType: DomainRegistrationType.Transfer,
    }),
    sdk.forConsole.domains
      .getPrice({
        ...params,
        registrationType: DomainRegistrationType.Renewal,
      })
      .catch(() => null),
  ])

  return {
    ...transfer,
    renewalPrice: renewal?.price,
    renewalPeriodYears: renewal?.periodYears,
  }
}

/**
 * Query options for transfer-in price preview (wizard sidebar).
 */
export function domainTransferPriceQueryOptions(
  domain: string | null | undefined,
) {
  const normalized = domain?.trim().toLowerCase() ?? ''
  const enabled =
    normalized.length > 0 &&
    normalized.includes('.') &&
    !normalized.startsWith('.') &&
    !normalized.endsWith('.')

  return queryOptions({
    queryKey: ['domain-price', 'transfer', normalized],
    queryFn: () => fetchDomainTransferPriceQuote(normalized),
    enabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

export async function createDomainPurchase(params: {
  domain: string
  organizationId: string
  firstName: string
  lastName: string
  email: string
  phone: string
  billingAddressId: string
  paymentMethodId: string
  addressLine3?: string
  companyName?: string
  periodYears?: number
}) {
  return await sdk.forConsole.domains.createPurchase({
    domain: params.domain.toLowerCase(),
    organizationId: params.organizationId,
    firstName: params.firstName,
    lastName: params.lastName,
    email: params.email,
    phone: params.phone,
    billingAddressId: params.billingAddressId,
    paymentMethodId: params.paymentMethodId,
    addressLine3: params.addressLine3,
    companyName: params.companyName,
    periodYears: params.periodYears,
  })
}

export async function finalizeDomainPurchase(params: {
  invoiceId: string
  organizationId: string
}) {
  return await sdk.forConsole.domains.updatePurchase({
    invoiceId: params.invoiceId,
    organizationId: params.organizationId,
  })
}

export async function createDomainTransferIn(params: {
  domain: string
  organizationId: string
  authCode: string
  paymentMethodId: string
}) {
  return await sdk.forConsole.domains.createTransferIn({
    domain: params.domain.toLowerCase(),
    organizationId: params.organizationId,
    authCode: params.authCode,
    paymentMethodId: params.paymentMethodId,
  })
}

export async function finalizeDomainTransferIn(params: {
  invoiceId: string
  organizationId: string
}) {
  return await sdk.forConsole.domains.updateTransferIn({
    invoiceId: params.invoiceId,
    organizationId: params.organizationId,
  })
}

export async function createDomainTransferOut(params: {
  domainId: string
  organizationId: string
}) {
  return await sdk.forConsole.domains.createTransferOut({
    domainId: params.domainId,
    organizationId: params.organizationId,
  })
}

export async function fetchDomainTransferStatus(domainId: string) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }
  return await sdk.forConsole.domains.getTransferStatus({ domainId })
}

// ============================================================================
// MUTATION FUNCTIONS
// ============================================================================

/**
 * Mutation function to create a domain
 *
 * @param organizationId - The organization/team ID
 * @param domain - The domain name (lowercase)
 * @returns Created domain object
 */
export async function createOrganizationDomain(
  organizationId: string,
  domain: string,
) {
  if (!organizationId) {
    throw new Error('Organization ID is required')
  }
  if (!domain) {
    throw new Error('Domain is required')
  }
  const response = await sdk.forConsole.domains.create({
    teamId: organizationId,
    domain: domain.toLowerCase(),
  })
  return response
}

/**
 * Mutation function to delete a domain
 *
 * @param domainId - The domain ID to delete
 */
export async function deleteOrganizationDomain(domainId: string) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }
  await sdk.forConsole.domains.delete({ domainId })
}

/**
 * Mutation function to verify nameservers (retry verification)
 *
 * Checks whether the domain's NS records match Appwrite nameservers and
 * updates verification status. Use this for external domains after the user
 * points NS at Appwrite. Do not use updateNameservers here — that endpoint
 * only changes registrar NS for domains managed (registered) by Appwrite.
 *
 * @param domainId - The domain ID to retry verification for
 * @returns Updated domain object
 */
export async function retryDomainVerification(domainId: string) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }
  const response = await sdk.forConsole.domains.verifyNameservers({ domainId })
  return response
}

/**
 * Mutation function to move a domain to a different organization
 *
 * @param domainId - The domain ID
 * @param teamId - The target organization/team ID
 */
export async function updateDomainTeam(domainId: string, teamId: string) {
  if (!getActiveProfileFeatures().multiTenancy) {
    throw new Error(
      'This console profile does not support transferring between organizations',
    )
  }
  if (!domainId) {
    throw new Error('Domain ID is required')
  }
  if (!teamId) {
    throw new Error('Team ID is required')
  }
  await sdk.forConsole.domains.updateTeam({ domainId, teamId })
}

/**
 * Mutation function to update domain auto-renewal status
 *
 * @param domainId - The domain ID
 * @param autoRenewal - Whether auto-renewal should be enabled
 * @returns Updated domain object
 */
export async function updateDomainAutoRenewal(
  domainId: string,
  autoRenewal: boolean,
) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }

  return await sdk.forConsole.domains.updateAutoRenewal({
    domainId,
    autoRenewal,
  })
}

/**
 * Query function to fetch DNS records for a domain
 *
 * @param domainId - The domain ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param filterQueries - Optional Appwrite Query strings for filtering
 * @returns Paginated DNS records list response from the API
 */
export const DNS_RECORDS_DEFAULT_SORT_BY = '$createdAt'
export const DNS_RECORDS_DEFAULT_SORT_ORDER = 'asc' as const

export async function fetchDomainRecords(
  domainId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  filterQueries?: string[],
  sortBy: string = DNS_RECORDS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = DNS_RECORDS_DEFAULT_SORT_ORDER,
) {
  if (!domainId) {
    return { dnsRecords: [], total: 0 }
  }

  const orderQuery =
    sortOrder === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy)
  const queries = [
    ...(filterQueries ?? []),
    orderQuery,
    Query.offset(page * limit),
    Query.limit(limit),
  ]

  const response = await sdk.forConsole.domains.listRecords({
    domainId,
    queries,
  })

  return {
    dnsRecords: response.dnsRecords || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch zone file for a domain
 *
 * @param domainId - The domain ID
 * @returns Zone file content
 */
export async function fetchDomainZone(domainId: string) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }
  const response = await sdk.forConsole.domains.getZone({ domainId })
  return response
}

/**
 * Helper function to create DNS record based on type
 */
export async function createDnsRecord(
  domainId: string,
  type: string,
  data: {
    name: string
    value: string
    ttl: number
    priority?: number
    weight?: number
    port?: number
    comment?: string
  },
) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }

  const baseParams = {
    domainId,
    name: data.name,
    value: data.value,
    ttl: data.ttl,
    comment: data.comment,
  }

  switch (type.toUpperCase()) {
    case 'A':
      return await sdk.forConsole.domains.createRecordA(baseParams)
    case 'AAAA':
      return await sdk.forConsole.domains.createRecordAAAA(baseParams)
    case 'CNAME':
      return await sdk.forConsole.domains.createRecordCNAME(baseParams)
    case 'MX':
      if (data.priority === undefined) {
        throw new Error('Priority is required for MX records')
      }
      return await sdk.forConsole.domains.createRecordMX({
        ...baseParams,
        priority: data.priority,
      })
    case 'TXT':
      return await sdk.forConsole.domains.createRecordTXT(baseParams)
    case 'NS':
      return await sdk.forConsole.domains.createRecordNS(baseParams)
    case 'SRV':
      if (
        data.priority === undefined ||
        data.weight === undefined ||
        data.port === undefined
      ) {
        throw new Error(
          'Priority, weight, and port are required for SRV records',
        )
      }
      return await sdk.forConsole.domains.createRecordSRV({
        ...baseParams,
        priority: data.priority,
        weight: data.weight,
        port: data.port,
      })
    case 'CAA':
      return await sdk.forConsole.domains.createRecordCAA(baseParams)
    case 'HTTPS':
      return await sdk.forConsole.domains.createRecordHTTPS(baseParams)
    case 'ALIAS':
      return await sdk.forConsole.domains.createRecordAlias(baseParams)
    default:
      throw new Error(`Unsupported DNS record type: ${type}`)
  }
}

/**
 * Helper function to update DNS record based on type
 */
export async function updateDnsRecord(
  domainId: string,
  recordId: string,
  type: string,
  data: {
    name: string
    value: string
    ttl: number
    priority?: number
    weight?: number
    port?: number
    comment?: string
  },
) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }
  if (!recordId) {
    throw new Error('Record ID is required')
  }

  const baseParams = {
    domainId,
    recordId,
    name: data.name,
    value: data.value,
    ttl: data.ttl,
    comment: data.comment,
  }

  switch (type.toUpperCase()) {
    case 'A':
      return await sdk.forConsole.domains.updateRecordA(baseParams)
    case 'AAAA':
      return await sdk.forConsole.domains.updateRecordAAAA(baseParams)
    case 'CNAME':
      return await sdk.forConsole.domains.updateRecordCNAME(baseParams)
    case 'MX':
      if (data.priority === undefined) {
        throw new Error('Priority is required for MX records')
      }
      return await sdk.forConsole.domains.updateRecordMX({
        ...baseParams,
        priority: data.priority,
      })
    case 'TXT':
      return await sdk.forConsole.domains.updateRecordTXT(baseParams)
    case 'NS':
      return await sdk.forConsole.domains.updateRecordNS(baseParams)
    case 'SRV':
      if (
        data.priority === undefined ||
        data.weight === undefined ||
        data.port === undefined
      ) {
        throw new Error(
          'Priority, weight, and port are required for SRV records',
        )
      }
      return await sdk.forConsole.domains.updateRecordSRV({
        ...baseParams,
        priority: data.priority,
        weight: data.weight,
        port: data.port,
      })
    case 'CAA':
      return await sdk.forConsole.domains.updateRecordCAA(baseParams)
    case 'HTTPS':
      return await sdk.forConsole.domains.updateRecordHTTPS(baseParams)
    case 'ALIAS':
      return await sdk.forConsole.domains.updateRecordAlias(baseParams)
    default:
      throw new Error(`Unsupported DNS record type: ${type}`)
  }
}

/**
 * Mutation function to delete a DNS record
 *
 * @param domainId - The domain ID
 * @param recordId - The DNS record ID to delete
 */
export async function deleteDnsRecord(domainId: string, recordId: string) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }
  if (!recordId) {
    throw new Error('Record ID is required')
  }
  await sdk.forConsole.domains.deleteRecord({ domainId, recordId })
}

/**
 * Mutation function to update zone file (import)
 *
 * @param domainId - The domain ID
 * @param content - Zone file content as string
 */
export async function updateDomainZone(domainId: string, content: string) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }
  if (!content) {
    throw new Error('Zone file content is required')
  }
  await sdk.forConsole.domains.updateZone({ domainId, content })
}

/**
 * Query function to fetch preset DNS records for email providers
 */
export async function fetchPresetRecords(
  domainId: string,
  preset:
    | 'zoho'
    | 'mailgun'
    | 'outlook'
    | 'protonmail'
    | 'icloud'
    | 'google-workspace',
) {
  if (!domainId) {
    throw new Error('Domain ID is required')
  }

  switch (preset) {
    case 'zoho':
      return await sdk.forConsole.domains.getPresetZoho({ domainId })
    case 'mailgun':
      return await sdk.forConsole.domains.getPresetMailgun({ domainId })
    case 'outlook':
      return await sdk.forConsole.domains.getPresetOutlook({ domainId })
    case 'protonmail':
      return await sdk.forConsole.domains.getPresetProtonMail({ domainId })
    case 'icloud':
      return await sdk.forConsole.domains.getPresetICloud({ domainId })
    case 'google-workspace':
      return await sdk.forConsole.domains.getPresetGoogleWorkspace({ domainId })
    default:
      throw new Error(`Unsupported preset: ${preset}`)
  }
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching paginated domains for an organization
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function organizationDomainsQueryOptions(
  organizationId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = DOMAINS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = DOMAINS_DEFAULT_SORT_ORDER,
) {
  return queryOptions({
    queryKey: [
      'domains',
      'organization',
      organizationId,
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ],
    queryFn: () =>
      fetchOrganizationDomains(
        organizationId!,
        page,
        limit,
        search,
        filterQueries,
        sortBy,
        sortOrder,
      ),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (page size/page/search change)
    // Don't keep disabled queries in cache
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching a single domain by ID
 */
export function domainQueryOptions(domainId: string | null | undefined) {
  return queryOptions({
    queryKey: ['domain', domainId],
    queryFn: () => fetchDomain(domainId!),
    enabled: !!domainId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
  })
}

/**
 * Query options for live domain transfer-in status (polls while pending).
 */
export function domainTransferStatusQueryOptions(
  domainId: string | null | undefined,
  transferStatusFromDomain?: string | null,
) {
  const enabled = !!domainId && isPendingDomainTransferStatus(transferStatusFromDomain)

  return queryOptions({
    queryKey: ['domain', domainId, 'transfer-status'],
    queryFn: () => fetchDomainTransferStatus(domainId!),
    enabled,
    staleTime: 0,
    retry: false,
    refetchOnMount: true,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchInterval: (query) =>
      isPendingDomainTransferStatus(query.state.data?.status)
        ? DOMAIN_TRANSFER_STATUS_POLL_MS
        : false,
  })
}

/**
 * Query options for fetching a single domain price
 */
export function domainPriceQueryOptions(domain: string | null | undefined) {
  return queryOptions({
    queryKey: ['domain-price', domain, 'renewal'],
    queryFn: () => fetchDomainPrice(domain!),
    enabled: !!domain && domain.length >= 4,
    staleTime: 60 * 1000,
  })
}

/**
 * Query options for fetching DNS records for a domain
 */
export function domainRecordsQueryOptions(
  domainId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  filterQueries?: string[],
  sortBy: string = DNS_RECORDS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = DNS_RECORDS_DEFAULT_SORT_ORDER,
) {
  const hasFilters = filterQueries !== undefined && filterQueries.length > 0
  return queryOptions({
    queryKey: [
      'dns-records',
      'domain',
      domainId,
      page,
      limit,
      ...(hasFilters ? [filterQueries] : []),
      sortBy,
      sortOrder,
    ],
    queryFn: () =>
      fetchDomainRecords(
        domainId!,
        page,
        limit,
        filterQueries,
        sortBy,
        sortOrder,
      ),
    enabled: !!domainId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (filters/page change)
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch domains for an organization
 *
 * @param organizationId - The organization ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated domains list with loading state
 */
export function useOrganizationDomains(
  organizationId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = DOMAINS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = DOMAINS_DEFAULT_SORT_ORDER,
) {
  const {
    data: domainsData,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  } = useQuery(
    organizationDomainsQueryOptions(
      organizationId,
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ),
  )

  const domains = useMemo(() => {
    if (!domainsData?.domains) return []
    return domainsData.domains
  }, [domainsData])

  const totalPages = useMemo(() => {
    if (!domainsData?.total) return 0
    return Math.ceil(domainsData.total / limit)
  }, [domainsData?.total, limit])

  return {
    domains,
    total: domainsData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  }
}

/**
 * Hook to fetch domain prices via batched getPrice calls
 *
 * Fires one getPrice request per TLD in parallel. Results stream in as each
 * completes for fast perceived performance.
 *
 * @param baseName - Base name (e.g. "myapp")
 * @param tlds - TLDs to fetch prices for
 * @returns Map of domain -> { price, available, periodYears?, premium?, renewalPrice?, renewalPeriodYears? }, loading/error state.
 *   price is total cost; periodYears is 1+ (price covers that many years).
 */
export function useDomainPrices(
  baseName: string | null | undefined,
  tlds: string[] = [],
) {
  const domains = useMemo(
    () =>
      baseName && baseName.length >= 1
        ? tlds.map((tld) => `${baseName}.${tld}`)
        : [],
    [baseName, tlds],
  )

  const queries = useQueries({
    queries: domains.map((domain) => domainPriceQueryOptions(domain)),
  })

  const pricesByDomain = useMemo(() => {
    const map = new Map<
      string,
      {
        price: number
        available: boolean
        periodYears?: number
        premium?: boolean
        renewalPrice?: number
        renewalPeriodYears?: number
      }
    >()
    for (let i = 0; i < domains.length; i++) {
      const { data } = queries[i]
      if (data) {
        const quote = data as DomainPriceQuote
        map.set(domains[i], {
          price: quote.price,
          available: quote.available,
          periodYears:
            typeof quote.periodYears === 'number' ? quote.periodYears : 1,
          premium: quote.premium,
          renewalPrice: quote.renewalPrice,
          renewalPeriodYears: quote.renewalPeriodYears,
        })
      }
    }
    return map
  }, [domains, queries])

  const hasError = queries.some((q: { error: unknown }) => q.error)
  const isFetching = queries.some((q: { isFetching: boolean }) => q.isFetching)

  return {
    pricesByDomain,
    isFetching,
    error: hasError
      ? queries.find((q: { error: unknown }) => q.error)?.error
      : undefined,
  }
}

/**
 * Hook to fetch a single domain by ID
 *
 * @param domainId - The domain ID
 * @returns Domain details with loading state
 */
export function useDomain(domainId: string | null | undefined) {
  return useQuery(domainQueryOptions(domainId))
}

/**
 * Live transfer-in status from the transfer status endpoint.
 * Enabled while `transferStatusFromDomain` is a pending enum value.
 */
export function useDomainTransferStatus(
  domainId: string | null | undefined,
  transferStatusFromDomain?: string | null,
  organizationId?: string | null,
) {
  const queryClient = useQueryClient()
  const query = useQuery(
    domainTransferStatusQueryOptions(domainId, transferStatusFromDomain),
  )

  useEffect(() => {
    const status = query.data?.status
    if (!status || !domainId) return

    const endpointPending = isPendingDomainTransferStatus(status)
    const domainPending = isPendingDomainTransferStatus(transferStatusFromDomain)

    if (endpointPending && !domainPending) {
      void queryClient.invalidateQueries({ queryKey: ['domain', domainId] })
      if (organizationId) {
        void queryClient.invalidateQueries({
          queryKey: ['domains', 'organization', organizationId],
        })
      }
      return
    }

    if (domainPending && !endpointPending) {
      void queryClient.invalidateQueries({ queryKey: ['domain', domainId] })
      if (organizationId) {
        void queryClient.invalidateQueries({
          queryKey: ['domains', 'organization', organizationId],
        })
      }
    }
  }, [
    query.data?.status,
    domainId,
    organizationId,
    queryClient,
    transferStatusFromDomain,
  ])

  return query
}

/**
 * Hook to create a domain
 *
 * @param organizationId - The organization ID
 * @returns Mutation hook for creating a domain
 */
export function useCreateOrganizationDomain(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (domain: string) => {
      if (!organizationId) {
        throw new Error('Organization ID is required')
      }
      return await createOrganizationDomain(organizationId, domain)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['domains', 'organization', organizationId],
      })
    },
  })
}

/**
 * Hook to delete a domain
 *
 * @param organizationId - The organization ID (for cache invalidation)
 * @returns Mutation hook for deleting a domain
 */
export function useDeleteOrganizationDomain(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (domainId: string) => {
      return await deleteOrganizationDomain(domainId)
    },
    onSuccess: async () => {
      // Refetch domains list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['domains', 'organization', organizationId],
      })
      await queryClient.refetchQueries({
        queryKey: Dependencies.DOMAINS,
      })
    },
  })
}

/**
 * Hook to retry domain verification (verify nameservers)
 *
 * @param organizationId - The organization ID (for cache invalidation)
 * @returns Mutation hook for retrying verification
 */
export function useRetryDomainVerification(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (domainId: string) => {
      return await retryDomainVerification(domainId)
    },
    onSuccess: (_, domainId) => {
      queryClient.invalidateQueries({
        queryKey: ['domains', 'organization', organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: ['domain', domainId],
      })
    },
  })
}

/**
 * Hook to move a domain to a different organization
 *
 * @param organizationId - The organization ID (for cache invalidation)
 * @returns Mutation hook for updating domain team
 */
export function useUpdateDomainTeam(organizationId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      domainId,
      teamId,
    }: {
      domainId: string
      teamId: string
    }) => {
      return await updateDomainTeam(domainId, teamId)
    },
    onSuccess: async (_, { domainId, teamId }) => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['domains', 'organization', organizationId],
        }),
        queryClient.refetchQueries({
          queryKey: ['domains', 'organization', teamId],
        }),
      ])
      queryClient.invalidateQueries({
        queryKey: ['domain', domainId],
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.DOMAINS,
      })
    },
  })
}

/**
 * Hook to update a domain's auto-renewal status
 *
 * @param organizationId - The organization ID (for cache invalidation)
 * @returns Mutation hook for updating auto-renewal
 */
export function useUpdateDomainAutoRenewal(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      domainId,
      autoRenewal,
    }: {
      domainId: string
      autoRenewal: boolean
    }) => {
      return await updateDomainAutoRenewal(domainId, autoRenewal)
    },
    onSuccess: (_, { domainId }) => {
      queryClient.invalidateQueries({
        queryKey: ['domains', 'organization', organizationId],
      })
      queryClient.invalidateQueries({
        queryKey: ['domain', domainId],
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.DOMAINS,
      })
    },
  })
}

/**
 * Hook to fetch DNS records for a domain
 *
 * @param domainId - The domain ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @returns Paginated DNS records list with loading state
 */
export function useDomainRecords(
  domainId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  filterQueries?: string[],
  sortBy: string = DNS_RECORDS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = DNS_RECORDS_DEFAULT_SORT_ORDER,
) {
  const {
    data: recordsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(
    domainRecordsQueryOptions(
      domainId,
      page,
      limit,
      filterQueries,
      sortBy,
      sortOrder,
    ),
  )

  const dnsRecords = useMemo(() => {
    if (!recordsData?.dnsRecords) return []
    return recordsData.dnsRecords || []
  }, [recordsData])

  const totalPages = useMemo(() => {
    if (!recordsData?.total) return 0
    return Math.ceil(recordsData.total / limit)
  }, [recordsData?.total, limit])

  return {
    dnsRecords,
    total: recordsData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch zone file for a domain
 *
 * @param domainId - The domain ID
 * @returns Zone file content with loading state
 */
export function useDomainZone(domainId: string | null | undefined) {
  return useQuery({
    queryKey: ['zone', 'domain', domainId],
    queryFn: () => fetchDomainZone(domainId!),
    enabled: !!domainId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to create a DNS record
 *
 * @param domainId - The domain ID
 * @returns Mutation hook for creating a DNS record
 */
export function useCreateDnsRecord(domainId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      type,
      data,
    }: {
      type: string
      data: {
        name: string
        value: string
        ttl: number
        priority?: number
        weight?: number
        port?: number
        comment?: string
      }
    }) => {
      if (!domainId) {
        throw new Error('Domain ID is required')
      }
      return await createDnsRecord(domainId, type, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['dns-records', 'domain', domainId],
      })
      queryClient.invalidateQueries({
        queryKey: ['domain', domainId],
      })
    },
  })
}

/**
 * Hook to update a DNS record
 *
 * @param domainId - The domain ID
 * @returns Mutation hook for updating a DNS record
 */
export function useUpdateDnsRecord(domainId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      recordId,
      type,
      data,
    }: {
      recordId: string
      type: string
      data: {
        name: string
        value: string
        ttl: number
        priority?: number
        weight?: number
        port?: number
        comment?: string
      }
    }) => {
      if (!domainId) {
        throw new Error('Domain ID is required')
      }
      return await updateDnsRecord(domainId, recordId, type, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['dns-records', 'domain', domainId],
      })
      queryClient.invalidateQueries({
        queryKey: ['domain', domainId],
      })
    },
  })
}

/**
 * Hook to delete a DNS record
 *
 * @param domainId - The domain ID
 * @returns Mutation hook for deleting a DNS record
 */
export function useDeleteDnsRecord(domainId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (recordId: string) => {
      if (!domainId) {
        throw new Error('Domain ID is required')
      }
      return await deleteDnsRecord(domainId, recordId)
    },
    onSuccess: async () => {
      // Refetch DNS records list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['dns-records', 'domain', domainId],
      })
      await queryClient.refetchQueries({
        queryKey: ['domain', domainId],
      })
    },
  })
}

/**
 * Hook to update zone file (import)
 *
 * @param domainId - The domain ID
 * @returns Mutation hook for importing zone file
 */
export function useUpdateDomainZone(domainId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (content: string) => {
      if (!domainId) {
        throw new Error('Domain ID is required')
      }
      return await updateDomainZone(domainId, content)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['dns-records', 'domain', domainId],
      })
      queryClient.invalidateQueries({
        queryKey: ['zone', 'domain', domainId],
      })
      queryClient.invalidateQueries({
        queryKey: ['domain', domainId],
      })
    },
  })
}

/**
 * Hook to fetch preset DNS records
 *
 * @param domainId - The domain ID
 * @returns Query hook for fetching preset records
 */
export function usePresetRecords(
  domainId: string | null | undefined,
  preset:
    | 'zoho'
    | 'mailgun'
    | 'outlook'
    | 'protonmail'
    | 'icloud'
    | 'google-workspace'
    | null,
) {
  return useQuery({
    queryKey: ['preset-records', 'domain', domainId, preset],
    queryFn: () => fetchPresetRecords(domainId!, preset!),
    enabled: !!domainId && !!preset,
    staleTime: DEFAULT_STALE_TIME,
  })
}
