import { PlatformType, type Models } from '@appwrite.io/console'

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

function hasLocalhostPlatform(platforms: PasskeyPlatform[]): boolean {
  return webHostnames(platforms).includes(LOCALHOST)
}

/**
 * Where passkeys work for a relying party ID, mirroring
 * `Appwrite\Auth\Passkey\Ceremony::getOrigins()`: web platforms on the
 * domain (wildcards included), the domain itself for Apple apps, and
 * localhost when it is a web platform. Previews an unsaved domain; the saved
 * policy carries the server's own list.
 */
export function platformPasskeyOrigins(
  rpId: string,
  platforms: PasskeyPlatform[],
): string[] {
  const origins = new Set<string>()
  if (rpId !== '' && rpId !== LOCALHOST) {
    if (platforms.some((platform) => platform.type === PlatformType.Apple)) {
      origins.add(`https://${rpId}`)
    }
    for (const hostname of webHostnames(platforms)) {
      const domain = hostname.startsWith('*.') ? hostname.slice(2) : hostname
      if (domain.includes('*')) continue
      if (domain === rpId || domain.endsWith(`.${rpId}`)) {
        origins.add(`https://${hostname}`)
      }
    }
  }
  if (hasLocalhostPlatform(platforms)) {
    origins.add('http://localhost')
    origins.add('https://localhost')
  }
  return [...origins]
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
