/**
 * Integration snippets for an analytics property.
 *
 * The Web and Flutter tabs mirror the `Tracking` helpers shipped in the client
 * SDKs, which wrap the generated `Analytics` service:
 *
 *   web:     new Tracking(analytics: Analytics, propertyId: string, options?: TrackingOptions)
 *   flutter: Tracking(Analytics analytics, String propertyId, {String? url})
 *   flutter: TrackingObserver(tracking, {nameExtractor, eventName = 'screen_view'})
 *
 * `start()` is NOT "everything" on either platform, and the snippets must keep
 * saying so:
 *   web:     covers pageviews, outbound links, scroll depth and engagement time.
 *            Downloads are excluded because the extension list is
 *            application-specific; `enableAutoDownloadTracking()` is required
 *            for any `file_download` event.
 *   flutter: covers app lifecycle events only. Route tracking (`screen_view`)
 *            requires attaching a `TrackingObserver`.
 *
 * The helpers take `props` as a map and flatten it; the raw endpoint (and the
 * service's `createEvent`) takes a flat alternating key/value list instead,
 * which is what the REST tab shows.
 *
 * Ingestion is nested under its property:
 *
 *   POST /v1/analytics/properties/:propertyId/events
 *
 * With a proxy (Web only), the browser uses the emitter-based
 * `AnalyticsTracking` instead and posts plain events to one path on the
 * site's own domain; see "Proxy" below.
 */

import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'

export const ANALYTICS_PLATFORMS = ['web', 'flutter', 'rest'] as const

export type AnalyticsPlatform = (typeof ANALYTICS_PLATFORMS)[number]

export type AnalyticsPlatformMeta = {
  id: AnalyticsPlatform
  label: string
  description: string
  /** Icon slug understood by `PlatformIcon`. */
  iconSlug: string
  /** Footnote rendered below the snippets. */
  note: string
}

export const ANALYTICS_PLATFORM_META: Record<
  AnalyticsPlatform,
  AnalyticsPlatformMeta
> = {
  web: {
    id: 'web',
    label: 'Web',
    description: 'Browser apps and static sites.',
    iconSlug: 'web',
    note: 'Do Not Track is respected by default. Pass { respectDoNotTrack: false } to the constructor options to opt out. Automatic events are named pageview, outbound_link, file_download, scroll_depth and engagement_time.',
  },
  flutter: {
    id: 'flutter',
    label: 'Flutter',
    description: 'iOS, Android, web and desktop from one codebase.',
    iconSlug: 'flutter',
    note: 'Automatic events are named screen_view, app_backgrounded and app_foregrounded.',
  },
  rest: {
    id: 'rest',
    label: 'REST',
    description: 'Any language, straight against the HTTP API.',
    iconSlug: 'web',
    note: 'This endpoint is public, so no API key is needed for client-side tracking. Only the server-side override fields (userId, ip, userAgent) require an API key with the analytics.write scope.',
  },
}

export function isAnalyticsPlatform(
  value: string | undefined | null,
): value is AnalyticsPlatform {
  return !!value && (ANALYTICS_PLATFORMS as readonly string[]).includes(value)
}

export type SnippetBlock = {
  /** Short caption rendered above the block. */
  label: string
  code: string
  language: CodeBlockLanguage
}

type SnippetInput = {
  endpoint: string
  projectId: string
  /**
   * The ingestion endpoint accepts either the property ID or the snippet ID,
   * so prefer the snippet ID in client-side code when the property has one.
   */
  trackingId: string
  domain: string
  /**
   * Path on the site's own domain that receives events (see
   * `buildAnalyticsProxyRecipes`). Web only; ignored for Flutter and REST.
   */
  proxyPath?: string
}

/** The platforms that can send events through a proxy. */
export function supportsAnalyticsProxy(platform: AnalyticsPlatform): boolean {
  return platform === 'web'
}

/**
 * Where the proxy is mounted in the generated code. A neutral word on
 * purpose: filter lists match paths like `/analytics` or `/track`. Any path
 * works; the code comments tell users to keep both sides in sync.
 */
export const ANALYTICS_PROXY_DEFAULT_PATH = '/relay'

function sampleUrl(domain: string): string {
  return domain ? `https://${domain}/pricing` : 'https://example.com/pricing'
}

function webBlocks(input: SnippetInput): SnippetBlock[] {
  if (input.proxyPath) return webProxyBlocks(input.proxyPath)
  const { endpoint, projectId, trackingId } = input
  return [
    {
      label: 'Install the SDK',
      language: 'bash',
      code: `npm install appwrite`,
    },
    {
      label: 'Initialize tracking',
      language: 'typescript',
      code: `import { Client, Analytics, Tracking } from 'appwrite'

const client = new Client()
  .setEndpoint('${endpoint}')
  .setProject('${projectId}')

const tracking = new Tracking(new Analytics(client), '${trackingId}')`,
    },
    {
      label: 'Turn on automatic tracking',
      language: 'typescript',
      code: `// Covers pageviews, outbound links, scroll depth and engagement time.
tracking.start()

// Downloads are NOT included above: the extension list is app-specific,
// so file_download events only fire once you opt in here.
tracking.enableAutoDownloadTracking()`,
    },
    {
      label: 'Send your own events',
      language: 'typescript',
      code: `// A custom event
tracking.track('signup_completed', { props: { plan: 'pro' } })

// A pageview for a route your router changed manually
tracking.pageview()`,
    },
  ]
}

/**
 * Web through a proxy: the emitter-based `AnalyticsTracking` keeps every
 * auto-tracking behaviour but hands each event to `send`, which posts it to
 * the proxy path. No endpoint, project or property ID in the browser: the
 * proxy holds them. `sendBeacon` is safe here (same origin, and the proxy
 * sets the project header a beacon can't), so unload events arrive too.
 */
function webProxyBlocks(proxyPath: string): SnippetBlock[] {
  return [
    {
      label: 'Install the SDK',
      language: 'bash',
      code: `npm install appwrite

# The Next.js and Express proxies also use the server SDK
npm install node-appwrite`,
    },
    {
      label: 'Initialize tracking',
      language: 'typescript',
      code: `import { AnalyticsTracking } from 'appwrite'

// Events go to ${proxyPath} on your own domain instead of straight to Appwrite,
// so ad blockers don't drop them. A proxy there forwards them to Appwrite.
// Any path works, as long as the proxy listens on the same one.
function send(event: Record<string, unknown>) {
  const body = JSON.stringify(event)
  // sendBeacon survives page unloads; fetch with keepalive is the fallback.
  if (!navigator.sendBeacon?.('${proxyPath}', body)) {
    fetch('${proxyPath}', { method: 'POST', body, keepalive: true }).catch(() => {})
  }
}

const tracking = new AnalyticsTracking((name, options) =>
  send({
    name,
    url: options?.url ?? window.location.href,
    domain: window.location.hostname,
    referrer: options?.referrer,
    screenWidth: window.innerWidth,
    scrollDepth: options?.scrollDepth,
    engagementTime: options?.engagementTime,
    // Flat key/value list, as Appwrite expects.
    props: Object.entries(options?.props ?? {}).flatMap(([key, value]) => [
      key,
      String(value),
    ]),
  }),
)`,
    },
    {
      label: 'Turn on automatic tracking',
      language: 'typescript',
      code: `// Covers pageviews, outbound links, scroll depth and engagement time.
tracking.enableAllAutoTracking()

// Downloads are NOT included above: the extension list is app-specific,
// so file_download events only fire once you opt in here.
tracking.enableAutoDownloadTracking()`,
    },
    {
      label: 'Send your own events',
      language: 'typescript',
      code: `// A custom event
tracking.track('signup_completed', { props: { plan: 'pro' } })

// A pageview for a route your router changed manually
tracking.pageview()`,
    },
  ]
}

function flutterBlocks(input: SnippetInput): SnippetBlock[] {
  const { endpoint, projectId, trackingId } = input
  return [
    {
      label: 'Add the package',
      language: 'bash',
      code: `flutter pub add appwrite`,
    },
    {
      label: 'Initialize tracking',
      language: 'dart',
      code: `import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('${endpoint}')
    .setProject('${projectId}');

final tracking = Tracking(Analytics(client), '${trackingId}');`,
    },
    {
      label: 'Turn on automatic tracking',
      language: 'dart',
      code: `// Currently this only covers app lifecycle events
// (app_backgrounded / app_foregrounded).
tracking.start();

// Equivalent, if you prefer to be explicit:
// tracking.enableAutoLifecycleEvents();
// tracking.disableAutoLifecycleEvents();`,
    },
    {
      label: 'Track route changes',
      language: 'dart',
      code: `// Route tracking is NOT part of start(): attach the observer to get
// screen_view events.
MaterialApp(
  navigatorObservers: [TrackingObserver(tracking)],
  home: const HomePage(),
);`,
    },
    {
      label: 'Send your own events',
      language: 'dart',
      code: `tracking.event('signup_completed', props: {'plan': 'pro'});

tracking.screenView('Checkout', className: 'CheckoutPage');`,
    },
  ]
}

function restBlocks(input: SnippetInput): SnippetBlock[] {
  const { endpoint, projectId, trackingId, domain } = input
  const host = domain || 'example.com'
  return [
    {
      label: 'Send an event',
      language: 'bash',
      code: `curl -X POST "${endpoint}/analytics/properties/${trackingId}/events" \\
  -H "X-Appwrite-Project: ${projectId}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "name": "pageview",
    "url": "${sampleUrl(domain)}",
    "domain": "${host}",
    "referrer": "https://www.google.com/"
  }'`,
    },
    {
      label: 'Send a custom event with properties',
      language: 'bash',
      code: `curl -X POST "${endpoint}/analytics/properties/${trackingId}/events" \\
  -H "X-Appwrite-Project: ${projectId}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "name": "signup_completed",
    "url": "${sampleUrl(domain)}",
    "props": ["plan", "pro"]
  }'`,
    },
  ]
}

/**
 * Just the custom-event part of each integration, for the "Track custom
 * events" guide. SDK examples assume tracking is already initialised (the
 * full setup lives in `buildAnalyticsSnippets`); REST is self-contained.
 */
export function buildCustomEventSnippets(
  platform: AnalyticsPlatform,
  input: SnippetInput,
): SnippetBlock[] {
  const { endpoint, projectId, trackingId, domain } = input
  if (platform === 'web') {
    return [
      {
        label: 'Track an event',
        language: 'typescript',
        code: `// After initialising tracking (see the full setup guide)
tracking.track('signup_completed', {
  props: { plan: 'pro', source: 'pricing_page' },
})`,
      },
    ]
  }
  if (platform === 'flutter') {
    return [
      {
        label: 'Track an event',
        language: 'dart',
        code: `// After initialising tracking (see the full setup guide)
tracking.event('signup_completed', props: {
  'plan': 'pro',
  'source': 'pricing_page',
});`,
      },
    ]
  }
  return [
    {
      label: 'Track an event',
      language: 'bash',
      code: `curl -X POST "${endpoint}/analytics/properties/${trackingId}/events" \\
  -H "X-Appwrite-Project: ${projectId}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "name": "signup_completed",
    "url": "${sampleUrl(domain)}",
    "props": ["plan", "pro", "source", "pricing_page"]
  }'`,
    },
  ]
}

export type AnalyticsInstallGuide = {
  /** Package install command; null when there's nothing to install (REST). */
  install: SnippetBlock | null
  /** Everything else as one file, each part introduced by a comment. */
  code: SnippetBlock
  /** Labels of the parts in `code`, in order (for a short summary). */
  parts: string[]
}

/**
 * The setup as two blocks instead of one per step: the install command, then
 * all the code in a single snippet that can be copied in one go.
 */
export function buildAnalyticsInstallGuide(
  platform: AnalyticsPlatform,
  input: SnippetInput,
): AnalyticsInstallGuide {
  const blocks = buildAnalyticsSnippets(platform, input)
  const [first, ...others] = blocks
  const hasInstall = platform !== 'rest' && first?.language === 'bash'
  const parts = hasInstall ? others : blocks
  const comment = platform === 'rest' ? '#' : '//'
  return {
    install: hasInstall ? first : null,
    parts: parts.map((block) => block.label),
    code: {
      label: 'Code',
      language: parts[0]?.language ?? 'bash',
      code: parts
        .map((block) => `${comment} ${block.label}\n${block.code}`)
        .join('\n\n'),
    },
  }
}

// ─── Proxy ──────────────────────────────────────────────────────────────────

/**
 * Ad blockers drop requests to known analytics hosts and paths. A proxy is
 * one endpoint on the site's own domain, at any path: it doesn't mirror the
 * Appwrite REST API. The endpoint, project and property are constants in the
 * proxy, so the browser only ever talks to its own origin.
 *
 * The proxy must pass the visitor along. Appwrite takes the client IP from
 * the rightmost untrusted `X-Forwarded-For` hop, which would be the proxy
 * itself: every visitor would geolocate to its data center and visitors
 * would merge. So each recipe:
 *
 *   - calls `createEvent` with the server-side overrides `ip` and `userAgent`
 *     taken from the visitor's own request;
 *   - authenticates with an API key that has only `analytics.write`, read
 *     from the proxy's environment and never shipped to the browser;
 *   - copies only the known event fields, so a browser can't set `userId`
 *     (or anything else the key would allow).
 *
 * Bodies are read as text: `sendBeacon` posts JSON as `text/plain`.
 */
export const ANALYTICS_PROXY_KEY_ENV = 'APPWRITE_ANALYTICS_KEY'

export type AnalyticsProxyRecipeId = 'nextjs' | 'cloudflare' | 'express'

export type AnalyticsProxyRecipe = {
  id: AnalyticsProxyRecipeId
  label: string
  /** Where the code goes (also its first line, as a comment). */
  file: string
  language: CodeBlockLanguage
  code: string
}

type ProxyInput = Pick<SnippetInput, 'endpoint' | 'projectId' | 'trackingId'> & {
  proxyPath: string
}

/**
 * The comment every recipe opens with. The modal shows no prose about the
 * proxy, so the code carries it: what it does, why it passes the visitor
 * on, and where the key comes from.
 */
function proxyHeader(location: string, framework: string, proxyPath: string): string {
  return `// ${location}
//
// ${framework} proxy. One proxy is enough: use the one that fits your stack.
// It receives tracking events at ${proxyPath} on your own domain and forwards
// them to Appwrite, so ad blockers don't drop them. It passes on each
// visitor's IP and user agent, so locations and visitor counts stay accurate.
//
// ${ANALYTICS_PROXY_KEY_ENV}: create an API key in the Appwrite Console with
// only the analytics.write scope. Keep it on the server, never in the browser.`
}

/** Field guards shared by the recipes, in TypeScript or plain JavaScript. */
function proxyGuards(typed: boolean): string {
  const unknown = typed ? ': unknown' : ''
  const isString = typed ? '(item): item is string' : '(item)'
  return `const text = (value${unknown}) => (typeof value === 'string' ? value : undefined)
const number = (value${unknown}) => (typeof value === 'number' ? value : undefined)
const strings = (value${unknown}) =>
  Array.isArray(value)
    ? value.filter(${isString} => typeof item === 'string').slice(0, 32)
    : undefined`
}

/**
 * The event fields copied from the browser's payload (nothing else), plus
 * the visitor's IP and user agent. Indented to sit inside each handler.
 */
function proxyEventFields(ip: string, userAgent: string, indent: string): string {
  return [
    'name,',
    'url,',
    'domain: text(event.domain),',
    'referrer: text(event.referrer),',
    'screenWidth: number(event.screenWidth),',
    'scrollDepth: number(event.scrollDepth),',
    'engagementTime: number(event.engagementTime),',
    'props: strings(event.props),',
    '// The visitor, not this server.',
    `ip: ${ip},`,
    `userAgent: ${userAgent},`,
  ].join(`\n${indent}`)
}

export function buildAnalyticsProxyRecipes(
  input: ProxyInput,
): AnalyticsProxyRecipe[] {
  const { endpoint, projectId, trackingId, proxyPath } = input

  return [
    {
      id: 'nextjs',
      label: 'Next.js',
      file: `app${proxyPath}/route.ts`,
      language: 'typescript',
      code: `${proxyHeader(`app${proxyPath}/route.ts`, 'Next.js', proxyPath)}
import { Client, Analytics } from 'node-appwrite'

const PROPERTY_ID = '${trackingId}'

const analytics = new Analytics(
  new Client()
    .setEndpoint('${endpoint}')
    .setProject('${projectId}')
    .setKey(process.env.${ANALYTICS_PROXY_KEY_ENV}!),
)

${proxyGuards(true)}

export async function POST(request: Request) {
  let event: Record<string, unknown>
  try {
    event = JSON.parse(await request.text())
  } catch {
    return new Response(null, { status: 400 })
  }
  const name = text(event.name)
  const url = text(event.url)
  if (!name || !url) return new Response(null, { status: 400 })

  try {
    await analytics.createEvent({
      propertyId: PROPERTY_ID,
      ${proxyEventFields(
        // On Vercel, x-forwarded-for is set by the platform.
        "request.headers.get('x-forwarded-for')?.split(',')[0]?.trim()",
        "request.headers.get('user-agent') ?? undefined",
        '      ',
      )}
    })
  } catch {
    return new Response(null, { status: 502 })
  }
  return new Response(null, { status: 204 })
}`,
    },
    {
      id: 'cloudflare',
      label: 'Cloudflare Workers',
      file: `a Worker on the route your-domain.com${proxyPath}`,
      language: 'javascript',
      code: `${proxyHeader(`Worker on the route your-domain.com${proxyPath}`, 'Cloudflare Workers', proxyPath)}
// Set it with: wrangler secret put ${ANALYTICS_PROXY_KEY_ENV}
// Plain fetch instead of the SDK: nothing to bundle in the Worker.
const ENDPOINT = '${endpoint}'
const PROJECT_ID = '${projectId}'
const PROPERTY_ID = '${trackingId}'

${proxyGuards(false)}

export default {
  async fetch(request, env) {
    if (request.method !== 'POST') return new Response(null, { status: 405 })

    let event
    try {
      event = JSON.parse(await request.text())
    } catch {
      return new Response(null, { status: 400 })
    }
    const name = text(event.name)
    const url = text(event.url)
    if (!name || !url) return new Response(null, { status: 400 })

    const response = await fetch(
      \`\${ENDPOINT}/analytics/properties/\${PROPERTY_ID}/events\`,
      {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'x-appwrite-project': PROJECT_ID,
          'x-appwrite-key': env.${ANALYTICS_PROXY_KEY_ENV},
        },
        body: JSON.stringify({
          ${proxyEventFields(
            "request.headers.get('cf-connecting-ip') ?? undefined",
            "request.headers.get('user-agent') ?? undefined",
            '          ',
          )}
        }),
      },
    )
    return new Response(null, { status: response.ok ? 204 : 502 })
  },
}`,
    },
    {
      id: 'express',
      label: 'Express',
      file: 'server.js',
      language: 'javascript',
      code: `${proxyHeader('server.js', 'Express', proxyPath)}
import express from 'express'
import { Client, Analytics } from 'node-appwrite'

const PROPERTY_ID = '${trackingId}'

const analytics = new Analytics(
  new Client()
    .setEndpoint('${endpoint}')
    .setProject('${projectId}')
    .setKey(process.env.${ANALYTICS_PROXY_KEY_ENV}),
)

${proxyGuards(false)}

const app = express()
// Behind a load balancer? Uncomment so req.ip is the visitor, not the
// balancer. Use the number of proxies in front of this server, never \`true\`:
// otherwise clients can fake their IP with X-Forwarded-For.
// app.set('trust proxy', 1)

// Read any content type as text: sendBeacon posts JSON as text/plain.
app.post('${proxyPath}', express.text({ type: '*/*' }), async (req, res) => {
  let event
  try {
    event = JSON.parse(req.body)
  } catch {
    return res.sendStatus(400)
  }
  const name = text(event.name)
  const url = text(event.url)
  if (!name || !url) return res.sendStatus(400)

  try {
    await analytics.createEvent({
      propertyId: PROPERTY_ID,
      ${proxyEventFields('req.ip', "req.get('user-agent')", '      ')}
    })
    res.sendStatus(204)
  } catch {
    res.sendStatus(502)
  }
})`,
    },
  ]
}

// ─── Prompt ─────────────────────────────────────────────────────────────────

/**
 * A prompt for an AI coding agent that sets up tracking end to end, matching
 * what the install modal is showing: the platform, and with a proxy its path
 * and the chosen framework. Mirrors the project Connect modal's "Copy prompt".
 */
export function buildAnalyticsSetupPrompt(
  platform: AnalyticsPlatform,
  input: SnippetInput & {
    propertyName?: string
    /** The proxy framework picked in the modal (with `proxyPath`). */
    proxyRecipe?: AnalyticsProxyRecipeId
  },
): string {
  const meta = ANALYTICS_PLATFORM_META[platform]
  const proxyPath = supportsAnalyticsProxy(platform) ? input.proxyPath : undefined
  const guide = buildAnalyticsInstallGuide(platform, { ...input, proxyPath })
  const recipes = proxyPath
    ? buildAnalyticsProxyRecipes({
        endpoint: input.endpoint,
        projectId: input.projectId,
        trackingId: input.trackingId,
        proxyPath,
      })
    : []
  const recipe =
    recipes.find((candidate) => candidate.id === input.proxyRecipe) ??
    recipes[0]
  const fence = (language: string, code: string) =>
    `\`\`\`${language}\n${code}\n\`\`\``

  const subject =
    platform === 'rest' ? 'app (using the REST API)' : `${meta.label} app`
  const lines = [
    proxyPath && recipe
      ? `Add Appwrite Analytics tracking to this ${subject}. Events go through a proxy at ${proxyPath} on the site's own domain (${recipe.label}), so ad blockers don't drop them.`
      : `Add Appwrite Analytics tracking to this ${subject}.`,
    '',
    'Context:',
    `- Appwrite endpoint: ${input.endpoint}`,
    `- Project ID: ${input.projectId}`,
    `- Analytics property${input.propertyName ? ` "${input.propertyName}"` : ''}, tracking ID: ${input.trackingId}`,
    ...(input.domain ? [`- Site domain: ${input.domain}`] : []),
    ...(proxyPath && recipe
      ? [`- Proxy: POST ${proxyPath}, built with ${recipe.label}`]
      : []),
    '',
  ]

  let step = 1
  if (guide.install) {
    lines.push(
      `${step++}. Install the SDK:`,
      fence(guide.install.language, guide.install.code),
      '',
    )
  }
  lines.push(
    `${step++}. ${
      platform === 'rest'
        ? 'Send events like this from the app or server:'
        : proxyPath
          ? 'Initialize tracking once, as early as possible in the browser code, and wire up the rest:'
          : 'Initialize tracking once, as early as possible in the app (for example where the Appwrite client is created), and wire up the rest:'
    }`,
    fence(guide.code.language, guide.code.code),
    '',
  )
  if (proxyPath && recipe) {
    lines.push(
      `${step++}. Add the proxy (${recipe.file}):`,
      fence(recipe.language, recipe.code),
      '',
    )
  }

  lines.push('Requirements:')
  if (proxyPath) {
    lines.push(
      '- The browser only talks to the proxy path; do not create an Appwrite Client for tracking in the browser.',
      `- The proxy authenticates with an API key that has only the analytics.write scope, read from the ${ANALYTICS_PROXY_KEY_ENV} environment variable. Never expose it to the browser or commit it; tell me to create the key in the Appwrite Console.`,
      "- The proxy must set ip and userAgent from the visitor's own request. Without them, every visitor is attributed to the proxy server.",
      '- The proxy copies only the listed event fields from the browser. Never forward userId or other fields the key would allow.',
      ...(recipe?.id === 'nextjs'
        ? ['- If the app uses the Pages Router, write the same handler as pages/api route instead, and adjust the proxy path to match.']
        : []),
    )
  } else {
    lines.push(
      '- Reuse an existing Appwrite Client if the app already has one; do not create a second client.',
    )
  }
  lines.push(
    '- Keep the tracking ID exactly as given.',
    '- Replace the example custom event with events that matter in this app (sign-ups, purchases, key actions), named in snake_case.',
    `- ${meta.note}`,
    '',
    'When done, load a page or open the app once so the first event reaches Appwrite.',
  )
  return lines.join('\n')
}

export function buildAnalyticsSnippets(
  platform: AnalyticsPlatform,
  input: SnippetInput,
): SnippetBlock[] {
  if (platform === 'web') return webBlocks(input)
  if (platform === 'flutter') return flutterBlocks(input)
  return restBlocks(input)
}
