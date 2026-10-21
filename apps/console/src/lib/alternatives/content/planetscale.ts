import type { AlternativeContent } from '@/lib/alternatives/types'

export const planetscaleAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Databases',
      rows: [
        {
          label: 'Database models',
          appwrite: { value: '5', note: 'Tables, documents, vectors, PostgreSQL, MySQL' },
          competitor: { value: '2', note: 'PostgreSQL and MySQL through Vitess' },
        },
        {
          label: 'Managed PostgreSQL',
          appwrite: { value: true, note: 'PostgreSQL 18, in beta' },
          competitor: true,
        },
        {
          label: 'Managed MySQL',
          appwrite: { value: true, note: 'In beta' },
          competitor: { value: true, note: 'Vitess, with horizontal sharding' },
        },
        {
          label: 'Serverless database with no fixed fee',
          appwrite: { value: true, note: 'TablesDB, including on the Free plan' },
          competitor: false,
        },
        {
          label: 'High availability',
          appwrite: { value: 'Up to 5 replicas', note: 'Async, sync, or quorum with failover' },
          competitor: { value: true, note: '1 primary and 2 replicas across 3 zones' },
        },
        { label: 'Point-in-time recovery', appwrite: '1 to 35 days', competitor: true },
        {
          label: 'Branching',
          appwrite: { value: 'Snapshot branches', note: 'Short-lived, never merge back' },
          competitor: { value: 'Branches and deploy requests', note: 'A PlanetScale strength' },
        },
      ],
    },
    {
      title: 'Around the database',
      rows: [
        {
          label: 'Authentication',
          appwrite: { value: true, note: 'MFA, teams, 40+ OAuth providers' },
          competitor: false,
        },
        { label: 'File storage with image transformations', appwrite: true, competitor: false },
        { label: 'Functions in 13+ runtimes', appwrite: true, competitor: false },
        { label: 'Realtime subscriptions', appwrite: true, competitor: false },
        { label: 'Email, SMS, and push messaging', appwrite: true, competitor: false },
        { label: 'Frontend hosting', appwrite: true, competitor: false },
      ],
    },
    {
      title: 'Pricing and platform',
      rows: [
        {
          label: 'Free plan',
          appwrite: { value: true, note: 'Serverless TablesDB, auth, storage, functions, and hosting' },
          competitor: { value: false, note: 'Removed in 2024' },
        },
        {
          label: 'Entry price for a managed database',
          appwrite: { value: 'From $10/mo', note: 'Pro includes $10/mo in compute credits' },
          competitor: { value: 'From $5/mo', note: 'Single-node Postgres' },
        },
        {
          label: 'Self-host the whole platform',
          appwrite: { value: true, note: 'Open source end to end' },
          competitor: { value: false, note: 'Vitess is open source, the platform is not' },
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When PlanetScale might still fit',
    description:
      'PlanetScale builds excellent databases. It may still suit you if these sound like your workload.',
    points: [
      'You need horizontal sharding for a very large MySQL or Postgres workload today.',
      'Branching with deploy requests is central to how your team ships schema changes.',
      'You want local NVMe storage on Metal for the highest IOPS.',
      {
        text: 'You only need a database, and your backend already lives elsewhere.',
        aside: 'Managed PostgreSQL and MySQL on Appwrite are in beta.',
      },
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'How Appwrite Databases can replace your PlanetScale database',
      description: 'Moving off PlanetScale onto a full backend.',
      href: '/blog/post/planetscale-databases-alternative',
    },
    {
      kind: 'blog',
      title: 'Native databases vs Appwrite databases: which one should you pick?',
      description: 'Tables, documents, vectors, and native SQL compared.',
      href: '/blog/post/native-databases-vs-appwrite-databases',
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
  ],
  faq: [
    {
      question: 'Is Appwrite better than PlanetScale?',
      answer:
        'For apps that need more than a database, yes. Appwrite runs managed PostgreSQL and MySQL next to TablesDB, DocumentsDB, and VectorsDB, and the same project includes auth, storage, functions in 13+ runtimes, realtime, messaging, and hosting. PlanetScale focuses on the database and leaves the rest of the backend to other vendors.',
    },
    {
      question: 'What is the best PlanetScale alternative with a free plan?',
      answer:
        'Appwrite is the best PlanetScale alternative with a free plan. PlanetScale removed its free tier in 2024. Appwrite Free includes a serverless TablesDB database, auth for 75,000 monthly active users, storage, functions, and hosting, and Pro adds managed PostgreSQL and MySQL with $10/mo in compute credits.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Does PlanetScale support more than one database model?',
      answer:
        'PlanetScale offers two relational engines, PostgreSQL and MySQL through Vitess, and no document or vector database. Appwrite gives you five database models in one project: TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute.',
      links: [{ label: 'Appwrite Databases', href: '/products/databases' }],
    },
    {
      question: 'How do I migrate from PlanetScale?',
      answer:
        'For PostgreSQL, run pg_dump against PlanetScale and pg_restore into Appwrite. For MySQL, use mysqldump and import into an Appwrite MySQL database. Any standard client works, so there is no Appwrite-specific tooling to learn.',
      links: [{ label: 'PostgreSQL connections', href: '/docs/products/databases/postgresql/connections' }],
    },
    {
      question: 'Is Appwrite managed PostgreSQL production ready?',
      answer:
        'Managed PostgreSQL and MySQL on Appwrite are in beta. They run on dedicated compute with connection pooling, up to five HA replicas, and point-in-time recovery. TablesDB is generally available and runs on serverless or dedicated compute.',
      links: [{ label: 'Managed PostgreSQL', href: '/products/postgres' }],
    },
    {
      question: 'Can I run Appwrite on my own servers?',
      answer:
        'Yes. Appwrite is fully open source and self-hosts with Docker, with the same APIs, SDKs, and Console as Appwrite Cloud. PlanetScale publishes Vitess as open source, but the PlanetScale platform runs only on PlanetScale.',
      links: [{ label: 'Self-hosting', href: '/docs/advanced/self-hosting' }],
    },
  ],
  sources: [
    { label: 'PlanetScale pricing', href: 'https://planetscale.com/pricing' },
    { label: 'PlanetScale plans', href: 'https://planetscale.com/docs/planetscale-plans' },
    { label: 'Vitess', href: 'https://planetscale.com/vitess' },
  ],
}
