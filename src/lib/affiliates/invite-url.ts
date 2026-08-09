import { getApiEndpoint } from '@/lib/appwrite/sdk'

/** Short share path on the console domain: `/i/{linkId}`. */
export const AFFILIATE_INVITE_PATH_PREFIX = '/i'

/** Appwrite CustomId: alphanumeric start, then a-z A-Z 0-9 . - _; max 36. */
const AFFILIATE_LINK_ID_PATTERN = /^[a-zA-Z0-9][a-zA-Z0-9._-]{0,35}$/

export function isValidAffiliateLinkId(linkId: string): boolean {
  return AFFILIATE_LINK_ID_PATTERN.test(linkId)
}

/**
 * Backend invite URL that records the click, sets attribution, and sends users to signup.
 * Uses `getApiEndpoint()` so the target matches the active console API (env or debug override).
 */
export function buildAffiliateApiInviteUrl(linkId: string): string {
  const endpoint = getApiEndpoint().replace(/\/$/, '')
  return `${endpoint}/affiliates/invite/${encodeURIComponent(linkId)}`
}

/** Relative short invite path on this app. */
export function buildAffiliateInvitePath(linkId: string): string {
  return `${AFFILIATE_INVITE_PATH_PREFIX}/${encodeURIComponent(linkId)}`
}

/**
 * Shareable invite URL on the console/app domain.
 * Resolves via `/i/{linkId}` → API invite endpoint.
 */
export function buildAffiliateInviteUrl(linkId: string): string {
  const path = buildAffiliateInvitePath(linkId)
  if (typeof window !== 'undefined') {
    return `${window.location.origin}${path}`
  }
  return path
}
