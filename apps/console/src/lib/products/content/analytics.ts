import type { ProductPageContent } from '@/lib/products/types'

/**
 * Analytics product page copy.
 *
 * Privacy statements describe the intended launch behavior (see the backend
 * prerequisites tracked with this page): no cookies or client storage, raw IP
 * and user agent discarded after enrichment, a visitor ID salt that rotates
 * daily, query strings stripped from stored URLs, and Do Not Track / Global
 * Privacy Control honored server-side. Keep this copy in step with the
 * ingestion code before publishing.
 */
export const analyticsProductContent: ProductPageContent = {
  id: 'analytics',
  metaTitle: 'Analytics: cookieless, privacy-first web and app analytics',
  metaDescription:
    'Appwrite Analytics measures visitors, pages, sources, campaigns, and custom events without cookies or stored IP addresses. See human, bot, and AI traffic side by side, in the same project as your app.',
  hero: {
    title: 'See who’s really visiting, humans and AI',
    description:
      'Understand who visits, where they come from, and what they do, without cookies, fingerprints, or stored IP addresses. Track websites and apps from the same Appwrite project that runs them.',
    stats: [
      { value: 'No cookies', label: 'Nothing stored in the browser' },
      { value: '50K', label: 'Free events every month' },
      { value: '2 clicks', label: 'From any bot to a Firewall rule' },
      { value: 'MCP', label: 'Ask your traffic from any AI tool' },
      { value: 'Any platform', label: 'Web, mobile, and server SDKs' },
    ],
  },
  faq: [
    {
      question: 'Does Appwrite Analytics use cookies?',
      answer:
        'No. Nothing is written to the visitor’s browser: no cookies, no local storage, no session storage. Unique visitors are counted with an identifier derived on the server from the request, the site’s hostname, and a salt that rotates every 24 hours, so the same person can’t be followed from one day to the next.',
      links: [{ label: 'Privacy and data', href: '/docs/products/analytics/privacy' }],
    },
    {
      question: 'Do I need a cookie banner or consent for Appwrite Analytics?',
      answer:
        'Appwrite Analytics is designed so you can measure traffic without one: it sets no cookies, stores no IP addresses or user agents, keeps no persistent identifiers, and honors Do Not Track and Global Privacy Control. Whether a banner is required for your site depends on your jurisdiction and everything else you run, so confirm your setup with your own counsel.',
      links: [{ label: 'Privacy and data', href: '/docs/products/analytics/privacy' }],
    },
    {
      question: 'What data is stored for each event?',
      answer:
        'The page path, hostname, referrer domain and campaign parameters, country, region and city, browser, operating system, device type, screen size bucket, and any custom properties you send. The raw IP address and user agent are used to derive location and device, then discarded. Query strings are stripped from stored URLs except for campaign parameters, so tokens and emails in links never reach your reports.',
      links: [{ label: 'Privacy and data', href: '/docs/products/analytics/privacy' }],
    },
    {
      question: 'How does Appwrite Analytics handle bots and AI agents?',
      answer:
        'Automated traffic is identified from its user agent and kept separate from people instead of silently dropped. You see the human versus bot split, named agents such as GPTBot, ClaudeBot, or Bingbot, and categories like AI crawler, AI assistant, and search crawler. Visits referred by AI assistants such as ChatGPT, Claude, Gemini, and Perplexity get their own AI channel, and any agent can be turned into a Firewall rule.',
      links: [{ label: 'Bots and AI traffic', href: '/docs/products/analytics/bots' }],
    },
    {
      question: 'Which platforms can I track?',
      answer:
        'Websites and web apps, mobile and desktop apps, and backends, using Appwrite’s client and server SDKs or the REST API, including server-side tracking from an Appwrite Function. Each property gets a public snippet ID, so client code never needs an API key.',
      links: [
        { label: 'Quick start', href: '/docs/products/analytics/quick-start' },
        { label: 'Server-side tracking', href: '/docs/products/analytics/server-side' },
      ],
    },
    {
      question: 'How long is data kept, and how do I delete it?',
      answer:
        'Reports cover the last 30 days on Free, 90 days on Pro, and 180 days on Scale. Data older than 180 days is removed automatically. Deleting a property permanently purges all of its events.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'How is Appwrite Analytics priced?',
      answer:
        'Analytics is included in every Appwrite plan. Free covers one property and 50,000 events a month. Pro includes five properties and 100,000 events a month, and additional events cost $3 per 100,000.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
  ],
  cta: {
    title: 'Measure your traffic, not your visitors',
    description:
      'Create a property, add one snippet, and watch the first visitors arrive without a cookie in sight.',
  },
}
