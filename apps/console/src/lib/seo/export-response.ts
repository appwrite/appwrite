/** Shared response helpers for Markdown / discovery export routes. */

export function markdownExportResponse(body: string): Response {
  return new Response(body, {
    headers: {
      'Content-Type': 'text/markdown; charset=utf-8',
      'Cache-Control': 'public, max-age=3600',
    },
  })
}

export function jsonExportResponse(body: string): Response {
  return new Response(body, {
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'public, max-age=3600',
    },
  })
}

/**
 * Prefer a prebuilt file from dist/client in production; fall back to runtime.
 */
export async function respondWithPrebuiltOrRuntime(
  relativePath: string,
  contentType: string,
  runtime: () => string | Promise<string>,
): Promise<Response> {
  if (process.env.NODE_ENV === 'production') {
    const { respondWithClientStaticFile } = await import(
      '@/lib/marketing/static-exports'
    )
    const prebuilt = await respondWithClientStaticFile(relativePath, contentType)
    if (prebuilt.status !== 404) return prebuilt
  }

  const body = await runtime()
  return new Response(body, {
    headers: {
      'Content-Type': contentType,
      'Cache-Control': 'public, max-age=3600',
    },
  })
}
