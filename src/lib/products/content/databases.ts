import {
  Database,
  Download,
  Layers,
  Search,
  ShieldCheck,
  Table,
  Zap,
} from 'lucide-react'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
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
  capabilities: {
    title: 'Query, relate, and protect your data',
    description:
      'From prototypes to production workloads, Databases gives you flexible storage with platform-native permissions and tooling.',
    items: [
      {
        title: 'Flexible data models',
        description:
          'Use TablesDB for document-style APIs or native Postgres and MySQL for SQL-first workflows.',
        icon: Database,
      },
      {
        title: 'Powerful queries',
        description:
          'Filter, sort, paginate, and search rows with a consistent query API across engines.',
        icon: Search,
      },
      {
        title: 'Relationships',
        description:
          'Model one-to-one, one-to-many, and many-to-many links between tables and collections.',
        icon: Layers,
      },
      {
        title: 'Permissions',
        description:
          'Assign read and write access per row using roles, users, teams, and labels from Auth.',
        icon: ShieldCheck,
      },
      {
        title: 'Bulk operations',
        description:
          'Import, export, and update data in bulk with CSV support and atomic numeric operations.',
        icon: Download,
      },
      {
        title: 'Realtime events',
        description:
          'React to creates, updates, and deletes across clients with Appwrite Realtime subscriptions.',
        icon: Zap,
      },
    ],
  },
  visual: {
    title: 'One console for every database engine',
    description:
      'Browse tables, run queries, manage permissions, and inspect data whether you use TablesDB or native SQL engines.',
  },
  uniqueSections: [
    {
      type: 'compare',
      title: 'Choose the right database for your use case',
      description:
        'Start with TablesDB for speed and SDK-native APIs, or connect Postgres and MySQL when your team needs full SQL.',
      items: [
        {
          title: 'TablesDB',
          description: 'Appwrite-native tables with SDK queries and built-in permissions.',
          icon: Table,
          bullets: [
            'Fast setup with SDK-native APIs',
            'Row-level permissions out of the box',
            'Relationships and type generation',
            'Great for most app data models',
          ],
        },
        {
          title: 'Postgres',
          description: 'Managed Postgres with SQL editor, extensions, and vector search.',
          icon: PostgresElephantIcon,
          bullets: [
            'Full SQL and migrations workflow',
            'pgvector for semantic search',
            'Backups and insights in the console',
            'Ideal for analytics and complex queries',
          ],
        },
        {
          title: 'MySQL',
          description: 'Managed MySQL for teams standardized on the MySQL ecosystem.',
          icon: MySQLDolphinIcon,
          bullets: [
            'Familiar SQL tooling and drivers',
            'Console SQL editor and table browser',
            'Same project permissions model',
            'Great for existing MySQL workloads',
          ],
        },
      ],
    },
    {
      type: 'feature-grid',
      title: 'Scale with confidence',
      description:
        'Operational features that help you move from prototype to production without changing platforms.',
      items: [
        {
          title: 'Vector search',
          description: 'Store embeddings and run semantic queries for AI-powered features.',
          icon: Search,
        },
        {
          title: 'Database backups',
          description: 'Schedule backups and restore data when you need to recover quickly.',
          icon: Download,
        },
        {
          title: 'CSV import and export',
          description: 'Move data in and out of tables for migrations and reporting.',
          icon: Table,
        },
        {
          title: 'Insights',
          description: 'Monitor usage and query patterns from the project console.',
          icon: Database,
        },
      ],
      columns: 2,
      muted: true,
    },
  ],
  integrations: {
    title: 'Works with the Appwrite platform',
    description:
      'Databases connects to the rest of your backend so data stays in sync across services.',
    items: [
      {
        productId: 'auth',
        title: 'Identity-aware rows',
        description: 'Use Auth users and teams in permission rules for every table.',
      },
      {
        productId: 'functions',
        title: 'Event-driven logic',
        description: 'Trigger Functions when rows are created, updated, or deleted.',
      },
      {
        productId: 'storage',
        title: 'Files and metadata',
        description: 'Store file references in tables while Storage handles the binary assets.',
      },
      {
        productId: 'sites',
        title: 'Full-stack apps',
        description: 'Pair Sites frontends with TablesDB or SQL backends in one project.',
      },
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
      question: 'Does Databases support realtime updates?',
      answer:
        'Yes. Subscribe to table or collection changes with Appwrite Realtime from web and mobile clients.',
    },
  ],
  cta: {
    title: 'Start building with Databases',
    description: 'Create a database, define your schema, and query your first rows in minutes.',
  },
}
