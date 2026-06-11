/**
 * Shared database tier/spec options for create wizard and upgrade specs page.
 * Aligned with Supabase-style compute add-ons.
 */

import type { Models } from '@appwrite.io/console'
import { DatabaseType } from '@appwrite.io/console'
import { formatDedicatedMonthlyPrice } from '@/lib/database-create-pricing'

export type SpecOption = {
  id: string
  label: string
  cpu: string
  memory: string
  storage: string
  /** Max direct database connections for the tier. */
  connections: string
  price: string
  /** Monthly base tier price in USD (when available from the API). */
  priceUsd?: number
  comingSoon?: boolean
}

export const TABLE_DB_SPEC_OPTIONS: SpecOption[] = [
  {
    id: 'shared',
    label: 'Shared',
    cpu: 'Shared',
    memory: 'Shared',
    storage: 'Shared',
    connections: 'Shared',
    price: 'Pay as you go (disk + DB ops)',
  },
  {
    id: 'micro',
    label: 'Micro',
    cpu: '2-core (shared)',
    memory: '1 GB',
    storage: '—',
    connections: '60',
    price: '$10/mo',
    comingSoon: true,
  },
  {
    id: 'small',
    label: 'Small',
    cpu: '2-core (shared)',
    memory: '2 GB',
    storage: '—',
    connections: '90',
    price: '$15/mo',
    comingSoon: true,
  },
  {
    id: 'medium',
    label: 'Medium',
    cpu: '2-core (shared)',
    memory: '4 GB',
    storage: '—',
    connections: '120',
    price: '$60/mo',
    comingSoon: true,
  },
  {
    id: 'large',
    label: 'Large',
    cpu: '2-core (dedicated)',
    memory: '8 GB',
    storage: '—',
    connections: '160',
    price: '$110/mo',
    comingSoon: true,
  },
  {
    id: 'xl',
    label: 'XL',
    cpu: '4-core (dedicated)',
    memory: '16 GB',
    storage: '—',
    connections: '240',
    price: '$210/mo',
    comingSoon: true,
  },
  {
    id: '2xl',
    label: '2XL',
    cpu: '8-core (dedicated)',
    memory: '32 GB',
    storage: '—',
    connections: '380',
    price: '$410/mo',
    comingSoon: true,
  },
  {
    id: '4xl',
    label: '4XL',
    cpu: '16-core (dedicated)',
    memory: '64 GB',
    storage: '—',
    connections: '480',
    price: '$960/mo',
    comingSoon: true,
  },
]

/** Default tier for Tables DB until the API exposes `spec` on the database model. */
export const DEFAULT_TABLES_MONITOR_SPEC_ID = 'shared' as const

/**
 * Effective spec id for monitor / capacity UI. Pass `apiSpecId` when the backend adds it.
 * Documents and vectors databases are modeled as dedicated compute (no shared tier).
 */
export function getEffectiveDatabaseSpecIdForMonitoring(
  databaseType: DatabaseType,
  apiSpecId?: string | null,
): string {
  if (apiSpecId && apiSpecId.trim() !== '') return apiSpecId.trim()
  if (databaseType === DatabaseType.Tablesdb) return DEFAULT_TABLES_MONITOR_SPEC_ID
  return 'micro'
}

/** Serverless here means Tables DB on the shared tier (pay-per-operation, no fixed CPU/RAM). */
export function isServerlessDatabaseMonitoring(
  databaseType: DatabaseType,
  specId: string,
): boolean {
  return (
    databaseType === DatabaseType.Tablesdb && specId === DEFAULT_TABLES_MONITOR_SPEC_ID
  )
}

export function getSpecOptionById(specId: string): SpecOption | undefined {
  return TABLE_DB_SPEC_OPTIONS.find((s) => s.id === specId)
}

export function formatDedicatedSpecCpu(millicores: number): string {
  if (millicores <= 0) return '—'
  const cores = millicores / 1000
  const label =
    Number.isInteger(cores) ? String(cores) : cores.toFixed(1).replace(/\.0$/, '')
  return `${label}-core`
}

export function formatDedicatedSpecMemory(memoryMb: number): string {
  if (memoryMb <= 0) return '—'
  if (memoryMb >= 1024 && memoryMb % 1024 === 0) {
    return `${memoryMb / 1024} GB`
  }
  if (memoryMb >= 1024) {
    return `${(memoryMb / 1024).toFixed(1).replace(/\.0$/, '')} GB`
  }
  return `${memoryMb} MB`
}

export function formatDedicatedSpecStorage(storageGb: number): string {
  if (storageGb <= 0) return '—'
  if (storageGb >= 1024 && storageGb % 1024 === 0) {
    return `${storageGb / 1024} TB`
  }
  if (storageGb >= 1024) {
    return `${(storageGb / 1024).toFixed(1).replace(/\.0$/, '')} TB`
  }
  return `${storageGb} GB`
}

export function formatDedicatedSpecPrice(priceUsd: number): string {
  return formatDedicatedMonthlyPrice(Math.max(0, priceUsd))
}

/** Map Compute API specification to wizard table rows. */
export function dedicatedDatabaseSpecificationToSpecOption(
  spec: Models.DedicatedDatabaseSpecification,
): SpecOption {
  return {
    id: spec.slug,
    label: spec.name,
    cpu: formatDedicatedSpecCpu(spec.cpu),
    memory: formatDedicatedSpecMemory(spec.memory),
    storage: formatDedicatedSpecStorage(spec.includedStorage),
    connections: String(spec.maxConnections),
    price: formatDedicatedSpecPrice(spec.price),
    priceUsd: spec.price,
    comingSoon: !spec.enabled,
  }
}

export function mapDedicatedDatabaseSpecifications(
  specifications: Models.DedicatedDatabaseSpecification[] | undefined,
): SpecOption[] {
  return (specifications ?? []).map(dedicatedDatabaseSpecificationToSpecOption)
}

/** First enabled spec slug, if any. */
export function getDefaultEnabledSpecId(specs: SpecOption[]): string | null {
  return specs.find((spec) => !spec.comingSoon)?.id ?? null
}

/** True when the spec list includes tiers that are not yet selectable. */
export function hasLockedDatabaseSpecifications(specs: SpecOption[]): boolean {
  return specs.some((s) => s.comingSoon === true)
}
