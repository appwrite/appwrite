import { describe, expect, test } from 'bun:test'
import { DOCS_PAGES } from '@/lib/docs/generated/manifest'
import { getLegacyRedirectTarget } from '@/lib/seo/legacy-redirects'
import { PRODUCT_IDS } from '@/lib/products/registry'

const docsSlugs = new Set(DOCS_PAGES.map((page) => page.slug))

function target(pathname: string, search = '') {
  return getLegacyRedirectTarget(pathname, search)
}

describe('legacy redirects that already existed', () => {
  test('sends contact and flat database docs to their current pages', () => {
    expect(target('/contact-us')).toBe('/enterprise')
    expect(target('/docs/products/databases/pagination')).toBe(
      '/docs/products/databases/tablesdb/pagination',
    )
    expect(target('/docs/products/databases/legacy/collections')).toBe(
      '/docs/products/databases/tablesdb/legacy/collections',
    )
    expect(target('/docs/products/databases/documents')).toBe(
      '/docs/products/databases/tablesdb/rows',
    )
    expect(target('/blog/category/case-studies')).toBe(
      '/blog/categories/customer-stories',
    )
  })

  test('does not redirect live pages', () => {
    expect(target('/pricing')).toBeNull()
    expect(target('/docs/products/auth')).toBeNull()
    expect(target('/docs/products/databases/tablesdb/pagination')).toBeNull()
    expect(target('/docs/references/cloud/client-web/account')).toBeNull()
    expect(target('/blog/post/what-is-mcp')).toBeNull()
    expect(target('/sign-in')).toBeNull()
    expect(target('/account/sessions')).toBeNull()
    expect(target('/products/sites')).toBeNull()
    expect(target('/products/analytics')).toBeNull()
  })

  test('never redirects a live product page', () => {
    // LIVE_PRODUCTS in inbound-redirects.ts must list every product page, or
    // full page loads 301 to `/` (and signed-in users bounce to the console).
    for (const id of PRODUCT_IDS) {
      expect(target(`/products/${id}`)).toBeNull()
    }
    expect(target('/docs/tooling/command-line/installation')).toBeNull()
    expect(target('/docs/partners/project/key-rotation.md')).toBeNull()
    expect(target('/docs/llms.txt')).toBeNull()
  })
})

describe('inbound 404 redirects', () => {
  test('strips pathless layout prefixes', () => {
    expect(target('/_marketing/pricing')).toBe('/pricing')
    expect(target('/_marketing/blog/')).toBe('/blog')
    expect(target('/_auth/sign-in')).toBe('/sign-in')
    expect(target('/_auth/sign-out')).toBe('/sign-out')
    expect(target('/_public/account')).toBe('/account')
    expect(target('/_protected')).toBe('/sign-in')
    expect(target('/_public/projects/$projectId/onboarding')).toBe('/sign-in')
  })

  test('repairs docs links with a missing section', () => {
    expect(target('/docs/$undefined/functions/templates')).toBe(
      '/docs/products/functions/templates',
    )
    expect(target('/docs/$products/sites/quick-start/flutter')).toBe(
      '/docs/products/sites/quick-start/flutter',
    )
    expect(target('/docs/undefined/nextjs/step-8')).toBe(
      '/docs/tutorials/nextjs/step-8',
    )
    expect(target('/docs/undefined/firewall/priority')).toBe(
      '/docs/products/firewall/priority',
    )
    expect(target('/docs/$advanced/billing/uptime-sla')).toBe(
      '/docs/advanced/billing/uptime-sla',
    )
    expect(target('/docs/$undefined/databases/transactions')).toBe(
      '/docs/products/databases/tablesdb/transactions',
    )
    expect(target('/docs/$$')).toBe('/docs')
    expect(target('/docs/references/$version/$platform/$service')).toBe(
      '/docs/references/cloud/client-web/account',
    )
    expect(target('/docs/references/cloud/server-rest')).toBe(
      '/docs/references/cloud/server-rest/account',
    )
    expect(target('/docs/references/cloud/client-web/tables')).toBe(
      '/docs/references/cloud/client-web/tablesDB',
    )
    expect(target('/docs/references/cloud/models/oAuth2Box')).toBe(
      '/docs/products/auth/oauth2',
    )
    expect(target('/docs/products/databases/mysql/concepts')).toBe(
      '/docs/products/databases/mysql',
    )
    expect(target('/docs/products/sites/quick-starts')).toBe(
      '/docs/products/sites/quick-start',
    )
    expect(target('/docs/products/realtime/presence')).toBe(
      '/docs/apis/realtime/presences',
    )
    expect(docsSlugs.has('products/functions/templates')).toBe(true)
    expect(docsSlugs.has('advanced/billing/uptime-sla')).toBe(true)
  })

  test('sends renamed blog, changelog, and marketing URLs to the current page', () => {
    expect(target('/blog/post/drizzle')).toBe(
      '/blog/post/drizzle-orm-appwrite-postgres',
    )
    expect(target('/blog/post/init-august-2026')).toBe(
      '/blog/post/appwrite-init-2026-recap',
    )
    expect(target('/blog/categories/engineering')).toBe('/blog')
    expect(target('/changelog/10')).toBe('/changelog')
    expect(target('/changelog/webhooks')).toBe('/changelog/entry/2026-04-22')
    expect(target('/cloud-ga')).toBe('/blog/post/product-update-august-2025')
    expect(target('/compare/firebase')).toBe('/alternative-to/firebase')
    expect(target('/compare/netlify')).toBe('/alternative-to/netlify')
    expect(target('/signin')).toBe('/sign-in')
    expect(target('/login', '?error=oauth')).toBe('/auth/oauth2/failure')
    expect(target('/legal/baa')).toBe('/baa')
    expect(target('/partners/catalog/sayone-technologies')).toBe('/partners')
    expect(target('/init/tickets/pezzin')).toBe('/init/pezzin')
    expect(target('/oauth/success')).toBe('/auth/oauth2/success')
    expect(target('/database/events/create')).toBe('/docs/apis/events')
    expect(target('/oss-fund-announcement')).toBe('/blog/post/announcing-the-appwrite-oss-program')
    expect(target('/images/blog-local/hyperloop-b/cover.avif')).toBe(
      '/images/blog/hyperloop-b/cover.avif',
    )
    expect(target('/images/integrations/avatars/sentry.avif')).toBe(
      '/images/integrations/logging-sentry/cover.avif',
    )
  })

  test('sends API paths crawled on the website to docs', () => {
    expect(target('/functions')).toBe('/products/functions')
    expect(target('/functions/%7BfunctionId%7D/executions')).toBe(
      '/docs/products/functions',
    )
    expect(target('/functions/runtimes')).toBe('/docs/products/functions/runtimes')
    expect(target('/tablesdb/transactions')).toBe(
      '/docs/products/databases/tablesdb/transactions',
    )
    expect(target('/postgresql')).toBe('/products/postgres')
    expect(target('/project/oauth2/google')).toBe('/docs/products/auth/oauth2')
    expect(target('/account/jwts')).toBe('/docs/products/auth/jwt')
  })

  test('keeps recovery secrets on the reset page', () => {
    expect(target('/recovery', '?userId=abc&secret=def')).toBe('/reset')
    expect(target('/recovery')).toBeNull()
    expect(target('/verify/email', '?userId=abc&secret=def')).toBe(
      '/verify-email',
    )
    expect(target('/account/recovery')).toBe('/recovery')
  })
})
