/**
 * Database tier/spec options for create wizard and upgrade specs page.
 * Aligned with Supabase-style compute add-ons.
 */

import type { Models } from '@appwrite.io/console'
import { DatabaseType } from '@/lib/databases/database-type'
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

export const SERVERLESS_DATABASE_SPEC_ID = 'shared' as const

export const TABLE_DB_SPEC_OPTIONS: SpecOption[] = [
  {
    id: SERVERLESS_DATABASE_SPEC_ID,
    label: 'Serverless',
    cpu: '-',
    memory: '-',
    storage: '-',
    connections: '-',
    price: 'No compute fee',
  },
  {
    id: 'micro',
    label: 'Micro',
    cpu: '2-core (shared)',
    memory: '1 GB',
    storage: '-',
    connections: '60',
    price: '$10/mo',
    comingSoon: true,
  },
  {
    id: 'small',
    label: 'Small',
    cpu: '2-core (shared)',
    memory: '2 GB',
    storage: '-',
    connections: '90',
    price: '$15/mo',
    comingSoon: true,
  },
  {
    id: 'medium',
    label: 'Medium',
    cpu: '2-core (shared)',
    memory: '4 GB',
    storage: '-',
    connections: '120',
    price: '$60/mo',
    comingSoon: true,
  },
  {
    id: 'large',
    label: 'Large',
    cpu: '2-core (dedicated)',
    memory: '8 GB',
    storage: '-',
    connections: '160',
    price: '$110/mo',
    comingSoon: true,
  },
  {
    id: 'xl',
    label: 'XL',
    cpu: '4-core (dedicated)',
    memory: '16 GB',
    storage: '-',
    connections: '240',
    price: '$210/mo',
    comingSoon: true,
  },
  {
    id: '2xl',
    label: '2XL',
    cpu: '8-core (dedicated)',
    memory: '32 GB',
    storage: '-',
    connections: '380',
    price: '$410/mo',
    comingSoon: true,
  },
  {
    id: '4xl',
    label: '4XL',
    cpu: '16-core (dedicated)',
    memory: '64 GB',
    storage: '-',
    connections: '480',
    price: '$960/mo',
    comingSoon: true,
  },
]

/** Default tier for Tables DB until the API exposes `spec` on the database model. */
export const DEFAULT_TABLES_MONITOR_SPEC_ID = SERVERLESS_DATABASE_SPEC_ID

/**
 * Effective spec id for monitor / capacity UI. Pass `apiSpecId` when the backend adds it.
 * Documents and vectors databases are modeled as dedicated compute (no serverless tier).
 */
export function getEffectiveDatabaseSpecIdForMonitoring(
  databaseType: DatabaseType,
  apiSpecId?: string | null,
): string {
  if (apiSpecId && apiSpecId.trim() !== '') return apiSpecId.trim()
  if (databaseType === DatabaseType.Tablesdb) return DEFAULT_TABLES_MONITOR_SPEC_ID
  return 'micro'
}

/**
 * Spec ids that mean TablesDB pay-per-operation (no fixed CPU/RAM).
 * Console UI uses `shared`; some APIs still report `serverless`.
 */
export function isServerlessDatabaseSpecId(specId: string): boolean {
  const normalized = specId.trim().toLowerCase()
  return (
    normalized === SERVERLESS_DATABASE_SPEC_ID ||
    normalized === 'serverless' ||
    normalized === ''
  )
}

/** Serverless means Tables DB on the pay-per-operation tier (no fixed CPU/RAM). */
export function isServerlessDatabaseMonitoring(
  databaseType: DatabaseType,
  specId: string,
): boolean {
  return (
    databaseType === DatabaseType.Tablesdb && isServerlessDatabaseSpecId(specId)
  )
}

export function getSpecOptionById(specId: string): SpecOption | undefined {
  return TABLE_DB_SPEC_OPTIONS.find((s) => s.id === specId)
}

/** Human-readable CPU/memory label for a dedicated database specification slug. */
export function resolveDatabaseSpecSummary(
  specs: SpecOption[],
  specSlug: string | null | undefined,
): string | null {
  const slug = specSlug?.trim()
  if (!slug) return null

  const spec =
    specs.find((item) => item.id === slug) ?? getSpecOptionById(slug)
  if (!spec) return slug

  const { cpu, memory } = spec
  if (
    spec.id === SERVERLESS_DATABASE_SPEC_ID ||
    (cpu === 'Serverless' && memory === 'Serverless')
  ) {
    return 'Serverless'
  }
  if (cpu === '-' && memory === '-') return null
  if (cpu === '-') return memory !== '-' ? memory : null
  if (memory === '-') return cpu

  return `${cpu} · ${memory}`
}

function getSpecOptionForSlug(
  specs: SpecOption[],
  specSlug: string | null | undefined,
): SpecOption | undefined {
  const slug = specSlug?.trim()
  if (!slug) return undefined
  return specs.find((item) => item.id === slug) ?? getSpecOptionById(slug)
}

function readSpecMetric(value: string | undefined): string | null {
  const trimmed = value?.trim()
  if (!trimmed || trimmed === '-' || trimmed === 'Serverless') return null
  return trimmed
}

/** Compact CPU label for tight sidebar rows (drops parenthetical qualifiers). */
export function compactDatabaseSpecCpuLabel(cpu: string): string {
  return cpu.replace(/\s*\([^)]*\)/g, '').trim()
}

export type DatabaseSpecDisplayParts = {
  variant: 'serverless' | 'metrics' | 'label'
  cpu: string | null
  memory: string | null
  connections: string | null
  label: string | null
}

/** Structured spec values for compact sidebar display (icons + short values). */
export function resolveDatabaseSpecDisplayParts(
  specs: SpecOption[],
  specSlug: string | null | undefined,
  options?: {
    cpuMillicores?: number
    memoryMb?: number
    fallbackLabel?: string | null
    forceServerless?: boolean
  },
): DatabaseSpecDisplayParts {
  const slug = specSlug?.trim()
  const spec = getSpecOptionForSlug(specs, slug)
  const summary = resolveDatabaseSpecSummary(specs, slug)

  const serverless =
    options?.forceServerless === true ||
    spec?.id === SERVERLESS_DATABASE_SPEC_ID ||
    summary === 'Serverless'

  if (serverless) {
    return {
      variant: 'serverless',
      cpu: null,
      memory: null,
      connections: null,
      label: 'Serverless',
    }
  }

  const cpu =
    options?.cpuMillicores != null && options.cpuMillicores > 0
      ? formatDedicatedSpecCpu(options.cpuMillicores)
      : (() => {
          const value = readSpecMetric(spec?.cpu)
          return value ? compactDatabaseSpecCpuLabel(value) : null
        })()

  const memory =
    options?.memoryMb != null && options.memoryMb > 0
      ? formatDedicatedSpecMemory(options.memoryMb)
      : readSpecMetric(spec?.memory)

  const connections = readSpecMetric(spec?.connections)
  const hasMetrics = !!(cpu || memory || connections)

  if (hasMetrics) {
    return {
      variant: 'metrics',
      cpu,
      memory,
      connections,
      label: null,
    }
  }

  const label =
    options?.fallbackLabel?.trim() ||
    spec?.label?.trim() ||
    slug ||
    null

  return {
    variant: 'label',
    cpu: null,
    memory: null,
    connections: null,
    label,
  }
}

/** Full readable summary for tooltips from structured display parts. */
export function formatDatabaseSpecDisplayTooltip(
  parts: DatabaseSpecDisplayParts,
  connectionsUnitLabel = 'connections',
): string | null {
  if (parts.variant === 'serverless') return parts.label
  if (parts.variant === 'label') return parts.label

  const segments: string[] = []
  if (parts.cpu) segments.push(parts.cpu)
  if (parts.memory) segments.push(parts.memory)
  if (parts.connections) {
    segments.push(
      formatDatabaseSpecConnectionsLabel(parts.connections, connectionsUnitLabel),
    )
  }
  return segments.length > 0 ? segments.join(' · ') : parts.label
}

/** Sidebar spec line: CPU · memory · max connections when available. */
export function resolveDatabaseSpecSidebarSummary(
  specs: SpecOption[],
  specSlug: string | null | undefined,
  connectionsUnitLabel = 'connections',
): string | null {
  const base = resolveDatabaseSpecSummary(specs, specSlug)
  if (!base) return null
  return appendSpecConnectionsToSummary(
    base,
    specs,
    specSlug,
    connectionsUnitLabel,
  )
}

/** Human-readable max-connections segment for spec summaries (e.g. "60 connections"). */
export function formatDatabaseSpecConnectionsLabel(
  count: string,
  connectionsUnitLabel: string,
): string {
  return `${count} ${connectionsUnitLabel}`
}

/** Append max connections to a spec summary when the tier is known. */
export function appendSpecConnectionsToSummary(
  summary: string,
  specs: SpecOption[],
  specSlug: string | null | undefined,
  connectionsUnitLabel = 'connections',
): string {
  if (summary === 'Serverless') return summary

  const spec = getSpecOptionForSlug(specs, specSlug)
  const connections = spec?.connections?.trim()
  if (
    !connections ||
    connections === '-' ||
    connections === 'Serverless'
  ) {
    return summary
  }

  const segment = formatDatabaseSpecConnectionsLabel(
    connections,
    connectionsUnitLabel,
  )
  if (summary.includes(segment) || summary.includes(connections)) {
    return summary
  }

  return `${summary} · ${segment}`
}

export function formatDedicatedSpecCpu(millicores: number): string {
  if (millicores <= 0) return '-'
  const cores = millicores / 1000
  const label =
    Number.isInteger(cores) ? String(cores) : cores.toFixed(1).replace(/\.0$/, '')
  return `${label}-core`
}

export function formatDedicatedSpecMemory(memoryMb: number): string {
  if (memoryMb <= 0) return '-'
  if (memoryMb >= 1024 && memoryMb % 1024 === 0) {
    return `${memoryMb / 1024} GB`
  }
  if (memoryMb >= 1024) {
    return `${(memoryMb / 1024).toFixed(1).replace(/\.0$/, '')} GB`
  }
  return `${memoryMb} MB`
}

export function formatDedicatedSpecStorage(storageGb: number): string {
  if (storageGb <= 0) return '-'
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

/** Map dedicated-database API specification to wizard table rows. */
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

/** Parse a spec connections label into a numeric max, when applicable. */
export function parseDatabaseMaxConnections(
  connections: string | null | undefined,
): number | null {
  if (!connections || connections === '-' || connections === 'Serverless') {
    return null
  }
  const parsed = Number.parseInt(connections, 10)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null
}

/** First enabled spec slug, if any. */
export function getDefaultEnabledSpecId(specs: SpecOption[]): string | null {
  return specs.find((spec) => !spec.comingSoon)?.id ?? null
}

/** True when at least one dedicated (non-serverless) spec can be selected. */
export function hasEnabledDedicatedComputeOptions(specs: SpecOption[]): boolean {
  return specs.some(
    (spec) => !spec.comingSoon && !isServerlessDatabaseSpecId(spec.id),
  )
}

/** True when the spec list includes tiers that are not yet selectable. */
export function hasLockedDatabaseSpecifications(specs: SpecOption[]): boolean {
  return specs.some((s) => s.comingSoon === true)
}
