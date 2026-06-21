import type {
  ApiExplorerConfig,
  ApiExplorerMethod,
  ApiExplorerRequestAuth,
} from './types'

function shellQuote(value: string): string {
  return `'${value.replace(/'/g, `'\\''`)}'`
}

function buildExplorerRequestHeaders(input: {
  projectId: string
  apiKey?: string
  jwt?: string
  contentType?: string
}): Record<string, string> {
  const headers: Record<string, string> = {
    'X-Appwrite-Project': input.projectId,
  }

  if (input.contentType) {
    headers['Content-Type'] = input.contentType
  }

  if (input.apiKey?.trim()) {
    headers['X-Appwrite-Key'] = input.apiKey.trim()
  }

  if (input.jwt?.trim()) {
    headers['X-Appwrite-JWT'] = input.jwt.trim()
  }

  return headers
}

function buildRequestUrl(
  endpoint: string,
  path: string,
  pathParams: Record<string, string>,
  queryParams: Record<string, string>,
): string {
  let resolvedPath = path
  for (const [key, value] of Object.entries(pathParams)) {
    resolvedPath = resolvedPath.replace(
      `{${key}}`,
      encodeURIComponent(value),
    )
  }

  const base = endpoint.replace(/\/$/, '')
  const url = new URL(`${base}${resolvedPath}`)

  for (const [key, value] of Object.entries(queryParams)) {
    if (value.trim() === '') continue

    let parsedArray: unknown[] | null = null
    try {
      const parsed = JSON.parse(value)
      if (Array.isArray(parsed)) parsedArray = parsed
    } catch {
      parsedArray = null
    }

    if (parsedArray) {
      for (const item of parsedArray) {
        url.searchParams.append(`${key}[]`, String(item))
      }
      continue
    }

    url.searchParams.set(key, value)
  }

  return url.toString()
}

export type BuildCurlCommandInput = {
  config: ApiExplorerConfig
  method: ApiExplorerMethod
  pathParams: Record<string, string>
  queryParams: Record<string, string>
  body?: string
  formData?: FormData
  requestAuth?: ApiExplorerRequestAuth
}

export function buildCurlCommand(input: BuildCurlCommandInput): string {
  const { config, method, pathParams, queryParams, body, formData, requestAuth } =
    input
  const methodUpper = method.httpMethod.toUpperCase()
  const url = buildRequestUrl(
    config.endpoint,
    method.path,
    pathParams,
    queryParams,
  )

  const contentType = method.contentType ?? 'application/json'
  const hasJsonBody =
    !formData &&
    body !== undefined &&
    body.trim() !== '' &&
    methodUpper !== 'GET' &&
    methodUpper !== 'HEAD'

  const headers = buildExplorerRequestHeaders({
    projectId: config.projectId,
    apiKey: config.apiKey,
    jwt: requestAuth?.mode === 'user' ? requestAuth.jwt : undefined,
    contentType:
      hasJsonBody && contentType === 'application/json'
        ? contentType
        : undefined,
  })

  const lines: string[] = [`curl -X ${methodUpper} ${shellQuote(url)} \\`]

  for (const [key, value] of Object.entries(headers)) {
    lines.push(`  -H ${shellQuote(`${key}: ${value}`)} \\`)
  }

  if (formData) {
    for (const [key, value] of formData.entries()) {
      if (value instanceof File) {
        lines.push(`  -F ${shellQuote(`${key}=@${value.name}`)} \\`)
      } else {
        lines.push(`  -F ${shellQuote(`${key}=${String(value)}`)} \\`)
      }
    }
  } else if (hasJsonBody) {
    lines.push(`  -d ${shellQuote(body!)} \\`)
  }

  const lastIndex = lines.length - 1
  lines[lastIndex] = lines[lastIndex].replace(/ \\$/, '')

  return lines.join('\n')
}
