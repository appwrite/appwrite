import { createMiddleware } from '@tanstack/react-start'
import {
  injectRuntimeConfigIntoHtml,
  readRuntimeConfigFromEnv,
  serializeRuntimeConfig,
} from '@/lib/runtime-config-shared'

const RUNTIME_CONFIG_JSON = serializeRuntimeConfig(
  readRuntimeConfigFromEnv(process.env),
)

/**
 * Appwrite Sites serves TanStack SSR directly (no server.ts wrapper). Stamp live
 * env into HTML responses so prerender placeholders and build-time config do not
 * reach the browser.
 */
export const runtimeConfigMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ next }) => {
  const result = await next()
  const response = result.response
  if (!response?.headers) {
    return result
  }

  const contentType = response.headers.get('content-type') ?? ''
  if (!contentType.includes('text/html')) {
    return result
  }

  const html = await response.text()
  const injected = injectRuntimeConfigIntoHtml(html, RUNTIME_CONFIG_JSON)

  const headers = new Headers(response.headers)
  headers.delete('content-length')
  return {
    ...result,
    response: new Response(injected, {
      status: response.status,
      statusText: response.statusText,
      headers,
    }),
  }
})
