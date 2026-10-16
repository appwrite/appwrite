/**
 * Production robots.txt body. Served by the `/robots.txt` route (and written to
 * `public/robots.txt` by generate:docs-exports for static mirrors).
 *
 * Use this file for crawl **policy** (console/auth areas, bot-specific allow
 * lists). Duplicate URL variants (UTM query strings, versioned API reference
 * mirrors) are handled with canonical links, `noindex`, and sitemap allowlists
 * instead of Disallow rules.
 *
 * Rule groups must stay complete under each `User-agent` line. Crawlers only
 * apply directives in their matched group (falling back to `*`), so never append
 * `Disallow` lines after another bot's block.
 */
const RETRIEVAL_BOTS = [
  'OAI-SearchBot',
  'ChatGPT-User',
  'Claude-User',
  'Claude-SearchBot',
  'PerplexityBot',
  'Google-Extended',
] as const

/** Console and authenticated areas (not public marketing content). */
const CONSOLE_PATHS = [
  '/projects/',
  '/organizations/',
  '/account/',
  '/sign-in',
  '/sign-up',
  '/sign-out',
  '/recovery',
  '/reset',
  '/join',
  '/mfa',
  '/verify-email',
  '/debug/',
  '/_protected/',
  '/comps',
  '/blocks',
  '/cache',
] as const

function userAgentBlock(userAgent: string): string {
  return [
    `User-agent: ${userAgent}`,
    'Allow: /',
    ...CONSOLE_PATHS.map((path) => `Disallow: ${path}`),
  ].join('\n')
}

export function getProductionRobotsTxt(): string {
  return `# https://www.robotstxt.org/robotstxt.html
${userAgentBlock('*')}

# Retrieval crawlers that answer live queries, and Google-Extended (the token
# that controls Gemini use). Each repeats the full rules, since a named group
# replaces the * group for that crawler.
${RETRIEVAL_BOTS.map(userAgentBlock).join('\n\n')}

Sitemap: https://appwrite.io/sitemap.xml
Sitemap: https://appwrite.io/sitemap/news.xml
`
}
