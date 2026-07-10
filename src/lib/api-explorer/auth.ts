import type {
  ApiExplorerMethod,
  ApiExplorerProjectPlatform,
  ApiExplorerServerAuthState,
} from './types'

function splitAuthLabel(authLabel?: string): string[] {
  if (!authLabel?.trim()) return []
  return authLabel
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

export function getMethodAuthKeys(method: ApiExplorerMethod): string[] {
  const auth = method.xAppwrite?.auth
  if (auth && Object.keys(auth).length > 0) {
    return Object.keys(auth)
  }
  return splitAuthLabel(method.authLabel)
}

/** Client API always runs as a user session; server endpoints may require Session or JWT. */
export function methodRequiresSessionAuthChoice(
  method: ApiExplorerMethod,
  platform: ApiExplorerProjectPlatform,
): boolean {
  if (platform === 'client') return true
  const keys = getMethodAuthKeys(method)
  return keys.includes('Session') || keys.includes('JWT')
}

export function methodRequiresApiKey(
  method: ApiExplorerMethod,
  platform: ApiExplorerProjectPlatform,
): boolean {
  if (platform !== 'server') return false
  return getMethodAuthKeys(method).includes('Key')
}

function allowsApiKeyInSecurity(method: ApiExplorerMethod): boolean {
  return Boolean(method.security?.[0]?.Key)
}

/** Server endpoints that require or accept an API key. */
export function methodSupportsServerApiKey(
  method: ApiExplorerMethod,
  platform: ApiExplorerProjectPlatform,
): boolean {
  if (platform !== 'server') return false
  if (methodRequiresApiKey(method, platform)) return true
  return allowsApiKeyInSecurity(method) && !getMethodAuthKeys(method).includes('Key')
}

/**
 * Server endpoints that do not accept an API key use Guest/User JWT auth
 * (same controls as the client explorer).
 */
export function methodSupportsServerSessionAuth(
  method: ApiExplorerMethod,
  platform: ApiExplorerProjectPlatform,
): boolean {
  if (platform !== 'server') return false
  return !methodSupportsServerApiKey(method, platform)
}

/** Whether the explorer should show Guest/User JWT controls for this method. */
export function methodUsesSessionAuthChoice(
  method: ApiExplorerMethod,
  platform: ApiExplorerProjectPlatform,
): boolean {
  if (platform === 'client') {
    return methodRequiresSessionAuthChoice(method, platform)
  }
  return methodSupportsServerSessionAuth(method, platform)
}

export function getMethodRequiredScopes(method: ApiExplorerMethod): string[] {
  if (!method.scope?.trim()) return []
  return method.scope
    .split(',')
    .map((scope) => scope.trim())
    .filter(Boolean)
}

export function resolveServerAuthApiKey(
  serverAuth: ApiExplorerServerAuthState,
): string | undefined {
  const key =
    serverAuth.mode === 'manual'
      ? serverAuth.manualApiKey
      : serverAuth.ephemeralApiKey
  const trimmed = key.trim()
  return trimmed || undefined
}

export function scopesIncludeRequired(
  keyScopes: string[],
  requiredScopes: string[],
): boolean {
  if (requiredScopes.length === 0) return true
  const keySet = new Set(keyScopes)
  return requiredScopes.every((scope) => keySet.has(scope))
}

export function getScopesMissingFromKey(
  keyScopes: string[],
  requiredScopes: string[],
): string[] {
  const keySet = new Set(keyScopes)
  return requiredScopes.filter((scope) => !keySet.has(scope))
}

export function mergeUniqueScopes(...scopeLists: string[][]): string[] {
  return [...new Set(scopeLists.flat())]
}

export type {
  ApiExplorerClientAuthState,
  ApiExplorerRequestAuth,
  ApiExplorerServerAuthMode,
  ApiExplorerServerAuthState,
} from './types'
