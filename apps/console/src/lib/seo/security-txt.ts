import { normalizeWellKnownPath } from './change-password-url.ts'
import { CANONICAL_HOST } from './indexing.ts'

/**
 * RFC 9116 security.txt: where researchers report vulnerabilities and abuse.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9116
 */
export const SECURITY_TXT_WELL_KNOWN_PATH = '/.well-known/security.txt'

const EXPIRES_AFTER_DAYS = 365

/**
 * Pinned to the public origin: behind TLS termination the request origin can be
 * internal HTTP, and RFC 9116 clients reject a file fetched from a URL missing
 * from its Canonical entries.
 */
const CANONICAL_URL = `https://${CANONICAL_HOST}${SECURITY_TXT_WELL_KNOWN_PATH}`

export function buildSecurityTxt(now: Date = new Date()): string {
  const expires = new Date(now.getTime() + EXPIRES_AFTER_DAYS * 86_400_000)
  expires.setUTCHours(0, 0, 0, 0)

  return [
    'Contact: mailto:security@appwrite.io',
    'Contact: https://github.com/appwrite/appwrite/security/advisories/new',
    `Expires: ${expires.toISOString()}`,
    'Preferred-Languages: en',
    `Canonical: ${CANONICAL_URL}`,
    'Policy: https://github.com/appwrite/appwrite/security/policy',
    '',
  ].join('\n')
}

/** HTTP response for the security.txt well-known URL. Returns null for unrelated paths. */
export function wellKnownSecurityTxtResponse(
  request: Request,
  pathname?: string,
): Response | null {
  if (
    normalizeWellKnownPath(pathname ?? new URL(request.url).pathname) !==
    SECURITY_TXT_WELL_KNOWN_PATH
  ) {
    return null
  }

  return new Response(buildSecurityTxt(), {
    status: 200,
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'Cache-Control': 'public, max-age=86400',
      'Access-Control-Allow-Origin': '*',
    },
  })
}
