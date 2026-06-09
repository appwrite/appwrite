import { explorerFetch } from './explorer-http'
import type {
  ApiExplorerMethod,
  ApiExplorerConfig,
  ExecuteApiRequestInput,
  ExecuteApiRequestResult,
} from './types'

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

function formatResponseBody(body: string): string {
  if (!body.trim()) return body
  try {
    return JSON.stringify(JSON.parse(body), null, 2)
  } catch {
    return body
  }
}

export async function executeApiRequest(
  input: ExecuteApiRequestInput,
): Promise<ExecuteApiRequestResult> {
  const { config, method, pathParams, queryParams, body, requestAuth } = input
  const { endpoint, projectId, apiKey } = config

  const contentType = method.contentType ?? 'application/json'
  const hasBody =
    method.httpMethod !== 'get' &&
    method.httpMethod !== 'head' &&
    body !== undefined &&
    body.trim() !== ''

  const headers = buildExplorerRequestHeaders({
    projectId,
    apiKey,
    jwt:
      requestAuth?.mode === 'user' ? requestAuth.jwt : undefined,
    contentType:
      hasBody && contentType === 'application/json' ? contentType : undefined,
  })

  const url = buildRequestUrl(endpoint, method.path, pathParams, queryParams)
  const startedAt = performance.now()

  const response = await explorerFetch(url, {
    method: method.httpMethod.toUpperCase(),
    headers,
    body: hasBody ? body : undefined,
  })

  const durationMs = Math.round(performance.now() - startedAt)
  const responseText = await response.text()
  const responseHeaders: Record<string, string> = {}
  response.headers.forEach((value, key) => {
    responseHeaders[key] = value
  })

  return {
    status: response.status,
    statusText: response.statusText,
    headers: responseHeaders,
    body: formatResponseBody(responseText),
    durationMs,
    ok: response.ok,
  }
}

export function isMultipartMethod(method: ApiExplorerMethod): boolean {
  return method.contentType === 'multipart/form-data'
}

export type { ApiExplorerConfig, ExecuteApiRequestResult }
