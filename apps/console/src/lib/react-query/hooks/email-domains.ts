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

export function organizationEmailDomainsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['emailDomains', 'organization', organizationId],
    queryFn: () => fetchOrganizationEmailDomains(organizationId!),
    enabled: !!organizationId,
    staleTime: LONG_STALE_TIME,
  })
}

export function useOrganizationEmailDomains(
  organizationId: string | null | undefined,
) {
  const { data, isLoading } = useQuery(
    organizationEmailDomainsQueryOptions(organizationId),
  )
  return { domains: data?.domains ?? [], isLoading }
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
  const { data, isLoading } = useQuery(
    organizationSSOProvidersQueryOptions(organizationId),
  )
  return { providers: data?.providers ?? [], isLoading }
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
