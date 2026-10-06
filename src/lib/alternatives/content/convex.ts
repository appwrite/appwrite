import type { AlternativeContent } from '@/lib/alternatives/types'

export const convexAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Platform',
      rows: [
        { label: 'Open source', appwrite: true, competitor: false },
        {
          label: 'License',
          appwrite: { value: 'Open source', note: 'OSI approved' },
          competitor: { value: 'FSL-1.1', note: 'Source-available, not open source' },
        },
        {
          label: 'Self-hosting',
          appwrite: { value: true, note: 'Same APIs, SDKs, and Console as Cloud' },
          competitor: { value: 'partial', note: 'Community support only' },
        },
        { label: 'Frontend hosting', appwrite: true, competitor: false },
        {
          label: 'Email, SMS, and push messaging',
          appwrite: true,
          competitor: { value: false, note: 'Through third-party services' },
        },
        { label: 'MCP server for AI agents', appwrite: true, competitor: true },
      ],
    },
    {
      title: 'Data and realtime',
      rows: [
        {
          label: 'Database models',
          appwrite: { value: '5', note: 'Tables, documents, vectors, PostgreSQL, MySQL' },
          competitor: { value: '1', note: 'Documents, optional schema' },
        },
        {
          label: 'Serverless or dedicated databases',
          appwrite: { value: true, note: 'Choose per database' },
          competitor: { value: false, note: 'Serverless only' },
        },
        {
          label: 'Realtime coverage',
          appwrite: { value: 'Every service', note: 'Rows, files, executions, sessions, teams' },
          competitor: { value: 'Query results', note: 'Reactive database queries' },
        },
        {
          label: 'Vector search',
          appwrite: { value: true, note: 'VectorsDB or pgvector' },
          competitor: { value: 'partial', note: 'From actions only, results are not reactive' },
        },
        {
          label: 'Managed PostgreSQL and MySQL',
          appwrite: true,
          competitor: false,
        },
      ],
    },
    {
      title: 'Auth and compute',
      rows: [
        {
          label: 'First-party authentication',
          appwrite: { value: true, note: 'MFA, teams, 40+ OAuth providers' },
          competitor: { value: 'partial', note: 'Convex Auth is in beta, third-party providers recommended' },
        },
        {
          label: 'Server languages',
          appwrite: { value: '13+ runtimes', note: 'Node.js, Python, Go, Dart, PHP, and more' },
          competitor: 'TypeScript and JavaScript',
        },
        {
          label: 'Calling external APIs',
          appwrite: 'From any function',
          competitor: 'From actions only',
        },
        { label: 'Scheduled jobs', appwrite: true, competitor: true },
      ],
    },
    {
      title: 'Pricing',
      rows: [
        {
          label: 'Paid plan',
          appwrite: { value: 'From $25/mo', note: 'Unlimited members' },
          competitor: '$25 per developer per month',
        },
        {
          label: 'Realtime is billed as',
          appwrite: { value: 'Connections and messages', note: '500 connections and 6M messages on Pro' },
          competitor: { value: 'Function calls', note: 'Subscription updates count as calls' },
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Convex might still fit',
    description:
      'Convex has a thoughtful developer experience and a loyal community. It may suit you better if these ring true.',
    points: [
      {
        text: "Reactive UI drives your product and you prefer Convex's built-in reactivity over wiring Realtime yourself.",
        aside:
          'That path trades flexibility for speed: more abstraction, and less room to mix databases, auth, and hosting on your own terms.',
      },
      'You picked Convex before you evaluated Appwrite, and migrating this project is not worth the effort right now.',
      'Your team is mid-ship on Convex and would rather finish the current roadmap than replatform.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Appwrite vs Convex for AI apps and agent workflows',
      description: 'Auth, data model, and function boundaries compared.',
      href: '/blog/post/appwrite-vs-convex-ai-agents',
    },
    {
      kind: 'product',
      title: 'Appwrite Realtime',
      description: 'Live events from every service over one socket.',
      href: '/products/realtime',
    },
    {
      kind: 'product',
      title: 'Appwrite Functions',
      description: 'APIs, cron jobs, and event handlers in 13+ runtimes.',
      href: '/products/functions',
    },
    {
      kind: 'product',
      title: 'Appwrite Auth',
      description: 'Email, OAuth, SMS, MFA, teams, and sessions.',
      href: '/products/auth',
    },
    {
      kind: 'docs',
      title: 'Function runtimes',
      description: 'Every supported language and version.',
      href: '/docs/products/functions/runtimes',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than Convex?',
      answer:
        'For most teams, yes. Appwrite gives you realtime on every service, first-party Auth with MFA and teams, functions in 13+ runtimes, storage, messaging, and web hosting in one open-source platform. Convex focuses on reactive queries in TypeScript and leaves auth, hosting, and messaging to other services.',
    },
    {
      question: 'Is Convex open source?',
      answer:
        'No. Convex is not open source. Its backend is source-available under the Functional Source License (FSL-1.1), which is not approved by the Open Source Initiative and restricts how the code can be used. Appwrite is fully open source, so you can read, run, and change all of it.',
      links: [{ label: 'Appwrite on GitHub', href: 'https://github.com/appwrite/appwrite' }],
    },
    {
      question: 'What is the best open-source alternative to Convex?',
      answer:
        'Appwrite is the best open-source alternative to Convex. It is fully open source, self-hosts with a single Docker command, and includes realtime, databases, auth, functions, storage, messaging, and hosting in every install.',
      links: [{ label: 'Self-hosting', href: '/docs/advanced/self-hosting' }],
    },
    {
      question: 'Does Convex support more than one database model?',
      answer:
        'No. Convex stores all data as documents in its own serverless database. Appwrite gives you five database models in one project: TablesDB, DocumentsDB, and VectorsDB on serverless or dedicated compute, plus managed PostgreSQL and MySQL.',
      links: [{ label: 'Appwrite Databases', href: '/products/databases' }],
    },
    {
      question: 'Is Appwrite a good Convex alternative?',
      answer:
        'Yes, if you want reactivity without being limited to one language or bringing your own auth. Appwrite streams realtime events from every service, includes first-party Auth with MFA and teams, runs functions in 13+ runtimes, and hosts your frontend with Sites.',
    },
    {
      question: 'Does Appwrite have reactive queries?',
      answer:
        'Yes. Subscribe to channels over a single WebSocket with queries filtered on the server, and receive updates for rows, files, function executions, sessions, teams, and presence. Every event is checked against permissions before it reaches the client.',
      links: [{ label: 'Realtime', href: '/products/realtime' }],
    },
    {
      question: 'Can I write backend logic in Python or Go?',
      answer:
        'Yes. Appwrite Functions support Node.js, Bun, Deno, Python, Go, Dart, PHP, Ruby, Rust, Java, Kotlin, Swift, .NET, C++, and more. Any function can call external APIs, run on a schedule, react to platform events, or serve HTTP.',
      links: [{ label: 'Runtimes', href: '/docs/products/functions/runtimes' }],
    },
    {
      question: 'How does pricing compare for a team?',
      answer:
        'Convex Professional is $25 per developer per month, so cost grows with headcount. Appwrite Pro starts at $25/mo for the whole organization with unlimited members.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Can I run Appwrite on my own servers?',
      answer:
        'Yes. Self-hosted Appwrite runs with Docker and uses the same APIs, SDKs, and Console as Appwrite Cloud, so you can move between them by changing the endpoint.',
      links: [{ label: 'Self-hosting', href: '/docs/advanced/self-hosting' }],
    },
  ],
  sources: [
    { label: 'Convex pricing', href: 'https://www.convex.dev/pricing' },
    { label: 'Convex limits', href: 'https://docs.convex.dev/production/state/limits' },
    { label: 'Convex self-hosting', href: 'https://docs.convex.dev/self-hosting' },
    { label: 'Convex authentication', href: 'https://docs.convex.dev/auth/overview' },
    { label: 'Convex hosting', href: 'https://docs.convex.dev/production/hosting' },
    { label: 'Convex backend license', href: 'https://github.com/get-convex/convex-backend/blob/main/LICENSE.md' },
    { label: 'Functional Source License', href: 'https://fsl.software' },
  ],
}
