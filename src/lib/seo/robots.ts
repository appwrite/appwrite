/**
 * Production robots.txt body. Served by the `/robots.txt` route (and written to
 * `public/robots.txt` by generate:docs-exports for static mirrors).
 */
export function getProductionRobotsTxt(): string {
  return `# https://www.robotstxt.org/robotstxt.html
User-agent: *
Allow: /

# Console and authenticated areas (not public marketing content)
Disallow: /projects/
Disallow: /organizations/
Disallow: /account/
Disallow: /sign-in
Disallow: /sign-up
Disallow: /sign-out
Disallow: /recovery
Disallow: /reset
Disallow: /join
Disallow: /mfa
Disallow: /verify-email
Disallow: /debug/
Disallow: /_protected/
Disallow: /comps
Disallow: /blocks
Disallow: /cache

Sitemap: https://appwrite.io/sitemap.xml
`
}
