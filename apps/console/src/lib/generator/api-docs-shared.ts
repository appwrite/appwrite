export type GeneratorApiParameterDoc = {
  name: string
  type: string
  required?: boolean
  description: string
  example?: string
  defaultValue?: string
  enumValues?: readonly string[]
}

export const GENERATOR_API_QUERY_PARAMETERS: GeneratorApiParameterDoc[] = [
  {
    name: 'disposition',
    type: 'string',
    description:
      'Controls the Content-Disposition response header. Use attachment to download the image instead of displaying it inline.',
    enumValues: ['inline', 'attachment'],
    defaultValue: 'inline',
  },
]

export type GeneratorApiResponseNote = {
  status: string
  title: string
  description: string
}

export const GENERATOR_API_RESPONSE_NOTES: GeneratorApiResponseNote[] = [
  {
    status: '200 OK',
    title: 'Success',
    description:
      'Returns a rendered image. Content-Type is image/png, image/jpeg, or image/avif based on format. Responses are cached for 5 minutes.',
  },
  {
    status: '404 Not Found',
    title: 'Unavailable',
    description: 'The generator API is disabled when the marketing profile is not active.',
  },
  {
    status: '500 Internal Server Error',
    title: 'Render failed',
    description: 'The request was understood but image rendering failed. Check template fields and image sources.',
  },
]

function escapeMarkdownTableCell(value: string): string {
  return value.replace(/\|/g, '\\|').replace(/\n/g, ' ')
}

export function formatApiParameterMarkdownRow(
  parameter: GeneratorApiParameterDoc,
): string {
  const name = parameter.required ? `\`${parameter.name}\` (required)` : `\`${parameter.name}\``
  const parts = [parameter.description]
  if (parameter.defaultValue) {
    parts.push(`Default: ${parameter.defaultValue}.`)
  }
  if (parameter.enumValues?.length) {
    parts.push(`Values: ${parameter.enumValues.join(', ')}.`)
  }
  if (parameter.example) {
    parts.push(`Example: ${parameter.example}.`)
  }
  return `| ${name} | ${parameter.type} | ${escapeMarkdownTableCell(parts.join(' '))} |`
}

export function formatApiParametersMarkdownTable(
  parameters: GeneratorApiParameterDoc[],
): string {
  if (parameters.length === 0) return ''
  return [
    '| Parameter | Type | Description |',
    '| --- | --- | --- |',
    ...parameters.map(formatApiParameterMarkdownRow),
  ].join('\n')
}

export function formatApiResponseMarkdown(notes = GENERATOR_API_RESPONSE_NOTES): string {
  return notes
    .map((note) => `- **${note.status}**: ${note.description}`)
    .join('\n')
}
