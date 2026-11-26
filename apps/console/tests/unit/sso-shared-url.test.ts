import { describe, expect, test } from 'bun:test'
import { parseSearch } from '@/lib/router-search'
import { ssoFailureMessage, ssoSignInURLs } from '@/lib/sso-sign-in'
import {
  hasSSOError,
  requestOrigin,
  ssoSearchSchema,
  ssoSharedProvider,
  ssoSharedURLRedirect,
  ssoSignInURL,
} from '@/lib/sso-shared-url'

const endpoint = 'https://cloud.example/v1'

/** A browser opening `path` on the Console at `origin`. */
function navigation(
  path: string,
  headers: Record<string, string> = {},
  origin = 'https://console.example',
) {
  return new Request(`${origin}${path}`, {
    headers: { 'sec-fetch-dest': 'document', ...headers },
  })
}

describe('ssoSharedURLRedirect', () => {
  test('sends a shared SSO URL to the sign-in the page would start', () => {
    const location = ssoSharedURLRedirect(
      navigation('/sso?provider=provider-okta'),
      endpoint,
    )
    const { success, failure } = ssoSignInURLs(
      'https://console.example',
      undefined,
    )
    expect(location).toBe(
      ssoSignInURL(endpoint, 'console', 'provider-okta', success, failure),
    )

    const url = new URL(location ?? '')
    expect(url.origin + url.pathname).toBe(`${endpoint}/account/sessions/sso`)
    expect(url.searchParams.get('project')).toBe('console')
    expect(url.searchParams.get('providerId')).toBe('provider-okta')
    // Without a page to return to, the API picks the provider's organization
    expect(url.searchParams.has('success')).toBe(false)
    expect(url.searchParams.get('failure')).toBe('https://console.example/sso')
  })

  test('reads the provider as the page does', () => {
    for (const path of [
      '/sso/?provider=provider-okta',
      '/sso?required=true&provider=provider-okta',
      '/sso?provider=provider-okta&error=',
      '/sso?provider=provider-okta&redirect=',
    ]) {
      const location = ssoSharedURLRedirect(navigation(path), endpoint)
      expect(new URL(location ?? '').searchParams.get('providerId'), path).toBe(
        'provider-okta',
      )
    }
    // Numbers stay text, as in the router
    const location = ssoSharedURLRedirect(
      navigation('/sso?provider=6507'),
      endpoint,
    )
    expect(new URL(location ?? '').searchParams.get('providerId')).toBe('6507')
  })

  test('leaves the page to answer when there is no sign-in to start', () => {
    for (const path of [
      '/sso',
      '/sso?provider=',
      '/sso?required=true',
      // A failed sign-in is shown, not started again
      `/sso?provider=provider-okta&error=${encodeURIComponent('{"type":"user_oauth2_provider_failure"}')}`,
      '/sso?provider=provider-okta&error=anything',
      // What the page cannot read, it refuses itself
      '/sso?provider=provider-okta&required=maybe',
      '/sign-in?provider=provider-okta',
      '/sso/other?provider=provider-okta',
      '/organizations/sso?provider=provider-okta',
    ]) {
      expect(ssoSharedURLRedirect(navigation(path), endpoint), path).toBeNull()
    }
  })

  test('leaves a page to return to for the page to check', () => {
    for (const redirect of [
      '/organizations/abc/settings',
      'https://evil.example',
      '//evil.example',
    ]) {
      const path = `/sso?provider=provider-okta&redirect=${encodeURIComponent(redirect)}`
      expect(ssoSharedURLRedirect(navigation(path), endpoint), path).toBeNull()
    }
  })

  test('redirects only a browser navigation', () => {
    const path = 'https://console.example/sso?provider=provider-okta'
    // A link preview or a script fetching the URL gets the page
    expect(ssoSharedURLRedirect(new Request(path), endpoint)).toBeNull()
    expect(
      ssoSharedURLRedirect(
        new Request(path, { headers: { 'sec-fetch-dest': 'empty' } }),
        endpoint,
      ),
    ).toBeNull()
    expect(
      ssoSharedURLRedirect(
        new Request(path, {
          method: 'POST',
          headers: { 'sec-fetch-dest': 'document' },
        }),
        endpoint,
      ),
    ).toBeNull()
    // A browser that sends no fetch metadata still asks for a document
    expect(
      ssoSharedURLRedirect(
        new Request(path, { headers: { accept: 'text/html,*/*;q=0.8' } }),
        endpoint,
      ),
    ).not.toBeNull()
  })
})

describe('requestOrigin', () => {
  test('is what the proxies in front of the Console report', () => {
    const request = new Request('http://10.0.0.7:3000/sso', {
      headers: {
        'x-forwarded-host': 'console.example, internal.example',
        'x-forwarded-proto': 'https, http',
      },
    })
    expect(requestOrigin(request)).toBe('https://console.example')
  })

  test('is HTTPS on a public host that reports no protocol', () => {
    expect(requestOrigin(new Request('http://console.example/sso'))).toBe(
      'https://console.example',
    )
    expect(
      requestOrigin(
        new Request('http://10.0.0.7:3000/sso', {
          headers: { 'x-forwarded-host': 'console.example' },
        }),
      ),
    ).toBe('https://console.example')
  })

  test('keeps plain HTTP and the port on a local host', () => {
    expect(requestOrigin(new Request('http://localhost:4173/sso'))).toBe(
      'http://localhost:4173',
    )
    expect(requestOrigin(new Request('http://127.0.0.1:3000/sso'))).toBe(
      'http://127.0.0.1:3000',
    )
  })

  test('is unknown when the forwarded host is not a host', () => {
    const request = new Request('http://localhost:4173/sso', {
      headers: { 'x-forwarded-host': 'not a host/' },
    })
    expect(requestOrigin(request)).toBeNull()
    expect(
      ssoSharedURLRedirect(
        new Request('http://localhost:4173/sso?provider=provider-okta', {
          headers: {
            'sec-fetch-dest': 'document',
            'x-forwarded-host': 'not a host/',
          },
        }),
        endpoint,
      ),
    ).toBeNull()
  })
})

describe('ssoSharedProvider', () => {
  /** The search the router hands the page for a URL query string. */
  const search = (query: string) => ssoSearchSchema.parse(parseSearch(query))

  test('is the provider of a shared SSO URL', () => {
    expect(ssoSharedProvider(search('?provider=provider-okta'))).toBe(
      'provider-okta',
    )
    expect(ssoSharedProvider(search(''))).toBeUndefined()
    expect(ssoSharedProvider(search('?provider='))).toBeUndefined()
  })

  test('is none where the page shows a failed sign-in', () => {
    for (const error of [
      '{"type":"user_oauth2_state_invalid"}',
      '{"message":"Single sign-on could not finish."}',
      'not json',
      '{}',
      '',
    ]) {
      const query = `?provider=provider-okta&error=${encodeURIComponent(error)}`
      const parsed = search(query)
      const shown = ssoFailureMessage(parsed.error) !== null
      expect(hasSSOError(parsed.error), error).toBe(shown)
      expect(ssoSharedProvider(parsed) === undefined, error).toBe(shown)
    }
  })
})
