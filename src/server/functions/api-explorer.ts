import { createServerFn } from '@tanstack/react-start'
import { z } from 'zod'
import { assertAllowedExplorerRequestUrl } from '@/lib/api-explorer/proxy-validation'

const proxyApiExplorerRequestSchema = z.object({
  url: z.string().url(),
  method: z.string().min(1),
  headers: z.record(z.string(), z.string()),
  body: z.string().optional(),
  allowedEndpoint: z.string().url(),
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
  .inputValidator(proxyApiExplorerRequestSchema)
  .handler(async ({ data }): Promise<ProxyApiExplorerRequestResult> => {
    assertAllowedExplorerRequestUrl(data.url, data.allowedEndpoint)

    const startedAt = performance.now()

    const response = await fetch(data.url, {
      method: data.method.toUpperCase(),
      headers: data.headers,
      body: data.body,
      cache: 'no-store',
      redirect: 'manual',
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
