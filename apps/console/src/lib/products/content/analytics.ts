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
  metaTitle: 'Analytics: built into your Appwrite hosting and auth',
  metaDescription:
    'Appwrite Analytics is built into Sites and Auth: turn it on when you create a site, no script to paste, and see signed-in users by name next to every visitor, bot, and AI agent. No cookies, no stored IP addresses.',
  hero: {
    title: 'Analytics built into your hosting and auth',
    description:
      'Turn it on when you create a site and Appwrite adds the tracker for you. Your signed-in users show up by name, next to every visitor, bot, and AI agent, without cookies or stored IP addresses.',
    stats: [
      { value: 'No script', label: 'Sites adds the tracker' },
      { value: 'By name', label: 'Signed-in users from Auth' },
      { value: 'No cookies', label: 'Nothing stored in the browser' },
      { value: '50K', label: 'Free events every month' },
      { value: 'MCP', label: 'Ask your traffic from any AI tool' },
    ],
  },
  faq: [
    {
      question: 'Do I need to add a tracking script to my Appwrite Site?',
      answer:
        'No. Leave Enable analytics on when you create the site, or turn it on later in the site’s settings, and Appwrite adds the tracker to every page served from your production domains. Preview deployments aren’t tracked. Sites hosted anywhere else use the Web SDK or a script tag.',
      links: [
        { label: 'Quick start', href: '/docs/products/analytics/quick-start' },
        { label: 'Sites', href: '/docs/products/sites' },
      ],
    },
    {
      question: 'Can I see which of my users visited?',
      answer:
        'Yes, if they sign in with Appwrite Auth and you turn on user identification for the property. Events sent from a signed-in session then carry the user’s ID, and the Console shows those users by name and photo, ranks them by visits, lists who is online now, and adds an Analytics tab to each user in Auth. Anonymous visitors aren’t affected, and only your project’s user IDs are stored, never emails or names.',
      links: [{ label: 'Privacy and data', href: '/docs/products/analytics/privacy' }],
    },
    {
      question: 'Does Appwrite Analytics use cookies?',
      answer:
        'No. Nothing is written to the visitor’s browser: no cookies, no local storage, no session storage. Unique visitors are counted with an identifier derived on the server from the request, the site’s hostname, and a salt that rotates every 24 hours, so the same person can’t be followed from one day to the next.',
      links: [{ label: 'Privacy and data', href: '/docs/products/analytics/privacy' }],
    },
    {
      question: 'Do I need a cookie banner or consent for Appwrite Analytics?',
      answer:
        'Appwrite Analytics is designed so you can measure traffic without one: it sets no cookies, stores no IP addresses or user agents, keeps no persistent identifiers for anonymous visitors, and honors Do Not Track and Global Privacy Control. Whether a banner is required for your site depends on your jurisdiction and everything else you run, so confirm your setup with your own counsel.',
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
        'Websites and web apps, mobile and desktop apps, AI agents, and backends, using Appwrite’s client and server SDKs or the REST API, including server-side tracking from an Appwrite Function. Each property has a type, web, mobile, or agent, that fits its dashboard to what you track. Client code only ever uses a public property ID, so it never needs an API key.',
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
    title: 'Built in, not bolted on',
    description:
      'Create a site with analytics on, sign your users in with Auth, and see who’s visiting from the same project.',
  },
}
