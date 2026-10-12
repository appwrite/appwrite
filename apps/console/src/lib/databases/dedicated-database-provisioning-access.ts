import { redirect } from '@tanstack/react-router'
import { isDedicatedDatabaseProvisioning } from '@/lib/databases/dedicated-database-status'

const RESTRICTED_DATABASE_LEVEL_SEGMENTS = new Set([
  'monitor',
  'roles',
  'backups',
  'settings',
  'connections',
])

const RESTRICTED_RESOURCE_LEVEL_SEGMENTS = new Set([
  'monitor',
  'backups',
  'db-settings',
  'db-security',
])

function pathSegmentsAfterDatabaseId(pathname: string): string[] {
  const parts = pathname.split('/').filter(Boolean)
  const databasesIndex = parts.indexOf('databases')
  if (databasesIndex < 0) return []
  return parts.slice(databasesIndex + 3)
}

/**
 * Credentials, monitor, connections, roles, backups, and database settings
 * (not table settings).
 */
export function isDedicatedDatabaseProvisioningRestrictedPath(
  pathname: string,
): boolean {
  const rest = pathSegmentsAfterDatabaseId(pathname)
  if (rest.length === 0) return false

  const [first, , third] = rest
  if (first && RESTRICTED_DATABASE_LEVEL_SEGMENTS.has(first)) return true
  if (
    (first === 'tables' || first === 'collections') &&
    third &&
    RESTRICTED_RESOURCE_LEVEL_SEGMENTS.has(third)
  ) {
    return true
  }
  return false
}

export function shouldRedirectDedicatedDatabaseProvisioning(
  status: string | null | undefined,
  pathname: string,
): boolean {
  return (
    isDedicatedDatabaseProvisioning(status) &&
    isDedicatedDatabaseProvisioningRestrictedPath(pathname)
  )
}

export function throwRedirectIfDedicatedDatabaseProvisioning(
  status: string | null | undefined,
  pathname: string,
  fallback: {
    to: string
    params: Record<string, string>
    search?: Record<string, never> | Record<string, unknown>
  },
): void {
  if (!shouldRedirectDedicatedDatabaseProvisioning(status, pathname)) return
  throw redirect({
    to: fallback.to as never,
    params: fallback.params as never,
    ...(fallback.search !== undefined ? { search: fallback.search } : {}),
    replace: true,
  })
}
