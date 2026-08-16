import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
/** Regions where dedicated database compute and native SQL engines are available. */
export const DEDICATED_DATABASE_SUPPORTED_REGIONS = ['fra', 'nyc'] as const

export type DedicatedDatabaseSupportedRegion =
  (typeof DEDICATED_DATABASE_SUPPORTED_REGIONS)[number]

export const DEDICATED_DATABASE_REGION_DISPLAY_NAMES: Record<
  DedicatedDatabaseSupportedRegion,
  string
> = {
  fra: 'Frankfurt (FRA)',
  nyc: 'New York (NYC)',
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
  return DEDICATED_DATABASE_SUPPORTED_REGIONS.map(
    (region) => DEDICATED_DATABASE_REGION_DISPLAY_NAMES[region],
  ).join(' and ')
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
