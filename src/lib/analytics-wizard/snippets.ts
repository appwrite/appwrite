/**
 * Integration snippets for an analytics property.
 *
 * The Web and Flutter helpers come from appwrite/sdk-generator#1622
 * (`feat/analytics-auto-tracking`), which is still a draft: neither
 * `sdk-for-web` nor the server SDKs ship an analytics service yet. Those tabs
 * must always be rendered with `unreleased: true` so the UI can warn before
 * anyone copies code that cannot resolve.
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
  },
  flutter: {
    id: 'flutter',
    label: 'Flutter',
    description: 'iOS, Android, web and desktop from one codebase.',
    iconSlug: 'flutter',
    unreleased: true,
  },
  rest: {
    id: 'rest',
    label: 'REST',
    description: 'Any language, straight against the HTTP API.',
    iconSlug: 'web',
    unreleased: false,
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
      code: `import { Client, AnalyticsTracking } from 'appwrite'

const client = new Client()
  .setEndpoint('${endpoint}')
  .setProject('${projectId}')

const analytics = new AnalyticsTracking(client, '${trackingId}')`,
    },
    {
      label: 'Turn on automatic tracking',
      language: 'typescript',
      code: `analytics.enableAutoPageviews()
analytics.enableAutoScrollDepth()
analytics.enableAutoEngagementTime()
analytics.enableAutoOutboundTracking()
analytics.enableAutoDownloadTracking()`,
    },
    {
      label: 'Send your own events',
      language: 'typescript',
      code: `// A custom event
analytics.track('signup_completed')

// A pageview for a route your router changed manually
analytics.pageview()`,
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

final analytics = AnalyticsTracking(client, '${trackingId}');`,
    },
    {
      label: 'Turn on automatic tracking',
      language: 'dart',
      code: `// Everything at once
analytics.enableAllAutoTracking();

// Or just app lifecycle events
analytics.enableAutoLifecycleEvents();
// analytics.disableAutoLifecycleEvents();`,
    },
    {
      label: 'Track route changes',
      language: 'dart',
      code: `MaterialApp(
  navigatorObservers: [AnalyticsObserver(analytics)],
  home: const HomePage(),
);`,
    },
    {
      label: 'Send your own events',
      language: 'dart',
      code: `analytics.event('signup_completed', props: {'plan': 'pro'});

analytics.screenView('/checkout');`,
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
