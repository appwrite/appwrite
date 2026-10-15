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

function retrievalBotAllowBlocks(): string {
  return RETRIEVAL_BOTS.map(
    (bot) => `User-agent: ${bot}
Allow: /`,
  ).join('\n\n')
}

function defaultUserAgentBlock(): string {
  return `User-agent: *
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
Disallow: /cache`
}

export function getProductionRobotsTxt(): string {
  return `# https://www.robotstxt.org/robotstxt.html
${defaultUserAgentBlock()}

# Retrieval crawlers that answer live queries (not training-only bots)
${retrievalBotAllowBlocks()}

Sitemap: https://appwrite.io/sitemap.xml
Sitemap: https://appwrite.io/sitemap/news.xml
`
}
