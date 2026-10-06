import { test } from './fixtures'
import { expectPageRenders } from './helpers/smoke'

/**
 * Public website / marketing pages. No auth. Read-only navigation only.
 */
const WEBSITE_PAGES: Array<{ name: string; path: string; url?: RegExp }> = [
  { name: 'home', path: '/home' },
  { name: 'pricing', path: '/pricing' },
  { name: 'blog', path: '/blog' },
  { name: 'docs', path: '/docs' },
  { name: 'changelog', path: '/changelog' },
  { name: 'company', path: '/company' },
  { name: 'community', path: '/community' },
  { name: 'partners', path: '/partners' },
  { name: 'enterprise', path: '/enterprise' },
  { name: 'education', path: '/education' },
  { name: 'startups', path: '/startups' },
  { name: 'affiliates', path: '/affiliates' },
  { name: 'integrations', path: '/integrations' },
  { name: 'domains', path: '/domains' },
  { name: 'privacy', path: '/privacy' },
  { name: 'terms', path: '/terms' },
  { name: 'cookies', path: '/cookies' },
  { name: 'product auth', path: '/products/auth' },
  { name: 'product databases', path: '/products/databases' },
  { name: 'product storage', path: '/products/storage' },
  { name: 'product functions', path: '/products/functions' },
  { name: 'product messaging', path: '/products/messaging' },
  { name: 'product sites', path: '/products/sites' },
  { name: 'alternative to supabase', path: '/alternative-to/supabase' },
  { name: 'alternative to firebase', path: '/alternative-to/firebase' },
  { name: 'alternative to vercel', path: '/alternative-to/vercel' },
  { name: 'alternative to netlify', path: '/alternative-to/netlify' },
  { name: 'alternative to neon', path: '/alternative-to/neon' },
  { name: 'alternative to auth0', path: '/alternative-to/auth0' },
  { name: 'alternative to convex', path: '/alternative-to/convex' },
  { name: 'alternative to cloudinary', path: '/alternative-to/cloudinary' },
  { name: 'alternative to clerk', path: '/alternative-to/clerk' },
  { name: 'alternative to amplify', path: '/alternative-to/amplify' },
  { name: 'alternative to planetscale', path: '/alternative-to/planetscale' },
]

test.describe('website smoke (read-only)', () => {
  for (const pageDef of WEBSITE_PAGES) {
    test(`${pageDef.name} renders`, async ({ page }) => {
      await expectPageRenders(page, pageDef.path, {
        // Allow trailing segments (e.g. /docs → /docs/…) and query strings.
        url:
          pageDef.url ??
          new RegExp(`${pageDef.path.replace(/\//g, '\\/')}(?:/|\\?|$)`),
      })
    })
  }
})
