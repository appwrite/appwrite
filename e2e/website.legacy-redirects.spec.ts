import { expect, test } from '@playwright/test'

/**
 * Legacy URL redirects (src/lib/seo/legacy-redirects.ts). The Location must be
 * path-only: behind the TLS-terminating proxy an absolute target is built
 * from an http:// request URL and adds an http hop to every redirect.
 */

const LEGACY_REDIRECTS: Array<{
  name: string
  path: string
  location: string
}> = [
  {
    name: 'flat databases docs page keeps the query string',
    path: '/docs/products/databases/rows?sdk=web-default',
    location: '/docs/products/databases/tablesdb/rows?sdk=web-default',
  },
  {
    name: 'renamed databases docs slug',
    path: '/docs/products/databases/collections',
    location: '/docs/products/databases/tablesdb/tables',
  },
  {
    name: 'spatial alias keeps the target fragment',
    path: '/docs/products/databases/spatial',
    location: '/docs/products/databases/tablesdb/geo-queries#spatial-columns',
  },
]

test.describe('legacy redirects', () => {
  for (const { name, path, location } of LEGACY_REDIRECTS) {
    test(`${name} (${path})`, async ({ request }) => {
      const response = await request.get(path, { maxRedirects: 0 })
      expect(response.status()).toBe(301)
      expect(response.headers()['location']).toBe(location)

      const target = await request.get(location.split('#')[0])
      expect(target.status()).toBe(200)
    })
  }
})
