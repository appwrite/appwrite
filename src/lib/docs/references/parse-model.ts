import type { OpenApiSchema, OpenApiSpec } from '@/lib/api-explorer/types'
import type { ReferenceVersion } from '@/lib/docs/references/constants'
import {
  formatSchemaType,
  getSchemaIdFromRef,
  resolveSchemaRef,
} from '@/lib/docs/references/schema-utils'
import type {
  ApiReferenceModelData,
  ApiReferenceModelProperty,
  ApiReferenceResponseModel,
} from '@/lib/docs/references/types'

function formatModelLink(modelId: string, version: ReferenceVersion): string {
  return `[${modelId}](/docs/references/${version}/models/${modelId})`
}

function formatRelatedModelsMarkdown(
  modelIds: string[],
  version: ReferenceVersion,
): string {
  return modelIds.map((id) => formatModelLink(id, version)).join(', ')
}

function collectRelatedModelIds(
  property: OpenApiSchema,
  spec: OpenApiSpec,
): string[] {
  if (property.$ref) {
    return [getSchemaIdFromRef(property.$ref)]
  }

  const resolved = resolveSchemaRef(property, spec) ?? property

  if (resolved.items) {
    const rawItems = resolved.items
    if (rawItems.$ref) {
      return [getSchemaIdFromRef(rawItems.$ref)]
    }
    const items = resolveSchemaRef(rawItems, spec) ?? rawItems
    if (items.oneOf?.length) {
      return items.oneOf
        .filter((item) => item.$ref)
        .map((item) => getSchemaIdFromRef(item.$ref!))
    }
    if (items.anyOf?.length) {
      return items.anyOf
        .filter((item) => item.$ref)
        .map((item) => getSchemaIdFromRef(item.$ref!))
    }
  }

  if (resolved.$ref) {
    return [getSchemaIdFromRef(resolved.$ref)]
  }

  if (resolved.oneOf?.length) {
    return resolved.oneOf
      .filter((item) => item.$ref)
      .map((item) => getSchemaIdFromRef(item.$ref!))
  }

  if (resolved.anyOf?.length) {
    return resolved.anyOf
      .filter((item) => item.$ref)
      .map((item) => getSchemaIdFromRef(item.$ref!))
  }

  return []
}

function resolvePropertyTypeMeta(
  resolved: OpenApiSchema,
  spec: OpenApiSpec,
  relatedIds: string[],
  variants: ApiReferenceResponseModel[] | undefined,
): Pick<
  ApiReferenceModelProperty,
  'typeKind' | 'type' | 'itemType' | 'variantCount'
> {
  if (variants?.length) {
    if (resolved.type === 'array') {
      return {
        typeKind: 'array',
        type: 'array',
        itemType: variants.length === 1 ? variants[0]!.name : undefined,
        variantCount: variants.length > 1 ? variants.length : undefined,
      }
    }
    return {
      typeKind: 'object',
      type: 'object',
      itemType: variants.length === 1 ? variants[0]!.name : undefined,
      variantCount: variants.length > 1 ? variants.length : undefined,
    }
  }

  if (resolved.type === 'array') {
    if (relatedIds.length === 1) {
      const itemId = relatedIds[0]!
      const itemName =
        spec.components?.schemas?.[itemId]?.description?.trim() || itemId
      return {
        typeKind: 'array',
        type: 'array',
        itemType: itemName,
      }
    }
    const itemType = formatSchemaType(resolved.items, spec)
    return {
      typeKind: 'array',
      type: 'array',
      itemType: itemType || undefined,
    }
  }

  if (resolved.type === 'object' || resolved.properties) {
    return { typeKind: 'object', type: 'object' }
  }

  return {
    typeKind: 'scalar',
    type: formatSchemaType(resolved, spec) || 'unknown',
  }
}

type ParseModelPropertiesOptions = {
  version?: ReferenceVersion
  /** When false, related model refs are plain ids (for inline display). */
  linkRelatedModels?: boolean
}

export function parseModelPropertiesFromSchema(
  schema: OpenApiSchema,
  spec: OpenApiSpec,
  options: ParseModelPropertiesOptions = {},
): ApiReferenceModelProperty[] {
  const { version, linkRelatedModels = false } = options
  const properties = schema.properties ?? {}

  return Object.entries(properties).map(([name, propertySchema]) => {
    const resolved = resolveSchemaRef(propertySchema, spec) ?? propertySchema
    const relatedIds = collectRelatedModelIds(propertySchema, spec)
    const shouldInlineModels =
      Boolean(version) &&
      relatedIds.length > 0 &&
      (relatedIds.length > 1 || resolved.type === 'array')

    const variants = shouldInlineModels
      ? relatedIds.map((id) =>
          buildInlineResponseModel(
            id,
            spec.components?.schemas?.[id]?.description?.trim() || id,
            spec,
            version!,
          ),
        )
      : undefined

    let relatedModels: string | undefined
    if (!variants && relatedIds.length > 0) {
      relatedModels =
        linkRelatedModels && version
          ? formatRelatedModelsMarkdown(relatedIds, version)
          : relatedIds.join(', ')
    }

    const typeMeta = resolvePropertyTypeMeta(
      resolved,
      spec,
      relatedIds,
      variants,
    )

    return {
      name,
      ...typeMeta,
      description: resolved.description ?? '',
      relatedModels,
      variants,
    }
  })
}

function getModelExamples(
  schema: OpenApiSchema,
): ApiReferenceModelData['examples'] {
  const examples: ApiReferenceModelData['examples'] = []
  const example = schema.example as
    | { rest?: unknown; graphql?: unknown }
    | undefined

  if (example?.rest !== undefined) {
    examples.push({ type: 'REST', example: example.rest })
  }
  if (example?.graphql !== undefined) {
    examples.push({ type: 'GraphQL', example: example.graphql })
  }

  return examples
}

export function parseModelFromSpec(
  modelId: string,
  spec: OpenApiSpec,
  version: ReferenceVersion,
  options: ParseModelPropertiesOptions = {},
): ApiReferenceModelData | null {
  const schema = spec.components?.schemas?.[modelId]
  if (!schema) return null

  const resolved = resolveSchemaRef(schema, spec) ?? schema

  return {
    id: modelId,
    title: (resolved.description as string | undefined)?.trim() || modelId,
    properties: parseModelPropertiesFromSchema(resolved, spec, {
      ...options,
      version,
    }),
    examples: getModelExamples(resolved),
  }
}

export function buildInlineResponseModel(
  modelId: string,
  fallbackName: string,
  consoleSpec: OpenApiSpec,
  version: ReferenceVersion,
): ApiReferenceResponseModel {
  const parsed = parseModelFromSpec(modelId, consoleSpec, version, {
    linkRelatedModels: false,
  })

  if (!parsed) {
    return { id: modelId, name: fallbackName, properties: [] }
  }

  return {
    id: parsed.id,
    name: parsed.title,
    properties: parsed.properties,
  }
}
