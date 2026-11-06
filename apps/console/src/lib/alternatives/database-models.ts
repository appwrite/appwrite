import type { AlternativeId } from '@/lib/alternatives/types'

export type DatabaseModelId = 'tablesdb' | 'documentsdb' | 'vectorsdb' | 'postgresql' | 'mysql'
export type DatabaseCompute = 'serverless' | 'dedicated'

/**
 * The five database models in an Appwrite project, in display order.
 * Only TablesDB runs on serverless compute; the other engines require dedicated compute.
 */
export const APPWRITE_DATABASE_MODELS: {
  id: DatabaseModelId
  name: string
  model: string
  compute: DatabaseCompute[]
}[] = [
  { id: 'tablesdb', name: 'TablesDB', model: 'Relational tables', compute: ['serverless', 'dedicated'] },
  { id: 'documentsdb', name: 'DocumentsDB', model: 'JSON documents', compute: ['dedicated'] },
  { id: 'vectorsdb', name: 'VectorsDB', model: 'Vectors and embeddings', compute: ['dedicated'] },
  { id: 'postgresql', name: 'PostgreSQL', model: 'Native SQL', compute: ['dedicated'] },
  { id: 'mysql', name: 'MySQL', model: 'Native SQL', compute: ['dedicated'] },
]

export type CompetitorDatabaseSlot = {
  /** Which Appwrite model this lines up with. */
  id: DatabaseModelId
  /** The engine or product that provides it. */
  engine: string
  compute: string
  /** A separate product or bring-your-own setup that covers the slot only partly. */
  partial?: boolean
}

export type CompetitorDatabaseModel = {
  /** Short line next to the count, e.g. "PostgreSQL" or "PostgreSQL and MySQL". */
  summary: string
  slots: CompetitorDatabaseSlot[]
  note: string
}

/** BaaS and database competitors, and the database models each one actually offers. */
export const COMPETITOR_DATABASE_MODEL: Partial<Record<AlternativeId, CompetitorDatabaseModel>> = {
  supabase: {
    summary: 'PostgreSQL',
    slots: [{ id: 'postgresql', engine: 'PostgreSQL', compute: 'One dedicated instance per project' }],
    note: 'Documents and vectors live inside that one PostgreSQL database, through JSONB and pgvector.',
  },
  firebase: {
    summary: 'Cloud Firestore',
    slots: [
      { id: 'documentsdb', engine: 'Cloud Firestore', compute: 'Serverless only' },
      { id: 'postgresql', engine: 'SQL Connect, a separate product', compute: 'Billed through Cloud SQL', partial: true },
    ],
    note: 'Firestore and Realtime Database are both document stores. Relational data needs SQL Connect, a separate PostgreSQL service billed through Cloud SQL.',
  },
  convex: {
    summary: 'Convex database',
    slots: [{ id: 'documentsdb', engine: 'Convex database', compute: 'Serverless only' }],
    note: 'Vector search runs over the same document tables, and only from actions.',
  },
  neon: {
    summary: 'PostgreSQL',
    slots: [{ id: 'postgresql', engine: 'PostgreSQL', compute: 'Serverless only' }],
    note: 'Every Neon database is PostgreSQL on serverless compute.',
  },
  amplify: {
    summary: 'Amazon DynamoDB',
    slots: [
      { id: 'documentsdb', engine: 'Amazon DynamoDB', compute: 'Serverless, behind AppSync' },
      { id: 'postgresql', engine: 'Bring your own database', compute: 'Provisioned and run by you', partial: true },
      { id: 'mysql', engine: 'Bring your own database', compute: 'Provisioned and run by you', partial: true },
    ],
    note: 'Amplify Data stores models in DynamoDB. SQL works only by connecting a PostgreSQL or MySQL database you provision, scale, and back up yourself.',
  },
  planetscale: {
    summary: 'PostgreSQL and MySQL',
    slots: [
      { id: 'postgresql', engine: 'PlanetScale Postgres', compute: 'Single node, HA, or Metal' },
      { id: 'mysql', engine: 'Vitess', compute: 'Sharded MySQL clusters' },
    ],
    note: 'Both engines are relational, and every database is a paid cluster with no free tier. There is no document or vector database.',
  },
}
