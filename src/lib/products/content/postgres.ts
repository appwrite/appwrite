import type { ProductPageContent } from '@/lib/products/types'

export const postgresProductContent: ProductPageContent = {
  id: 'postgres',
  metaTitle: 'Managed PostgreSQL hosting',
  metaDescription:
    'Managed PostgreSQL hosting on Appwrite. Every database is provisioned for your project with a TLS hostname, connection pooling, extensions like pgvector, backups, PITR, replicas, and branches.',
  hero: {
    title: 'Raw Postgres, fully managed',
    description:
      'Appwrite runs the engine and you keep the SQL. Connect with psql or any driver, bring your own ORM and migrations, and get the full PostgreSQL feature set with nothing in between.',
    stats: [
      { value: '$10/mo', label: 'Compute credits on every Pro plan' },
      { value: '6 regions', label: 'Every Appwrite Cloud region' },
      { value: '5 replicas', label: 'High availability maximum' },
      { value: 'PITR', label: 'Restore to any moment' },
    ],
  },
  faq: [
    {
      question:
        'How is managed PostgreSQL different from TablesDB, DocumentsDB, and VectorsDB?',
      answer:
        'A managed PostgreSQL database is the raw engine with its own compute, storage, and credentials. You talk to it over the PostgreSQL wire protocol instead of an Appwrite SDK, so schema, migrations, roles, and queries are standard PostgreSQL. Use TablesDB, DocumentsDB, or VectorsDB when you want Appwrite SDKs and platform permissions for app data instead.',
      links: [
        {
          label: 'PostgreSQL docs',
          href: '/docs/products/databases/postgresql',
        },
        { label: 'TablesDB docs', href: '/docs/products/databases/tablesdb' },
        {
          label: 'DocumentsDB docs',
          href: '/docs/products/databases/documentsdb',
        },
        {
          label: 'VectorsDB docs',
          href: '/docs/products/databases/vectorsdb',
        },
      ],
    },
    {
      question: 'Which PostgreSQL versions can I run, and can I upgrade later?',
      answer:
        'New databases run PostgreSQL 18 by default, and you can pick PostgreSQL 17 at create time. Upgrades run online: a second instance is provisioned on the new version, data streams over with logical replication, and traffic cuts over once it catches up. Connections are closed at the cutover, so your application has to reconnect.',
      links: [
        {
          label: 'PostgreSQL docs',
          href: '/docs/products/databases/postgresql',
        },
        {
          label: 'Maintenance docs',
          href: '/docs/products/databases/postgresql/maintenance',
        },
      ],
    },
    {
      question: 'Which regions can I run a database in?',
      answer:
        'Every Appwrite Cloud region: Frankfurt, New York, San Francisco, Singapore, Sydney, and Toronto. A database takes the region of the project that owns it, so create your project close to your users first. Each database gets a hostname in the form db-<hash>.<region>.appwrite.center and the data does not leave the region.',
      links: [
        { label: 'Cloud regions', href: '/docs/products/network/regions' },
        {
          label: 'Quick start',
          href: '/docs/products/databases/postgresql/quick-start',
        },
      ],
    },
    {
      question: 'How do specifications and pricing work?',
      answer:
        'PostgreSQL uses the same dedicated compute tiers as pricing: reserved CPU, memory, and connections, from $10/mo per database. Reads and writes are included in the tier. High availability replicas are +50% of base per replica, and point-in-time recovery is +20% of base. Extra storage and bandwidth are usage-based overage. Managed databases need a paid plan.',
      links: [
        { label: 'Pricing', href: '/pricing' },
        {
          label: 'Scaling docs',
          href: '/docs/products/databases/postgresql/scaling',
        },
      ],
    },
    {
      question: 'How many connections can I open, and when do I need the pooler?',
      answer:
        'The limit comes from your specification, from 100 on the smallest tier up to a platform cap of 10,000. Since PostgreSQL spends a backend process per connection, point runtime traffic at the pooler, where many short-lived clients share a small pool. Keep migrations and long administrative sessions on a direct connection.',
      links: [
        {
          label: 'Connection pooling docs',
          href: '/docs/products/databases/postgresql/connection-pooling',
        },
        {
          label: 'Connections docs',
          href: '/docs/products/databases/postgresql/connections',
        },
      ],
    },
    {
      question: 'Which extensions can I install?',
      answer:
        'The engine reports every extension this PostgreSQL version can install, so the catalog is the full set available to this database, not a short list. That includes pgvector, PostGIS, pg_trgm, pgcrypto, and many more. Installs are free, up to 50 per database. Uninstalling always cascades, so anything depending on the extension is dropped with it.',
      links: [
        {
          label: 'Extensions docs',
          href: '/docs/products/databases/postgresql/extensions',
        },
      ],
    },
    {
      question:
        'What is the difference between backups and point-in-time recovery?',
      answer:
        'Backups are snapshots on a schedule, stored off the instance, with your own cron and retention on top of the default policy. Point-in-time recovery is an add-on that archives the write-ahead log continuously, so you can restore to any moment in a window of 1 to 35 days. Both restore in place, so anything written after the target is discarded.',
      links: [
        {
          label: 'Backups docs',
          href: '/docs/products/databases/postgresql/backups',
        },
      ],
    },
    {
      question: 'How does high availability and failover work?',
      answer:
        'High availability adds up to five streaming replicas, each a full copy on its own compute. Pick asynchronous replication for the fastest writes, or synchronous or quorum when you cannot lose acknowledged writes. When the primary stops responding, the most caught-up replica is promoted and the hostname repointed, so a driver pool with retries usually recovers on its own.',
      links: [
        {
          label: 'High availability docs',
          href: '/docs/products/databases/postgresql/high-availability',
        },
        {
          label: 'Connection pooling docs',
          href: '/docs/products/databases/postgresql/connection-pooling',
        },
      ],
    },
    {
      question: 'What are branches good for?',
      answer:
        'A branch is a short-lived copy created from a storage snapshot, with its own endpoint and the parent credentials. The parent is never frozen and takes no write pause. Rehearse a destructive migration, reproduce a bug, or give every pull request its own database. Branches never merge back and expire after 24 hours by default, 7 days at most.',
      links: [
        {
          label: 'Branches docs',
          href: '/docs/products/databases/postgresql/branches',
        },
      ],
    },
    {
      question: 'What does Appwrite manage, and what do I still own?',
      answer:
        'Appwrite manages the container, storage, networking, TLS, backups, replication, security patches, and engine upgrades. You own everything above the wire protocol: schema, migrations, indexes, roles and grants, queries, extensions, and the IP allowlist. There is nothing Appwrite-specific in your application code.',
      links: [
        {
          label: 'Maintenance docs',
          href: '/docs/products/databases/postgresql/maintenance',
        },
        {
          label: 'Network security docs',
          href: '/docs/products/databases/postgresql/network-security',
        },
      ],
    },
    {
      question: 'Can I migrate an existing PostgreSQL database in?',
      answer:
        'Yes, with the standard PostgreSQL tooling you already use. Run your dump and restore over a direct connection, because tools like pg_dump expect one session for the whole run, then point your application runtime at the pooler. Any client that speaks the wire protocol works, including pgAdmin and DataGrip.',
      links: [
        {
          label: 'Connections docs',
          href: '/docs/products/databases/postgresql/connections',
        },
        {
          label: 'Connection pooling docs',
          href: '/docs/products/databases/postgresql/connection-pooling',
        },
      ],
    },
  ],
  cta: {
    title: 'Start building on managed PostgreSQL',
    description:
      'Create a database in your project region, copy the connection string, and run your first query.',
  },
}
