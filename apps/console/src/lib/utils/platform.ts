import type { Models } from '@appwrite.io/console'

/** Union of platform documents returned by `listPlatforms` / `getPlatform`. */
export type ProjectPlatform = Models.PlatformList['platforms'][number]

/** Hostname, bundle ID, package name, or other primary identifier for search/display. */
export function getPlatformIdentifier(
  platform: ProjectPlatform | Record<string, unknown>,
): string {
  const p = platform as Record<string, unknown>
  if (typeof p.hostname === 'string' && p.hostname) return p.hostname
  if (typeof p.applicationId === 'string') return p.applicationId
  if (typeof p.bundleIdentifier === 'string') return p.bundleIdentifier
  if (typeof p.packageName === 'string') return p.packageName
  if (typeof p.packageIdentifierName === 'string')
    return p.packageIdentifierName
  if (typeof p.key === 'string') return p.key
  if (typeof p.identifier === 'string') return p.identifier
  return ''
}

/** Web platform hostname that accepts requests from every website. */
export const WILDCARD_HOSTNAME = '*'

/**
 * Hostname seeded on a new project's first web app.
 *
 * Platform hostnames are the API's origin boundary: it folds them into the
 * allowed-hostname list behind CORS with credentials and the origin check, and
 * a `*` entry matches every host. Session cookies are SameSite=None, so a
 * seeded WILDCARD_HOSTNAME would let any website a signed-in user visits call
 * the project's client API with their session. Loopback keeps local
 * development working without handing that out.
 */
export const LOOPBACK_HOSTNAME = 'localhost'

export function isWildcardHostname(hostname: string): boolean {
  return hostname.trim() === WILDCARD_HOSTNAME
}

export function isWildcardPlatform(platform: ProjectPlatform): boolean {
  return 'hostname' in platform && isWildcardHostname(platform.hostname)
}

export function getPlatformSearchText(platform: ProjectPlatform): string {
  return [platform.name, getPlatformIdentifier(platform), platform.type]
    .filter(Boolean)
    .join(' ')
}

/**
 * Get human-readable display name for a platform type
 */
export function getPlatformDisplayName(platform: string): string {
  const normalized = platform.toLowerCase()

  // Handle Flutter platforms
  if (normalized.startsWith('flutter-')) {
    const basePlatform = normalized.replace('flutter-', '')
    const baseName = getPlatformDisplayName(basePlatform)
    return `Flutter ${baseName}`
  }

  // Handle React Native platforms
  if (normalized.startsWith('react-native-')) {
    const basePlatform = normalized.replace('react-native-', '')
    const baseName = getPlatformDisplayName(basePlatform)
    return `React Native ${baseName}`
  }

  // Handle Apple platforms
  if (normalized.startsWith('apple-')) {
    const appleType = normalized.replace('apple-', '')
    const appleTypeMap: Record<string, string> = {
      ios: 'iOS',
      macos: 'macOS',
      watchos: 'watchOS',
      tvos: 'tvOS',
    }
    return `Apple ${appleTypeMap[appleType] || appleType}`
  }

  // Base platforms
  const nameMap: Record<string, string> = {
    web: 'Web',
    android: 'Android',
    ios: 'iOS',
    apple: 'Apple',
    'apple-ios': 'Apple iOS',
    'apple-macos': 'Apple macOS',
    'apple-watchos': 'Apple watchOS',
    'apple-tvos': 'Apple tvOS',
    linux: 'Linux',
    macos: 'macOS',
    mac: 'macOS',
    windows: 'Windows',
    win: 'Windows',
    unity: 'Unity',
    flutter: 'Flutter',
    'react-native': 'React Native',
  }

  return (
    nameMap[normalized] ||
    normalized.charAt(0).toUpperCase() + normalized.slice(1)
  )
}
