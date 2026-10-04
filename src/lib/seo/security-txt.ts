import { normalizeWellKnownPath } from './change-password-url.ts'

/**
 * RFC 9116 security.txt: where researchers report vulnerabilities and abuse.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9116
 */
export const SECURITY_TXT_WELL_KNOWN_PATH = '/.well-known/security.txt'

const EXPIRES_AFTER_DAYS = 365

export function buildSecurityTxt(
  origin: string,
  now: Date = new Date(),
): string {
  const expires = new Date(now.getTime() + EXPIRES_AFTER_DAYS * 86_400_000)
  expires.setUTCHours(0, 0, 0, 0)

  return [
    'Contact: mailto:security@appwrite.io',
    'Contact: https://github.com/appwrite/appwrite/security/advisories/new',
    `Expires: ${expires.toISOString()}`,
    'Preferred-Languages: en',
    `Canonical: ${origin}${SECURITY_TXT_WELL_KNOWN_PATH}`,
    'Policy: https://github.com/appwrite/appwrite/security/policy',
    '',
  ].join('\n')
}

/** HTTP response for the security.txt well-known URL. Returns null for unrelated paths. */
export function wellKnownSecurityTxtResponse(
  request: Request,
  pathname?: string,
): Response | null {
  const url = new URL(request.url)
  if (
    normalizeWellKnownPath(pathname ?? url.pathname) !==
    SECURITY_TXT_WELL_KNOWN_PATH
  ) {
    return null
  }

  return new Response(buildSecurityTxt(url.origin), {
    status: 200,
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'Cache-Control': 'public, max-age=86400',
      'Access-Control-Allow-Origin': '*',
    },
  })
}
