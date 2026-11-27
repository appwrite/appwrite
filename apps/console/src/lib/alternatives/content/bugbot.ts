import type { AlternativeContent } from '@/lib/alternatives/types'

/**
 * Cursor Bugbot and Graphite facts checked on 2026-11-27 against Cursor's
 * Bugbot, pricing, Origin, and Rollouts docs and Graphite's pricing (see
 * `sources`). Cursor hosts Git (Origin, early beta) and checks deploys with
 * Rollouts against your observability; it doesn't host sites or run Postgres.
 */
export const bugbotAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Review',
      rows: [
        { label: 'Pull request summary', appwrite: true, competitor: true },
        {
          label: 'Findings on the changed lines',
          appwrite: {
            value: 'Critical to Info',
            note: 'With a category on each',
          },
          competitor: true,
        },
        {
          label: 'Suggested fixes',
          appwrite: {
            value: true,
            note: 'Suggestions and prompts for coding agents',
          },
          competitor: { value: true, note: 'Fix in Cursor or on the web' },
        },
        {
          label: 'Fixes findings with an agent',
          appwrite: { value: false, note: 'Hands a prompt to your own agent' },
          competitor: {
            value: true,
            note: 'Autofix with the cloud agent, billed as agent usage',
          },
        },
        {
          label: 'Learns team rules',
          appwrite: { value: true, note: 'Learnings saved from conversations' },
          competitor: { value: true, note: 'Learned rules and BUGBOT.md' },
        },
        {
          label: 'Status check on the commit',
          appwrite: true,
          competitor: true,
        },
        {
          label: 'Review analytics',
          appwrite: {
            value: true,
            note: 'Approvals, useful findings, review time',
          },
          competitor: { value: true, note: 'Dashboard and rule analytics' },
        },
        {
          label: 'Run a review from the editor',
          appwrite: false,
          competitor: { value: true, note: 'From the Cursor agent' },
        },
      ],
    },
    {
      title: 'Git providers',
      rows: [
        { label: 'GitHub', appwrite: true, competitor: true },
        { label: 'GitLab', appwrite: true, competitor: true },
        { label: 'Bitbucket', appwrite: true, competitor: true },
        { label: 'Azure DevOps', appwrite: false, competitor: true },
      ],
    },
    {
      title: 'Pricing',
      rows: [
        {
          label: 'Pricing model',
          appwrite: {
            value: 'Credits per review',
            note: 'From your Appwrite plan',
          },
          competitor: {
            value: 'Usage per review',
            note: 'About $1.00 to $1.50 on average',
          },
        },
        {
          label: 'Plan you need',
          appwrite: {
            value: 'Any Appwrite plan',
            note: '100 credits a month on Free',
          },
          competitor: {
            value: 'Cursor Premium or Teams',
            note: 'From $30 a month',
          },
        },
        {
          label: 'Typical review past the plan',
          appwrite: { value: '$0.50', note: 'A Normal review, 50 credits' },
          competitor: {
            value: 'About $1.00 to $1.50',
            note: 'Billed to whoever enabled it',
          },
        },
      ],
    },
    {
      title: 'Platform',
      rows: [
        {
          label: 'Hosts customer-facing apps',
          appwrite: {
            value: true,
            note: 'Sites and Functions with custom domains',
          },
          competitor: false,
        },
        {
          label: 'Auth for your own users',
          appwrite: true,
          competitor: false,
        },
        {
          label: 'Databases, storage, messaging, and realtime',
          appwrite: true,
          competitor: false,
        },
        {
          label: 'Hosts your Git repositories',
          appwrite: false,
          competitor: { value: 'partial', note: 'Origin, in early beta' },
        },
        {
          label: 'Watches production',
          appwrite: { value: true, note: 'Monitors on usage and endpoints' },
          competitor: {
            value: 'partial',
            note: 'Rollouts read your observability tool',
          },
        },
        {
          label: 'On-call pushes to your phone',
          appwrite: { value: true, note: 'Appwrite Beep, with escalations' },
          competitor: false,
        },
        { label: 'Open source', appwrite: true, competitor: false },
      ],
    },
  ],
  fairPlay: {
    title: 'When Cursor Bugbot might still fit',
    description:
      'Bugbot lives inside Cursor, and Cursor now reaches from the editor to Git hosting and deploy checks. If your team builds in Cursor, it keeps review next to the agent that writes the code.',
    points: [
      'Your team writes code in Cursor and wants findings to open as fixes in the editor or the cloud agent.',
      'You review on Azure DevOps or GitHub Enterprise Server.',
      'You want Rollouts to check deploys against the observability tools you already run.',
      'You use Graphite for stacked pull requests on GitHub.',
    ],
  },
  related: [
    {
      kind: 'product',
      title: 'Appwrite Reviews',
      description: 'Code review on every pull request, next to your deploys.',
      href: '/products/reviews',
    },
    {
      kind: 'product',
      title: 'Appwrite Sites',
      description: 'Static, SSR, and CSR deploys from Git.',
      href: '/products/sites',
    },
    {
      kind: 'product',
      title: 'Appwrite Auth',
      description: 'Email, OAuth, SMS, MFA, teams, and sessions.',
      href: '/products/auth',
    },
    {
      kind: 'product',
      title: 'Appwrite Databases',
      description:
        'Managed PostgreSQL, TablesDB, DocumentsDB, VectorsDB, MySQL.',
      href: '/products/databases',
    },
    {
      kind: 'product',
      title: 'Appwrite Monitors',
      description: 'Alerts when your project crosses a threshold you set.',
      href: '/products/monitors',
    },
    {
      kind: 'product',
      title: 'Appwrite Beep',
      description: 'Your project updates on your phone, on your terms.',
      href: '/products/beep',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite Reviews a good alternative to Cursor Bugbot?',
      answer:
        'Yes, especially for apps that run on Appwrite. Appwrite Reviews reviews every pull request on GitHub, GitLab, and Bitbucket, from the same project that deploys your Sites and Functions, runs your backend, watches production with Monitors, and pages your phone with Beep. Bugbot is part of Cursor, billed per review on top of a Cursor plan.',
      links: [{ label: 'Appwrite Reviews', href: '/products/reviews' }],
    },
    {
      question: 'Does Cursor host my app or its database?',
      answer:
        'No. Cursor doesn’t host sites or run PostgreSQL for your app. Origin hosts Git repositories in early beta, and Rollouts checks deploys against an observability tool you connect. Appwrite is a production backend for customer-facing apps: auth for your own users, managed PostgreSQL and other databases, storage, functions, messaging, realtime, and hosting with custom domains, open source and self-hostable.',
    },
    {
      question: 'How does pricing compare?',
      answer:
        'Bugbot bills each review, about $1.00 to $1.50 on average, and needs Cursor Premium or a Teams plan. Appwrite Reviews uses credits from your Appwrite plan: 10, 50, or 500 credits per review, 100 a month on Free, 2,000 a month for each project on Pro, and extra credits at $1 per 100.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'What about Graphite?',
      answer:
        'Graphite, now part of Cursor, offers stacked pull requests and an AI reviewer on GitHub, from $20 per user a month on Starter and $40 on Team, billed annually. Appwrite Reviews has no seats and works on GitHub, GitLab, and Bitbucket.',
    },
    {
      question: 'Does Appwrite watch my deploys after the merge?',
      answer:
        'Appwrite Monitors watch usage metrics and endpoints of the project you deploy to, and alert by email, in the Console, and on your phone with Beep, escalating until someone acknowledges. Cursor Rollouts checks deploys against an observability tool you connect, such as Datadog, and opens an issue.',
      links: [{ label: 'Appwrite Monitors', href: '/products/monitors' }],
    },
    {
      question: 'Can coding agents act on the findings?',
      answer:
        'Yes. Each finding includes a prompt to fix it with an AI agent, and the summary has one prompt for all of them. Bugbot can also fix findings itself with Cursor’s cloud agent, billed as agent usage.',
    },
    {
      question: 'Is Appwrite open source?',
      answer:
        'Yes. Appwrite and its review service are open source, and you can self-host them. Bugbot, Graphite, and Origin are closed source services run by Cursor.',
    },
  ],
  sources: [
    { label: 'Cursor Bugbot', href: 'https://cursor.com/bugbot' },
    { label: 'Bugbot docs', href: 'https://cursor.com/docs/bugbot' },
    {
      label: 'Bugbot legacy pricing',
      href: 'https://cursor.com/docs/bugbot/legacy-pricing',
    },
    {
      label: 'Bugbot pricing changes',
      href: 'https://cursor.com/blog/may-2026-bugbot-changes',
    },
    { label: 'Cursor pricing', href: 'https://cursor.com/pricing' },
    {
      label: 'Cursor Origin',
      href: 'https://cursor.com/en-US/changelog/origin-code-hosting',
    },
    { label: 'Cursor Rollouts', href: 'https://cursor.com/docs/rollouts' },
    {
      label: 'Cursor acquires Graphite',
      href: 'https://cursor.com/blog/graphite',
    },
    { label: 'Graphite pricing', href: 'https://graphite.com/pricing' },
    { label: 'Cursor data use', href: 'https://cursor.com/data-use' },
  ],
}
