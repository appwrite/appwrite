import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
/**
 * Regions where dedicated database compute and native SQL engines are available.
 * Every Appwrite Cloud region is supported; plan gating is handled separately by
 * `planSupportsDedicatedDatabases`. Regions that are not yet live on Cloud (for
 * example `blr`, `ams`, `lon`) are intentionally absent so the unavailable copy
 * still applies to them once projects can be created there.
 */
export const DEDICATED_DATABASE_SUPPORTED_REGIONS = [
  'fra',
  'nyc',
  'sfo',
  'sgp',
  'syd',
  'tor',
] as const

export type DedicatedDatabaseSupportedRegion =
  (typeof DEDICATED_DATABASE_SUPPORTED_REGIONS)[number]

export const DEDICATED_DATABASE_REGION_DISPLAY_NAMES: Record<
  DedicatedDatabaseSupportedRegion,
  string
> = {
  fra: 'Frankfurt (FRA)',
  nyc: 'New York (NYC)',
  sfo: 'San Francisco (SFO)',
  sgp: 'Singapore (SGP)',
  syd: 'Sydney (SYD)',
  tor: 'Toronto (TOR)',
}

export function normalizeProjectRegion(
  region: string | null | undefined,
): string {
  return coerceTrimmedString(region).toLowerCase()
}

export function projectSupportsDedicatedDatabaseCompute(
  region: string | null | undefined,
): boolean {
  const normalized = normalizeProjectRegion(region)
  if (!normalized || normalized === 'unknown') return false
  return (
    DEDICATED_DATABASE_SUPPORTED_REGIONS as readonly string[]
  ).includes(normalized)
}

export function getDedicatedDatabaseSupportedRegionsLabel(): string {
  const names = DEDICATED_DATABASE_SUPPORTED_REGIONS.map(
    (region) => DEDICATED_DATABASE_REGION_DISPLAY_NAMES[region],
  )
  if (names.length <= 2) return names.join(' and ')
  return `${names.slice(0, -1).join(', ')}, and ${names[names.length - 1]}`
}

export function getDedicatedDatabaseRegionUnavailableDescription(): string {
  return `Coming soon in your project region. Available in ${getDedicatedDatabaseSupportedRegionsLabel()}.`
}

/** User-facing region unavailable copy with `t()` applied to the translatable prefix. */
export function formatDedicatedDatabaseRegionUnavailableDescription(
  t: (text: string) => string,
): string {
  return `${t('Coming soon in your project region. Available in')} ${getDedicatedDatabaseSupportedRegionsLabel()}.`
}
