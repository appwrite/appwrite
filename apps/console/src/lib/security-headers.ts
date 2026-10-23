/**
 * Response headers every console document carries.
 *
 * Bun-safe (no path aliases / Vite-only constructs): `server.ts` imports it
 * raw for the prerendered and static HTML it serves itself, and the TanStack
 * request middleware applies it to rendered pages (Appwrite Sites serves
 * those without `server.ts`).
 *
 * The console is never meant to render inside another origin's frame, so
 * framing is limited to this origin (the legacy `/console` served by the API
 * sent `X-Frame-Options: SAMEORIGIN` for the same reason). The policy also
 * pins `<base>` to this origin and turns off plugin content, neither of which
 * the console uses. Script and style sources are not restricted here: the
 * shell relies on inline scripts and per-deployment third-party origins.
 * `nosniff` keeps browsers from running a mislabelled response as script,
 * and the referrer policy keeps console paths (project and resource ids) out
 * of third-party referrers.
 */
export const DOCUMENT_SECURITY_HEADERS: Readonly<Record<string, string>> = {
  'X-Frame-Options': 'SAMEORIGIN',
  'Content-Security-Policy':
    "frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
}

/** Add the document headers a response does not already set. */
export function applyDocumentSecurityHeaders(headers: Headers): Headers {
  for (const [name, value] of Object.entries(DOCUMENT_SECURITY_HEADERS)) {
    if (!headers.has(name)) {
      headers.set(name, value)
    }
  }
  return headers
}

/** The response with document headers when it is HTML, otherwise as is. */
export function withDocumentSecurityHeaders(response: Response): Response {
  const contentType = response.headers.get('content-type') ?? ''
  if (!contentType.includes('text/html')) {
    return response
  }
  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers: applyDocumentSecurityHeaders(new Headers(response.headers)),
  })
}
