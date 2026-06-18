import type { OpenApiSchema, OpenApiSpec } from '@/lib/api-explorer/types'
import type { ApiReferenceResponseModel } from '@/lib/docs/references/types'

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

function getResponseModelFromRef(
  ref: string,
  spec: OpenApiSpec,
): Pick<ApiReferenceResponseModel, 'id' | 'name'> {
  const id = getSchemaIdFromRef(ref)
  const resolved = spec.components?.schemas?.[id]
  return { id, name: resolved?.description?.trim() || id }
}

export function resolveResponseModels(
  schema: OpenApiSchema | undefined,
  spec: OpenApiSpec,
): Pick<ApiReferenceResponseModel, 'id' | 'name'>[] {
  if (!schema) return []

  if (schema.oneOf?.length) {
    return schema.oneOf
      .filter((item): item is OpenApiSchema & { $ref: string } => Boolean(item.$ref))
      .map((item) => getResponseModelFromRef(item.$ref, spec))
  }

  if (schema.$ref) {
    return [getResponseModelFromRef(schema.$ref, spec)]
  }

  if (schema.type === 'array' && schema.items) {
    return resolveResponseModels(schema.items, spec)
  }

  return []
}
