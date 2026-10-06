import { getPlatformAuth, isPlatformSupported } from './spec-metadata'
import type { ApiSpecPlatform, OpenApiOperation, OpenApiSpec } from './types'

const SCHEMA_REF_PREFIX = '#/components/schemas/'

function collectSchemaRefs(value: unknown, refs: Set<string>): void {
  if (Array.isArray(value)) {
    for (const item of value) collectSchemaRefs(item, refs)
    return
  }
  if (!value || typeof value !== 'object') return
  for (const [key, child] of Object.entries(value)) {
    if (
      key === '$ref' &&
      typeof child === 'string' &&
      child.startsWith(SCHEMA_REF_PREFIX)
    ) {
      refs.add(child.slice(SCHEMA_REF_PREFIX.length))
    } else {
      collectSchemaRefs(child, refs)
    }
  }
}

function filterOperation(
  operation: OpenApiOperation,
  platform: ApiSpecPlatform,
  allowedSchemes: (name: string) => boolean,
): OpenApiOperation | undefined {
  const xAppwrite = operation['x-appwrite']
  if (!isPlatformSupported(xAppwrite, platform)) return undefined

  const filtered: OpenApiOperation = {
    ...operation,
    security: operation.security?.map((requirement) =>
      Object.fromEntries(
        Object.entries(requirement).filter(([name]) => allowedSchemes(name)),
      ),
    ),
  }
  if (!xAppwrite) return filtered

  const methods = xAppwrite.methods?.filter((method) =>
    isPlatformSupported(method, platform),
  )
  if (xAppwrite.methods?.length && !methods?.length) return undefined

  filtered['x-appwrite'] = {
    ...xAppwrite,
    auth: getPlatformAuth(xAppwrite.auth, platform),
    ...(methods && {
      methods: methods.map((method) => ({
        ...method,
        auth: method.auth && getPlatformAuth(method.auth, platform),
      })),
    }),
  }
  return filtered
}

/**
 * Narrow a canonical (all-platform) OpenAPI document to what one SDK platform
 * can call, using `x-appwrite.platforms` on operations, additional methods and
 * security schemes. Tags and schemas left unreferenced are dropped.
 */
export function filterSpecByPlatform(
  spec: OpenApiSpec,
  platform: ApiSpecPlatform,
): OpenApiSpec {
  const securitySchemes = Object.fromEntries(
    Object.entries(spec.components?.securitySchemes ?? {}).filter(
      ([, scheme]) => isPlatformSupported(scheme['x-appwrite'], platform),
    ),
  )
  const allowedSchemes = (name: string) => name in securitySchemes

  const paths: NonNullable<OpenApiSpec['paths']> = {}
  for (const [path, pathItem] of Object.entries(spec.paths ?? {})) {
    const kept: Record<string, OpenApiOperation> = {}
    for (const [key, value] of Object.entries(pathItem ?? {})) {
      const operation =
        value?.operationId || value?.['x-appwrite']
          ? filterOperation(value, platform, allowedSchemes)
          : value
      if (operation) kept[key] = operation
    }
    if (
      Object.values(kept).some(
        (operation) => operation?.operationId || operation?.['x-appwrite'],
      )
    ) {
      paths[path] = kept
    }
  }

  const usedTags = new Set(
    Object.values(paths).flatMap((item) =>
      Object.values(item).flatMap((operation) => operation.tags ?? []),
    ),
  )

  const schemas = spec.components?.schemas ?? {}
  const usedSchemas = new Set<string>()
  collectSchemaRefs(paths, usedSchemas)
  const pending = [...usedSchemas]
  while (pending.length > 0) {
    const name = pending.pop()!
    const found = new Set<string>()
    collectSchemaRefs(schemas[name], found)
    for (const ref of found) {
      if (usedSchemas.has(ref)) continue
      usedSchemas.add(ref)
      pending.push(ref)
    }
  }

  return {
    ...spec,
    tags: spec.tags?.filter((tag) => tag.name && usedTags.has(tag.name)),
    paths,
    components: {
      ...spec.components,
      securitySchemes,
      schemas: Object.fromEntries(
        Object.entries(schemas).filter(([name]) => usedSchemas.has(name)),
      ),
    },
  }
}
