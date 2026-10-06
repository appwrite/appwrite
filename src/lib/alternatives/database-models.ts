import type { AlternativeId } from '@/lib/alternatives/types'

export type DatabaseModelId = 'tablesdb' | 'documentsdb' | 'vectorsdb' | 'postgresql' | 'mysql'
export type DatabaseCompute = 'serverless' | 'dedicated'

/** The five database models in an Appwrite project, in display order. */
export const APPWRITE_DATABASE_MODELS: {
  id: DatabaseModelId
  name: string
  model: string
  compute: DatabaseCompute[]
}[] = [
  { id: 'tablesdb', name: 'TablesDB', model: 'Relational tables', compute: ['serverless', 'dedicated'] },
  { id: 'documentsdb', name: 'DocumentsDB', model: 'JSON documents', compute: ['serverless', 'dedicated'] },
  { id: 'vectorsdb', name: 'VectorsDB', model: 'Vectors and embeddings', compute: ['serverless', 'dedicated'] },
  { id: 'postgresql', name: 'PostgreSQL', model: 'Native SQL', compute: ['dedicated'] },
  { id: 'mysql', name: 'MySQL', model: 'Native SQL', compute: ['dedicated'] },
]

export type CompetitorDatabaseModel = {
  /** The one model the platform is built around. */
  model: string
  /** The engine or product that provides it. */
  engine: string
  /** Which Appwrite model it lines up with. */
  slot: DatabaseModelId
  compute: string
  /** Optional separate product that covers another slot only partly. */
  extra?: { slot: DatabaseModelId; label: string }
  note: string
}

/** BaaS competitors and the single database model each one is built around. */
export const COMPETITOR_DATABASE_MODEL: Partial<Record<AlternativeId, CompetitorDatabaseModel>> = {
  supabase: {
    model: 'Relational',
    engine: 'PostgreSQL',
    slot: 'postgresql',
    compute: 'One dedicated instance per project',
    note: 'Documents and vectors live inside that one PostgreSQL database, through JSONB and pgvector.',
  },
  firebase: {
    model: 'Documents',
    engine: 'Cloud Firestore',
    slot: 'documentsdb',
    compute: 'Serverless only',
    extra: { slot: 'postgresql', label: 'SQL Connect, a separate product' },
    note: 'Firestore and Realtime Database are both document stores. Relational data needs SQL Connect, a separate PostgreSQL service billed through Cloud SQL.',
  },
  convex: {
    model: 'Documents',
    engine: 'Convex database',
    slot: 'documentsdb',
    compute: 'Serverless only',
    note: 'Vector search runs over the same document tables, and only from actions.',
  },
  neon: {
    model: 'Relational',
    engine: 'PostgreSQL',
    slot: 'postgresql',
    compute: 'Serverless only',
    note: 'Every Neon database is PostgreSQL on serverless compute.',
  },
}
