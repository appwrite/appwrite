/**
 * Shared database tier/spec options for create wizard and upgrade specs page.
 * Aligned with Supabase-style compute add-ons.
 */

import { DatabaseType } from '@appwrite.io/console'

export type SpecOption = {
  id: string
  label: string
  cpu: string
  memory: string
  price: string
  comingSoon?: boolean
}

export const TABLE_DB_SPEC_OPTIONS: SpecOption[] = [
  {
    id: 'shared',
    label: 'Shared DB',
    cpu: 'Shared',
    memory: 'Shared',
    price: 'Pay as you go (disk + DB ops)',
  },
  {
    id: 'micro',
    label: 'Micro',
    cpu: '2-core (shared)',
    memory: '1 GB',
    price: '$10/mo',
    comingSoon: true,
  },
  {
    id: 'small',
    label: 'Small',
    cpu: '2-core (shared)',
    memory: '2 GB',
    price: '$15/mo',
    comingSoon: true,
  },
  {
    id: 'medium',
    label: 'Medium',
    cpu: '2-core (shared)',
    memory: '4 GB',
    price: '$60/mo',
    comingSoon: true,
  },
  {
    id: 'large',
    label: 'Large',
    cpu: '2-core (dedicated)',
    memory: '8 GB',
    price: '$110/mo',
    comingSoon: true,
  },
  {
    id: 'xl',
    label: 'XL',
    cpu: '4-core (dedicated)',
    memory: '16 GB',
    price: '$210/mo',
    comingSoon: true,
  },
  {
    id: '2xl',
    label: '2XL',
    cpu: '8-core (dedicated)',
    memory: '32 GB',
    price: '$410/mo',
    comingSoon: true,
  },
  {
    id: '4xl',
    label: '4XL',
    cpu: '16-core (dedicated)',
    memory: '64 GB',
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
