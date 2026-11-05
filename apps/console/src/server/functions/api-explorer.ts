import { createServerFn } from '@tanstack/react-start'
import { z } from 'zod'
import {
  EXPLORER_PROXY_METHODS,
  assertAllowedExplorerRequestUrl,
  getExplorerProxyEndpoint,
} from '@/lib/api-explorer/proxy-validation'
import { getRuntimeConfig } from '@/lib/runtime-config'

const PROXY_TIMEOUT_MS = 60_000

export const proxyApiExplorerRequestSchema = z.object({
  url: z.string().url(),
  method: z.enum(EXPLORER_PROXY_METHODS),
  headers: z
    .object({
      'X-Appwrite-Project': z.string().min(1),
      'X-Appwrite-Key': z.string().optional(),
      'X-Appwrite-JWT': z.string().optional(),
      'Content-Type': z.literal('application/json').optional(),
    })
    .strict(),
  body: z.string().optional(),
})

export type ProxyApiExplorerRequestResult = {
  status: number
  statusText: string
  headers: Record<string, string>
  bodyBase64: string
  durationMs: number
  ok: boolean
}

export const proxyApiExplorerRequestFn = createServerFn({ method: 'POST' })
  .validator(proxyApiExplorerRequestSchema)
  .handler(async ({ data }): Promise<ProxyApiExplorerRequestResult> => {
    const endpoint = getExplorerProxyEndpoint(getRuntimeConfig())
    if (!endpoint) {
      throw new Error('Explorer proxy is not available on this console')
    }
    assertAllowedExplorerRequestUrl(data.url, endpoint)

    const headers: Record<string, string> = {}
    for (const [name, value] of Object.entries(data.headers)) {
      if (value) headers[name] = value
    }

    const startedAt = performance.now()

    const response = await fetch(data.url, {
      method: data.method,
      headers,
      body: data.body,
      cache: 'no-store',
      redirect: 'manual',
      signal: AbortSignal.timeout(PROXY_TIMEOUT_MS),
    })

    const buffer = Buffer.from(await response.arrayBuffer())
    const responseHeaders: Record<string, string> = {}
    response.headers.forEach((value, key) => {
      responseHeaders[key] = value
    })

    return {
      status: response.status,
      statusText: response.statusText,
      headers: responseHeaders,
      bodyBase64: buffer.toString('base64'),
      durationMs: Math.round(performance.now() - startedAt),
      ok: response.ok,
    }
  })
