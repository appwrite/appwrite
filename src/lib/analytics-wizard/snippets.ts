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
      code: `// Everything at once
tracking.enableAllAutoTracking()

// Or opt in one at a time
tracking.enableAutoPageviews()
tracking.enableAutoScrollDepth()
tracking.enableAutoEngagementTime()
tracking.enableAutoOutboundTracking()
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
      code: `// Everything at once
tracking.enableAllAutoTracking();

// Or just app lifecycle events
tracking.enableAutoLifecycleEvents();
// tracking.disableAutoLifecycleEvents();`,
    },
    {
      label: 'Track route changes',
      language: 'dart',
      code: `MaterialApp(
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
      code: `curl -X POST "${endpoint}/analytics/event" \\
  -H "X-Appwrite-Project: ${projectId}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "propertyId": "${trackingId}",
    "name": "pageview",
    "url": "${sampleUrl(domain)}",
    "domain": "${host}",
    "referrer": "https://www.google.com/"
  }'`,
    },
    {
      label: 'Send a custom event with properties',
      language: 'bash',
      code: `curl -X POST "${endpoint}/analytics/event" \\
  -H "X-Appwrite-Project: ${projectId}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "propertyId": "${trackingId}",
    "name": "signup_completed",
    "url": "${sampleUrl(domain)}",
    "props": ["plan", "pro"]
  }'`,
    },
  ]
}

export function buildAnalyticsSnippets(
  platform: AnalyticsPlatform,
  input: SnippetInput,
): SnippetBlock[] {
  if (platform === 'web') return webBlocks(input)
  if (platform === 'flutter') return flutterBlocks(input)
  return restBlocks(input)
}
