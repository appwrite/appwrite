import { PlatformType, type Models } from '@appwrite.io/console'
import { parse } from 'tldts'

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

function webHostnames(platforms: PasskeyPlatform[]): string[] {
  return platforms.flatMap((platform) =>
    platform.type === PlatformType.Web &&
    'hostname' in platform &&
    platform.hostname
      ? [platform.hostname.toLowerCase()]
      : [],
  )
}

const FINGERPRINT_BYTES = 32

const HEX_DIGITS = '0123456789ABCDEF'

/** Whether a value is a SHA-256 certificate fingerprint, as Play Console and `keytool` print it. */
export function isFingerprint(value: string): boolean {
  const bytes = value.split(':')
  return (
    bytes.length === FINGERPRINT_BYTES &&
    bytes.every(
      (byte) =>
        byte.length === 2 &&
        [...byte.toUpperCase()].every((digit) => HEX_DIGITS.includes(digit)),
    )
  )
}

/**
 * The WebAuthn origin an Android app signed with this certificate signs in
 * from, mirroring `Appwrite\Auth\Passkey\Ceremony`.
 */
export function androidPasskeyOrigin(fingerprint: string): string {
  const bytes = fingerprint
    .split(':')
    .map((byte) => String.fromCharCode(parseInt(byte, 16)))
    .join('')
  const hash = btoa(bytes)
    .replaceAll('+', '-')
    .replaceAll('/', '_')
    .replaceAll('=', '')
  return `android:apk-key-hash:${hash}`
}

function androidFingerprints(platforms: PasskeyPlatform[]): string[] {
  return platforms.flatMap((platform) =>
    platform.type === PlatformType.Android && 'fingerprints' in platform
      ? (platform.fingerprints ?? [])
      : [],
  )
}

function hasLocalhostPlatform(platforms: PasskeyPlatform[]): boolean {
  return webHostnames(platforms).includes(LOCALHOST)
}

/**
 * Where passkeys work for a relying party ID, mirroring
 * `Appwrite\Auth\Passkey\Ceremony::getOrigins()`: Android apps by their
 * signing certificates, web platforms on the domain (wildcards included), the
 * domain itself for Apple apps, and localhost when it is a web platform. Previews an unsaved domain; the saved
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
    for (const fingerprint of androidFingerprints(platforms)) {
      origins.add(androidPasskeyOrigin(fingerprint))
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
 * Relying party IDs to offer, from the project's web platforms: each
 * registrable domain first, since it also covers its subdomains, then the
 * hostname itself. Like the server, public suffixes (including private ones
 * such as github.io) are never offered, and hostnames under a shared domain
 * such as the Sites domain only offer themselves.
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
    const { domain, isIcann, isPrivate } = parse(hostname, {
      allowPrivateDomains: true,
    })
    if (!domain || !(isIcann || isPrivate)) continue
    hosts.push(hostname)
    const shared = sharedDomains.some(
      (shared) => shared && hostname.endsWith(`.${shared}`),
    )
    if (!shared && domain !== hostname) parents.push(domain)
  }
  return [...new Set([...parents, ...hosts])].filter(
    (domain) => !sharedDomains.includes(domain),
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
