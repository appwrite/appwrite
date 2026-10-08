import type { AlternativeContent } from '@/lib/alternatives/types'

export const firebaseAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Platform',
      rows: [
        {
          label: 'Open source',
          appwrite: { value: true, note: 'Self-host anywhere' },
          competitor: { value: false, note: 'Client SDKs and emulators only' },
        },
        {
          label: 'Self-hosting',
          appwrite: { value: true, note: 'Same APIs and Console as Cloud' },
          competitor: false,
        },
        {
          label: 'Web hosting',
          appwrite: { value: 'Sites', note: 'Static and SSR on every plan' },
          competitor: { value: 'Hosting and App Hosting', note: 'App Hosting requires Blaze' },
        },
        { label: 'MCP server for AI agents', appwrite: true, competitor: true },
      ],
    },
    {
      title: 'Data and access',
      rows: [
        {
          label: 'Database models',
          appwrite: { value: '5', note: 'Tables, documents, vectors, PostgreSQL, MySQL' },
          competitor: { value: 'Documents', note: 'Firestore and Realtime Database' },
        },
        {
          label: 'Serverless or dedicated databases',
          appwrite: { value: true, note: 'Serverless TablesDB, or dedicated compute for any engine' },
          competitor: { value: false, note: 'Firestore is serverless only' },
        },
        {
          label: 'Managed PostgreSQL',
          appwrite: { value: true, note: 'Dedicated compute in your project' },
          competitor: { value: 'partial', note: 'SQL Connect, a separate product billed through Cloud SQL' },
        },
        {
          label: 'Access rules',
          appwrite: { value: 'Table and row permissions', note: 'Role strings, set from Console or SDK' },
          competitor: { value: 'Security Rules', note: 'A separate rules language' },
        },
        {
          label: 'Relationships between tables',
          appwrite: true,
          competitor: { value: false, note: 'Denormalize or join in code (Firestore)' },
        },
        {
          label: 'Realtime coverage',
          appwrite: { value: 'Every service', note: 'Rows, files, executions, sessions, teams' },
          competitor: { value: 'Data listeners', note: 'Firestore and Realtime Database' },
        },
      ],
    },
    {
      title: 'Auth and compute',
      rows: [
        {
          label: 'MFA, OIDC, and multi-tenancy',
          appwrite: { value: true, note: 'Included in Appwrite Auth' },
          competitor: { value: 'partial', note: 'Requires the Identity Platform upgrade' },
        },
        {
          label: 'Function runtimes',
          appwrite: { value: '13+', note: 'Node.js, Python, Go, Dart, PHP, and more' },
          competitor: { value: 'Node.js and Python', note: 'Dart is experimental' },
        },
        {
          label: 'Functions on the free plan',
          appwrite: { value: true, note: '750K executions per month' },
          competitor: { value: false, note: 'Requires the Blaze plan' },
        },
        {
          label: 'Messaging',
          appwrite: { value: 'Email, SMS, and push', note: 'Delivers push through FCM and APNs' },
          competitor: { value: 'Push', note: 'Firebase Cloud Messaging' },
        },
      ],
    },
    {
      title: 'Billing',
      rows: [
        {
          label: 'Pricing model',
          appwrite: { value: 'Plan with included usage', note: 'Pro from $25/mo, unlimited members' },
          competitor: { value: 'Pay per operation', note: 'Reads, writes, and deletes on Firestore' },
        },
        {
          label: 'Hard budget cap',
          appwrite: { value: true, note: 'Organization-wide, on Pro' },
          competitor: { value: 'partial', note: 'Preview, four services, not Firestore, Storage, or Auth' },
        },
        {
          label: 'Migration from the other platform',
          appwrite: { value: true, note: 'Free, built into the Console' },
          competitor: '-',
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Firebase might still fit',
    description:
      'Firebase helped a generation of developers ship faster, and it can still make sense in a few cases.',
    points: [
      'You are deep in Google Cloud and want BigQuery, Analytics, and Crashlytics wired together.',
      'Your team is already productive on Firebase and is not looking to switch stacks right now.',
      'You want analytics, crash reporting, remote config, and A/B testing from one vendor.',
      'Your product is built around Gemini in Firebase and the Google AI stack.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Appwrite vs Firebase for AI-assisted development',
      description: 'Readable permissions, 14 runtimes, and one MCP server for your agent.',
      href: '/blog/post/appwrite-vs-firebase-ai-development',
    },
    {
      kind: 'blog',
      title: 'Budget caps: How to stop unexpected cloud bills before they happen',
      description: 'Why a hard cap beats an alert when traffic spikes.',
      href: '/blog/post/budget-caps-stop-unexpected-cloud-bills',
    },
    {
      kind: 'docs',
      title: 'Migrate from Firebase',
      description: 'Service account setup, what moves, and known limits.',
      href: '/docs/advanced/migrations/firebase',
    },
    {
      kind: 'product',
      title: 'Appwrite Realtime',
      description: 'Live events from every service over one socket.',
      href: '/products/realtime',
    },
    {
      kind: 'product',
      title: 'Appwrite Databases',
      description: 'Five engines with permissions tied to Auth.',
      href: '/products/databases',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than Firebase?',
      answer:
        'For most new apps, yes. Appwrite gives you the same all-in-one backend as Firebase with readable permissions instead of a rules language, relational tables and managed PostgreSQL, functions in 13+ runtimes, and hard budget caps. It is also open source, so you can self-host it and never get locked in to one cloud.',
    },
    {
      question: 'What is the best open-source alternative to Firebase?',
      answer:
        'Appwrite is the best open-source alternative to Firebase. It covers auth, databases, storage, functions, messaging, realtime, and hosting, runs on any cloud or your own servers, and a free Migrations tool moves your Firebase users, data, and files over.',
      links: [{ label: 'Migrate from Firebase', href: '/docs/advanced/migrations/firebase' }],
    },
    {
      question: 'Does Firebase support relational databases?',
      answer:
        'Firebase is built around Firestore documents. Relational data needs SQL Connect, a separate PostgreSQL service billed through Cloud SQL. Appwrite gives you five database models in one project: TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute, all in the same Console.',
      links: [{ label: 'Appwrite Databases', href: '/products/databases' }],
    },
    {
      question: 'Is Appwrite a good Firebase alternative?',
      answer:
        'Yes. Appwrite covers the same ground as Firebase (auth, databases, storage, functions, messaging, realtime, and hosting) and adds self-hosting, relational tables, managed PostgreSQL, and functions in 13+ runtimes. It is open source, so your backend is never tied to one vendor.',
    },
    {
      question: 'How do I migrate from Firebase to Appwrite?',
      answer:
        'Create a service account in Google Cloud, upload its JSON key in the Appwrite Console, and choose what to import. Users, top-level Firestore collections, and Storage files move in the background, and migration usage does not count toward your Appwrite Cloud bill. Cloud Functions are rewritten in any Appwrite runtime.',
      links: [{ label: 'Migrate from Firebase', href: '/docs/advanced/migrations/firebase' }],
    },
    {
      question: 'Will my users need to reset their passwords?',
      answer:
        'No. Appwrite supports the modified scrypt hashes Firebase uses, so imported email and password users keep signing in with their existing passwords. Users who signed in with an OAuth provider sign in again with the same provider.',
    },
    {
      question: 'Can I cap my bill on Appwrite?',
      answer:
        'Yes. On Pro you set an organization-wide budget cap. Appwrite emails your team as usage approaches it and stops automatic scaling once you reach it, so a traffic spike or a runaway loop cannot turn into a surprise invoice.',
      links: [{ label: 'Budget caps', href: '/docs/advanced/billing/pro#budget-cap' }],
    },
    {
      question: 'Does Appwrite have realtime like Firestore listeners?',
      answer:
        'Yes, and it goes beyond data. Subscribe to channels over one WebSocket and receive events for rows, files, function executions, sessions, and team changes, filtered by queries on the server and checked against permissions.',
      links: [{ label: 'Realtime', href: '/products/realtime' }],
    },
    {
      question: 'Do I need to learn a rules language?',
      answer:
        'No. Appwrite permissions are role strings on tables, rows, buckets, and files, such as any user, a specific user, a team, or a team role. Set them from the Console or the SDK, and they apply to every API and to Realtime.',
      links: [{ label: 'Permissions', href: '/docs/advanced/security/permissions' }],
    },
  ],
  sources: [
    { label: 'Firebase pricing', href: 'https://firebase.google.com/pricing' },
    { label: 'Cloud Firestore pricing', href: 'https://cloud.google.com/firestore/pricing' },
    { label: 'Firebase spend caps', href: 'https://firebase.google.com/docs/projects/billing/spend-caps' },
    { label: 'Identity Platform pricing', href: 'https://cloud.google.com/identity-platform/pricing' },
    { label: 'Cloud Functions quotas', href: 'https://firebase.google.com/docs/functions/quotas' },
    { label: 'Firebase Studio', href: 'https://firebase.google.com/docs/studio' },
  ],
}
