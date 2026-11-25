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
  TeamSSO,
} from '@/lib/appwrite/team-sso'
import {
  ssoQueryKey,
  ssoTargetTeamId,
  ssoTeamQueryKey,
  type SSOTarget,
} from '@/lib/sso-target'
import { LONG_STALE_TIME } from './constants'
import { organizationQueryOptions } from './organizations'
import { useTeam } from './users'

/**
 * The single sign-on client for `target`: the Console's for an organization,
 * the project's (admin mode, the project's ID in `X-Appwrite-Project`) for a
 * team of a project.
 */
export function teamSSOFor(target: SSOTarget): TeamSSO {
  return target.scope === 'organization'
    ? sdk.forConsole.teamSSO
    : sdk.forProject(target.projectId).teamSSO
}

/** The team the routes are called on; refuses a target with no ID. */
function requireTeamId(target: SSOTarget): string {
  const teamId = ssoTargetTeamId(target)
  if (!teamId) {
    throw new Error(
      target.scope === 'organization'
        ? 'Organization ID is required'
        : 'Team ID is required',
    )
  }
  return teamId
}

function organizationTarget(
  organizationId: string | null | undefined,
): SSOTarget | null {
  return organizationId ? { scope: 'organization', organizationId } : null
}

/** The single sign-on domains of an organization, or of a team of a project. */
export async function fetchSSODomains(
  target: SSOTarget,
): Promise<SSODomainList> {
  return teamSSOFor(target).listDomains(requireTeamId(target))
}

const UNVERIFIED_DOMAIN_POLL_MS = 30_000

export function ssoDomainsQueryOptions(target: SSOTarget | null) {
  return queryOptions({
    queryKey: ssoQueryKey('emailDomains', target),
    queryFn: () => fetchSSODomains(target!),
    enabled: !!target && !!ssoTargetTeamId(target),
    staleTime: LONG_STALE_TIME,
    // The server keeps checking unverified domains on its own; pick that up
    // while someone waits on the page for DNS to propagate.
    refetchInterval: (query) =>
      query.state.data?.domains.some((domain) => domain.status !== 'verified')
        ? UNVERIFIED_DOMAIN_POLL_MS
        : false,
  })
}

export function useSSODomains(target: SSOTarget | null) {
  const { data, isLoading, isError, isSuccess, refetch, isFetching } = useQuery(
    ssoDomainsQueryOptions(target),
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

/** An organization's single sign-on domains. */
export function organizationEmailDomainsQueryOptions(
  organizationId: string | null | undefined,
) {
  return ssoDomainsQueryOptions(organizationTarget(organizationId))
}

export function useOrganizationEmailDomains(
  organizationId: string | null | undefined,
) {
  return useSSODomains(organizationTarget(organizationId))
}

function useRefreshSSODomains(target: SSOTarget) {
  const queryClient = useQueryClient()
  return () =>
    queryClient.invalidateQueries({
      queryKey: ssoQueryKey('emailDomains', target),
    })
}

export function useCreateSSODomain(target: SSOTarget) {
  const refresh = useRefreshSSODomains(target)
  return useMutation({
    mutationFn: ({ domain }: { domain: string }) =>
      teamSSOFor(target).createDomain(
        requireTeamId(target),
        domain.trim().toLowerCase(),
      ),
    onSuccess: refresh,
  })
}

/** Checks the TXT record; resolves with the domain, verified, or rejects. */
export function useVerifySSODomain(target: SSOTarget) {
  const refresh = useRefreshSSODomains(target)
  return useMutation({
    mutationFn: (domainId: string): Promise<SSODomain> =>
      teamSSOFor(target).updateDomainStatus(requireTeamId(target), domainId),
    onSettled: refresh,
  })
}

export function useDeleteSSODomain(target: SSOTarget) {
  const refresh = useRefreshSSODomains(target)
  return useMutation({
    mutationFn: (domainId: string) =>
      teamSSOFor(target).deleteDomain(requireTeamId(target), domainId),
    onSuccess: refresh,
  })
}

/** The identity providers of an organization, or of a team of a project. */
export function ssoProvidersQueryOptions(target: SSOTarget | null) {
  return queryOptions({
    queryKey: ssoQueryKey('ssoProviders', target),
    queryFn: (): Promise<SSOProviderList> =>
      teamSSOFor(target!).listProviders(requireTeamId(target!)),
    enabled: !!target && !!ssoTargetTeamId(target),
    staleTime: LONG_STALE_TIME,
  })
}

export function useSSOProviders(target: SSOTarget | null) {
  const { data, isLoading, isError, isSuccess, refetch, isFetching } = useQuery(
    ssoProvidersQueryOptions(target),
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

/** An organization's identity providers for single sign-on. */
export function organizationSSOProvidersQueryOptions(
  organizationId: string | null | undefined,
) {
  return ssoProvidersQueryOptions(organizationTarget(organizationId))
}

export function useOrganizationSSOProviders(
  organizationId: string | null | undefined,
) {
  return useSSOProviders(organizationTarget(organizationId))
}

function useRefreshSSOProviders(target: SSOTarget) {
  const queryClient = useQueryClient()
  return () =>
    queryClient.invalidateQueries({
      queryKey: ssoQueryKey('ssoProviders', target),
    })
}

/** Creates the provider, or updates it when `providerId` is given. */
export function useSaveSSOProvider(target: SSOTarget) {
  const refresh = useRefreshSSOProviders(target)
  return useMutation({
    mutationFn: ({
      providerId,
      params,
    }: {
      providerId?: string
      params: OidcProviderParams
    }) =>
      providerId
        ? teamSSOFor(target).updateOidcProvider(
            requireTeamId(target),
            providerId,
            params,
          )
        : teamSSOFor(target).createOidcProvider(requireTeamId(target), params),
    onSuccess: refresh,
  })
}

export function useDeleteSSOProvider(target: SSOTarget) {
  const refresh = useRefreshSSOProviders(target)
  return useMutation({
    mutationFn: (providerId: string) =>
      teamSSOFor(target).deleteProvider(requireTeamId(target), providerId),
    onSuccess: refresh,
  })
}

/**
 * Whether `target` requires single sign-on: `ssoRequired` on the organization,
 * or on the team of the project.
 */
export function useSSORequired(target: SSOTarget): boolean {
  const { data: organization } = useQuery(
    organizationQueryOptions(
      target.scope === 'organization' ? target.organizationId : null,
    ),
  )
  const { data: team } = useTeam(
    target.scope === 'project' ? target.projectId : null,
    target.scope === 'project' ? target.teamId : null,
  )
  const document = target.scope === 'organization' ? organization : team
  return (
    (document as { ssoRequired?: boolean } | null | undefined)?.ssoRequired ===
    true
  )
}

/**
 * Turns Require SSO on or off. The organization's query, or the team's, carries
 * `ssoRequired`, so it is updated in place and refetched.
 */
export function useRequireSSO(target: SSOTarget) {
  const queryClient = useQueryClient()
  const documentKey = ssoTeamQueryKey(target)
  return useMutation({
    mutationFn: (required: boolean) =>
      teamSSOFor(target).updateRequired(requireTeamId(target), required),
    onSuccess: (team) => {
      queryClient.setQueryData(
        documentKey,
        (current: object | null | undefined) =>
          current ? { ...current, ssoRequired: team.ssoRequired } : current,
      )
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: documentKey }),
  })
}
