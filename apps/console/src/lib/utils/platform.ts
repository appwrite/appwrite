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
