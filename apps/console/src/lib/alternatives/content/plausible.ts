import type { AlternativeContent } from '@/lib/alternatives/types'

/**
 * Appwrite Analytics vs Plausible. Both are cookieless; the page competes on
 * the platform, the free plan, and bot visibility, and concedes retention,
 * funnels, and self-hosting. Appwrite privacy rows describe the intended
 * launch behavior of Analytics ingestion (see `lib/products/content/analytics.ts`).
 */
export const plausibleAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Privacy',
      rows: [
        { label: 'No cookies or browser storage', appwrite: true, competitor: true },
        {
          label: 'Raw IP addresses stored',
          appwrite: { value: 'Never', note: 'Used for location, then discarded' },
          competitor: { value: 'Never', note: 'Used for location, then discarded' },
        },
        {
          label: 'Visitor identifiers',
          appwrite: { value: 'Rotate daily', note: 'Hashed with a daily salt' },
          competitor: { value: 'Rotate daily', note: 'Hashed with a daily salt' },
        },
        {
          label: 'Data location',
          appwrite: { value: 'Your project’s region', note: 'Pick the region for each project' },
          competitor: { value: 'EU only', note: 'European-owned infrastructure' },
        },
      ],
    },
    {
      title: 'Analytics',
      rows: [
        { label: 'Visitors, pageviews, bounce rate, duration', appwrite: true, competitor: true },
        { label: 'Sources, UTM campaigns, pages, locations, devices', appwrite: true, competitor: true },
        {
          label: 'Custom event properties',
          appwrite: { value: true, note: 'On every plan' },
          competitor: { value: 'partial', note: 'Business plan and up' },
        },
        {
          label: 'Bots and AI agents',
          appwrite: { value: 'Shown and labeled', note: 'Human vs bot split, named agents' },
          competitor: { value: 'Filtered out', note: 'Removed from stats' },
        },
        { label: 'AI assistant referral channel', appwrite: true, competitor: true },
        { label: 'Funnels and user journeys', appwrite: false, competitor: { value: true, note: 'Business plan and up' } },
        {
          label: 'Data history',
          appwrite: { value: 'Up to 180 days', note: '30 days on Free, 90 on Pro' },
          competitor: { value: '3 to 5 years', note: 'Depending on plan' },
        },
      ],
    },
    {
      title: 'Plans and platform',
      rows: [
        {
          label: 'Free plan',
          appwrite: { value: true, note: '50,000 events a month, one property' },
          competitor: { value: false, note: '30-day trial, then from $9/mo' },
        },
        {
          label: 'Backend in the same project',
          appwrite: { value: true, note: 'Auth, databases, storage, functions, hosting' },
          competitor: false,
        },
        {
          label: 'Turn a value into a firewall rule',
          appwrite: { value: true, note: 'Country, path, or bot to a Firewall rule' },
          competitor: false,
        },
        {
          label: 'Open source and self-hostable',
          appwrite: { value: 'partial', note: 'Appwrite is; Analytics is Cloud only' },
          competitor: { value: true, note: 'AGPL Community Edition' },
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Plausible might still fit',
    description:
      'Plausible set the bar for privacy-friendly analytics, and both products count visitors the same cookieless way. Plausible may suit you better if these apply.',
    points: [
      'You need years of history, not months.',
      'You rely on funnels, goals, ecommerce revenue attribution, or Search Console in the dashboard.',
      'Your policy requires analytics hosted only in the EU on European-owned infrastructure.',
      {
        text: 'You want to self-host your analytics.',
        aside: 'Appwrite itself is open source, but Analytics runs on Appwrite Cloud.',
      },
    ],
  },
  related: [
    {
      kind: 'product',
      title: 'Appwrite Analytics',
      description: 'Cookieless web and app analytics, bots and AI included.',
      href: '/products/analytics',
    },
    {
      kind: 'docs',
      title: 'Privacy and data',
      description: 'What Analytics collects, stores, and never stores.',
      href: '/docs/products/analytics/privacy',
    },
    {
      kind: 'docs',
      title: 'Bots and AI traffic',
      description: 'How agents, crawlers, and AI referrals are classified.',
      href: '/docs/products/analytics/bots',
    },
    {
      kind: 'product',
      title: 'Appwrite Sites',
      description: 'Host your site and see its traffic in the same project.',
      href: '/products/sites',
    },
    {
      kind: 'product',
      title: 'Appwrite Firewall',
      description: 'Block or rate limit the bots you find in Analytics.',
      href: '/products/firewall',
    },
    {
      kind: 'product',
      title: 'Appwrite Functions',
      description: 'Send server-side events from the code that handles them.',
      href: '/products/functions',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite Analytics a good Plausible alternative?',
      answer:
        'Yes, especially if you build on Appwrite. Both count visitors without cookies, stored IP addresses, or persistent identifiers. Appwrite adds a free plan, custom event properties on every plan, labeled bot and AI agent traffic, and analytics that live next to your hosting, backend, and Firewall.',
    },
    {
      question: 'Is Appwrite Analytics as private as Plausible?',
      answer:
        'Both follow the same model: no cookies or browser storage, IP addresses used for location and then discarded, and visitor identifiers hashed with a salt that rotates every 24 hours. Appwrite also strips query strings from stored URLs and honors Do Not Track and Global Privacy Control. Plausible keeps all data in the EU; Appwrite stores it in your project’s region.',
      links: [{ label: 'Privacy and data', href: '/docs/products/analytics/privacy' }],
    },
    {
      question: 'Does Appwrite Analytics have a free plan?',
      answer:
        'Yes. The Free plan includes one property and 50,000 events a month with 30 days of history. Pro includes 100,000 events, then $3 per additional 100,000. Plausible starts with a 30-day trial and paid plans from $9 a month.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Why show bots instead of filtering them out?',
      answer:
        'Because AI agents and crawlers are now a real share of most sites’ traffic. Appwrite keeps people and automated traffic apart, so your visitor numbers stay human, while you can still see which AI crawlers read your pages and turn an abusive agent into a Firewall rule.',
      links: [{ label: 'Bots and AI traffic', href: '/docs/products/analytics/bots' }],
    },
    {
      question: 'Can I keep my history if I switch from Plausible?',
      answer:
        'Appwrite does not import Plausible data today. Run both side by side for a while, then switch your snippet once Appwrite has built up the history you need.',
    },
    {
      question: 'Can I self-host Appwrite Analytics?',
      answer:
        'Not yet. Appwrite is open source and self-hostable, but Analytics is available on Appwrite Cloud. If self-hosting your analytics is a requirement, Plausible Community Edition is a good fit.',
    },
  ],
  sources: [
    { label: 'Plausible pricing', href: 'https://plausible.io/#pricing' },
    { label: 'Plausible data policy', href: 'https://plausible.io/data-policy' },
    { label: 'Plausible bot filtering', href: 'https://plausible.io/docs/bot-traffic-filtering' },
    { label: 'Plausible self-hosting', href: 'https://plausible.io/docs/self-hosting' },
  ],
}
