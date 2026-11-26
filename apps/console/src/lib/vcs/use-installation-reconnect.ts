/**
 * Resolves everything a surface needs to offer "reconnect this installation":
 * which provider and owner broke, and the authorize URL that repairs it.
 *
 * Reconnecting is a plain redirect to the provider's authorize endpoint in
 * `update` mode. The callback upserts onto the existing installation row (same
 * providerInstallationId, project and provider), so the stored token is
 * replaced in place - the installation never has to be deleted and recreated.
 */

import { useMemo } from 'react'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import {
  useInstallation,
  useProject,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { buildVcsAuthUrl, getKnownVcsProvider } from '@/lib/vcs/providers'

export interface VcsInstallationReconnect {
  /** Raw provider id from the installation, for display. */
  provider?: string
  /** Installation owner, so the user knows which account to re-authorize. */
  organization?: string
  /** Undefined for providers we cannot build an authorize URL for. */
  reconnectUrl?: string
  /**
   * The installation's repository access settings on GitHub. Undefined until
   * the server reports whether the installation is personal, because GitHub
   * uses a different path for accounts and organizations.
   */
  accessUrl?: string
}

export function useVcsInstallationReconnect(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
  /** Where the provider returns to. Defaults to the current URL. */
  returnUrl?: string,
): VcsInstallationReconnect {
  // The installations list is usually already in cache on these routes, so the
  // common case costs nothing.
  const { data: installationsData } = useVcsInstallations(projectId)
  const { project } = useProject(projectId ?? undefined)

  const listed = installationsData?.installations?.find(
    (candidate) => candidate.$id === installationId,
  )

  // The list is paginated, and routes prefetch it at differing limits, so the
  // installation we want is not guaranteed to be on the cached page. Falling
  // back to a lookup by id keeps the reconnect action from silently vanishing;
  // it stays disabled while the list already answered.
  const { data: fetched } = useInstallation(
    projectId,
    listed ? null : installationId,
  )

  const installation = listed ?? fetched

  const provider = installation?.provider
  const organization = installation?.organization
  const providerInstallationId = installation?.providerInstallationId
  const personal = (installation as { personal?: boolean } | undefined)
    ?.personal
  const region = project?.region

  return useMemo(() => {
    const knownProvider = getKnownVcsProvider(provider)
    if (!knownProvider || !projectId || typeof window === 'undefined') {
      return { provider, organization }
    }

    const redirectUrl = returnUrl ?? window.location.href

    const accessUrl =
      knownProvider.id === 'github' &&
      typeof personal === 'boolean' &&
      providerInstallationId
        ? personal
          ? `https://github.com/settings/installations/${providerInstallationId}`
          : `https://github.com/organizations/${organization}/settings/installations/${providerInstallationId}`
        : undefined

    return {
      provider,
      organization,
      accessUrl,
      reconnectUrl: buildVcsAuthUrl({
        endpoint: getApiEndpoint(region),
        provider: knownProvider.id,
        projectId,
        successUrl: redirectUrl,
        failureUrl: redirectUrl,
      }),
    }
  }, [
    provider,
    organization,
    providerInstallationId,
    personal,
    projectId,
    region,
    returnUrl,
  ])
}
