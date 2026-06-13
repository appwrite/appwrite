import type { ProductPageContent } from '@/lib/products/types'

export const databasesProductContent: ProductPageContent = {
  id: 'databases',
  metaDescription:
    'Store and query structured data with TablesDB, native Postgres, and MySQL. Permissions, relationships, vector search, and backups included.',
  hero: {
    title: 'Databases built for modern applications',
    description:
      'Model, query, and scale structured data with Appwrite TablesDB or connect native Postgres and MySQL when you need full SQL control.',
    stats: [
      { value: '5', label: 'Database engines' },
      { value: 'SQL', label: 'In-console query editor' },
      { value: 'Rows', label: 'Permission-aware tables' },
      { value: 'Backups', label: 'Recovery policies' },
    ],
  },
  faq: [
    {
      question: 'Should I use TablesDB or Postgres?',
      answer:
        'TablesDB is fastest to integrate with Appwrite SDKs and permissions. Choose Postgres when you need advanced SQL, extensions like pgvector, or an existing SQL toolchain.',
    },
    {
      question: 'Can I migrate from a legacy document database?',
      answer:
        'Appwrite supports both TablesDB and legacy Collections APIs. Docs cover migration paths and compatibility notes.',
    },
    {
      question: 'Are database backups included?',
      answer:
        'Backup features depend on your plan and database engine. Cloud plans include backup options for supported engines.',
    },
    {
      question: 'Does Databases work with Auth permissions?',
      answer:
        'Yes. Row and table permissions can reference users, teams, and roles from Appwrite Auth.',
    },
  ],
  cta: {
    title: 'Start building with Databases',
    description: 'Create a database, define your schema, and query your first rows in minutes.',
  },
}
