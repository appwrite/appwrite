import type { OpenApiSchema, OpenApiSpec } from '@/lib/api-explorer/types'
import {
  isReferenceVersion,
  type ReferenceVersion,
} from '@/lib/docs/references/constants'
import { ReferenceNotFoundError } from '@/lib/docs/references/errors'
import {
  formatSchemaType,
  resolveSchemaRef,
} from '@/lib/docs/references/schema-utils'
import type {
  ApiReferenceModelData,
  ApiReferenceModelProperty,
} from '@/lib/docs/references/types'
import { loadReferenceConsoleSpec } from './load-spec'

function formatModelLink(modelId: string, version: ReferenceVersion): string {
  return `[${modelId}](/docs/references/${version}/models/${modelId})`
}

function formatRelatedModels(
  modelIds: string[],
  version: ReferenceVersion,
): string {
  return modelIds.map((id) => formatModelLink(id, version)).join(', ')
}

function collectRelatedModelIds(
  property: OpenApiSchema,
  spec: OpenApiSpec,
): string[] {
  const resolved = resolveSchemaRef(property, spec) ?? property

  if (resolved.items) {
    const items = resolveSchemaRef(resolved.items, spec) ?? resolved.items
    if (items.$ref) {
      return [items.$ref.replace('#/components/schemas/', '')]
    }
    if (items.oneOf?.length) {
      return items.oneOf
        .filter((item) => item.$ref)
        .map((item) => item.$ref!.replace('#/components/schemas/', ''))
    }
    if (items.anyOf?.length) {
      return items.anyOf
        .filter((item) => item.$ref)
        .map((item) => item.$ref!.replace('#/components/schemas/', ''))
    }
  }

  if (resolved.$ref) {
    return [resolved.$ref.replace('#/components/schemas/', '')]
  }

  return []
}

function parseModelProperties(
  schema: OpenApiSchema,
  spec: OpenApiSpec,
  version: ReferenceVersion,
): ApiReferenceModelProperty[] {
  const properties = schema.properties ?? {}

  return Object.entries(properties).map(([name, propertySchema]) => {
    const resolved = resolveSchemaRef(propertySchema, spec) ?? propertySchema
    const relatedIds = collectRelatedModelIds(resolved, spec)

    return {
      name,
      type: formatSchemaType(resolved, spec),
      description: resolved.description ?? '',
      relatedModels:
        relatedIds.length > 0
          ? formatRelatedModels(relatedIds, version)
          : undefined,
    }
  })
}

function getModelExamples(schema: OpenApiSchema): Array<{ type: string; example: unknown }> {
  const examples: Array<{ type: string; example: unknown }> = []
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

export async function loadApiReferenceModel(
  version: string,
  modelId: string,
): Promise<ApiReferenceModelData | null> {
  if (!isReferenceVersion(version)) return null

  const spec = await loadReferenceConsoleSpec(version)
  const schema = spec.components?.schemas?.[modelId]

  if (!schema) {
    throw new ReferenceNotFoundError(`Model ${modelId} not found`)
  }

  const resolved = resolveSchemaRef(schema, spec) ?? schema

  return {
    id: modelId,
    title: (resolved.description as string | undefined) ?? modelId,
    properties: parseModelProperties(resolved, spec, version),
    examples: getModelExamples(resolved),
  }
}
