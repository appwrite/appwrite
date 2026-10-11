import type { Models } from '@appwrite.io/console'

/** The server rejects more origins than this. */
export const MAX_PASSKEY_ORIGINS = 10

/** The server rejects a longer relying party ID (the maximum length of a domain name). */
export const MAX_PASSKEY_RP_ID_LENGTH = 253

const IPV4 = /^\d{1,3}(\.\d{1,3}){3}$/

export type PasskeyPolicy = Pick<Models.PolicyPasskey, 'rpId' | 'origins'>

export const DEFAULT_PASSKEY_POLICY: PasskeyPolicy = {
  rpId: '',
  origins: [],
}

export function parsePasskeyPolicy(
  policy: Models.PolicyPasskey | undefined,
): PasskeyPolicy {
  if (!policy) return DEFAULT_PASSKEY_POLICY
  return {
    rpId: policy.rpId,
    origins: policy.origins,
  }
}

/** Passkeys fail closed: sign-in needs a relying party and at least one origin. */
export function isPasskeyPolicyConfigured(policy: PasskeyPolicy): boolean {
  return policy.rpId !== '' && policy.origins.length > 0
}

/** Matches the server's normalisation closely enough to compare for changes. */
export function normalizePasskeyOrigin(origin: string): string {
  const trimmed = origin.trim()
  return trimmed.endsWith('/') ? trimmed.slice(0, -1) : trimmed
}

/**
 * Light client-side checks for obvious mistakes. The server is authoritative
 * (public suffixes, port normalisation), so anything subtle is left to it.
 */
export function passkeyRpIdError(rpId: string): string | null {
  if (rpId === '' || rpId === 'localhost') return null
  if (rpId.includes('://') || rpId.includes('/')) {
    return 'Enter a domain without a scheme or path, like example.com.'
  }
  if (IPV4.test(rpId) || rpId.includes('[') || rpId.split(':').length > 2) {
    return 'The relying party ID must be a domain, not an IP address.'
  }
  if (rpId !== rpId.toLowerCase()) {
    return 'The relying party ID must be lowercase.'
  }
  if (rpId.includes(':')) {
    return 'The relying party ID cannot include a port.'
  }
  if (!rpId.includes('.') || rpId.startsWith('.') || rpId.endsWith('.')) {
    return 'Enter a domain like example.com, or localhost.'
  }
  return null
}

export function passkeyOriginError(
  origin: string,
  rpId: string,
): string | null {
  let url: URL
  try {
    url = new URL(origin)
  } catch {
    return 'Enter a full origin, like https://example.com.'
  }
  const isLocalhost = url.hostname === 'localhost'
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && isLocalhost)) {
    return 'Origins must use https://, or http:// on localhost.'
  }
  if (url.username || url.password) {
    return 'Origins cannot include credentials.'
  }
  if (url.pathname !== '/' || url.search || url.hash) {
    return 'Origins cannot include a path, query or fragment.'
  }
  if (
    rpId !== '' &&
    url.hostname !== rpId &&
    !url.hostname.endsWith(`.${rpId}`)
  ) {
    return 'Origins must be on the relying party ID or one of its subdomains.'
  }
  return null
}
