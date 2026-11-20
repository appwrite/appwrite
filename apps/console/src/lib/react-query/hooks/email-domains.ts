import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import type {
  OidcProviderParams,
  SSODomain,
  SSODomainList,
  SSOProviderList,
} from '@/lib/appwrite/team-sso'
import { LONG_STALE_TIME } from './constants'

/** An organization's single sign-on domains. */
export async function fetchOrganizationEmailDomains(
  organizationId: string,
): Promise<SSODomainList> {
  if (!organizationId) {
    throw new Error('Organization ID is required')
  }
  return sdk.forConsole.teamSSO.listDomains(organizationId)
}

const UNVERIFIED_DOMAIN_POLL_MS = 30_000

export function organizationEmailDomainsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['emailDomains', 'organization', organizationId],
    queryFn: () => fetchOrganizationEmailDomains(organizationId!),
    enabled: !!organizationId,
    staleTime: LONG_STALE_TIME,
    // The server keeps checking unverified domains on its own; pick that up
    // while someone waits on the page for DNS to propagate.
    refetchInterval: (query) =>
      query.state.data?.domains.some((domain) => domain.status !== 'verified')
        ? UNVERIFIED_DOMAIN_POLL_MS
        : false,
  })
}

export function useOrganizationEmailDomains(
  organizationId: string | null | undefined,
) {
  const { data, isLoading, isError, isSuccess, refetch, isFetching } = useQuery(
    organizationEmailDomainsQueryOptions(organizationId),
  )
  // `isSuccess` tells an empty list from one that has not loaded or failed.
  return {
    domains: data?.domains ?? [],
    isLoading,
    isError,
    isSuccess,
    isFetching,
    refetch,
  }
}

function useRefreshEmailDomains(organizationId: string | null | undefined) {
  const queryClient = useQueryClient()
  return () =>
    queryClient.invalidateQueries({
      queryKey: ['emailDomains', 'organization', organizationId],
    })
}

function requireOrganization(organizationId: string | null | undefined) {
  if (!organizationId) {
    throw new Error('Organization ID is required')
  }
  return organizationId
}

export function useCreateOrganizationEmailDomain(
  organizationId: string | null | undefined,
) {
  const refresh = useRefreshEmailDomains(organizationId)
  return useMutation({
    mutationFn: ({ domain }: { domain: string }) =>
      sdk.forConsole.teamSSO.createDomain(
        requireOrganization(organizationId),
        domain.trim().toLowerCase(),
      ),
    onSuccess: refresh,
  })
}

/** Checks the TXT record; resolves with the domain, verified, or rejects. */
export function useVerifyOrganizationEmailDomain(
  organizationId: string | null | undefined,
) {
  const refresh = useRefreshEmailDomains(organizationId)
  return useMutation({
    mutationFn: (domainId: string): Promise<SSODomain> =>
      sdk.forConsole.teamSSO.updateDomainStatus(
        requireOrganization(organizationId),
        domainId,
      ),
    onSettled: refresh,
  })
}

export function useDeleteOrganizationEmailDomain(
  organizationId: string | null | undefined,
) {
  const refresh = useRefreshEmailDomains(organizationId)
  return useMutation({
    mutationFn: (domainId: string) =>
      sdk.forConsole.teamSSO.deleteDomain(
        requireOrganization(organizationId),
        domainId,
      ),
    onSuccess: refresh,
  })
}

/** An organization's identity providers for single sign-on. */
export function organizationSSOProvidersQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['ssoProviders', 'organization', organizationId],
    queryFn: (): Promise<SSOProviderList> =>
      sdk.forConsole.teamSSO.listProviders(organizationId!),
    enabled: !!organizationId,
    staleTime: LONG_STALE_TIME,
  })
}

export function useOrganizationSSOProviders(
  organizationId: string | null | undefined,
) {
  const { data, isLoading, isError, isSuccess, refetch, isFetching } = useQuery(
    organizationSSOProvidersQueryOptions(organizationId),
  )
  // `isSuccess` tells an empty list from one that has not loaded or failed.
  return {
    providers: data?.providers ?? [],
    isLoading,
    isError,
    isSuccess,
    isFetching,
    refetch,
  }
}

function useRefreshSSOProviders(organizationId: string | null | undefined) {
  const queryClient = useQueryClient()
  return () =>
    queryClient.invalidateQueries({
      queryKey: ['ssoProviders', 'organization', organizationId],
    })
}

/** Creates the provider, or updates it when `providerId` is given. */
export function useSaveOrganizationSSOProvider(
  organizationId: string | null | undefined,
) {
  const refresh = useRefreshSSOProviders(organizationId)
  return useMutation({
    mutationFn: ({
      providerId,
      params,
    }: {
      providerId?: string
      params: OidcProviderParams
    }) =>
      providerId
        ? sdk.forConsole.teamSSO.updateOidcProvider(
            requireOrganization(organizationId),
            providerId,
            params,
          )
        : sdk.forConsole.teamSSO.createOidcProvider(
            requireOrganization(organizationId),
            params,
          ),
    onSuccess: refresh,
  })
}

export function useDeleteOrganizationSSOProvider(
  organizationId: string | null | undefined,
) {
  const refresh = useRefreshSSOProviders(organizationId)
  return useMutation({
    mutationFn: (providerId: string) =>
      sdk.forConsole.teamSSO.deleteProvider(
        requireOrganization(organizationId),
        providerId,
      ),
    onSuccess: refresh,
  })
}

/**
 * Turns Require SSO on or off for an organization. The organization query carries
 * `ssoRequired`, so it is updated in place and refetched.
 */
export function useRequireOrganizationSSO(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (required: boolean) =>
      sdk.forConsole.teamSSO.updateRequired(
        requireOrganization(organizationId),
        required,
      ),
    onSuccess: (organization) => {
      queryClient.setQueryData(
        ['organization', organizationId],
        (current: object | null | undefined) =>
          current
            ? { ...current, ssoRequired: organization.ssoRequired }
            : current,
      )
    },
    onSettled: () =>
      queryClient.invalidateQueries({
        queryKey: ['organization', organizationId],
      }),
  })
}
