import type { AlternativeContent } from '@/lib/alternatives/types'

export const neonAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'PostgreSQL',
      rows: [
        {
          label: 'Database models',
          appwrite: { value: '5', note: 'Tables, documents, vectors, PostgreSQL, MySQL' },
          competitor: { value: '1', note: 'PostgreSQL' },
        },
        { label: 'Wire protocol, psql, any driver or ORM', appwrite: true, competitor: true },
        { label: 'Connection pooling', appwrite: true, competitor: true },
        {
          label: 'Point-in-time recovery window',
          appwrite: '1 to 35 days',
          competitor: { value: 'Up to 30 days', note: 'On the Scale plan' },
        },
        {
          label: 'Replicas',
          appwrite: { value: 'Up to 5 HA replicas', note: 'Async, sync, or quorum with failover' },
          competitor: 'Read replicas',
        },
        {
          label: 'Branching',
          appwrite: { value: 'Snapshot branches', note: 'Short-lived, never merge back' },
          competitor: { value: 'Copy-on-write branches', note: 'A Neon strength' },
        },
      ],
    },
    {
      title: 'Around the database',
      rows: [
        {
          label: 'Authentication',
          appwrite: { value: true, note: 'MFA, teams, 40+ OAuth providers' },
          competitor: { value: true, note: 'Managed Better Auth' },
        },
        { label: 'Object storage', appwrite: true, competitor: true },
        {
          label: 'Function runtimes',
          appwrite: '13+',
          competitor: 'JavaScript and TypeScript',
        },
        { label: 'Frontend hosting', appwrite: true, competitor: false },
        { label: 'Realtime subscriptions', appwrite: true, competitor: false },
        { label: 'Email, SMS, and push messaging', appwrite: true, competitor: false },
        { label: 'Domains and firewall rules', appwrite: true, competitor: false },
        {
          label: 'Self-host the whole platform',
          appwrite: { value: true, note: 'Open source end to end' },
          competitor: { value: false, note: 'Storage engine only' },
        },
        {
          label: 'Regions for the full backend',
          appwrite: '6',
          competitor: { value: '4', note: 'AWS regions for Functions and Object Storage' },
        },
      ],
    },
    {
      title: 'Pricing',
      rows: [
        { label: 'Billing model', appwrite: 'Fixed monthly tier', competitor: 'Per compute-hour' },
        {
          label: '2GB database running all month',
          appwrite: { value: '$15/mo', note: 'Small tier, reads and writes included' },
          competitor: { value: 'About $39/mo', note: '0.5 CU on Launch at $0.106 per CU-hour' },
        },
        {
          label: 'Compute credits on paid plans',
          appwrite: '$10/mo on Pro',
          competitor: '-',
        },
        {
          label: 'Hard budget cap',
          appwrite: true,
          competitor: { value: false, note: 'Email alerts and autoscaling limits' },
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Neon might still fit',
    description:
      'Neon is well-built serverless Postgres. It may suit you if these sound like your workload.',
    points: [
      'You only need a database, and auth, hosting, and the rest of your backend already live elsewhere.',
      'You want a copy-on-write branch for every pull request with instant restores.',
      'You create many small databases, for example one per user or per agent, on a generous free plan.',
      'You are standardizing on Databricks and want Postgres next to your lakehouse.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Dedicated Postgres vs. serverless databases: Which one should developers choose?',
      description: 'How the two models compare on latency, pricing, and operations.',
      href: '/blog/post/managed-postgres-vs-serverless-databases-which-one-should-developers-choose',
    },
    {
      kind: 'product',
      title: 'Managed PostgreSQL',
      description: 'Raw Postgres with pooling, replicas, PITR, and branches.',
      href: '/products/postgres',
    },
    {
      kind: 'product',
      title: 'Appwrite Databases',
      description: 'Five engines in two categories, one Console.',
      href: '/products/databases',
    },
    {
      kind: 'docs',
      title: 'High availability',
      description: 'Streaming replicas, replication modes, and failover.',
      href: '/docs/products/databases/postgresql/high-availability',
    },
    {
      kind: 'docs',
      title: 'Connection pooling',
      description: 'Serve many short-lived clients from a small pool.',
      href: '/docs/products/databases/postgresql/connection-pooling',
    },
    {
      kind: 'docs',
      title: 'PostgreSQL extensions',
      description: 'pgvector, PostGIS, pg_trgm, and up to 50 per database.',
      href: '/docs/products/databases/postgresql/extensions',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than Neon?',
      answer:
        'For apps that need more than a database, yes. Appwrite gives you managed PostgreSQL with up to five HA replicas and point-in-time recovery, and the same project includes auth, storage, functions in 13+ runtimes, realtime, messaging, and hosting. Neon focuses on the database and leaves most of the backend to other vendors.',
    },
    {
      question: 'What is the best Neon alternative for a complete backend?',
      answer:
        'Appwrite is the best Neon alternative when you need more than a database. It pairs managed PostgreSQL with an open-source backend platform, so your data, users, files, and server logic share one Console, one permission model, and one bill.',
      links: [{ label: 'Managed PostgreSQL', href: '/products/postgres' }],
    },
    {
      question: 'Does Neon support databases other than PostgreSQL?',
      answer:
        'No. Every Neon database is PostgreSQL on serverless compute. Appwrite gives you five database models in one project: TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute.',
      links: [{ label: 'Appwrite Databases', href: '/products/databases' }],
    },
    {
      question: 'Is Appwrite a good Neon alternative?',
      answer:
        'Yes, if you want Postgres with the rest of your backend next to it. Appwrite runs managed PostgreSQL alongside Auth, Storage, Functions in 13+ runtimes, Realtime, Messaging, and Sites hosting, all in the same project and Console.',
    },
    {
      question: 'Is Appwrite PostgreSQL real Postgres?',
      answer:
        'Yes. It is the PostgreSQL engine (18 by default, 17 available) over the standard wire protocol. Use psql, pgAdmin, Prisma, Drizzle, or any driver, install up to 50 extensions like pgvector and PostGIS, and move data in or out with pg_dump and pg_restore.',
      links: [{ label: 'PostgreSQL docs', href: '/docs/products/databases/postgresql' }],
    },
    {
      question: 'What does Appwrite include that Neon does not?',
      answer:
        'Realtime subscriptions, email, SMS, and push messaging, web hosting with Sites, domains, firewall rules, and functions in 13+ languages instead of JavaScript and TypeScript only. Everything shares one set of users, teams, and permissions, and the whole platform is open source and self-hostable.',
    },
    {
      question: 'How do I migrate from Neon?',
      answer:
        'Run pg_dump against your Neon database and pg_restore into Appwrite over a direct connection, then point your application at the Appwrite pooler. Any PostgreSQL client works, so there is no Appwrite-specific tooling to learn.',
      links: [{ label: 'Connections', href: '/docs/products/databases/postgresql/connections' }],
    },
    {
      question: 'Does Appwrite support high availability and point-in-time recovery?',
      answer:
        'Yes. Add up to five streaming replicas with asynchronous, synchronous, or quorum replication and automatic failover. Point-in-time recovery archives the write-ahead log continuously, so you can restore to any moment in a 1 to 35 day window.',
      links: [
        { label: 'High availability', href: '/docs/products/databases/postgresql/high-availability' },
        { label: 'Backups', href: '/docs/products/databases/postgresql/backups' },
      ],
    },
    {
      question: 'What does managed PostgreSQL cost on Appwrite?',
      answer:
        'Dedicated compute starts at $10/mo per database, and every Pro plan includes $10/mo in compute credits. Each high availability replica is billed at the full compute tier price, and point-in-time recovery adds 20%. Managed databases need a paid plan.',
      links: [{ label: 'Database pricing', href: '/pricing#database-pricing' }],
    },
  ],
  sources: [
    { label: 'Neon pricing', href: 'https://neon.com/pricing' },
    { label: 'Neon Functions', href: 'https://neon.com/docs/compute/functions/overview' },
    { label: 'Neon backend announcement', href: 'https://neon.com/blog/neon-backend-is-ga' },
  ],
}
