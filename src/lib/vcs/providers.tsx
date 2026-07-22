/**
 * VCS provider metadata — single source of truth for the console's Git
 * integration UI (icons, display labels, "open in provider" links, and the
 * OAuth authorize URL). Adding a provider here lights it up everywhere that
 * reads from this module instead of hardcoding "github".
 */

import type { ReactElement } from 'react'

export function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

export function GitLabIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="m23.6004 9.5927-.0337-.0862L20.3.9814a.851.851 0 0 0-.3362-.405.8748.8748 0 0 0-.9997.0539.8748.8748 0 0 0-.29.4399l-2.2055 6.748H7.5375l-2.2057-6.748a.8573.8573 0 0 0-.29-.4412.8748.8748 0 0 0-.9997-.0537.8585.8585 0 0 0-.3362.4049L.4332 9.5015l-.0325.0862a6.0657 6.0657 0 0 0 2.0119 7.0105l.0113.0087.03.0213 4.976 3.7264 2.462 1.8633 1.4995 1.1321a1.0085 1.0085 0 0 0 1.2197 0l1.4995-1.1321 2.4619-1.8633 5.006-3.7489.0125-.01a6.0682 6.0682 0 0 0 2.0094-7.003z" />
    </svg>
  )
}

export type VcsProviderId = 'github' | 'gitlab'

export interface VcsProviderMeta {
  id: VcsProviderId
  label: string
  Icon: (props: { className?: string }) => ReactElement
  /** Web URL for an owner/organization on the provider. */
  baseUrl: (organization: string) => string
}

export const VCS_PROVIDERS: Record<VcsProviderId, VcsProviderMeta> = {
  github: {
    id: 'github',
    label: 'GitHub',
    Icon: GitHubIcon,
    baseUrl: (organization) => `https://github.com/${organization}`,
  },
  gitlab: {
    id: 'gitlab',
    label: 'GitLab',
    Icon: GitLabIcon,
    baseUrl: (organization) => `https://gitlab.com/${organization}`,
  },
}

/**
 * Resolve provider metadata for display (icon, label), defaulting to GitHub
 * for unknown/legacy values. Safe for cosmetic rendering, but never use this
 * to build a clickable link -- an unknown provider would silently produce a
 * real-looking github.com URL. Use {@link getProviderOwnerUrl} for that.
 */
export function getVcsProvider(provider?: string): VcsProviderMeta {
  const id = provider?.toLowerCase() as VcsProviderId | undefined
  return (id && VCS_PROVIDERS[id]) || VCS_PROVIDERS.github
}

/**
 * Resolve provider metadata strictly, returning null for unknown providers
 * instead of falling back to GitHub.
 */
export function getKnownVcsProvider(provider?: string): VcsProviderMeta | null {
  const id = provider?.toLowerCase() as VcsProviderId | undefined
  return (id && VCS_PROVIDERS[id]) || null
}

/**
 * Build the "open in provider" owner/org URL, or null if the provider isn't
 * recognized -- callers should hide the link entirely rather than point it
 * at a fabricated github.com URL for an unknown provider.
 */
export function getProviderOwnerUrl(
  provider: string | undefined,
  organization: string,
): string | null {
  const meta = getKnownVcsProvider(provider)
  return meta ? meta.baseUrl(organization) : null
}

/** Build the OAuth authorize URL the console redirects to for a provider. */
export function buildVcsAuthUrl(opts: {
  endpoint: string
  provider: VcsProviderId
  projectId: string
  successUrl: string
  failureUrl: string
}): string {
  const { endpoint, provider, projectId, successUrl, failureUrl } = opts
  const params = new URLSearchParams({
    project: projectId,
    success: successUrl,
    failure: failureUrl,
    mode: 'admin',
  })
  return `${endpoint}/vcs/${provider}/authorize?${params.toString()}`
}
