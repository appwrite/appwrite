import {
  CONSOLE_ONLY_DATABASE_API_SERVICES,
  type ConsoleOnlyDatabaseApiService,
} from './services'
import type { OpenApiOperation, OpenApiSpec } from './types'

const COMPUTE_DATABASES_PREFIX = '/compute/databases'
const LEGACY_EMBEDDINGS_PATH = '/vectorsdb/embeddings/text'
const EMBEDDINGS_PATH = '/embeddings/text'

function cloneJson<T>(value: T): T {
  return structuredClone(value)
}

function collectOperationTags(spec: OpenApiSpec): Set<string> {
  const tags = new Set<string>()
  for (const pathItem of Object.values(spec.paths ?? {})) {
    if (!pathItem) continue
    for (const [key, operation] of Object.entries(pathItem)) {
      if (!isOpenApiOperation(key, operation)) continue
      for (const tag of operation.tags ?? []) tags.add(tag)
    }
  }
  return tags
}

function isOpenApiOperation(
  key: string,
  value: unknown,
): value is OpenApiOperation {
  if (
    key === 'parameters' ||
    key === 'summary' ||
    key === 'description' ||
    key === 'servers' ||
    key === '$ref'
  ) {
    return false
  }
  if (!value || typeof value !== 'object') return false
  const operation = value as OpenApiOperation
  return Boolean(
    operation.operationId ||
      operation['x-appwrite'] ||
      (operation.tags && operation.tags.length > 0),
  )
}

function withServiceTag(
  pathItem: Record<string, OpenApiOperation>,
  serviceId: string,
  replaceTags: readonly string[],
): Record<string, OpenApiOperation> {
  const next = cloneJson(pathItem)
  const drop = new Set(replaceTags)

  for (const [key, value] of Object.entries(next)) {
    if (!isOpenApiOperation(key, value)) continue
    const remaining = (value.tags ?? []).filter((tag) => !drop.has(tag))
    value.tags = [serviceId, ...remaining.filter((tag) => tag !== serviceId)]
  }

  return next
}

function mergePathItem(
  paths: Record<string, Record<string, OpenApiOperation>>,
  path: string,
  pathItem: Record<string, OpenApiOperation>,
) {
  const existing = paths[path]
  if (!existing) {
    paths[path] = pathItem
    return
  }
  paths[path] = { ...existing, ...pathItem }
}

function engineAllowsRewrittenPath(
  engine: ConsoleOnlyDatabaseApiService,
  path: string,
): boolean {
  if (path.includes('/extensions')) return engine === 'postgresql'
  if (path.includes('/executions') || path.includes('/pooler')) {
    return engine === 'postgresql' || engine === 'mysql'
  }
  if (
    path.includes('/connections') ||
    path.includes('/slow-queries') ||
    path.includes('/explanation')
  ) {
    return false
  }
  return true
}

function rewriteComputePath(
  path: string,
  engine: ConsoleOnlyDatabaseApiService,
): string | null {
  if (path !== COMPUTE_DATABASES_PREFIX && !path.startsWith(`${COMPUTE_DATABASES_PREFIX}/`)) {
    return null
  }

  let rest = path.slice(COMPUTE_DATABASES_PREFIX.length)
  if (rest.endsWith('/ha/failovers')) {
    rest = `${rest.slice(0, -'/ha/failovers'.length)}/failovers`
  } else if (rest.endsWith('/ha')) {
    return null
  }

  const rewritten = `/${engine}${rest}`
  if (!engineAllowsRewrittenPath(engine, rewritten)) return null
  return rewritten
}

function isLegacyEmbeddingsPath(path: string): boolean {
  return path === LEGACY_EMBEDDINGS_PATH
}

/**
 * Published OpenAPI specs still use `/compute/databases` and nest text
 * embeddings under VectorsDB. The console SDK exposes `/postgresql`,
 * `/mysql`, `/mongo`, and `/embeddings/text` instead. Rewrite so explorer
 * and API reference nav match the live services.
 */
export function normalizeDatabaseOpenApiSpec(spec: OpenApiSpec): OpenApiSpec {
  const paths = spec.paths
  if (!paths) return spec

  const existingTags = collectOperationTags(spec)
  const hasNativeEngineTags = CONSOLE_ONLY_DATABASE_API_SERVICES.some((tag) =>
    existingTags.has(tag),
  )
  const hasEmbeddingsTag = existingTags.has('embeddings')

  if (hasNativeEngineTags && hasEmbeddingsTag) return spec

  const nextPaths: Record<string, Record<string, OpenApiOperation>> = {}

  for (const [path, pathItem] of Object.entries(paths)) {
    if (!pathItem) continue

    if (!hasEmbeddingsTag && isLegacyEmbeddingsPath(path)) {
      mergePathItem(
        nextPaths,
        EMBEDDINGS_PATH,
        withServiceTag(pathItem, 'embeddings', ['vectorsDB', 'embeddings']),
      )
      continue
    }

    if (!hasNativeEngineTags && path.startsWith(COMPUTE_DATABASES_PREFIX)) {
      for (const engine of CONSOLE_ONLY_DATABASE_API_SERVICES) {
        const rewrittenPath = rewriteComputePath(path, engine)
        if (!rewrittenPath) continue
        mergePathItem(
          nextPaths,
          rewrittenPath,
          withServiceTag(pathItem, engine, ['compute', engine]),
        )
      }
      continue
    }

    mergePathItem(nextPaths, path, pathItem)
  }

  return { ...spec, paths: nextPaths }
}
