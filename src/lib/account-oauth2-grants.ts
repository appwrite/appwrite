import type { Models } from '@appwrite.io/console'

export const OAUTH2_IDENTITY_PREFIX = 'oauth2:'

export function isOAuth2GrantIdentity(identity: Models.Identity): boolean {
  return identity.provider?.startsWith(OAUTH2_IDENTITY_PREFIX) ?? false
}

export function getOAuth2AppIdFromIdentity(
  identity: Models.Identity,
): string | null {
  if (!isOAuth2GrantIdentity(identity)) return null
  return identity.provider.slice(OAUTH2_IDENTITY_PREFIX.length)
}

export function getSignInIdentities(
  identities: Models.Identity[],
): Models.Identity[] {
  return identities.filter((identity) => !isOAuth2GrantIdentity(identity))
}

export function getOAuth2GrantIdentities(
  identities: Models.Identity[],
): Models.Identity[] {
  return identities.filter(isOAuth2GrantIdentity)
}
