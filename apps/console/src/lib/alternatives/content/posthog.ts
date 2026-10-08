import type { AlternativeContent } from '@/lib/alternatives/types'

/**
 * Appwrite Analytics vs PostHog. Appwrite privacy rows describe the intended
 * launch behavior of Analytics ingestion (see `lib/products/content/analytics.ts`).
 */
export const posthogAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Privacy defaults',
      rows: [
        {
          label: 'Cookies',
          appwrite: { value: 'None', note: 'No cookies or browser storage, ever' },
          competitor: { value: 'By default', note: 'Cookieless mode is opt-in' },
        },
        {
          label: 'Raw IP addresses',
          appwrite: { value: 'Never stored', note: 'Used for location, then discarded' },
          competitor: { value: 'partial', note: 'Discard is a project setting' },
        },
        {
          label: 'Visitor identifiers',
          appwrite: { value: 'Rotate daily', note: 'No cross-day tracking' },
          competitor: { value: 'Persistent', note: 'Built for identifying users' },
        },
        {
          label: 'Do Not Track and GPC',
          appwrite: { value: true, note: 'Honored by default' },
          competitor: { value: 'partial', note: 'Do Not Track is an SDK option' },
        },
        {
          label: 'Query strings in stored URLs',
          appwrite: { value: 'Stripped', note: 'Except campaign parameters' },
          competitor: { value: 'Kept', note: 'Redact in your own code' },
        },
      ],
    },
    {
      title: 'Web analytics',
      rows: [
        { label: 'Visitors, pageviews, bounce rate, duration', appwrite: true, competitor: true },
        { label: 'Sources, UTM campaigns, pages, locations, devices', appwrite: true, competitor: true },
        { label: 'Custom events with properties', appwrite: true, competitor: true },
        { label: 'Server-side tracking', appwrite: true, competitor: true },
        {
          label: 'Bot and AI agent breakdowns',
          appwrite: { value: true, note: 'Shown by default, on the main dashboard' },
          competitor: { value: true, note: 'Query-time classification' },
        },
        { label: 'Funnels, retention, and cohorts', appwrite: false, competitor: true },
        { label: 'Session replay, feature flags, experiments', appwrite: false, competitor: true },
      ],
    },
    {
      title: 'Platform',
      rows: [
        {
          label: 'Backend in the same project',
          appwrite: { value: true, note: 'Auth, databases, storage, functions, hosting' },
          competitor: false,
        },
        {
          label: 'Traffic on your hosting dashboard',
          appwrite: { value: true, note: 'On every Appwrite Site' },
          competitor: false,
        },
        {
          label: 'Turn a value into a firewall rule',
          appwrite: { value: true, note: 'Country, path, or bot to a Firewall rule' },
          competitor: false,
        },
        {
          label: 'Free monthly allowance',
          appwrite: { value: '50,000 events', note: 'Free plan, then $3 per 100K on Pro' },
          competitor: { value: '1M events', note: 'Then usage-based' },
        },
        {
          label: 'Open source and self-hostable',
          appwrite: { value: 'partial', note: 'Appwrite is; Analytics is Cloud only' },
          competitor: { value: true, note: 'MIT-licensed core' },
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When PostHog might still fit',
    description:
      'PostHog is a broad product toolkit, and Appwrite Analytics is focused web and app analytics. PostHog may suit you better if these apply.',
    points: [
      'You need funnels, retention, cohorts, or paths across identified users.',
      'You want session replay, feature flags, experiments, or surveys in the same tool.',
      'You track at high volume and the 1M free events a month cover most of it.',
      {
        text: 'You need to self-host your analytics.',
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
      title: 'Server-side tracking',
      description: 'Send events from your backend or an Appwrite Function.',
      href: '/docs/products/analytics/server-side',
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
      title: 'Appwrite Auth',
      description: 'Users and sessions next to the events they create.',
      href: '/products/auth',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite Analytics a good PostHog alternative?',
      answer:
        'For web and app traffic analytics, yes. Appwrite Analytics covers visitors, pages, sources, campaigns, locations, devices, and custom events, cookieless by default and inside the same project as your backend and hosting. If you rely on session replay, feature flags, experiments, or funnels and retention, PostHog covers more of that product surface.',
    },
    {
      question: 'Does Appwrite Analytics need a cookie banner like PostHog?',
      answer:
        'Appwrite Analytics sets no cookies, stores no IP addresses, uses identifiers that expire daily, and honors Do Not Track and Global Privacy Control, so it is built to run without a consent banner. PostHog uses cookies by default and offers a cookieless mode you configure. Confirm what your own site needs with your counsel.',
      links: [{ label: 'Privacy and data', href: '/docs/products/analytics/privacy' }],
    },
    {
      question: 'Can I track events from my server with Appwrite?',
      answer:
        'Yes. Send events over REST or with any Appwrite server SDK, from your backend or an Appwrite Function. With an API key you can attach a user ID, and client code only ever uses a public snippet ID.',
      links: [{ label: 'Server-side tracking', href: '/docs/products/analytics/server-side' }],
    },
    {
      question: 'How does Appwrite handle bots and AI crawlers?',
      answer:
        'Automated traffic is labeled instead of hidden: you see the human and bot split, named agents like GPTBot and ClaudeBot, and categories such as AI crawler and AI assistant. Visits referred by ChatGPT, Claude, Gemini, or Perplexity get their own AI channel.',
      links: [{ label: 'Bots and AI traffic', href: '/docs/products/analytics/bots' }],
    },
    {
      question: 'How does pricing compare?',
      answer:
        'PostHog includes 1 million analytics events a month for free, then bills per event. Appwrite Analytics is part of your Appwrite plan: Free includes 50,000 events a month and one property, and Pro includes 100,000 events and five properties, then $3 per additional 100,000 events.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Can I use Appwrite Analytics with PostHog?',
      answer:
        'Yes. Many teams keep a product analytics tool for in-app behavior and use a cookieless tool for public traffic. Appwrite Analytics can measure your marketing site and Appwrite Sites while PostHog stays on the logged-in product.',
    },
  ],
  sources: [
    { label: 'PostHog pricing', href: 'https://posthog.com/pricing' },
    { label: 'PostHog GDPR compliance', href: 'https://posthog.com/docs/privacy/gdpr-compliance' },
    { label: 'PostHog data collection controls', href: 'https://posthog.com/docs/privacy/data-collection' },
    { label: 'PostHog bot detection', href: 'https://posthog.com/docs/web-analytics/bot-detection' },
  ],
}
