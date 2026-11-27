import type { ProductFeatureContent } from '@/lib/products/features/types'

/**
 * Analytics product page sections, in page order. The page leads with what
 * only a built-in product can do: Sites adds the tracker, Auth puts names on
 * visitors. Privacy copy reflects the launch behavior; see `content/analytics.ts`.
 */
export const analyticsProductFeatures: ProductFeatureContent[] = [
  {
    id: 'sites',
    title: 'On from the moment you create a site',
    description:
      'Leave Enable analytics on when you create an Appwrite Site and every production page carries the tracker. No script to paste, no tag manager, nothing to redeploy. Preview deployments stay out of your numbers, and each site shows its visitors, top sources, and trend on its own page.',
    docsHref: '/docs/products/analytics/quick-start',
    docsLabel: 'Quick start',
    extraDocsLinks: [{ href: '/docs/products/sites', label: 'Sites docs' }],
  },
  {
    id: 'users',
    title: 'Your visitors, by name',
    description:
      'Turn on user identification and events from a signed-in session carry the user’s ID from Appwrite Auth. Analytics shows them as people, with photo and name, ranked by visits. See who is online right now, open any user to their pages and events, and find an Analytics tab on every user in Auth. Anonymous visitors stay anonymous.',
    docsHref: '/docs/products/analytics/visitors',
    docsLabel: 'Visitors docs',
    extraDocsLinks: [{ href: '/docs/products/auth', label: 'Auth docs' }],
  },
  {
    id: 'dashboard',
    title: 'Every number that matters, on one page',
    description:
      'Visitors, visits, pageviews, bounce rate, visit duration, and engagement time over any range, compared with the previous period, with live visitors as they arrive. Break traffic down by page, source, campaign, location, and device, and click any value to filter the whole dashboard.',
    docsHref: '/docs/products/analytics/dashboard',
    docsLabel: 'Dashboard docs',
  },
  {
    id: 'notes',
    title: 'Notes that explain the spikes',
    description:
      'Mark a release, a campaign, or an incident on the day it happened. Hover the chart and click to add a note, or post one from your deploy pipeline with an API key. Each note shows as a badge on the chart with its text in the tooltip, for everyone in the project, so a jump in traffic always has its reason next to it.',
    docsHref: '/docs/products/analytics/dashboard#notes',
    docsLabel: 'Notes docs',
  },
  {
    id: 'privacy',
    title: 'Private by design, not by setting',
    description:
      'Nothing is stored in the browser, raw IP addresses never reach the database, and visitor identifiers expire every day. Signed-in users are linked only when you turn it on, and only by your project’s own user IDs.',
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
    id: 'apps',
    title: 'Websites, mobile apps, and AI agents',
    description:
      'Pick a property type and the dashboard fits it. Websites get pages and sources. Mobile apps get screens, app versions, installs and updates, and a push funnel from received to opened, with each Appwrite Messaging message by name. Agents get the models and tools they call.',
    docsHref: '/docs/products/analytics/properties',
    docsLabel: 'Properties docs',
    extraDocsLinks: [
      { href: '/docs/products/messaging', label: 'Messaging docs' },
    ],
  },
  {
    id: 'ai-traffic',
    title: 'See humans, bots, and AI side by side',
    description:
      'Know how much of your traffic is people. Crawlers and agents are recognized and split by category, from GPTBot and ClaudeBot to search and social previews, while visits sent by ChatGPT, Claude, Gemini, or Perplexity land in their own AI channel. Click the humans or bots bar to filter the whole dashboard.',
    docsHref: '/docs/products/analytics/bots',
    docsLabel: 'Bots and AI docs',
  },
  {
    id: 'custom-events',
    title: 'Custom events for the moments that matter',
    description:
      'Pageviews, outbound links, scroll depth, and engagement time are tracked automatically. Add your own events with properties for sign-ups, purchases, or any action, then plot them, filter by them, and break each property down by value like any other metric.',
    docsHref: '/docs/products/analytics/custom-events',
    docsLabel: 'Custom events docs',
  },
  {
    id: 'platform',
    title: 'One project, no glue code',
    description:
      'Analytics lives next to your Sites, Auth, Messaging, and Firewall, so they already know each other. Turn a suspicious country, path, or bot into a Firewall rule for your site in two clicks, and manage properties with the same API keys, SDKs, and webhooks you already use.',
    docsHref: '/docs/products/analytics/firewall',
    docsLabel: 'Firewall docs',
  },
  {
    id: 'server-side',
    title: 'Track from the browser, the app, or the server',
    description:
      'Track sites, mobile and desktop apps, and backends with Appwrite’s client and server SDKs, or send events over REST from anywhere, including an Appwrite Function. Server-side events can carry a user ID with an API key, while client code only ever sees a public property ID.',
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
]
