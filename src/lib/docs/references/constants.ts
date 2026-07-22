import {
  isDiscoveredReferenceVersion,
  LATEST_EXAMPLES_VERSION,
  REFERENCE_VERSIONS,
} from './reference-versions'

export { LATEST_EXAMPLES_VERSION, REFERENCE_VERSIONS }

/** Docs version slug, including virtual `cloud` (maps to `latest` specs). */
export type ReferenceVersion = (typeof REFERENCE_VERSIONS)[number]

export const REFERENCE_PLATFORMS = [
  'client-web',
  'client-flutter',
  'client-react-native',
  'client-apple',
  'client-android-kotlin',
  'client-android-java',
  'client-graphql',
  'client-rest',
  'server-nodejs',
  'server-python',
  'server-dart',
  'server-php',
  'server-ruby',
  'server-dotnet',
  'server-deno',
  'server-go',
  'server-swift',
  'server-kotlin',
  'server-rust',
  'server-java',
  'server-graphql',
  'server-rest',
] as const

export type ReferencePlatform = (typeof REFERENCE_PLATFORMS)[number]

export const REFERENCE_SERVICES = [
  'account',
  'avatars',
  'databases',
  'tablesDB',
  'documentsDB',
  'vectorsDB',
  'postgresql',
  'mysql',
  'mongo',
  'functions',
  'messaging',
  'health',
  'locale',
  'presences',
  'storage',
  'teams',
  'users',
  'sites',
  'tokens',
  'project',
] as const

export type ReferenceService = (typeof REFERENCE_SERVICES)[number]

export const SERVICE_LABELS: Record<ReferenceService, string> = {
  account: 'Account',
  avatars: 'Avatars',
  databases: 'Databases',
  tablesDB: 'TablesDB',
  documentsDB: 'DocumentsDB',
  vectorsDB: 'VectorsDB',
  postgresql: 'PostgreSQL',
  mysql: 'MySQL',
  mongo: 'MongoDB',
  functions: 'Functions',
  messaging: 'Messaging',
  health: 'Health',
  locale: 'Localization',
  presences: 'Presences',
  storage: 'Storage',
  teams: 'Teams',
  users: 'Users',
  sites: 'Sites',
  tokens: 'Tokens',
  project: 'Project',
}

export const PLATFORM_LABELS: Record<ReferencePlatform, string> = {
  'client-web': 'Web',
  'client-flutter': 'Flutter',
  'client-react-native': 'React Native',
  'client-apple': 'Apple',
  'client-android-kotlin': 'Android (Kotlin)',
  'client-android-java': 'Android (Java)',
  'client-graphql': 'GraphQL',
  'client-rest': 'REST',
  'server-nodejs': 'Node.js',
  'server-python': 'Python',
  'server-dart': 'Dart',
  'server-php': 'PHP',
  'server-ruby': 'Ruby',
  'server-dotnet': '.NET',
  'server-deno': 'Deno',
  'server-go': 'Go',
  'server-swift': 'Swift',
  'server-kotlin': 'Kotlin',
  'server-rust': 'Rust',
  'server-java': 'Java',
  'server-graphql': 'GraphQL',
  'server-rest': 'REST',
}

/** Maps platform slug to CodeBlock language id. */
export const PLATFORM_CODE_LANGUAGES: Record<ReferencePlatform, string> = {
  'client-web': 'javascript',
  'client-flutter': 'dart',
  'client-react-native': 'javascript',
  'client-apple': 'swift',
  'client-android-kotlin': 'kotlin',
  'client-android-java': 'java',
  'client-graphql': 'graphql',
  'client-rest': 'http',
  'server-nodejs': 'javascript',
  'server-python': 'python',
  'server-dart': 'dart',
  'server-php': 'php',
  'server-ruby': 'ruby',
  'server-dotnet': 'csharp',
  'server-deno': 'javascript',
  'server-go': 'go',
  'server-swift': 'swift',
  'server-kotlin': 'kotlin',
  'server-rust': 'rust',
  'server-java': 'java',
  'server-graphql': 'graphql',
  'server-rest': 'http',
}

export function isReferenceVersion(value: string): value is ReferenceVersion {
  return isDiscoveredReferenceVersion(value)
}

export function isReferencePlatform(value: string): value is ReferencePlatform {
  return (REFERENCE_PLATFORMS as readonly string[]).includes(value)
}

export function isReferenceService(value: string): value is ReferenceService {
  return (REFERENCE_SERVICES as readonly string[]).includes(value)
}

export function getSpecMode(platform: ReferencePlatform): 'client' | 'server' | 'console' {
  if (platform.startsWith('client-')) return 'client'
  if (platform.startsWith('server-')) return 'server'
  return 'console'
}

export function getPlatformType(platform: ReferencePlatform): 'CLIENT' | 'SERVER' {
  return platform.startsWith('client-') ? 'CLIENT' : 'SERVER'
}

export function getDefaultReferencePlatform(
  mode: 'client' | 'server',
): ReferencePlatform {
  return mode === 'client' ? 'client-web' : 'server-nodejs'
}

export function getReferencePlatformForMode(
  mode: 'client' | 'server',
  current?: ReferencePlatform,
): ReferencePlatform {
  if (current && getSpecMode(current) === mode) return current
  return getDefaultReferencePlatform(mode)
}

export function resolveSpecVersionDirs(version: ReferenceVersion): {
  specDir: string
  examplesDir: string
} {
  if (version === 'cloud') {
    // Match website references: cloud uses the newest numbered spec release.
    return {
      specDir: LATEST_EXAMPLES_VERSION,
      examplesDir: LATEST_EXAMPLES_VERSION,
    }
  }
  return { specDir: version, examplesDir: version }
}

export function getSpecFilename(
  specDir: string,
  mode: 'client' | 'server' | 'console',
): string {
  if (specDir === 'latest') {
    return `open-api3-latest-${mode}.json`
  }
  return `open-api3-${specDir}-${mode}.json`
}

export function getReferenceOpenApiSpecDownloadFilename(
  version: ReferenceVersion,
  mode: 'client' | 'server',
): string {
  const { specDir } = resolveSpecVersionDirs(version)
  return getSpecFilename(specDir, mode)
}
