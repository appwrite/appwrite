import type { ProductFeatureContent } from '@/lib/products/features/types'

export const databasesProductFeatures: ProductFeatureContent[] = [
  {
    id: 'engines',
    title: 'Five engines, two categories',
    description:
      'Appwrite DBs cover tables, documents, and vectors. Native DBs bring managed PostgreSQL and MySQL when you need full SQL control. Pick the model that matches your data, then operate every engine from the same Console and project.',
    docsHref: '/docs/products/databases',
    docsLabel: 'Databases docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
  },
  {
    id: 'serverless-dedicated',
    title: 'Serverless or dedicated compute',
    description:
      'Start on a shared serverless pool when you want speed and simplicity. Move to dedicated specifications when you need isolated resources, higher connection limits, and production options like replicas and PITR. Choose at create time or upgrade later.',
    docsHref: '/docs/products/databases',
    docsLabel: 'Databases docs',
  },
  {
    id: 'replication',
    title: 'Replication and high availability',
    description:
      'Dedicated databases run behind a connection pooler with a primary for writes and read replicas for query scale and failover. Choose asynchronous, synchronous, or quorum sync mode, then promote a replica when you need to move write traffic.',
    docsHref: '/docs/products/databases',
    docsLabel: 'Databases docs',
  },
  {
    id: 'backups',
    title: 'Backups and point-in-time recovery',
    description:
      'Automate encrypted hot backups with policies, or create a manual backup when you need a snapshot now. Enable PITR on dedicated databases to restore to a specific moment after accidental deletes, failed migrations, or bad writes.',
    docsHref: '/docs/products/databases/backups',
    docsLabel: 'Backups docs',
  },
  {
    id: 'permissions',
    title: 'Permissions wired to Auth',
    description:
      'Scope TablesDB and DocumentsDB access with users, teams, and roles from Appwrite Auth. Set rules at the table, collection, row, or document level so each tenant only sees their data.',
    docsHref: '/docs/products/databases/permissions',
    docsLabel: 'Permissions docs',
  },
  {
    id: 'queries',
    title: 'Queries, relationships, and transactions',
    description:
      'Filter, order, and paginate from the SDKs and Console. Model related data with relationships, run multi-step writes in transactions, and use bulk operations when you need to update many rows or documents at once.',
    docsHref: '/docs/products/databases/queries',
    docsLabel: 'Queries docs',
  },
  {
    id: 'sql',
    title: 'Native SQL for PostgreSQL and MySQL',
    description:
      'Connect with standard SQL clients and the in-console query editor. Keep portable schemas, use the extensions your stack needs, and manage roles, connections, and backups alongside your Appwrite project.',
    docsHref: '/docs/products/databases',
    docsLabel: 'Databases docs',
  },
  {
    id: 'tooling',
    title: 'Works with your ORM and toolstack',
    description:
      'Use the same connection strings with Prisma, Drizzle, Sequelize, TypeORM, SQLAlchemy, psql, and the rest of your SQL toolchain. Copy ready-made snippets from the Console Connect tab and keep shipping with the stack your team already knows.',
    docsHref: '/docs/products/databases',
    docsLabel: 'Databases docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
  },
]
