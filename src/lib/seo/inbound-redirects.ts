import { isAlternativeId } from '@/lib/alternatives/registry'
import { DOCS_PAGES } from '@/lib/docs/generated/manifest'
import {
  isReferencePlatform,
  isReferenceService,
  isReferenceVersion,
  type ReferenceService,
} from '@/lib/docs/references/constants'

/**
 * 301 targets for URLs that still 404 after the exact legacy map: TanStack
 * pathless layouts (`/_marketing`, `/_public`, `/_auth`), docs links whose
 * section interpolated to `$undefined`, renamed blog slugs, and API paths
 * crawled on the website host.
 *
 * Returns null when the path should stay (a live route, or junk with no
 * successor). Callers follow the result through the legacy map so a cleaned
 * docs path can still pick up the TablesDB rename.
 */

const DOCS_SLUGS = new Set(DOCS_PAGES.map((page) => page.slug))

/** Index routes that are not markdoc pages. */
const DOCS_INDEX_SLUGS = new Set(['tutorials', 'quick-starts'])

const DOCS_SECTION_PREFIXES = [
  'products',
  'advanced',
  'tooling',
  'tutorials',
  'apis',
  'quick-starts',
  'partners',
] as const

const LAYOUT_PREFIXES = new Set([
  '_marketing',
  '_public',
  '_auth',
  '_protected',
  '_api',
])

const DATABASES_ENGINES = new Set([
  'tablesdb',
  'documentsdb',
  'vectorsdb',
  'mysql',
  'postgresql',
])

/** Docs slug (no `/docs` prefix) → replacement slug. */
const DOCS_SLUG_ALIASES: Record<string, string> = {
  web: 'references/cloud/client-web/account',
  dart: 'references/cloud/server-dart/account',
  messaging: 'products/messaging',
  teams: 'products/auth/teams',
  account: 'products/auth',
  admin: '',
  cli: 'tooling/command-line/installation',
  'client/users': 'references/cloud/client-web/users',
  'server/health': 'references/cloud/server-nodejs/health',
  'server/tablesdb': 'references/cloud/server-nodejs/tablesDB',
  'server/tablesdbdb': 'references/cloud/server-nodejs/tablesDB',
  'products/authentication': 'products/auth',
  'products/auth/passkeys': 'products/auth',
  'products/sites/quick-starts': 'products/sites/quick-start',
  'products/sites/domain': 'products/sites/domains',
  'products/sites/cdn': 'products/network/cdn',
  'products/realtime/presence': 'apis/realtime/presences',
  'advanced/network': 'products/network',
  'tooling/ai/mcp': 'tooling/ai/mcp-servers',
  'tooling/ai/grok-build': 'tooling/ai/agents/grok-build',
  'tooling/ai/vibe-coding': 'tooling',
  'tooling/claude-code': 'tooling/ai/agents/claude-code',
  'tooling/command-line-interface': 'tooling/command-line/installation',
  'tooling/command-line/deployment': 'tooling/command-line/installation',
  'tooling/terraform/resources': 'tooling/terraform',
  'sdks/client/react-native': 'quick-starts/react-native',
  'database-attributes': 'products/databases/tablesdb',
  'apis/error-handling': 'apis/response-codes',
  'apis/(overview)': 'apis',
  'tutorials/lib/appwrite': 'tutorials',
  'quick-starts/auth-panel': 'quick-starts',
  'quick-starts/App': 'quick-starts',
  'docs/command-line': 'tooling/command-line/installation',
}

const BLOG_SLUG_ALIASES: Record<string, string> = {
  'init-august-2026': 'appwrite-init-2026-recap',
  drizzle: 'drizzle-orm-appwrite-postgres',
  'startups-ideas-for-developers-2024': 'startups-ideas-for-developers-2025',
  'remote-appwrite-mcp-server': 'announcing-remote-appwrite-mcp-server',
  hackt: 'hacktoberfest-ideas-2024',
  'guide-to': 'guide-to-user-authentication',
  types: 'new-string-types',
  'gemini-3-5-flash': 'gemini-3-5-flash-deep-dive',
}

const INTEGRATION_AVATARS: Record<string, string> = {
  docusaurus: '/images/integrations/sites-docusaurus/cover.avif',
  sentry: '/images/integrations/logging-sentry/cover.avif',
  elevenlabs: '/images/integrations/ai-elevenlabs-text-to-speech/cover.avif',
  claude: '/images/integrations/mcp-claude/cover.avif',
  'hugging-face':
    '/images/integrations/ai-hugging-face-image-classification/cover.avif',
  perplexity: '/images/integrations/ai-perplexity/cover.avif',
  twilio: '/images/integrations/sms-twilio/cover.avif',
  vonage: '/images/integrations/whatsapp-vonage/cover.avif',
  github: '/images/integrations/deployments-github/cover.avif',
  x: '/images/integrations/oauth-x/cover.avif',
  algolia: '/images/integrations/search-algolia/cover.avif',
}

const CHANGELOG_SLUG_ALIASES: Record<string, string> = {
  webhooks: '2026-04-22',
}

/** Bare product URLs. Deeper API paths go to docs instead. */
const BARE_PRODUCT_PATHS: Record<string, string> = {
  functions: '/products/functions',
  sites: '/products/sites',
  storage: '/products/storage',
  auth: '/products/auth',
  postgresql: '/products/postgres',
  postgres: '/products/postgres',
  messaging: '/products/messaging',
  realtime: '/products/realtime',
  firewall: '/products/firewall',
  databases: '/products/databases',
  presences: '/products/realtime',
  users: '/products/auth',
  tablesdb: '/docs/products/databases/tablesdb',
  documentsdb: '/docs/products/databases/documentsdb',
}

/** First path segment of a REST path crawled on the website → docs slug. */
const API_DOCS_ROOTS: Record<string, string> = {
  functions: 'products/functions',
  users: 'products/auth/users',
  teams: 'products/auth/teams',
  sites: 'products/sites',
  tablesdb: 'products/databases/tablesdb',
  documentsdb: 'products/databases/documentsdb',
  storage: 'products/storage',
  mysql: 'products/databases/mysql',
  postgresql: 'products/databases/postgresql',
  avatars: 'references/cloud/client-web/avatars',
  locale: 'references/cloud/client-web/locale',
  graphql: 'apis/graphql',
  backups: 'products/databases/tablesdb/backups',
  presences: 'apis/realtime/presences',
}

const LIVE_PRODUCTS = new Set([
  'auth',
  'databases',
  'postgres',
  'storage',
  'functions',
  'messaging',
  'realtime',
  'sites',
  'firewall',
])

const CONSOLE_ACCOUNT_SECTIONS = new Set([
  'applications',
  'notifications',
  'billing-addresses',
  'affiliates',
  'payments',
  'payment-methods',
  'security',
  'sessions',
])

const SERVICE_ALIASES: Record<string, ReferenceService> = {
  tables: 'tablesDB',
  table: 'tablesDB',
}

function docsSlugExists(slug: string): boolean {
  if (!slug) return true
  if (DOCS_SLUGS.has(slug) || DOCS_INDEX_SLUGS.has(slug)) return true
  return isCanonicalReferenceSlug(slug)
}

function isCanonicalReferenceSlug(slug: string): boolean {
  const parts = slug.split('/')
  if (parts[0] !== 'references' || parts.length !== 4) return false
  const [, version, platform, service] = parts
  if (!version || !platform || !service) return false
  if (!isReferenceVersion(version) || !isReferencePlatform(platform)) return false
  return isReferenceService(service)
}

function joinPath(segments: string[]): string {
  return `/${segments.filter(Boolean).join('/')}` || '/'
}

function isPlaceholderSegment(segment: string): boolean {
  return (
    segment === 'undefined' ||
    segment === '*' ||
    segment.startsWith('$') ||
    segment.includes('{') ||
    segment.includes('}')
  )
}

function hasPlaceholder(pathname: string): boolean {
  return pathname.split('/').filter(Boolean).some(isPlaceholderSegment)
}

function stripLayoutPrefix(pathname: string): string | null {
  const segments = pathname.split('/').filter(Boolean)
  if (!segments[0] || !LAYOUT_PREFIXES.has(segments[0])) return null
  const layout = segments[0]
  const rest = segments.slice(1)
  if (rest.length === 0) {
    return layout === '_auth' || layout === '_protected' ? '/sign-in' : '/'
  }
  return joinPath(rest)
}

function stripMarkdownLeak(pathname: string): string | null {
  const leak = pathname.indexOf('[](')
  if (leak === -1) return null
  const trimmed = pathname.slice(0, leak).replace(/\/+$/, '')
  return trimmed || '/'
}

function rewriteDocsDollarSegments(segments: string[]): string[] {
  const out: string[] = []
  for (const segment of segments) {
    if (segment === '$undefined' || segment === 'undefined') continue
    if (segment === '$' || segment === '$$') continue
    if (segment === '$messaging') {
      out.push('products', 'messaging')
      continue
    }
    if (segment.startsWith('$')) {
      const name = segment.slice(1)
      if (name === 'version') out.push('cloud')
      else if (name === 'platform') out.push('client-web')
      else if (name === 'service') out.push('account')
      else if (name === 'model') continue
      else if (name) out.push(name)
      continue
    }
    out.push(segment)
  }
  return out
}

function referenceRedirect(slug: string): string | null {
  const parts = slug.split('/')
  if (parts[0] !== 'references') return null

  const version = parts[1]
  const platform = parts[2]
  const rest = parts.slice(3)

  if (!version || isPlaceholderSegment(version) || !isReferenceVersion(version)) {
    return '/docs/references/cloud/client-web/account'
  }

  if (platform === 'models') {
    const model = rest[0] ?? ''
    if (!model || isPlaceholderSegment(model)) {
      return '/docs/references/cloud/client-web/account'
    }
    if (/^oAuth2/i.test(model) || model === 'authProvider') {
      return '/docs/products/auth/oauth2'
    }
    if (model.startsWith('policy') || model.startsWith('mockNumber')) {
      return '/docs/products/auth/security'
    }
    if (model.startsWith('column')) return '/docs/products/databases/tablesdb'
    if (model.startsWith('presence')) return '/docs/apis/realtime/presences'
    if (model.startsWith('platform')) return '/docs/partners/project/platforms'
    return null
  }

  if (!platform || isPlaceholderSegment(platform) || !isReferencePlatform(platform)) {
    return `/docs/references/${version}/client-web/account`
  }

  const serviceSegment = rest[0]
  if (!serviceSegment || isPlaceholderSegment(serviceSegment)) {
    return `/docs/references/${version}/${platform}/account`
  }

  const aliased = SERVICE_ALIASES[serviceSegment] ?? serviceSegment
  if (isReferenceService(aliased) && aliased !== serviceSegment) {
    return `/docs/references/${version}/${platform}/${aliased}`
  }
  if (!isReferenceService(aliased)) {
    return `/docs/references/${version}/${platform}/account`
  }
  if (rest.length > 1) {
    return `/docs/references/${version}/${platform}/${aliased}`
  }
  return null
}

function walkDocsSlug(slug: string): string | null {
  const parts = slug.split('/').filter(Boolean)
  while (parts.length > 0) {
    const candidate = parts.join('/')
    if (docsSlugExists(candidate)) return candidate
    parts.pop()
  }
  return null
}

function resolveDocsSlug(slug: string): string | null {
  const aliased = DOCS_SLUG_ALIASES[slug] ?? slug
  if (docsSlugExists(aliased)) return aliased

  if (aliased.startsWith('products/databases/')) {
    const rest = aliased.slice('products/databases/'.length)
    const first = rest.split('/')[0] ?? ''
    if (first && !DATABASES_ENGINES.has(first)) {
      return aliased
    }
  }

  if (aliased.startsWith('tooling/command-line')) {
    return 'tooling/command-line/installation'
  }

  const candidates = [aliased]
  if (!DOCS_SECTION_PREFIXES.some((prefix) => aliased === prefix || aliased.startsWith(`${prefix}/`))) {
    for (const prefix of DOCS_SECTION_PREFIXES) {
      candidates.push(`${prefix}/${aliased}`)
    }
  }

  for (const candidate of candidates) {
    if (candidate.startsWith('products/databases/')) {
      const rest = candidate.slice('products/databases/'.length)
      const first = rest.split('/')[0] ?? ''
      if (first && !DATABASES_ENGINES.has(first)) return candidate
    }
    if (docsSlugExists(candidate)) return candidate
  }

  let best: string | null = null
  for (const candidate of candidates) {
    const walked = walkDocsSlug(candidate)
    if (walked && (!best || walked.length > best.length)) best = walked
  }
  return best
}

function docsPathRedirect(pathname: string): string | null {
  if (pathname !== '/docs' && !pathname.startsWith('/docs/')) return null

  let slug = pathname.slice('/docs/'.length).replace(/^\/+|\/+$/g, '')
  if (slug.endsWith('.md') || slug.endsWith('.json')) {
    const bare = slug.replace(/\.(md|json)$/, '')
    if (docsSlugExists(bare)) return null
    slug = bare
  }

  const segments = rewriteDocsDollarSegments(slug.split('/').filter(Boolean))
  slug = segments.join('/')

  if (!slug || slug === '$') return '/docs'

  const reference = referenceRedirect(slug)
  if (reference) return reference
  if (/^references\/[^/]+\/models\/[^/]+$/.test(slug)) return null

  const cleaned = slug ? `/docs/${slug}`.replace(/\/+$/, '') || '/docs' : '/docs'
  if (isCanonicalReferenceSlug(slug) || docsSlugExists(slug)) {
    return cleaned === pathname ? null : cleaned
  }

  const resolved = resolveDocsSlug(slug)
  if (resolved == null) return '/docs'
  if (resolved === '') return '/docs'
  const target = `/docs/${resolved}`.replace(/\/+$/, '') || '/docs'
  return target === pathname ? null : target
}

function placeholderFallback(pathname: string): string | null {
  if (!hasPlaceholder(pathname)) return null
  const [root, second] = pathname.split('/').filter(Boolean)
  if (root === 'docs') return '/docs'
  if (root === 'blog') return '/blog'
  if (root === 'changelog') return '/changelog'
  if (root === 'threads') return '/threads'
  if (root === 'integrations') return '/integrations'
  if (root === 'init') return '/init'
  if (root === 'products') return '/'
  if (root === 'projects' || root === 'organizations' || root === 'marketplace') {
    return '/sign-in'
  }
  if (root === 'account') return second ? '/account' : '/sign-in'
  if (root === 'agent') return '/agent'
  return '/sign-in'
}

function blogRedirect(pathname: string): string | null {
  if (pathname === '/blog/post/products/sites') return '/products/sites'
  if (pathname === '/post/guide-to-user-authentication') {
    return '/blog/post/guide-to-user-authentication'
  }

  const post = pathname.match(/^\/blog\/post\/([^/]+)$/)
  if (post) {
    const target = BLOG_SLUG_ALIASES[post[1] ?? '']
    if (target) return `/blog/post/${target}`
    if (post[1] === 'db' || post[1] === 'Profile' || post[1] === 'migrate-') {
      return '/blog'
    }
  }

  const bare = pathname.match(/^\/blog\/([^/]+)$/)
  if (bare) {
    const slug = bare[1] ?? ''
    if (slug === 'post' || slug === 'categories' || slug === 'author') return null
    if (/^\d+$/.test(slug)) return null
    const target = BLOG_SLUG_ALIASES[slug]
    if (target) return `/blog/post/${target}`
  }

  return null
}

function changelogRedirect(pathname: string): string | null {
  const match = pathname.match(/^\/changelog\/([^/]+)$/)
  if (!match) return null
  const slug = match[1] ?? ''
  if (slug === 'entry') return null
  if (/^\d+$/.test(slug)) return '/changelog'
  const entry = CHANGELOG_SLUG_ALIASES[slug]
  if (entry) return `/changelog/entry/${entry}`
  return null
}

function imageRedirect(pathname: string): string | null {
  if (pathname.startsWith('/images/blog-local/')) {
    return `/images/blog/${pathname.slice('/images/blog-local/'.length)}`
  }
  const avatar = pathname.match(/^\/images\/integrations\/avatars\/([^/]+)\.avif$/)
  if (avatar) {
    return INTEGRATION_AVATARS[avatar[1] ?? ''] ?? '/integrations'
  }
  return null
}

function projectApiRedirect(pathname: string): string | null {
  if (!pathname.startsWith('/project/')) return null
  if (pathname.startsWith('/project/oauth2-server')) {
    return '/docs/products/auth/oauth-server'
  }
  if (pathname.startsWith('/project/oauth2')) return '/docs/products/auth/oauth2'
  if (pathname.startsWith('/project/policies')) return '/docs/products/auth/security'
  if (pathname.startsWith('/project/platforms')) {
    return '/docs/partners/project/platforms'
  }
  if (pathname.startsWith('/project/keys')) return '/docs/partners/project/api-keys'
  if (pathname.startsWith('/project/variables')) {
    return '/docs/partners/project/environment-variables'
  }
  if (pathname.startsWith('/project/labels')) return '/docs/products/auth/labels'
  return '/docs/products/auth'
}

function accountApiRedirect(pathname: string): string | null {
  if (pathname !== '/account' && !pathname.startsWith('/account/')) return null
  const segments = pathname.split('/').filter(Boolean).slice(1)
  if (segments.length === 0) return null
  if (segments.length === 1 && CONSOLE_ACCOUNT_SECTIONS.has(segments[0] ?? '')) {
    return null
  }
  const head = segments[0]
  if (head === 'recovery') return '/recovery'
  if (head === 'identities') return '/docs/products/auth/identities'
  if (head === 'jwts') return '/docs/products/auth/jwt'
  if (head === 'mfa') return '/docs/products/auth/mfa'
  if (head === 'targets') return '/docs/products/messaging'
  if (head === 'tokens') return '/auth/magic-url'
  if (head === 'sessions') return '/account/sessions'
  return '/docs/products/auth'
}

function apiRootRedirect(pathname: string): string | null {
  const segments = pathname.split('/').filter(Boolean)
  const root = segments[0]
  if (!root || segments.length === 0) return null

  if (segments.length === 1 && BARE_PRODUCT_PATHS[root]) {
    return BARE_PRODUCT_PATHS[root] ?? null
  }

  const docsRoot = API_DOCS_ROOTS[root]
  if (!docsRoot) return null

  const rest = segments.slice(1).filter((segment) => !isPlaceholderSegment(segment))
  if (segments.slice(1).some(isPlaceholderSegment) || rest.length === 0) {
    return docsSlugExists(docsRoot) ? `/docs/${docsRoot}` : '/docs'
  }

  const candidate = `${docsRoot}/${rest.join('/')}`
  if (docsSlugExists(candidate)) return `/docs/${candidate}`
  const walked = walkDocsSlug(candidate)
  if (walked) return `/docs/${walked}`
  return `/docs/${docsRoot}`
}

function compareRedirect(pathname: string): string | null {
  const match = pathname.match(/^\/compare\/([^/]+)$/)
  if (!match) return null
  const id = match[1] ?? ''
  if (id === 'backend-as-a-service') return '/blog/post/backend-as-a-service'
  if (isAlternativeId(id)) return `/alternative-to/${id}`
  return '/alternative-to'
}

function threadRedirect(pathname: string): string | null {
  if (pathname === '/threads/*') return '/threads'
  const author = pathname.match(/^\/threads\/authors\/([^/]+)$/)
  if (author) return null
  const thread = pathname.match(/^\/threads\/([^/]+)$/)
  if (!thread) return null
  const id = thread[1] ?? ''
  if (/^\d{10,}$/.test(id)) return null
  return '/threads'
}

function authPathRedirect(pathname: string, search: string): string | null {
  const params = new URLSearchParams(search)
  const hasSecret = Boolean(params.get('userId') && params.get('secret'))

  if (
    pathname === '/recovery' ||
    pathname === '/account/recovery' ||
    pathname === '/reset'
  ) {
    if (pathname === '/reset') return null
    if (hasSecret) return '/reset'
    if (pathname === '/account/recovery') return '/recovery'
    return null
  }

  if (
    pathname === '/verify' ||
    pathname === '/verify/email' ||
    pathname === '/verify-email'
  ) {
    if (pathname === '/verify-email') return null
    return '/verify-email'
  }

  if (pathname === '/magic_url_session') return '/auth/magic-url'
  if (pathname === '/login-google-fail') return '/auth/oauth2/failure'
  if (pathname === '/login' || pathname === '/signin') {
    if (params.get('error') === 'oauth') return '/auth/oauth2/failure'
    return '/sign-in'
  }
  if (pathname === '/oauth2' || pathname === '/oauth') {
    return '/docs/products/auth/oauth2'
  }
  if (pathname === '/auth/oauth2') return '/sign-in'
  return null
}

const EXACT_REDIRECTS: Record<string, string> = {
  '/cloud-ga': '/blog/post/product-update-august-2025',
  '/cloud': '/',
  '/about': '/company',
  '/support': '/enterprise',
  '/hacktoberfest': '/blog/post/hacktoberfest-ideas-2024',
  '/engineering-internship': '/company',
  '/startups/hosting': '/startups',
  '/legal/baa': '/baa',
  '/mcp': '/docs/tooling/ai/mcp-servers',
  '/openapi.json': '/references-api/open-api-spec',
  '/oas': '/references-api/open-api-spec',
  '/mcp.json': '/.well-known/mcp/server-card.json',
  '/.well-known/mcp.json': '/.well-known/mcp/server-card.json',
  '/.well-known/webmcp.json': '/.well-known/mcp/server-card.json',
  '/webmcp.json': '/.well-known/mcp/server-card.json',
  '/agents.json': '/.well-known/ai-catalog.json',
  '/.well-known/agents.json': '/.well-known/ai-catalog.json',
  '/.well-known/agent.json': '/.well-known/ai-catalog.json',
  '/.well-known/agent-card.json': '/.well-known/ai-catalog.json',
  '/.well-known/a2a/agent-card.json': '/.well-known/ai-catalog.json',
  '/agent-card.json': '/.well-known/ai-catalog.json',
  '/index.html': '/',
  '/index.html.md': '/',
  '/docker-compose.yml': '/docs/advanced/self-hosting',
  '/getting-started-with-android': '/docs/quick-starts/android',
  '/appwrite-vs-auth0-b2c': '/alternative-to/auth0',
  '/webhook': '/docs/apis/webhooks',
  '/downloads': '/docs/advanced/self-hosting',
  '/mobile': '/docs/quick-starts',
  '/rooms': '/docs/apis/realtime/presences',
  '/graphql': '/docs/apis/graphql',
  '/graphql/mutation': '/docs/apis/graphql',
  '/admin': '/sign-in',
  '/user': '/account',
  '/records': '/docs/products/databases/tablesdb/rows',
  '/v1': '/docs/apis/rest',
  '/products/sites/open-source-netlify': '/alternative-to/netlify',
  '/init/tickets': '/init',
  '/oauth/success': '/auth/oauth2/success',
  '/oss-fund-announcement': '/blog/post/announcing-the-appwrite-oss-program',
  '/api/users/signup': '/sign-up',
  '/api/user': '/account',
  '/api/appwrite': '/docs',
  '/api/generate': '/generator',
  '/i': '/',
  '/database/events/create': '/docs/apis/events',
  '/database/events/update': '/docs/apis/events',
}

function exactAndPrefixRedirect(pathname: string): string | null {
  const exact = EXACT_REDIRECTS[pathname]
  if (exact) return exact
  if (pathname.startsWith('/contact-us')) return '/enterprise'
  if (pathname === '/partners/catalog' || pathname.startsWith('/partners/catalog/')) {
    return '/partners'
  }
  const extraProduct = pathname.match(/^\/products\/([^/]+)(?:\/(.+))?$/)
  if (extraProduct) {
    const id = extraProduct[1] ?? ''
    if (!LIVE_PRODUCTS.has(id)) return '/'
    if (extraProduct[2]) return `/products/${id}`
  }
  if (pathname.startsWith('/init/tickets/')) {
    const id = pathname.slice('/init/tickets/'.length).split('/')[0]
    if (id && !isPlaceholderSegment(id)) return `/init/${id}`
    return '/init'
  }
  if (pathname === '/privacy.' || pathname === '/를') return pathname === '/를' ? '/' : '/privacy'
  if (pathname.startsWith('/pricing') && pathname !== '/pricing') {
    const rest = pathname.slice('/pricing'.length)
    if (/^[^\w/]/.test(rest)) return '/pricing'
  }
  return null
}

function rewriteOnce(pathname: string, search: string): string | null {
  return (
    stripMarkdownLeak(pathname) ??
    stripLayoutPrefix(pathname) ??
    authPathRedirect(pathname, search) ??
    exactAndPrefixRedirect(pathname) ??
    imageRedirect(pathname) ??
    blogRedirect(pathname) ??
    changelogRedirect(pathname) ??
    compareRedirect(pathname) ??
    threadRedirect(pathname) ??
    projectApiRedirect(pathname) ??
    accountApiRedirect(pathname) ??
    docsPathRedirect(pathname) ??
    apiRootRedirect(pathname) ??
    placeholderFallback(pathname)
  )
}

/**
 * One inbound rewrite, or null when this path is not a known 404 successor.
 * `search` is only used for auth email links (`userId` + `secret`).
 */
export function getInboundRedirect(pathname: string, search = ''): string | null {
  let current = pathname
  for (let step = 0; step < 6; step += 1) {
    const next = rewriteOnce(current, search)
    if (!next || next === current) break
    current = next.split('#')[0] || current
  }
  return current === pathname ? null : current
}
