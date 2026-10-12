import type { ProductFeatureContent } from '@/lib/products/features/types'

/**
 * Analytics product page sections, in page order. Privacy copy reflects the
 * intended launch behavior; see `content/analytics.ts`.
 */
export const analyticsProductFeatures: ProductFeatureContent[] = [
  {
    id: 'dashboard',
    title: 'Every number that matters, on one page',
    description:
      'Visitors, visits, pageviews, bounce rate, visit duration, and engagement time over any range, compared with the previous period. Break traffic down by page, source, campaign, location, and device, and click any value to filter the whole dashboard.',
    docsHref: '/docs/products/analytics',
    docsLabel: 'Analytics docs',
  },
  {
    id: 'privacy',
    title: 'Private by design, not by setting',
    description:
      'Appwrite Analytics measures traffic, not people. Nothing is stored in the browser, raw IP addresses never reach the database, and visitor identifiers expire every day.',
    docsHref: '/docs/products/analytics/privacy',
    docsLabel: 'Privacy docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
    brandLight: 'purple',
    dottedBackground: true,
  },
  {
    id: 'ai-traffic',
    title: 'See humans, bots, and AI side by side',
    description:
      'Know how much of your traffic is people. Crawlers and agents are recognized and split by category, from GPTBot and ClaudeBot to search and social previews, while visits sent by ChatGPT, Claude, Gemini, or Perplexity land in their own AI channel.',
    docsHref: '/docs/products/analytics/bots',
    docsLabel: 'Bots and AI docs',
  },
  {
    id: 'custom-events',
    title: 'Custom events for the moments that matter',
    description:
      'Pageviews, outbound links, scroll depth, and engagement time are tracked automatically. Add your own events with properties for sign-ups, purchases, or any action, then plot them, filter by them, and break them down like any other metric.',
    docsHref: '/docs/products/analytics/custom-events',
    docsLabel: 'Custom events docs',
  },
  {
    id: 'server-side',
    title: 'Track from the browser, the app, or the server',
    description:
      'Track sites, mobile and desktop apps, and backends with Appwrite’s client and server SDKs, or send events over REST from anywhere, including an Appwrite Function. Server-side events can carry a user ID with an API key, while client code only ever sees a public snippet ID.',
    docsHref: '/docs/products/analytics/server-side',
    docsLabel: 'Server-side docs',
  },
  {
    id: 'mcp',
    title: 'Ask your analytics from your AI tools',
    description:
      'Connect the Appwrite MCP server to Claude, Cursor, VS Code, or any MCP client and ask about your traffic in plain language. Your agent pulls metrics for any range and filter, compares periods, spots which crawlers hit a page, and drafts the Firewall rule for you to approve.',
    docsHref: '/docs/tooling/ai/mcp-servers',
    docsLabel: 'MCP server docs',
  },
  {
    id: 'platform',
    title: 'Built into the project that runs your app',
    description:
      'Analytics lives next to your Sites, Functions, and Firewall. See traffic on each site’s overview, turn a suspicious country, path, or bot into a Firewall rule in two clicks, and manage properties with the same API keys, SDKs, and webhooks you already use.',
    docsHref: '/docs/products/analytics',
    docsLabel: 'Analytics docs',
  },
]
