/**
 * React Query hooks for Migrations
 *
 * Handles migration fetching and API key creation for migrations.
 * CSV export/import migrations are used by the floating progress boxes.
 */

import {
  useMutation,
  useQuery,
  useQueryClient,
  queryOptions,
} from '@tanstack/react-query'
import {
  Query,
  ID,
  ProjectKeyScopes,
  AppwriteMigrationResource,
  SupabaseMigrationResource,
  FirebaseMigrationResource,
  NHostMigrationResource,
  OnDuplicate,
} from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { migrationMatchesDatabaseTables } from '@/lib/migrations/csv-resource'
import { DEFAULT_STALE_TIME } from './constants'

/** Query options for project migrations list (for route loader prefetch). */
export function projectMigrationsQueryOptions(
  projectId: string | null | undefined,
  region?: string,
) {
  return queryOptions({
    queryKey: ['migrations', 'project', projectId, region],
    queryFn: () => fetchProjectMigrations(projectId!, region),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
  })
}

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch migrations for a project
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @returns Migrations list response from the API
 */
export async function fetchProjectMigrations(
  projectId: string,
  region?: string,
) {
  if (!projectId) {
    return { migrations: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId, region)
  const queries = [
    Query.equal('source', ['Appwrite', 'Firebase', 'NHost', 'Supabase']),
    Query.or([
      Query.equal('destination', ['Appwrite', 'Firebase', 'NHost', 'Supabase']),
      Query.isNull('destination'),
    ]),
    Query.orderDesc('$createdAt'),
  ]

  const response = await projectSdk.migrations.list({ queries })
  return {
    migrations: response.migrations || [],
    total: response.total || 0,
  }
}

/**
 * Fetch CSV export migrations (destination=CSV).
 * Includes pending, processing, completed, and failed so the box can show progress and download/errors.
 * Ordered by $updatedAt desc so recent ones first.
 */
export async function fetchCsvExportMigrations(projectId: string) {
  if (!projectId) {
    return { migrations: [] as Models.Migration[] }
  }
  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.migrations.list({
    queries: [
      Query.equal('destination', 'CSV'),
      Query.orderDesc('$updatedAt'),
      Query.limit(20),
    ],
  })
  return {
    migrations: (response.migrations || []) as Models.Migration[],
  }
}

/**
 * Fetch CSV import migrations (source=CSV).
 * Includes pending, processing, completed, and failed for progress and error display.
 */
export async function fetchCsvImportMigrations(projectId: string) {
  if (!projectId) {
    return { migrations: [] as Models.Migration[] }
  }
  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.migrations.list({
    queries: [
      Query.equal('source', 'CSV'),
      Query.orderDesc('$updatedAt'),
      Query.limit(20),
    ],
  })
  return {
    migrations: (response.migrations || []) as Models.Migration[],
  }
}

/** Limit for list view; we filter by database/table client-side so fetch more. */
const MIGRATIONS_LIST_LIMIT = 100

/**
 * Fetch CSV export/import migrations in one API call, then filter client-side
 * to tables in the given database.
 *
 * Matches both the current migration shape (`parentResourceId` + `resourceId`)
 * and legacy composite `resourceId` values (`databaseId:tableId`).
 */
export async function fetchDatabaseCsvMigrations(
  projectId: string,
  databaseId: string,
  tableIds: string[],
) {
  if (!projectId || !databaseId) {
    return { migrations: [] as Models.Migration[] }
  }
  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.migrations.list({
    queries: [
      Query.or([
        Query.equal('destination', 'CSV'),
        Query.equal('source', 'CSV'),
      ]),
      Query.orderDesc('$updatedAt'),
      Query.limit(MIGRATIONS_LIST_LIMIT),
    ],
  })
  const all = (response.migrations || []) as Models.Migration[]
  const tableIdSet = new Set(tableIds)
  const migrations =
    tableIdSet.size > 0
      ? all.filter((m) =>
          migrationMatchesDatabaseTables(m, databaseId, tableIdSet),
        )
      : []
  return { migrations }
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch migrations for a project
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @returns Migrations list with loading state
 */
export function useProjectMigrations(
  projectId: string | null | undefined,
  region?: string,
) {
  const { data, isLoading, error, refetch } = useQuery(
    projectMigrationsQueryOptions(projectId, region),
  )

  return {
    migrations: data?.migrations || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

const IN_PROGRESS_MIGRATION_STATUSES = new Set([
  'pending',
  'processing',
  'uploading',
  'downloading',
])

/**
 * Query options for a single migration (`migrations.get`).
 * Polls while the migration is in progress so restore/CSV-style progress stays live
 * even if a realtime event is missed.
 */
export function projectMigrationQueryOptions(
  projectId: string | null | undefined,
  region: string | undefined,
  migrationId: string | null | undefined,
  options?: { pollWhileInProgress?: boolean },
) {
  const pollWhileInProgress = options?.pollWhileInProgress ?? false
  return queryOptions({
    queryKey: ['migration', 'project', projectId, region, migrationId],
    queryFn: async () => {
      if (!projectId || !migrationId) {
        throw new Error('Project ID and Migration ID are required')
      }
      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.migrations.get({ migrationId })
    },
    enabled: !!projectId && !!migrationId,
    staleTime: pollWhileInProgress ? 0 : DEFAULT_STALE_TIME,
    refetchInterval: pollWhileInProgress
      ? (query) => {
          const status = query.state.data?.status
          if (status && IN_PROGRESS_MIGRATION_STATUSES.has(status)) {
            return 2000
          }
          return false
        }
      : false,
    refetchOnWindowFocus: false,
  })
}

/**
 * Hook to get a single migration
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @param migrationId - The migration ID
 * @param options.pollWhileInProgress - Poll every 2s while status is in progress
 */
export function useProjectMigration(
  projectId: string | null | undefined,
  region: string | undefined,
  migrationId: string | null | undefined,
  options?: { pollWhileInProgress?: boolean },
) {
  const { data, isLoading, error, refetch } = useQuery(
    projectMigrationQueryOptions(projectId, region, migrationId, options),
  )

  return {
    migration: data || null,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to create an API key for migration
 *
 * @param projectId - The project ID
 */
export function useCreateMigrationKey(projectId: string | null | undefined) {
  return useMutation({
    mutationFn: async () => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const name = `[AUTO-GENERATED] Migration ${new Date().toISOString()}`
      return await sdk.forProject(projectId).project.createKey({
        keyId: ID.unique(),
        name,
        scopes: [
          'users.read',
          'teams.read',
          'databases.read',
          'tables.read',
          'columns.read',
          'indexes.read',
          'rows.read',
          'files.read',
          'buckets.read',
          'functions.read',
          'executions.read',
          'locale.read',
          'avatars.read',
          'health.read',
        ] as ProjectKeyScopes[],
      })
    },
  })
}

// ============================================================================
// CSV EXPORT / IMPORT
// ============================================================================

/**
 * Hook to fetch CSV export migrations for the current session only.
 * Only runs when sessionExportIds has length > 0 (user triggered an export this session).
 * Returns migrations filtered to session ids so we don't show old exports on reload.
 * Progress updates come from realtime (migrations.*.update); no polling or refetch on window focus.
 */
export function useCsvExportMigrations(
  projectId: string | null | undefined,
  sessionExportIds: string[],
) {
  const hasSessionIds = sessionExportIds.length > 0
  const { data, refetch } = useQuery({
    queryKey: [
      'migrations',
      'project',
      projectId,
      'csv-export',
      sessionExportIds,
    ],
    queryFn: async () => {
      const result = await fetchCsvExportMigrations(projectId!)
      const set = new Set(sessionExportIds)
      return {
        migrations: (result.migrations || []).filter((m) => set.has(m.$id)),
      }
    },
    enabled: !!projectId && hasSessionIds,
    staleTime: 30 * 1000,
    refetchOnWindowFocus: false,
  })
  return {
    migrations: data?.migrations ?? [],
    refetch,
  }
}

/**
 * Hook to fetch CSV import migrations for the current session only.
 * Only runs when sessionImportIds has length > 0 (user triggered an import this session).
 * Returns migrations filtered to session ids so we don't show old imports on reload.
 * Progress updates come from realtime (migrations.*.update); no polling or refetch on window focus.
 */
export function useCsvImportMigrations(
  projectId: string | null | undefined,
  sessionImportIds: string[],
) {
  const hasSessionIds = sessionImportIds.length > 0
  const { data, refetch } = useQuery({
    queryKey: [
      'migrations',
      'project',
      projectId,
      'csv-import',
      sessionImportIds,
    ],
    queryFn: async () => {
      const result = await fetchCsvImportMigrations(projectId!)
      const set = new Set(sessionImportIds)
      return {
        migrations: (result.migrations || []).filter((m) => set.has(m.$id)),
      }
    },
    enabled: !!projectId && hasSessionIds,
    staleTime: 30 * 1000,
    refetchOnWindowFocus: false,
  })
  return {
    migrations: data?.migrations ?? [],
    refetch,
  }
}

// ============================================================================
// CSV EXPORT / IMPORT LIST (for database Export / Import tab) – single API call
// ============================================================================

/**
 * Query options for fetching CSV export/import migrations for a database.
 */
export function databaseCsvMigrationsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableIds: string[],
) {
  const sortedTableIds = tableIds.slice().sort()
  return queryOptions({
    queryKey: [
      'migrations',
      'project',
      projectId,
      'database',
      databaseId,
      'csv',
      sortedTableIds,
    ],
    queryFn: () =>
      fetchDatabaseCsvMigrations(projectId!, databaseId!, sortedTableIds),
    enabled: !!projectId && !!databaseId && sortedTableIds.length > 0,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Hook to fetch CSV export/import migrations for a database in one API call.
 * Pass table IDs from useProjectTables.
 */
export function useDatabaseCsvMigrations(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableIds: string[],
) {
  const { data, isLoading, refetch } = useQuery(
    databaseCsvMigrationsQueryOptions(projectId, databaseId, tableIds),
  )
  return {
    migrations: data?.migrations ?? [],
    isLoading,
    refetch,
  }
}

export interface CreateCSVExportParams {
  databaseId: string
  collectionId: string
  filename: string
  columns?: string[]
  queries?: string[]
  delimiter?: string
  header?: boolean
  notify?: boolean
}

/**
 * Mutation to start a CSV export migration.
 */
export function useCreateCSVExport(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (params: CreateCSVExportParams) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.migrations.createCSVExport({
        databaseId: params.databaseId,
        collectionId: params.collectionId,
        filename: params.filename,
        columns: params.columns,
        queries: params.queries ?? [],
        delimiter: params.delimiter ?? ',',
        header: params.header ?? true,
        notify: params.notify ?? true,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['migrations', 'project', projectId],
      })
    },
  })
}

export interface CreateCSVImportParams {
  bucketId: string
  fileId: string
  databaseId: string
  collectionId: string
  internalFile?: boolean
  onDuplicate?: OnDuplicate
}

/**
 * Mutation to start a CSV import migration.
 */
export function useCreateCSVImport(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (params: CreateCSVImportParams) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.migrations.createCSVImport({
        bucketId: params.bucketId,
        fileId: params.fileId,
        databaseId: params.databaseId,
        collectionId: params.collectionId,
        internalFile: params.internalFile ?? false,
        onDuplicate: params.onDuplicate,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['migrations', 'project', projectId],
      })
    },
  })
}

// ============================================================================
// PROVIDER MIGRATIONS (Appwrite, Supabase, Firebase, NHost)
// ============================================================================

/** All Appwrite resources for report and migration. */
export const APPWRITE_RESOURCES: AppwriteMigrationResource[] = [
  AppwriteMigrationResource.User,
  AppwriteMigrationResource.Database,
  AppwriteMigrationResource.Table,
  AppwriteMigrationResource.Column,
  AppwriteMigrationResource.Index,
  AppwriteMigrationResource.Row,
  AppwriteMigrationResource.Document,
  AppwriteMigrationResource.Attribute,
  AppwriteMigrationResource.Collection,
  AppwriteMigrationResource.Bucket,
  AppwriteMigrationResource.File,
]

/** Resources supported by Supabase migrations (Document/Attribute/Collection). */
export const SUPABASE_NHOST_RESOURCES: SupabaseMigrationResource[] = [
  SupabaseMigrationResource.User,
  SupabaseMigrationResource.Database,
  SupabaseMigrationResource.Collection,
  SupabaseMigrationResource.Attribute,
  SupabaseMigrationResource.Index,
  SupabaseMigrationResource.Document,
  SupabaseMigrationResource.Bucket,
  SupabaseMigrationResource.File,
]

/** Resources supported by NHost migrations (same shape as Supabase report). */
export const NHOST_RESOURCES: NHostMigrationResource[] = [
  NHostMigrationResource.User,
  NHostMigrationResource.Database,
  NHostMigrationResource.Collection,
  NHostMigrationResource.Attribute,
  NHostMigrationResource.Index,
  NHostMigrationResource.Document,
  NHostMigrationResource.Bucket,
  NHostMigrationResource.File,
]

/** Resources supported by Firebase (same as Supabase but no Index per prompt). */
export const FIREBASE_RESOURCES: FirebaseMigrationResource[] = [
  FirebaseMigrationResource.User,
  FirebaseMigrationResource.Database,
  FirebaseMigrationResource.Collection,
  FirebaseMigrationResource.Attribute,
  FirebaseMigrationResource.Document,
  FirebaseMigrationResource.Bucket,
  FirebaseMigrationResource.File,
]

export interface AppwriteReportParams {
  endpoint: string
  projectID: string
  key: string
}

export async function fetchAppwriteReport(
  projectId: string,
  params: AppwriteReportParams,
  region?: string,
) {
  const projectSdk = sdk.forProject(projectId, region)
  return await projectSdk.migrations.getAppwriteReport({
    resources: APPWRITE_RESOURCES,
    endpoint: params.endpoint,
    projectID: params.projectID,
    key: params.key,
  })
}

export interface SupabaseReportParams {
  endpoint: string
  apiKey: string
  databaseHost: string
  username: string
  password: string
  port?: number
}

export async function fetchSupabaseReport(
  projectId: string,
  params: SupabaseReportParams,
  region?: string,
) {
  const projectSdk = sdk.forProject(projectId, region)
  return await projectSdk.migrations.getSupabaseReport({
    resources: SUPABASE_NHOST_RESOURCES,
    endpoint: params.endpoint,
    apiKey: params.apiKey,
    databaseHost: params.databaseHost,
    username: params.username,
    password: params.password,
    port: params.port,
  })
}

export interface FirebaseReportParams {
  serviceAccount: string
}

export async function fetchFirebaseReport(
  projectId: string,
  params: FirebaseReportParams,
  region?: string,
) {
  const projectSdk = sdk.forProject(projectId, region)
  return await projectSdk.migrations.getFirebaseReport({
    resources: FIREBASE_RESOURCES,
    serviceAccount: params.serviceAccount,
  })
}

export interface NHostReportParams {
  subdomain: string
  region: string
  adminSecret: string
  database?: string
  username?: string
  password: string
  port?: number
}

export async function fetchNHostReport(
  projectId: string,
  params: NHostReportParams,
  region?: string,
) {
  const projectSdk = sdk.forProject(projectId, region)
  return await projectSdk.migrations.getNHostReport({
    resources: NHOST_RESOURCES,
    subdomain: params.subdomain,
    region: params.region,
    adminSecret: params.adminSecret,
    database: params.database ?? params.subdomain,
    username: params.username ?? 'postgres',
    password: params.password,
    port: params.port,
  })
}

export interface CreateAppwriteMigrationParams {
  resources: AppwriteMigrationResource[]
  endpoint: string
  projectId: string
  apiKey: string
  onDuplicate?: OnDuplicate
}

export function useCreateAppwriteMigration(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (params: CreateAppwriteMigrationParams) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.migrations.createAppwriteMigration({
        resources: params.resources,
        endpoint: params.endpoint,
        projectId: params.projectId,
        apiKey: params.apiKey,
        onDuplicate: params.onDuplicate,
      })
    },
    onSuccess: () => {
      queryClient.refetchQueries({
        queryKey: ['migrations', 'project', projectId],
      })
    },
  })
}

export interface CreateSupabaseMigrationParams {
  resources: SupabaseMigrationResource[]
  endpoint: string
  apiKey: string
  databaseHost: string
  username: string
  password: string
  port?: number
}

export function useCreateSupabaseMigration(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (params: CreateSupabaseMigrationParams) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.migrations.createSupabaseMigration({
        resources: params.resources,
        endpoint: params.endpoint,
        apiKey: params.apiKey,
        databaseHost: params.databaseHost,
        username: params.username,
        password: params.password,
        port: params.port,
      })
    },
    onSuccess: () => {
      queryClient.refetchQueries({
        queryKey: ['migrations', 'project', projectId],
      })
    },
  })
}

export interface CreateFirebaseMigrationParams {
  resources: FirebaseMigrationResource[]
  serviceAccount: string
}

export function useCreateFirebaseMigration(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (params: CreateFirebaseMigrationParams) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.migrations.createFirebaseMigration({
        resources: params.resources,
        serviceAccount: params.serviceAccount,
      })
    },
    onSuccess: () => {
      queryClient.refetchQueries({
        queryKey: ['migrations', 'project', projectId],
      })
    },
  })
}

export interface CreateNHostMigrationParams {
  resources: NHostMigrationResource[]
  subdomain: string
  region: string
  adminSecret: string
  database?: string
  username?: string
  password: string
  port?: number
}

export function useCreateNHostMigration(
  projectId: string | null | undefined,
  region?: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (params: CreateNHostMigrationParams) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId, region)
      return await projectSdk.migrations.createNHostMigration({
        resources: params.resources,
        subdomain: params.subdomain,
        region: params.region,
        adminSecret: params.adminSecret,
        database: params.database ?? params.subdomain,
        username: params.username ?? 'postgres',
        password: params.password,
        port: params.port,
      })
    },
    onSuccess: () => {
      queryClient.refetchQueries({
        queryKey: ['migrations', 'project', projectId],
      })
    },
  })
}
