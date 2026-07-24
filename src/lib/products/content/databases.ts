import type { ProductPageContent } from '@/lib/products/types'

export const databasesProductContent: ProductPageContent = {
  id: 'databases',
  metaDescription:
    'Store and query data with TablesDB, DocumentsDB, VectorsDB, PostgreSQL, and MySQL. Choose serverless or dedicated, with replication, backups, and PITR.',
  hero: {
    title: 'Databases for every data model',
    description:
      'Pick the engine that fits your workload, then scale it the same way. Appwrite Databases cover structured tables, documents, vectors, and native SQL, with serverless or dedicated compute, replication, backups, and point-in-time recovery.',
  },
  faq: [
    {
      question: 'What database engines does Appwrite offer?',
      answer:
        'Appwrite Databases include five engines in two categories. Appwrite DBs are TablesDB for relational-style tables and columns, DocumentsDB for flexible JSON documents, and VectorsDB for embeddings and similarity search. Native DBs are managed PostgreSQL and MySQL for teams that need full SQL compatibility, extensions, and portable schemas.',
      links: [
        { label: 'Databases overview', href: '/docs/products/databases' },
        { label: 'Quick start', href: '/docs/products/databases/quick-start' },
      ],
    },
    {
      question: 'When should I use Appwrite DBs vs native PostgreSQL or MySQL?',
      answer:
        'Choose TablesDB, DocumentsDB, or VectorsDB when you want Appwrite SDKs, Console workflows, and Auth-aware permissions out of the box. Choose PostgreSQL or MySQL when you need advanced SQL, existing ORM tooling, extensions such as pgvector, or to run schemas you already operate elsewhere.',
      links: [
        { label: 'Tables', href: '/docs/products/databases/tables' },
        { label: 'Queries', href: '/docs/products/databases/queries' },
      ],
    },
    {
      question: 'What is the difference between serverless and dedicated databases?',
      answer:
        'Serverless databases run on a shared pool and are the fastest way to start. Billing is usage-based: there is no fixed compute fee, and you pay for storage plus reads and writes against your plan quota (then overage). Dedicated databases provision isolated compute for predictable performance, higher connection limits, and production options such as read replicas, high availability, and point-in-time recovery. Billing is a fixed monthly compute tier per database (from $10/mo), with reads and writes included in the tier. HA replicas and PITR are optional add-ons, and extra storage or bandwidth is billed as overage. You pick a specification when you create the database and can upgrade later.',
      links: [{ label: 'Database pricing', href: '/pricing#database-pricing' }],
    },
    {
      question: 'How do replication and high availability work?',
      answer:
        'On serverless databases, replication and high availability are abstracted and managed by the platform, so you do not configure replicas or sync mode yourself. On dedicated databases, traffic can enter through a connection pooler such as PgDog or ProxySQL. A primary instance accepts reads and writes, and read replicas scale query traffic and improve failover resilience. High availability is enabled when replica count is greater than zero. Choose asynchronous, synchronous, or quorum sync mode, and promote a replica from the Console when you need to move write traffic.',
    },
    {
      question: 'Are backups and PITR included?',
      answer:
        'Yes on Appwrite Cloud for supported plans and engines. Create automated backup policies or run manual backups from the Backups tab. Point-in-time recovery (PITR) on dedicated databases lets you restore to a specific moment beyond the latest scheduled backup, which helps after accidental deletes, failed migrations, or bad writes.',
      links: [{ label: 'Backups', href: '/docs/products/databases/backups' }],
    },
    {
      question: 'Do Appwrite DBs integrate with Auth permissions?',
      answer:
        'Yes. TablesDB and DocumentsDB permissions can reference users, teams, and roles from Appwrite Auth at the table, collection, row, and document level. Scope data per customer or workspace without building custom access control.',
      links: [
        { label: 'Permissions', href: '/docs/products/databases/permissions' },
        { label: 'Multi-tenancy', href: '/docs/products/auth/multi-tenancy' },
      ],
    },
    {
      question: 'Can I query, relate, and bulk-update data from the SDKs?',
      answer:
        'Yes. Appwrite DBs support filters, ordering, pagination, relationships, transactions, bulk operations, and geo queries through the SDKs and Console. Native PostgreSQL and MySQL databases support full SQL from the in-console editor and your existing SQL clients.',
      links: [
        { label: 'Queries', href: '/docs/products/databases/queries' },
        { label: 'Relationships', href: '/docs/products/databases/relationships' },
        { label: 'Transactions', href: '/docs/products/databases/transactions' },
        { label: 'Bulk operations', href: '/docs/products/databases/bulk-operations' },
      ],
    },
  ],
  cta: {
    title: 'Start building with Databases',
    description:
      'Create a database, choose your engine and compute model, and query your first data in minutes.',
  },
}
