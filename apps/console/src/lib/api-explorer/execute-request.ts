import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import type {
  ApiExplorerMethod,
  ApiExplorerConfig,
  ExecuteApiRequestInput,
  ExecuteApiRequestResult,
  ExecuteApiMultipartRequestInput,
} from './types'
import { assertAllowedExplorerRequestUrl } from './proxy-validation'
import { explorerFetch } from './explorer-http'
import { proxyApiExplorerRequestFn } from '@/server/functions/api-explorer'

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

function getResponseHeader(
  headers: Record<string, string>,
  name: string,
): string | undefined {
  const target = name.toLowerCase()
  for (const [key, value] of Object.entries(headers)) {
    if (key.toLowerCase() === target) return value
  }
  return undefined
}

function parseImageContentType(
  contentType: string | undefined,
): string | undefined {
  if (!contentType) return undefined
  const mediaType = contentType.split(';')[0]?.trim().toLowerCase()
  return mediaType?.startsWith('image/') ? mediaType : undefined
}

export function revokeApiExplorerImagePreview(
  url: string | null | undefined,
): void {
  if (url?.startsWith('blob:')) {
    URL.revokeObjectURL(url)
  }
}

function base64ToArrayBuffer(base64: string): ArrayBuffer {
  const binary = atob(base64)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i)
  }
  return bytes.buffer
}

function arrayBufferToDataUrl(buffer: ArrayBuffer, mediaType: string): string {
  const bytes = new Uint8Array(buffer)
  const chunks: string[] = []
  const chunkSize = 0x8000

  for (let i = 0; i < bytes.length; i += chunkSize) {
    chunks.push(
      String.fromCharCode(
        ...bytes.subarray(i, Math.min(i + chunkSize, bytes.length)),
      ),
    )
  }

  return `data:${mediaType};base64,${btoa(chunks.join(''))}`
}

function formatBinaryImageBody(mediaType: string, byteSize: number): string {
  return `(binary image, ${mediaType}, ${formatDecimalBytes(byteSize)})`
}

function buildExecuteApiRequestResult(input: {
  status: number
  statusText: string
  headers: Record<string, string>
  bodyBytes: ArrayBuffer
  durationMs: number
  ok: boolean
}): ExecuteApiRequestResult {
  const imageContentType = parseImageContentType(
    getResponseHeader(input.headers, 'content-type'),
  )

  if (imageContentType) {
    return {
      status: input.status,
      statusText: input.statusText,
      headers: input.headers,
      body: formatBinaryImageBody(imageContentType, input.bodyBytes.byteLength),
      durationMs: input.durationMs,
      ok: input.ok,
      imagePreviewUrl: arrayBufferToDataUrl(input.bodyBytes, imageContentType),
      responseContentType: imageContentType,
      responseByteSize: input.bodyBytes.byteLength,
    }
  }

  const responseText = new TextDecoder().decode(input.bodyBytes)
  const responseContentType = getResponseHeader(input.headers, 'content-type')

  return {
    status: input.status,
    statusText: input.statusText,
    headers: input.headers,
    body: formatResponseBody(responseText),
    durationMs: input.durationMs,
    ok: input.ok,
    responseContentType,
    responseByteSize: input.bodyBytes.byteLength,
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

  const proxied = await proxyApiExplorerRequestFn({
    data: {
      url,
      method: method.httpMethod.toUpperCase(),
      headers,
      body: hasBody ? body : undefined,
      allowedEndpoint: endpoint,
    },
  })

  return buildExecuteApiRequestResult({
    status: proxied.status,
    statusText: proxied.statusText,
    headers: proxied.headers,
    bodyBytes: base64ToArrayBuffer(proxied.bodyBase64),
    durationMs: proxied.durationMs,
    ok: proxied.ok,
  })
}

export async function executeApiMultipartRequest(
  input: ExecuteApiMultipartRequestInput,
): Promise<ExecuteApiRequestResult> {
  const { config, method, pathParams, queryParams, formData, requestAuth } =
    input
  const { endpoint, projectId, apiKey } = config

  const headers = buildExplorerRequestHeaders({
    projectId,
    apiKey,
    jwt:
      requestAuth?.mode === 'user' ? requestAuth.jwt : undefined,
  })

  const url = buildRequestUrl(endpoint, method.path, pathParams, queryParams)
  assertAllowedExplorerRequestUrl(url, endpoint)

  const started = performance.now()
  const response = await explorerFetch(url, {
    method: method.httpMethod.toUpperCase(),
    headers,
    body: formData,
  })
  const durationMs = Math.round(performance.now() - started)
  const bodyBytes = await response.arrayBuffer()

  const responseHeaders: Record<string, string> = {}
  response.headers.forEach((value, key) => {
    responseHeaders[key] = value
  })

  return buildExecuteApiRequestResult({
    status: response.status,
    statusText: response.statusText,
    headers: responseHeaders,
    bodyBytes,
    durationMs,
    ok: response.ok,
  })
}

export function isMultipartMethod(method: ApiExplorerMethod): boolean {
  return method.contentType === 'multipart/form-data'
}

export type { ApiExplorerConfig, ExecuteApiRequestResult }
