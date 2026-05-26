import type { Models } from '@appwrite.io/console'

export function findGitHubIdentity(
  identities: Models.Identity[] | undefined,
): Models.Identity | undefined {
  return identities?.find(
    (identity) => identity.provider?.toLowerCase() === 'github',
  )
}

/**
 * Resolves a GitHub login from an identity's noreply email, with account name as fallback.
 */
export function getGitHubUsername(
  identity: Models.Identity | undefined,
  accountName?: string,
): string | undefined {
  if (!identity) return undefined

  const email = identity.providerEmail?.trim()
  if (email) {
    const noreplyMatch = email.match(
      /^(?:\d+\+)?([^@]+)@users\.noreply\.github\.com$/i,
    )
    if (noreplyMatch?.[1]) return noreplyMatch[1]
  }

  const name = accountName?.trim()
  return name || undefined
}
