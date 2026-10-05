import type { ProductFeatureContent } from '@/lib/products/features/types'

export const postgresProductFeatures: ProductFeatureContent[] = [
  {
    id: 'connections',
    title: 'A PostgreSQL endpoint, nothing else in the way',
    description:
      'Every database gets its own hostname on port 5432, TLS, and an admin role that owns it. Connect with psql, any driver, or any ORM, and copy a ready-made DSN, .env, or Prisma snippet from the Console.',
    docsHref: '/docs/products/databases/postgresql/connections',
    docsLabel: 'Connections docs',
    extraDocsLinks: [
      {
        href: '/docs/products/databases/postgresql/quick-start',
        label: 'Quick start docs',
      },
    ],
  },
  {
    id: 'pooling',
    title: 'Connection pooling for short-lived clients',
    description:
      'PostgreSQL spends a backend process per connection, so serverless functions exhaust a specification fast. Point runtime traffic at the pooler, same hostname and credentials, and short-lived clients share a small pool.',
    docsHref: '/docs/products/databases/postgresql/connection-pooling',
    docsLabel: 'Connection pooling docs',
    brandLight: 'teal',
  },
  {
    id: 'extensions',
    title: 'A full extension catalog, at no extra cost',
    description:
      'Each database lists every extension its PostgreSQL version can install, from pgvector and PostGIS to pg_trgm, pgcrypto, and many more. Free to install, up to 50 per database.',
    docsHref: '/docs/products/databases/postgresql/extensions',
    docsLabel: 'Extensions docs',
    layout: 'stacked',
    hideVisual: true,
    wideCompanion: true,
  },
  {
    id: 'branches',
    title: 'Snapshot branches in minutes',
    description:
      'A branch is an isolated copy taken from a storage snapshot, with its own endpoint and the parent credentials. The parent never pauses writes. Rehearse a migration or give every pull request a database.',
    docsHref: '/docs/products/databases/postgresql/branches',
    docsLabel: 'Branches docs',
    brandLight: 'orange',
  },
  {
    id: 'backups',
    title: 'Backups off the instance, restores on demand',
    description:
      'Every database ships with a backup policy and backups are stored off the instance. Add your own schedule and retention, or enable point-in-time recovery to restore to any moment in the last 35 days.',
    docsHref: '/docs/products/databases/postgresql/backups',
    docsLabel: 'Backups docs',
  },
  {
    id: 'high-availability',
    title: 'Replicas with automatic failover',
    description:
      'Add up to five streaming replicas and pick how safe writes should be: asynchronous, synchronous, or quorum. If the primary stops responding, the most caught-up replica is promoted and the hostname repointed.',
    docsHref: '/docs/products/databases/postgresql/high-availability',
    docsLabel: 'High availability docs',
  },
  {
    id: 'monitoring',
    title: 'Live metrics and online resizing',
    description:
      'The Monitor tab tracks CPU, memory, queries per second, cache hit ratio, and disk growth with nothing to install. Inspect live sessions, cancel a query, then resize compute online when it is time.',
    docsHref: '/docs/products/databases/postgresql/monitoring',
    docsLabel: 'Monitoring docs',
    extraDocsLinks: [
      {
        href: '/docs/products/databases/postgresql/scaling',
        label: 'Scaling docs',
      },
    ],
    brandLight: 'pink',
  },
  {
    id: 'tooling',
    title: 'Works with your ORM and toolstack',
    description:
      'Use the same hostname and credentials with Prisma, Drizzle, Sequelize, TypeORM, SQLAlchemy, psql, and the rest of your PostgreSQL toolchain. Copy DSN, .env, and ORM snippets from the Console Credentials tab, or follow integration guides in the docs.',
    docsHref: '/docs/products/databases/postgresql',
    docsLabel: 'PostgreSQL docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
  },
  {
    id: 'specifications',
    title: 'Specifications and pricing',
    description:
      'Fixed monthly compute tiers with reserved CPU, memory, and connections.',
    docsHref: '/docs/products/databases/postgresql#specifications',
    docsLabel: 'Specifications docs',
    layout: 'stacked',
    hideVisual: true,
    wideCompanion: true,
  },
]
