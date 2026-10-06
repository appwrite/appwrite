import type { AlternativeContent } from '@/lib/alternatives/types'

export const supabaseAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Platform',
      rows: [
        { label: 'Open source', appwrite: true, competitor: true },
        {
          label: 'Many projects in one self-hosted install',
          appwrite: true,
          competitor: { value: false, note: 'One project per instance' },
        },
        {
          label: 'Self-hosting',
          appwrite: { value: 'One Docker install', note: 'Same APIs, SDKs, and Console as Cloud' },
          competitor: { value: 'Docker Compose', note: 'Community supported, one project per instance' },
        },
        {
          label: 'Web hosting (static and SSR)',
          appwrite: { value: true, note: 'Sites, with Git deploys and previews' },
          competitor: { value: false, note: 'Pair it with a separate frontend host' },
        },
        {
          label: 'Email, SMS, and push messaging',
          appwrite: { value: true, note: '12 providers, topics, and scheduling' },
          competitor: false,
        },
        { label: 'MCP server for AI agents', appwrite: true, competitor: true },
      ],
    },
    {
      title: 'Data',
      rows: [
        {
          label: 'Database models',
          appwrite: { value: '5', note: 'Tables, documents, vectors, PostgreSQL, MySQL' },
          competitor: { value: '1', note: 'PostgreSQL' },
        },
        {
          label: 'Serverless or dedicated databases',
          appwrite: { value: true, note: 'Choose per database' },
          competitor: { value: false, note: 'One dedicated instance per project' },
        },
        {
          label: 'Managed PostgreSQL',
          appwrite: { value: true, note: 'PostgreSQL 18 on dedicated compute' },
          competitor: true,
        },
        {
          label: 'Databases with SDKs',
          appwrite: 'TablesDB, DocumentsDB, VectorsDB',
          competitor: 'Postgres with auto-generated APIs',
        },
        {
          label: 'Access control',
          appwrite: { value: 'Table and row permissions', note: 'Readable role strings, no SQL' },
          competitor: { value: 'Row Level Security', note: 'Policies written in SQL' },
        },
        {
          label: 'Point-in-time recovery',
          appwrite: { value: '+20% of the compute tier', note: 'Restore window of 1 to 35 days' },
          competitor: { value: '$100/mo per 7 days', note: 'Requires Small compute or larger' },
        },
        { label: 'Dedicated compute', appwrite: 'From $10/mo', competitor: 'From $10/mo' },
      ],
    },
    {
      title: 'Compute and realtime',
      rows: [
        {
          label: 'Function runtimes',
          appwrite: { value: '13+', note: 'Node.js, Python, Go, Dart, PHP, and more' },
          competitor: { value: 'TypeScript', note: 'Deno-compatible runtime' },
        },
        { label: 'Cron and event triggers', appwrite: true, competitor: true },
        {
          label: 'Realtime coverage',
          appwrite: { value: 'Every service', note: 'Rows, files, executions, sessions, teams' },
          competitor: { value: 'Database and channels', note: 'Postgres changes, broadcast, presence' },
        },
        {
          label: 'Teams for multi-tenancy',
          appwrite: { value: true, note: 'Memberships and roles built in' },
          competitor: { value: 'partial', note: 'Model it with tables and RLS' },
        },
      ],
    },
    {
      title: 'Pricing',
      rows: [
        { label: 'Free plan', appwrite: '75K MAU, 2 projects', competitor: '50K MAU, 2 projects' },
        { label: 'Pro plan', appwrite: 'From $25/mo', competitor: 'From $25/mo' },
        {
          label: 'Monthly active users on Pro',
          appwrite: '200K, then $3 per 1,000',
          competitor: '100K, then $3.25 per 1,000',
        },
        { label: 'Bandwidth on Pro', appwrite: '2TB', competitor: '250GB' },
        { label: 'Spend control', appwrite: 'Budget cap', competitor: 'Spend cap' },
      ],
    },
  ],
  fairPlay: {
    title: 'When Supabase might still fit',
    description:
      'Supabase is a solid Postgres platform with a great team behind it. It may suit you if these describe your project.',
    points: [
      'Your team relies mostly on Postgres and does not need much else from the platform.',
      'You rely on PostgREST for an auto-generated REST API over Postgres.',
      {
        text: 'You offer SAML single sign-on to your own end users.',
        aside: 'SAML SSO for your end users is coming soon to Appwrite.',
      },
      'Your AI app builder has a one-click Supabase integration your workflow depends on.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Appwrite vs Supabase for AI app builders',
      description: 'Permissions an agent can read, runtimes beyond Deno, and hosting in one place.',
      href: '/blog/post/appwrite-vs-supabase-ai-apps',
    },
    {
      kind: 'blog',
      title: 'Appwrite vs Supabase: a comparison of Backend-as-a-Service platforms',
      description: 'A product-by-product walkthrough of both platforms.',
      href: '/blog/post/appwrite-compared-to-supabase',
    },
    {
      kind: 'docs',
      title: 'Migrate from Supabase',
      description: 'Import users, databases, and files from a Supabase project.',
      href: '/docs/advanced/migrations/supabase',
    },
    {
      kind: 'product',
      title: 'Managed PostgreSQL',
      description: 'Raw Postgres with pooling, replicas, PITR, and branches.',
      href: '/products/postgres',
    },
    {
      kind: 'product',
      title: 'Appwrite Sites',
      description: 'Deploy static and SSR apps next to your backend.',
      href: '/products/sites',
    },
    {
      kind: 'product',
      title: 'Appwrite Messaging',
      description: 'Email, SMS, and push from one API.',
      href: '/products/messaging',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than Supabase?',
      answer:
        'For teams that want their whole stack in one place, yes. Appwrite includes everything Supabase covers, auth, databases, managed PostgreSQL, storage, functions, and realtime, plus web hosting, messaging, domains, and a project firewall. Permissions are readable role strings instead of SQL policies, and functions run in 13+ languages instead of TypeScript on Deno only.',
    },
    {
      question: 'What is the best open-source alternative to Supabase?',
      answer:
        'Appwrite is the best open-source alternative to Supabase. It self-hosts with a single Docker command, runs the same APIs, SDKs, and Console as Appwrite Cloud, and includes more products out of the box, from web hosting to messaging. Independent benchmarks show that self-hosted Appwrite has significantly superior performance over Supabase and similar platforms.',
      links: [
        { label: 'Self-hosting', href: '/docs/advanced/self-hosting' },
        {
          label: 'Cloud vs self-hosted performance',
          href: '/blog/post/appwrite-compared-to-supabase',
        },
      ],
    },
    {
      question: 'Does Supabase support more than one database model?',
      answer:
        'No. Supabase is built on PostgreSQL, with one dedicated instance per project. Appwrite gives you five database models in the same project: TablesDB, DocumentsDB, and VectorsDB on serverless or dedicated compute, plus managed PostgreSQL and MySQL.',
      links: [{ label: 'Appwrite Databases', href: '/products/databases' }],
    },
    {
      question: 'Is Appwrite a good Supabase alternative?',
      answer:
        'Yes, if you want more of your stack in one place. Both platforms are open source and start at $25/mo on Pro. Appwrite adds web hosting with Sites, email, SMS, and push with Messaging, project firewall rules, and functions in 13+ runtimes, so your frontend and backend deploy from one Console.',
    },
    {
      question: 'Does Appwrite support PostgreSQL?',
      answer:
        'Yes. Appwrite runs managed PostgreSQL 18 (or 17) on dedicated compute with connection pooling, up to five HA replicas, point-in-time recovery, and up to 50 extensions including pgvector and PostGIS. Connect with psql or any driver. If you prefer SDKs with built-in permissions, use TablesDB, DocumentsDB, or VectorsDB.',
      links: [
        { label: 'Managed PostgreSQL', href: '/products/postgres' },
        { label: 'PostgreSQL docs', href: '/docs/products/databases/postgresql' },
      ],
    },
    {
      question: 'How do Appwrite permissions compare to Row Level Security?',
      answer:
        'Supabase protects rows with RLS policies written in SQL. Appwrite attaches permissions to tables and rows as readable role strings, such as read for one user or update for a team, and enforces them across the REST API, Realtime, and every SDK. They are easier to audit, especially when an AI agent writes the code. With Appwrite managed PostgreSQL, you can use Row Level Security in SQL the same way Supabase does when that is the model you want.',
      links: [
        { label: 'Permissions', href: '/docs/products/databases/permissions' },
        { label: 'Managed PostgreSQL', href: '/products/postgres' },
      ],
    },
    {
      question: 'Can I migrate from Supabase to Appwrite?',
      answer:
        'Yes. The Migrations tool in the Console imports users, databases, and files from a Supabase project, and usage during the migration does not count toward your Appwrite Cloud bill. Edge Functions are moved by hand to any Appwrite runtime.',
      links: [{ label: 'Migrate from Supabase', href: '/docs/advanced/migrations/supabase' }],
    },
    {
      question: 'Can I self-host Appwrite?',
      answer:
        'Yes. Appwrite runs anywhere Docker runs with a single install command, and the self-hosted version uses the same APIs, SDKs, and Console as Appwrite Cloud. Moving between them only changes the endpoint.',
      links: [{ label: 'Self-hosting', href: '/docs/advanced/self-hosting' }],
    },
    {
      question: 'Which one costs less?',
      answer:
        'Both Pro plans start at $25/mo, and dedicated database compute starts at $10/mo on both. Appwrite Pro includes twice the monthly active users (200K vs 100K), eight times the bandwidth (2TB vs 250GB), and unlimited Sites, so you can drop a separate frontend host.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
  ],
  sources: [
    { label: 'Supabase pricing', href: 'https://supabase.com/pricing' },
    { label: 'Supabase Edge Functions', href: 'https://supabase.com/docs/guides/functions' },
    { label: 'Supabase self-hosting', href: 'https://supabase.com/docs/guides/self-hosting' },
  ],
}
