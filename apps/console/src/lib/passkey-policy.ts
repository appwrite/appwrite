import { PlatformType, type Models } from '@appwrite.io/console'

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

export type PasskeyPlatform = Models.PlatformList['platforms'][number]

const LOCALHOST = 'localhost'

/** Second-level labels that sit under a country code, like co.uk or com.au. */
const SUFFIX_LABELS = new Set(['co', 'com', 'net', 'org', 'gov', 'edu', 'ac'])

function webHostnames(platforms: PasskeyPlatform[]): string[] {
  return platforms.flatMap((platform) =>
    platform.type === PlatformType.Web &&
    'hostname' in platform &&
    platform.hostname
      ? [platform.hostname.toLowerCase()]
      : [],
  )
}

export function hasLocalhostPlatform(platforms: PasskeyPlatform[]): boolean {
  return webHostnames(platforms).includes(LOCALHOST)
}

/**
 * Origins the server allows when the policy lists none, mirroring
 * `Appwrite\Auth\Passkey\Ceremony::getOrigins()`.
 */
export function platformPasskeyOrigins(
  rpId: string,
  platforms: PasskeyPlatform[],
): string[] {
  if (rpId === '') return []
  const origins = new Set<string>()
  if (rpId === LOCALHOST) {
    if (hasLocalhostPlatform(platforms)) origins.add('http://localhost')
    return [...origins]
  }
  if (platforms.some((platform) => platform.type === PlatformType.Apple)) {
    origins.add(`https://${rpId}`)
  }
  for (const hostname of webHostnames(platforms)) {
    if (hostname.includes('*')) continue
    if (hostname === rpId || hostname.endsWith(`.${rpId}`)) {
      origins.add(`https://${hostname}`)
    }
  }
  return [...origins]
}

/** Passkeys fail closed: sign-in needs a relying party with at least one origin, or a localhost platform. */
export function isPasskeyReady(
  policy: PasskeyPolicy,
  platforms: PasskeyPlatform[],
): boolean {
  if (hasLocalhostPlatform(platforms)) return true
  if (policy.rpId === '') return false
  return (
    policy.origins.length > 0 ||
    platformPasskeyOrigins(policy.rpId, platforms).length > 0
  )
}

/**
 * Relying party IDs to offer, from the project's web platforms: each parent
 * domain first, since it also covers its subdomains, then the hostname itself.
 * Hostnames under a shared domain such as the Sites domain only offer themselves.
 */
export function suggestPasskeyRpIds(
  platforms: PasskeyPlatform[],
  sharedDomains: string[] = [],
): string[] {
  const parents: string[] = []
  const hosts: string[] = []
  for (const raw of webHostnames(platforms)) {
    const hostname = raw.startsWith('*.') ? raw.slice(2) : raw
    if (hostname.includes('*') || passkeyRpIdError(hostname) !== null) continue
    if (hostname === LOCALHOST) continue
    hosts.push(hostname)
    const shared = sharedDomains.some(
      (domain) => domain && hostname.endsWith(`.${domain}`),
    )
    const labels = hostname.split('.')
    if (shared || labels.length < 3) continue
    const parent = labels.slice(-2).join('.')
    const suffix =
      labels.length >= 3 && SUFFIX_LABELS.has(labels[labels.length - 2])
    parents.push(suffix ? labels.slice(-3).join('.') : parent)
  }
  return [...new Set([...parents, ...hosts])].filter(
    (domain) =>
      !sharedDomains.includes(domain) &&
      !(
        domain.split('.').length === 2 &&
        SUFFIX_LABELS.has(domain.split('.')[0])
      ),
  )
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
