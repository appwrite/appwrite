import {
  COVER_HEIGHT,
  COVER_IMAGE_FORMATS,
  COVER_TEMPLATE_IDS,
  COVER_WIDTH,
  type CoverTemplateId,
} from '@/lib/cover-generator/constants'
import {
  getCoverCardsAngledIconKeys,
  getCoverCardsAngledIconVisibilityKey,
} from '@/lib/cover-generator/cards-angled/constants'
import {
  getCoverChartLabelKeys,
  getCoverChartValueKeys,
} from '@/lib/cover-generator/chart/constants'
import {
  coverRenderDataToSearchParams,
  DEFAULT_COVER_VALUES,
  parseCoverRenderData,
} from '@/lib/cover-generator/parse-params'
import {
  COVER_TEMPLATE_DEFINITIONS,
  getCoverTemplateDefinition,
} from '@/lib/cover-generator/template-config'
import {
  getCoverTableCellKeys,
  getCoverTableHeaderKeys,
} from '@/lib/cover-generator/table/constants'
import { COVER_THEME_IDS } from '@/lib/cover-generator/themes'
import {
  coverRenderDataHasInlineAssets,
  shouldPostCoverRenderRequest,
} from '@/lib/cover-generator/fetch-cover-image'
import type { CoverFieldDefinition } from '@/lib/cover-generator/types'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import {
  formatApiParametersMarkdownTable,
  formatApiResponseMarkdown,
  GENERATOR_API_QUERY_PARAMETERS,
  type GeneratorApiParameterDoc,
} from '@/lib/generator/api-docs-shared'
import { GENERATOR_COVER_API_PATH } from '@/lib/generator/constants'

export const COVER_API_PATH = GENERATOR_COVER_API_PATH
export const COVER_API_MAX_GET_URL_LENGTH = 1800

export type CoverApiParameterDoc = GeneratorApiParameterDoc

export type CoverApiTemplateSummary = {
  id: CoverTemplateId
  label: string
  description: string
}

export type CoverApiDocsContext = {
  origin: string
  endpointUrl: string
  templateId: CoverTemplateId
  templateLabel: string
  templateDescription: string
  templateSummaries: CoverApiTemplateSummary[]
  sharedParameters: CoverApiParameterDoc[]
  templateParameters: CoverApiParameterDoc[]
  queryParameters: CoverApiParameterDoc[]
  parameters: CoverApiParameterDoc[]
  payload: Record<string, string | number | boolean>
  displayPayload: Record<string, string | number | boolean>
  getUrl: string
  recommendsPost: boolean
  hasInlineAssets: boolean
  getUrlTooLong: boolean
  curlGet: string
  curlPost: string
  curlPostDownload: string
  fetchExample: string
  fetchDownloadExample: string
}

function shellQuote(value: string): string {
  if (/^[A-Za-z0-9_./:?&=%#@+-]+$/.test(value)) return value
  return `'${value.replace(/'/g, `'\\''`)}'`
}

function mapFieldType(field: CoverFieldDefinition): string {
  switch (field.type) {
    case 'boolean':
      return 'boolean'
    case 'number':
    case 'range':
      return 'number'
    case 'select':
      return field.options?.length ? 'string' : 'string'
    case 'image':
      return 'string'
    default:
      return 'string'
  }
}

function mapFieldDescription(field: CoverFieldDefinition): string {
  const parts: string[] = []
  if (field.description) parts.push(field.description)
  if (field.type === 'image') {
    parts.push(
      'Public URL, /icons path, lucide:icon-name, or data URI. Use POST for uploads and data URIs.',
    )
  }
  if (field.type === 'range' || field.type === 'number') {
    if (field.min != null && field.max != null) {
      parts.push(`Range: ${field.min}${field.unit ?? ''} to ${field.max}${field.unit ?? ''}.`)
    }
    if (field.placeholder) parts.push(`Example: ${field.placeholder}.`)
  } else if (field.placeholder) {
    parts.push(`Example: ${field.placeholder}.`)
  }
  return parts.join(' ')
}

function mapFieldToParameter(field: CoverFieldDefinition): CoverApiParameterDoc {
  return {
    name: field.key,
    type: mapFieldType(field),
    description: mapFieldDescription(field),
    example: field.placeholder,
    enumValues: field.options?.map((option) => option.value),
  }
}

function buildSharedParameterDocs(): CoverApiParameterDoc[] {
  return [
    {
      name: 'template',
      type: 'string',
      required: true,
      description: 'Cover template to render. Each template accepts its own content fields in addition to the shared fields below.',
      enumValues: COVER_TEMPLATE_IDS,
      defaultValue: DEFAULT_COVER_VALUES.template,
    },
    {
      name: 'theme',
      type: 'string',
      description: 'Background theme for the cover artboard.',
      enumValues: COVER_THEME_IDS,
      defaultValue: DEFAULT_COVER_VALUES.theme,
    },
    {
      name: 'format',
      type: 'string',
      description: 'Output image format returned by the API.',
      enumValues: COVER_IMAGE_FORMATS,
      defaultValue: DEFAULT_COVER_VALUES.format,
    },
    {
      name: 'width',
      type: 'number',
      description: 'Canvas width in pixels.',
      defaultValue: String(COVER_WIDTH),
      example: '1200',
    },
    {
      name: 'height',
      type: 'number',
      description: 'Canvas height in pixels.',
      defaultValue: String(COVER_HEIGHT),
      example: '630',
    },
  ]
}

function buildSupplementalParameterDocs(templateId: CoverTemplateId): CoverApiParameterDoc[] {
  switch (templateId) {
    case 'table':
      return [
        ...getCoverTableHeaderKeys().map((key) => ({
          name: key,
          type: 'string',
          description: `Header label for column ${key.replace('header', '')}.`,
        })),
        ...getCoverTableCellKeys().map((key) => ({
          name: key,
          type: 'string',
          description: `Table cell value (${key.replace('cell_', '').replace('_', ', column ')}).`,
        })),
      ]
    case 'bar-chart':
    case 'line-chart':
      return [
        ...getCoverChartLabelKeys().map((key) => ({
          name: key,
          type: 'string',
          description: `Chart label for point ${key.replace('label', '')}.`,
        })),
        ...getCoverChartValueKeys().map((key) => ({
          name: key,
          type: 'number',
          description: `Chart value for point ${key.replace('value', '')}.`,
        })),
      ]
    case 'cards-angled':
      return [
        ...getCoverCardsAngledIconKeys().flatMap((key) => [
          {
            name: key,
            type: 'string',
            description: `Icon source for grid slot ${key.replace('icon', '')}.`,
          },
          {
            name: getCoverCardsAngledIconVisibilityKey(key),
            type: 'string',
            description: 'Slot visibility: visible, hidden, or fade.',
            enumValues: ['visible', 'hidden', 'fade'] as const,
          },
        ]),
      ]
    default:
      return []
  }
}

export function buildCoverTemplateParameterDocs(
  templateId: CoverTemplateId,
): CoverApiParameterDoc[] {
  const templateDefinition = getCoverTemplateDefinition(templateId)
  const templateFields = templateDefinition?.fields.map(mapFieldToParameter) ?? []
  const supplemental = buildSupplementalParameterDocs(templateId)
  const fieldNames = new Set(templateFields.map((field) => field.name))
  const uniqueSupplemental = supplemental.filter((field) => !fieldNames.has(field.name))

  return [...templateFields, ...uniqueSupplemental]
}

export function buildCoverApiParameterDocs(
  templateId: CoverTemplateId,
): CoverApiParameterDoc[] {
  return [...buildSharedParameterDocs(), ...buildCoverTemplateParameterDocs(templateId)]
}

export function buildDefaultCoverRenderData(
  templateId: CoverTemplateId = DEFAULT_COVER_VALUES.template,
): CoverRenderData {
  return parseCoverRenderData(new URLSearchParams({ template: templateId }))
}

export function coverRenderDataToApiPayload(
  data: CoverRenderData,
): Record<string, string | number | boolean> {
  const params = coverRenderDataToSearchParams(data)
  const payload: Record<string, string | number | boolean> = {}

  for (const [key, value] of params.entries()) {
    if (value === 'true') {
      payload[key] = true
      continue
    }
    if (value === 'false') {
      payload[key] = false
      continue
    }
    if (/^-?\d+(\.\d+)?$/.test(value)) {
      payload[key] = Number(value)
      continue
    }
    payload[key] = value
  }

  return payload
}

export function sanitizeCoverApiPayloadForDisplay(
  payload: Record<string, string | number | boolean>,
): Record<string, string | number | boolean> {
  const next: Record<string, string | number | boolean> = {}

  for (const [key, value] of Object.entries(payload)) {
    if (typeof value !== 'string') {
      next[key] = value
      continue
    }

    if (value.startsWith('data:')) {
      next[key] = 'data:image/...;base64,...'
      continue
    }

    if (value.length > 120) {
      next[key] = `${value.slice(0, 80)}...`
      continue
    }

    next[key] = value
  }

  return next
}

export function buildCoverApiEndpointUrl(origin = ''): string {
  const normalizedOrigin = origin.replace(/\/+$/, '')
  return normalizedOrigin ? `${normalizedOrigin}${COVER_API_PATH}` : COVER_API_PATH
}

export function buildCoverApiGetUrl(data: CoverRenderData, origin = ''): string {
  const params = coverRenderDataToSearchParams(data)
  return `${buildCoverApiEndpointUrl(origin)}?${params.toString()}`
}

export function buildCoverApiCurlGet(data: CoverRenderData, origin = ''): string {
  return `curl ${shellQuote(buildCoverApiGetUrl(data, origin))}`
}

export function buildCoverApiCurlPost(
  data: CoverRenderData,
  origin = '',
  options?: { download?: boolean },
): string {
  const endpoint = buildCoverApiEndpointUrl(origin)
  const url = options?.download ? `${endpoint}?disposition=attachment` : endpoint
  const body = JSON.stringify(coverRenderDataToApiPayload(data))
  return [
    `curl -X POST ${shellQuote(url)} \\`,
    `  -H ${shellQuote('Content-Type: application/json')} \\`,
    `  -d ${shellQuote(body)}`,
  ].join('\n')
}

export function buildCoverApiFetchExample(
  data: CoverRenderData,
  origin = '',
  options?: { download?: boolean },
): string {
  const endpoint = buildCoverApiEndpointUrl(origin)
  const url = options?.download ? `${endpoint}?disposition=attachment` : endpoint
  const payload = coverRenderDataToApiPayload(data)
  const lines = [
    `const response = await fetch(${JSON.stringify(url)}, {`,
    `  method: 'POST',`,
    `  headers: { 'Content-Type': 'application/json' },`,
    `  body: JSON.stringify(${JSON.stringify(payload, null, 2).replace(/\n/g, '\n  ')}),`,
    `})`,
    '',
    `if (!response.ok) {`,
    `  throw new Error(\`Cover render failed (\${response.status})\`)`,
    `}`,
    '',
    `const blob = await response.blob()`,
  ]
  return lines.join('\n')
}

function escapeMarkdownTableCell(value: string): string {
  return value.replace(/\|/g, '\\|').replace(/\n/g, ' ')
}

function formatCoverTemplateCatalogMarkdown(summaries: CoverApiTemplateSummary[]): string {
  return [
    '| Template | Description |',
    '| --- | --- |',
    ...summaries.map(
      (template) =>
        `| \`${template.id}\` (${template.label}) | ${escapeMarkdownTableCell(template.description)} |`,
    ),
  ].join('\n')
}

export function buildCoverApiDocsMarkdown(docs: CoverApiDocsContext): string {
  const payloadJson = JSON.stringify(docs.displayPayload, null, 2)
  const lines: string[] = [
    '# Cover generator API',
    '',
    'Render PNG, JPEG, or AVIF cover images from template parameters. The API is available on deployments with the marketing profile enabled.',
    '',
    'Use **GET** for simple covers with URL-safe parameters. Use **POST** when the request includes uploaded images, data URIs, or a query string longer than about 1,800 characters.',
    '',
  ]

  if (docs.recommendsPost) {
    const reasons: string[] = []
    if (docs.hasInlineAssets) reasons.push('includes inline image data')
    if (docs.getUrlTooLong) reasons.push('GET URL exceeds safe length limits')
    lines.push(
      `> **Note:** The current cover should use **POST**${reasons.length ? ` because it ${reasons.join(' and ')}` : ''}.`,
      '',
    )
  }

  lines.push(
    '## Endpoint',
    '',
    'Both methods accept the same template fields. POST accepts JSON and is recommended for image-heavy requests.',
    '',
    '```http',
    `GET  ${docs.endpointUrl}`,
    `POST ${docs.endpointUrl}`,
    '```',
    '',
    '## Methods',
    '',
    '### GET',
    '',
    'Pass template fields as query parameters. Returns the image inline. Best for short, URL-safe parameter sets and quick `<img>` or Open Graph links.',
    '',
    '### POST',
    '',
    'Send a JSON object with the same fields as GET query parameters. Supports data URIs, long text fields, and custom uploads. Add `?disposition=attachment` to download the file.',
    '',
    'Boolean and number fields can be sent as JSON booleans/numbers or as strings. Omitted fields use template defaults.',
    '',
    '## Query parameters',
    '',
    formatApiParametersMarkdownTable(docs.queryParameters),
    '',
    '## Available templates',
    '',
    formatCoverTemplateCatalogMarkdown(docs.templateSummaries),
    '',
    `## Shared parameters`,
    '',
    'These fields apply to every cover template.',
    '',
    formatApiParametersMarkdownTable(docs.sharedParameters),
    '',
    `## Parameters for ${docs.templateLabel}`,
    '',
    docs.templateDescription,
    '',
    formatApiParametersMarkdownTable(docs.templateParameters),
    '',
    '## Examples for current cover',
    '',
    'Generated from the values in the editor.',
    '',
    '### Current JSON body',
    '',
    'Use this payload with POST.',
    '',
    '```json',
    payloadJson,
    '```',
    '',
    '### cURL (POST)',
    '',
    'Recommended for the current cover.',
    '',
    '```bash',
    docs.curlPost,
    '```',
    '',
    '### cURL (POST download)',
    '',
    '```bash',
    docs.curlPostDownload,
    '```',
    '',
    '### fetch (POST)',
    '',
    '```javascript',
    docs.fetchExample,
    '```',
    '',
    '### fetch (POST download)',
    '',
    '```javascript',
    docs.fetchDownloadExample,
    '```',
    '',
    '### cURL (GET)',
    '',
    docs.recommendsPost
      ? 'Included for reference. The current cover may exceed safe URL length limits.'
      : 'Works for the current cover.',
    '',
    '```bash',
    docs.curlGet,
    '```',
    '',
    '## Response',
    '',
    formatApiResponseMarkdown(),
    '',
  )

  return lines.join('\n')
}

export function buildCoverApiDocsContext(
  data: CoverRenderData,
  origin = '',
): CoverApiDocsContext {
  const templateDefinition = getCoverTemplateDefinition(data.template)
  const payload = coverRenderDataToApiPayload(data)
  const hasInlineAssets = coverRenderDataHasInlineAssets(data)
  const getUrl = buildCoverApiGetUrl(data, origin)
  const getUrlTooLong = getUrl.length > COVER_API_MAX_GET_URL_LENGTH
  const recommendsPost = shouldPostCoverRenderRequest(data, origin)
  const templateSummaries = listCoverApiTemplateSummaries()

  return {
    origin,
    endpointUrl: buildCoverApiEndpointUrl(origin),
    templateId: data.template,
    templateLabel: templateDefinition?.label ?? data.template,
    templateDescription:
      templateDefinition?.description ??
      'Render a cover image from template parameters.',
    templateSummaries,
    sharedParameters: buildSharedParameterDocs(),
    templateParameters: buildCoverTemplateParameterDocs(data.template),
    queryParameters: GENERATOR_API_QUERY_PARAMETERS,
    parameters: buildCoverApiParameterDocs(data.template),
    payload,
    displayPayload: sanitizeCoverApiPayloadForDisplay(payload),
    getUrl,
    recommendsPost,
    hasInlineAssets,
    getUrlTooLong,
    curlGet: buildCoverApiCurlGet(data, origin),
    curlPost: buildCoverApiCurlPost(data, origin),
    curlPostDownload: buildCoverApiCurlPost(data, origin, { download: true }),
    fetchExample: buildCoverApiFetchExample(data, origin),
    fetchDownloadExample: buildCoverApiFetchExample(data, origin, { download: true }),
  }
}

export function listCoverApiTemplateSummaries(): CoverApiTemplateSummary[] {
  return COVER_TEMPLATE_DEFINITIONS.map((template) => ({
    id: template.id,
    label: template.label,
    description: template.description,
  }))
}
