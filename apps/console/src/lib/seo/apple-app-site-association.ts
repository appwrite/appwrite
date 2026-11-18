import { normalizeWellKnownPath } from './change-password-url.ts'

/**
 * Lets the Beep iOS app use passwords saved for this site, so password managers
 * offer them instead of treating it as an unrelated app.
 *
 * @see https://developer.apple.com/documentation/xcode/supporting-associated-domains
 */
export const APPLE_APP_SITE_ASSOCIATION_WELL_KNOWN_PATH =
  '/.well-known/apple-app-site-association'

const ASSOCIATION = {
  webcredentials: {
    apps: ['GWZ23QTYB5.io.appwrite.beep'],
  },
}

/** HTTP response for the association well-known URL. Returns null for unrelated paths. */
export function wellKnownAppleAppSiteAssociationResponse(
  request: Request,
  pathname?: string,
): Response | null {
  if (
    normalizeWellKnownPath(pathname ?? new URL(request.url).pathname) !==
    APPLE_APP_SITE_ASSOCIATION_WELL_KNOWN_PATH
  ) {
    return null
  }

  return new Response(JSON.stringify(ASSOCIATION), {
    status: 200,
    headers: {
      'Content-Type': 'application/json',
      'Cache-Control': 'public, max-age=3600',
    },
  })
}
