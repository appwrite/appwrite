import { formatMethodAuthDescription } from '@/lib/api-explorer/format-method-auth'
import { formatRateLimitDescription } from '@/lib/api-explorer/format-rate-limit'
import {
  getRequestBodyFormFields,
  getFormFieldOpenApiTypeLabel,
  parameterToFormField,
} from '@/lib/api-explorer/request-form'
import {
  PLATFORM_CODE_LANGUAGES,
  type ReferencePlatform,
  type ReferenceVersion,
} from './constants'
import type { ApiReferenceMethod } from './types'

const DEFAULT_BASE_URL = 'https://<REGION>.cloud.appwrite.io/v1'

function splitMetadataList(value?: string): string[] {
  if (!value?.trim()) return []
  return value
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

function appendSection(lines: string[], title: string, body: string): void {
  if (!body.trim()) return
  lines.push(`## ${title}`, '', body.trim(), '')
}

function buildParameterTable(
  fields: ReturnType<typeof parameterToFormField>[],
): string {
  if (fields.length === 0) return ''

  const rows = fields.map((field) => {
    const required = field.required ? 'Required' : ''
    const description = field.description?.trim() || ''
    return `| ${field.name} | ${getFormFieldOpenApiTypeLabel(field.kind)} | ${required} | ${description.replace(/\|/g, '\\|').replace(/\n/g, ' ')} |`
  })

  return [
    '| Name | Type | Required | Description |',
    '| --- | --- | --- | --- |',
    ...rows,
  ].join('\n')
}

function formatPropertyTypeForMarkdown(property: ApiReferenceModelProperty): string {
  if (property.typeKind === 'array') {
    if (property.itemType) return `array of ${property.itemType}`
    if (property.variantCount) {
      return `array (${property.variantCount} possible object types)`
    }
    return 'array'
  }
  if (property.typeKind === 'object') {
    if (property.itemType) return `object (${property.itemType})`
    if (property.variantCount) {
      return `object (${property.variantCount} possible types)`
    }
    return 'object'
  }
  return property.type
}

function buildModelPropertiesTable(
  properties: ApiReferenceMethod['responses'][number]['models'][number]['properties'],
): string {
  if (properties.length === 0) return ''

  const rows = properties.map((property) => {
    const description = property.description?.trim() || ''
    const related = property.relatedModels
      ? `${description ? `${description} ` : ''}Can be one of: ${property.relatedModels}`
      : description
    return `| ${property.name} | ${formatPropertyTypeForMarkdown(property)} | ${related.replace(/\|/g, '\\|').replace(/\n/g, ' ')} |`
  })

  const sections = [
    '| Name | Type | Description |',
    '| --- | --- | --- |',
    ...rows,
  ]

  for (const property of properties) {
    if (!property.variants?.length) continue
    for (const variant of property.variants) {
      sections.push('', `#### ${property.name}: ${variant.name}`)
      const variantTable = buildModelPropertiesTable(variant.properties)
      if (variantTable) {
        sections.push('', variantTable)
      }
    }
  }

  return sections.join('\n')
}

function buildResponsesSection(method: ApiReferenceMethod): string {
  if (method.responses.length === 0) return ''

  return method.responses
    .map((response) => {
      const lines = [`### ${response.code}`]
      if (response.contentType) {
        lines.push('', response.contentType)
      }
      if (response.models.length > 0) {
        for (const model of response.models) {
          lines.push('', `**${model.name}**`)
          const table = buildModelPropertiesTable(model.properties)
          if (table) {
            lines.push('', table)
          }
        }
      }
      return lines.join('\n')
    })
    .join('\n\n')
}

export function buildApiReferenceMethodMarkdown(
  method: ApiReferenceMethod,
  version: ReferenceVersion,
  platform: ReferencePlatform,
  options?: { baseUrl?: string; pageUrl?: string },
): string {
  const baseUrl = options?.baseUrl ?? DEFAULT_BASE_URL
  const fullUrl = `${baseUrl.replace(/\/$/, '')}${method.path}`
  const codeLanguage = PLATFORM_CODE_LANGUAGES[platform]
  const lines: string[] = [`# ${method.summary}`, '']

  if (options?.pageUrl) {
    lines.push(`Source: ${options.pageUrl}`, '')
  }

  if (method.deprecated) {
    const meta = method.xAppwrite?.deprecated
    const parts: string[] = ['> **Deprecated endpoint**']
    if (meta?.since) parts.push(`> Deprecated since ${meta.since}.`)
    if (meta?.replaceWith) parts.push(`> Use ${meta.replaceWith} instead.`)
    if (!meta?.since && !meta?.replaceWith) {
      parts.push(
        '> This endpoint is deprecated and may be removed in a future version.',
      )
    }
    lines.push(...parts, '')
  }

  appendSection(
    lines,
    'Endpoint',
    `\`${method.httpMethod.toUpperCase()}\` ${fullUrl}`,
  )

  if (method.description?.trim()) {
    appendSection(lines, 'Description', method.description.trim())
  }

  const scopes = splitMetadataList(method.scope)
  if (scopes.length > 0) {
    appendSection(
      lines,
      'Required scopes',
      scopes.map((scope) => `- \`${scope}\``).join('\n'),
    )
  }

  appendSection(lines, 'Authentication', formatMethodAuthDescription(method, platform))

  const rateLimit = method.xAppwrite?.['rate-limit']
  if (rateLimit !== undefined && rateLimit > 0) {
    appendSection(
      lines,
      'Rate limit',
      formatRateLimitDescription(
        rateLimit,
        method.xAppwrite?.['rate-time'] ?? 3600,
        method.xAppwrite?.['rate-key'],
      ),
    )
  }

  const pathFields = (method.parameters ?? [])
    .filter((param) => param.in === 'path')
    .map((param) => parameterToFormField(param))
  const pathTable = buildParameterTable(pathFields)
  if (pathTable) appendSection(lines, 'Path parameters', pathTable)

  const queryFields = (method.parameters ?? [])
    .filter((param) => param.in === 'query')
    .map((param) => parameterToFormField(param))
  const queryTable = buildParameterTable(queryFields)
  if (queryTable) appendSection(lines, 'Query parameters', queryTable)

  const bodyFields = getRequestBodyFormFields(method)
  const bodyTable = buildParameterTable(bodyFields)
  if (bodyTable) {
    const bodyTitle =
      pathFields.length > 0 || queryFields.length > 0 ? 'Body' : 'Parameters'
    appendSection(lines, bodyTitle, bodyTable)
  }

  const responses = buildResponsesSection(method)
  if (responses) appendSection(lines, 'Responses', responses)

  if (method.demo?.trim()) {
    lines.push('## Example', '', `\`\`\`${codeLanguage}`, method.demo.trim(), '```', '')
  }

  return lines.join('\n').trimEnd()
}
