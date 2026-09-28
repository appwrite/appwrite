/**
 * Production robots.txt body. Served by the `/robots.txt` route (and written to
 * `public/robots.txt` by generate:docs-exports for static mirrors).
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

export function getProductionRobotsTxt(): string {
  return `# https://www.robotstxt.org/robotstxt.html
User-agent: *
Allow: /

# Retrieval crawlers that answer live queries (not training-only bots)
${retrievalBotAllowBlocks()}

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
Sitemap: https://appwrite.io/sitemap/news.xml
`
}
