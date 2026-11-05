import {
  DatabaseType,
  coerceDatabaseType,
  engineFromDatabaseTypeValue,
  isNativeDatabaseTypeValue,
} from '@/lib/databases/database-type'
import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
import {
  formatDedicatedSpecCpu,
  formatDedicatedSpecMemory,
  isServerlessDatabaseSpecId,
  type SpecOption,
  SERVERLESS_DATABASE_SPEC_ID,
} from '@/lib/database-specs'
import type { Models } from '@appwrite.io/console'

export type DatabaseComputeHints = {
  $id?: string
  name?: string | null
  databaseType?: DatabaseType | string | null
  /** Product `Models.Database.status` lifecycle string, or dedicated status. */
  status?: unknown
  /** Present (including 0) when the product DB has dedicated compute backing. */
  replicas?: number | null
  /** Spec slug when the product API returns it (may be untyped on Models.Database). */
  specification?: string | null
}

export type DedicatedComputeHints = {
  $id?: string
  specification?: string | null
  status?: string | null
  replicas?: number | null
  engine?: string | null
  api?: string | null
  cpu?: number | null
  memory?: number | null
} | null

/** Fields the databases list card needs from a dedicated (or synthesized) row. */
export type DedicatedDatabaseCardSource = Pick<
  Models.DedicatedDatabase,
  '$id' | 'status' | 'replicas' | 'specification' | 'engine' | 'api'
> &
  Partial<Pick<Models.DedicatedDatabase, 'name' | 'cpu' | 'memory'>>

/** Lifecycle status string from product or dedicated payloads (null when serverless). */
export function readDatabaseLifecycleStatus(status: unknown): string | null {
  if (typeof status !== 'string') return null
  const trimmed = status.trim()
  return trimmed ? trimmed : null
}

/** Spec slug from a product or dedicated payload. */
export function readDatabaseSpecification(
  value: unknown,
): string | null {
  const trimmed = coerceTrimmedString(value)
  return trimmed ? trimmed : null
}

/**
 * Engine for native dedicated compute lookups only.
 * Product DBs must use `{ type: 'product', dbKind }` - never engine probing.
 */
export function productDedicatedEngineHints(
  databaseType: string | null | undefined,
): string[] {
  const nativeEngine = engineFromDatabaseTypeValue(databaseType)
  if (nativeEngine) return [nativeEngine]

  const type = coerceDatabaseType(databaseType)
  if (type === DatabaseType.Documentsdb) return ['mongodb']
  if (type === DatabaseType.Vectorsdb) return ['postgresql']
  return ['mysql']
}

/**
 * True when the database has dedicated compute.
 *
 * Product DBs created with a dedicated tier expose backing via `specification`
 * and/or `replicas` on `Models.Database` (not always present in the native
 * engine list used by `useProjectDedicatedDatabases`). Lifecycle `status`
 * alone is not an indication: serverless and self-hosted TablesDB also return
 * values like `ready`.
 */
export function hasDedicatedDatabaseCompute(
  db: DatabaseComputeHints,
  dedicated?: DedicatedComputeHints,
): boolean {
  if (dedicated?.$id) return true

  const spec = readDatabaseSpecification(db.specification)
  if (spec && !isServerlessDatabaseSpecId(spec)) return true

  if (typeof db.replicas === 'number') return true

  // Native engine types and always-dedicated product APIs.
  if (isNativeDatabaseTypeValue(db.databaseType)) return true

  const type = coerceDatabaseType(db.databaseType)
  return (
    type === DatabaseType.Documentsdb || type === DatabaseType.Vectorsdb
  )
}

/**
 * True when replication / failover settings should be offered.
 * Stricter than {@link hasDedicatedDatabaseCompute}: serverless product DBs
 * (including those with a lifecycle `status` or `replicas: 0`) are excluded.
 */
export function canConfigureDedicatedReplication(
  db: DatabaseComputeHints,
  dedicated?: DedicatedComputeHints,
): boolean {
  const productSpec = readDatabaseSpecification(db.specification)
  const dedicatedSpec = readDatabaseSpecification(dedicated?.specification)

  // Explicit serverless tier never gets replication settings.
  if (productSpec && isServerlessDatabaseSpecId(productSpec)) {
    // Unless a real non-serverless dedicated row exists for the same ID.
    if (
      !dedicated?.$id ||
      (dedicatedSpec != null && isServerlessDatabaseSpecId(dedicatedSpec))
    ) {
      return false
    }
  }
  if (dedicatedSpec && isServerlessDatabaseSpecId(dedicatedSpec)) {
    return false
  }

  // Native engines, DocumentsDB, and VectorsDB are always dedicated compute.
  if (isNativeDatabaseTypeValue(db.databaseType)) return true
  const type = coerceDatabaseType(db.databaseType)
  if (type === DatabaseType.Documentsdb || type === DatabaseType.Vectorsdb) {
    return true
  }

  // Dedicated engine document (product-owned or native).
  if (dedicated?.$id) return true

  // Product API reports a dedicated specification slug.
  if (productSpec && !isServerlessDatabaseSpecId(productSpec)) return true

  // HA replicas imply dedicated compute (0 alone does not). Status alone does
  // not: serverless product DBs often expose a health `status` object.
  if (typeof dedicated?.replicas === 'number' && dedicated.replicas > 0) {
    return true
  }
  if (typeof db.replicas === 'number' && db.replicas > 0) return true

  return false
}

/**
 * Match a dedicated compute tier from allocated CPU/memory against the plan
 * specification catalog (product list rows sometimes omit `specification`).
 */
export function findSpecOptionByResources(
  specs: SpecOption[],
  cpuMillicores?: number | null,
  memoryMb?: number | null,
  rawSpecifications?: Models.DedicatedDatabaseSpecification[] | null,
): SpecOption | null {
  if (
    (cpuMillicores == null || cpuMillicores <= 0) &&
    (memoryMb == null || memoryMb <= 0)
  ) {
    return null
  }

  if (rawSpecifications?.length) {
    const match = rawSpecifications.find((spec) => {
      const cpuOk =
        cpuMillicores == null ||
        cpuMillicores <= 0 ||
        spec.cpu === cpuMillicores
      const memoryOk =
        memoryMb == null || memoryMb <= 0 || spec.memory === memoryMb
      return cpuOk && memoryOk
    })
    if (match) {
      return (
        specs.find((item) => item.id === match.slug) ?? {
          id: match.slug,
          label: match.name,
          cpu: formatDedicatedSpecCpu(match.cpu),
          memory: formatDedicatedSpecMemory(match.memory),
          storage: '-',
          connections: String(match.maxConnections),
          price: '',
          priceUsd: match.price,
          comingSoon: !match.enabled,
        }
      )
    }
  }

  const cpuLabel =
    cpuMillicores != null && cpuMillicores > 0
      ? formatDedicatedSpecCpu(cpuMillicores)
      : null
  const memoryLabel =
    memoryMb != null && memoryMb > 0
      ? formatDedicatedSpecMemory(memoryMb)
      : null
  if (!cpuLabel && !memoryLabel) return null

  return (
    specs.find((spec) => {
      if (spec.id === SERVERLESS_DATABASE_SPEC_ID) return false
      const cpuOk = !cpuLabel || spec.cpu === cpuLabel
      const memoryOk = !memoryLabel || spec.memory === memoryLabel
      return cpuOk && memoryOk
    }) ?? null
  )
}

/**
 * Build a card-compatible dedicated source from product list fields so Appwrite
 * DBs with dedicated compute can render the same cluster graph as native DBs
 * even when they are missing from the engine list response.
 */
export function buildProductDedicatedCardSource(
  db: DatabaseComputeHints,
  dedicated?: DedicatedComputeHints | Models.DedicatedDatabase | null,
): DedicatedDatabaseCardSource | null {
  if (!db.$id) return null
  if (!hasDedicatedDatabaseCompute(db, dedicated ?? undefined)) return null

  const type = coerceDatabaseType(db.databaseType)
  const api =
    coerceTrimmedString(dedicated?.api) ||
    (type === DatabaseType.Documentsdb
      ? 'documentsdb'
      : type === DatabaseType.Vectorsdb
        ? 'vectorsdb'
        : isNativeDatabaseTypeValue(db.databaseType)
          ? String(db.databaseType ?? '')
          : 'tablesdb')

  const engineHint =
    productDedicatedEngineHints(db.databaseType)[0] ?? 'postgresql'
  const engine = coerceTrimmedString(dedicated?.engine) || engineHint

  const status =
    readDatabaseLifecycleStatus(dedicated?.status) ||
    readDatabaseLifecycleStatus(db.status) ||
    'ready'

  const specification =
    readDatabaseSpecification(dedicated?.specification) ||
    readDatabaseSpecification(db.specification) ||
    ''

  const replicas =
    typeof dedicated?.replicas === 'number'
      ? dedicated.replicas
      : typeof db.replicas === 'number'
        ? db.replicas
        : 0

  return {
    $id: dedicated?.$id || db.$id,
    name: db.name ?? undefined,
    status,
    replicas,
    specification,
    engine,
    api,
    cpu: dedicated?.cpu ?? undefined,
    memory: dedicated?.memory ?? undefined,
  }
}

export type ResolveDatabaseComputeLabelOptions = {
  specs?: SpecOption[]
  rawSpecifications?: Models.DedicatedDatabaseSpecification[] | null
  /**
   * Label when the database is not dedicated and has no spec. Defaults to
   * "Serverless". Pass `''` when compute tiers are not a product concept
   * (e.g. self-hosted TablesDB).
   */
  unspecifiedLabel?: string
}

/**
 * Compute tier label for list/cards. Prefer the dedicated engine spec slug
 * (same as native DB cards). When the slug is missing, resolve the tier from
 * allocated CPU/memory against the specification catalog.
 */
export function resolveDatabaseComputeLabel(
  db: DatabaseComputeHints,
  dedicated: DedicatedComputeHints | undefined,
  t: (text: string) => string,
  options?: ResolveDatabaseComputeLabelOptions,
): string {
  const fromDedicated = readDatabaseSpecification(dedicated?.specification)
  if (fromDedicated) {
    return isServerlessDatabaseSpecId(fromDedicated)
      ? t('Serverless')
      : fromDedicated
  }

  const fromProduct = readDatabaseSpecification(db.specification)
  if (fromProduct) {
    return isServerlessDatabaseSpecId(fromProduct)
      ? t('Serverless')
      : fromProduct
  }

  if (hasDedicatedDatabaseCompute(db, dedicated)) {
    const fromResources = findSpecOptionByResources(
      options?.specs ?? [],
      dedicated?.cpu,
      dedicated?.memory,
      options?.rawSpecifications,
    )
    // Match native cards: prefer the specification slug when known.
    if (fromResources?.id) return fromResources.id
    if (fromResources?.label?.trim()) return fromResources.label.trim()

    if (
      dedicated?.cpu != null &&
      dedicated.cpu > 0 &&
      dedicated?.memory != null &&
      dedicated.memory > 0
    ) {
      return `${formatDedicatedSpecCpu(dedicated.cpu)} · ${formatDedicatedSpecMemory(dedicated.memory)}`
    }

    // No spec or resources to display. Do not guess "Dedicated".
    return ''
  }

  if (typeof options?.unspecifiedLabel === 'string') {
    return options.unspecifiedLabel
  }

  return t('Serverless')
}

/** Effective spec id for monitoring / sidebar when joining product + dedicated. */
export function resolveDatabaseComputeSpecId(
  db: DatabaseComputeHints,
  dedicated: DedicatedComputeHints | undefined,
  options?: ResolveDatabaseComputeLabelOptions,
): string | null {
  const fromDedicated = readDatabaseSpecification(dedicated?.specification)
  if (fromDedicated) return fromDedicated

  const fromProduct = readDatabaseSpecification(db.specification)
  if (fromProduct) return fromProduct

  if (hasDedicatedDatabaseCompute(db, dedicated)) {
    const fromResources = findSpecOptionByResources(
      options?.specs ?? [],
      dedicated?.cpu,
      dedicated?.memory,
      options?.rawSpecifications,
    )
    return fromResources?.id ?? null
  }

  return SERVERLESS_DATABASE_SPEC_ID
}
