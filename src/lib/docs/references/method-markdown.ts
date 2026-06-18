import { getMethodAuthKeys } from '@/lib/api-explorer/auth'
import {
  getRequestBodyFormFields,
  getFormFieldTypeLabel,
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
    return `| ${field.name} | ${getFormFieldTypeLabel(field.kind)} | ${required} | ${description.replace(/\|/g, '\\|').replace(/\n/g, ' ')} |`
  })

  return [
    '| Name | Type | Required | Description |',
    '| --- | --- | --- | --- |',
    ...rows,
  ].join('\n')
}

function buildResponsesSection(
  method: ApiReferenceMethod,
  version: ReferenceVersion,
): string {
  if (method.responses.length === 0) return ''

  return method.responses
    .map((response) => {
      const lines = [`### ${response.code}`]
      if (response.contentType) {
        lines.push('', response.contentType)
      }
      if (response.models.length > 0) {
        lines.push(
          '',
          ...response.models.map(
            (model) =>
              `- [${model.name}](/docs/references/${version}/models/${model.id})`,
          ),
        )
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

  const authMethods = getMethodAuthKeys(method)
  if (authMethods.length > 0) {
    appendSection(
      lines,
      'Required auth',
      authMethods.map((auth) => `- ${auth}`).join('\n'),
    )
  }

  const rateLimit = method.xAppwrite?.['rate-limit']
  if (rateLimit !== undefined && rateLimit > 0) {
    appendSection(
      lines,
      'Rate limit',
      `${rateLimit} requests per ${method.xAppwrite?.['rate-time'] ?? 3600}s`,
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

  const responses = buildResponsesSection(method, version)
  if (responses) appendSection(lines, 'Responses', responses)

  if (method.demo?.trim()) {
    lines.push('## Example', '', `\`\`\`${codeLanguage}`, method.demo.trim(), '```', '')
  }

  return lines.join('\n').trimEnd()
}
