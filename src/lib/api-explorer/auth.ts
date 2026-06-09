import type {
  ApiExplorerMethod,
  ApiExplorerProjectPlatform,
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

export type { ApiExplorerRequestAuth } from './types'
