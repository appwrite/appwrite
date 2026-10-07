/**
 * Integration snippets for an analytics property.
 *
 * The Web and Flutter helpers come from appwrite/sdk-generator#1622
 * (`feat/analytics-auto-tracking`), which is still a draft: neither
 * `sdk-for-web` nor the server SDKs ship an analytics service yet. Those tabs
 * must always be rendered with `unreleased: true` so the UI can warn before
 * anyone copies code that cannot resolve.
 *
 * Both helpers take a **plain emitter function**, not a client and not a
 * property ID, so they do not hard-couple to the generated `Analytics` class:
 *
 *   web:     constructor(emit: AnalyticsEventEmitter, options: AnalyticsTrackingOptions = {})
 *   flutter: AnalyticsTracking(AnalyticsEventEmitter emit)
 *   flutter: AnalyticsObserver(this.emit, {nameExtractor, eventName = 'screen_view'})
 *
 * `propertyId` appears nowhere in either template, but the ingestion endpoint
 * requires it, so the console binds it inside the emitter closure.
 *
 * The emitter body calls `analytics.event(...)`, which is what both templates'
 * docblocks document. The console SDK generates `createEvent`, and the client
 * SDKs have not generated this service at all yet, so the method name is not
 * confirmed. That ambiguity is covered by the unreleased warning in the UI.
 *
 * `enableAllAutoTracking()` is NOT "everything" on either platform, and the
 * snippets must keep saying so:
 *   web:     covers pageviews, outbound links, scroll depth and engagement time.
 *            Downloads are excluded because the extension list is
 *            application-specific; `enableAutoDownloadTracking()` is required
 *            for any `file_download` event.
 *   flutter: covers app lifecycle events only. Route tracking (`screen_view`)
 *            requires attaching an `AnalyticsObserver`.
 *
 * Ingestion is nested under its property, matching the convention used by
 * `POST /v1/storage/buckets/:bucketId/files`:
 *
 *   POST /v1/analytics/properties/:propertyId/events
 *
 * The SDK tabs are unaffected because `propertyId` is a declared param that
 * Appwrite binds into the path, so only the raw curl example carries the URL.
 *
 * REST is the only integration that works against the API today.
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
  /** True while the SDK helpers for this platform are unpublished. */
  unreleased: boolean
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
    unreleased: true,
    note: 'Do Not Track is respected by default. Pass { respectDoNotTrack: false } to the constructor options to opt out. Automatic events are named pageview, outbound_link, file_download, scroll_depth and engagement_time.',
  },
  flutter: {
    id: 'flutter',
    label: 'Flutter',
    description: 'iOS, Android, web and desktop from one codebase.',
    iconSlug: 'flutter',
    unreleased: true,
    note: 'Automatic events are named screen_view, app_backgrounded and app_foregrounded.',
  },
  rest: {
    id: 'rest',
    label: 'REST',
    description: 'Any language, straight against the HTTP API.',
    iconSlug: 'web',
    unreleased: false,
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
}

function sampleUrl(domain: string): string {
  return domain ? `https://${domain}/pricing` : 'https://example.com/pricing'
}

function webBlocks(input: SnippetInput): SnippetBlock[] {
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
      code: `import { Client, Analytics, AnalyticsTracking } from 'appwrite'

const client = new Client()
  .setEndpoint('${endpoint}')
  .setProject('${projectId}')

const analytics = new Analytics(client)

// AnalyticsTracking takes a plain emitter function, so bind the property here.
const tracking = new AnalyticsTracking((name, options) =>
  analytics.event({ propertyId: '${trackingId}', name, ...(options ?? {}) }),
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

final analytics = Analytics(client);

// The tracking helpers take a plain emitter function, so bind the property here.
void emit(String name, {Map<String, dynamic>? props}) =>
    analytics.event(propertyId: '${trackingId}', name: name, props: props);

final tracking = AnalyticsTracking(emit);`,
    },
    {
      label: 'Turn on automatic tracking',
      language: 'dart',
      code: `// Currently this only covers app lifecycle events
// (app_backgrounded / app_foregrounded).
tracking.enableAllAutoTracking();

// Equivalent, if you prefer to be explicit:
// tracking.enableAutoLifecycleEvents();
// tracking.disableAutoLifecycleEvents();`,
    },
    {
      label: 'Track route changes',
      language: 'dart',
      code: `// Route tracking is NOT part of enableAllAutoTracking(): attach the
// observer to get screen_view events.
MaterialApp(
  navigatorObservers: [AnalyticsObserver(emit)],
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

/**
 * A prompt for an AI coding agent that sets up tracking end to end: the
 * property context, the install command, the code to add, and the platform
 * notes. Mirrors the project Connect modal's "Copy prompt".
 */
export function buildAnalyticsSetupPrompt(
  platform: AnalyticsPlatform,
  input: SnippetInput & { propertyName?: string },
): string {
  const meta = ANALYTICS_PLATFORM_META[platform]
  const guide = buildAnalyticsInstallGuide(platform, input)
  const fence = (language: string, code: string) =>
    `\`\`\`${language}\n${code}\n\`\`\``

  const lines = [
    `Add Appwrite Analytics tracking to this ${meta.label === 'REST' ? 'app (using the REST API)' : `${meta.label} app`}.`,
    '',
    'Context:',
    `- Appwrite endpoint: ${input.endpoint}`,
    `- Project ID: ${input.projectId}`,
    `- Analytics property${input.propertyName ? ` "${input.propertyName}"` : ''}, tracking ID: ${input.trackingId}`,
    ...(input.domain ? [`- Site domain: ${input.domain}`] : []),
    '',
  ]
  if (guide.install) {
    lines.push('1. Install the SDK:', fence(guide.install.language, guide.install.code), '')
  }
  lines.push(
    `${guide.install ? '2' : '1'}. ${platform === 'rest' ? 'Send events like this from the app or server:' : 'Initialize tracking once, as early as possible in the app (for example where the Appwrite client is created), and wire up the rest:'}`,
    fence(guide.code.language, guide.code.code),
    '',
    'Requirements:',
    '- Reuse an existing Appwrite Client if the app already has one; do not create a second client.',
    '- Keep the tracking ID exactly as given.',
    '- Replace the example custom event with events that matter in this app (sign-ups, purchases, key actions), named in snake_case.',
    `- ${meta.note}`,
  )
  if (meta.unreleased) {
    lines.push(
      '- Note: this SDK helper is not published yet. If the import does not resolve, fall back to POSTing events to the REST endpoint shown in the Appwrite docs.',
    )
  }
  lines.push('', 'When done, load a page or open the app once so the first event reaches Appwrite.')
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
