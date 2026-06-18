import type { OpenApiSchema, OpenApiSpec } from '@/lib/api-explorer/types'

export function resolveSchemaRef(
  schema: OpenApiSchema | undefined,
  spec: OpenApiSpec,
): OpenApiSchema | undefined {
  if (!schema?.$ref || !spec.components?.schemas) return schema
  const name = schema.$ref.replace('#/components/schemas/', '')
  return spec.components.schemas[name] ?? schema
}

export function getSchemaIdFromRef(ref: string): string {
  return ref.replace('#/components/schemas/', '')
}

export function formatSchemaType(
  schema: OpenApiSchema | undefined,
  spec: OpenApiSpec,
): string {
  if (!schema) return ''
  const resolved = resolveSchemaRef(schema, spec) ?? schema

  if (resolved.$ref) {
    return getSchemaIdFromRef(resolved.$ref)
  }

  if (resolved.type === 'array' && resolved.items) {
    const itemType = formatSchemaType(resolved.items, spec)
    return itemType ? `${itemType}[]` : 'array'
  }

  if (resolved.enum?.length) {
    const enumName = resolved['x-enum-name']
    if (enumName) return enumName
    return resolved.enum.map(String).join(' | ')
  }

  if (resolved.type) return resolved.type
  return ''
}
