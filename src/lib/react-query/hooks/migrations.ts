/**
 * React Query hooks for Migrations
 *
 * Handles migration fetching and API key creation for migrations.
 * CSV export/import migrations are used by the floating progress boxes.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME } from './constants'

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
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['migrations', 'project', projectId, region],
    queryFn: () => fetchProjectMigrations(projectId!, region),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
  })

  return {
    migrations: data?.migrations || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to get a single migration
 *
 * @param projectId - The project ID
 * @param region - The project region
 * @param migrationId - The migration ID
 */
export function useProjectMigration(
  projectId: string | null | undefined,
  region: string | undefined,
  migrationId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['migration', 'project', projectId, region, migrationId],
    queryFn: async () => {
      if (!projectId || !migrationId) {
        throw new Error('Project ID and Migration ID are required')
      }
      const projectSdk = sdk.forProject(projectId, region)
      const response = await projectSdk.migrations.list({
        queries: [Query.equal('$id', migrationId)],
      })
      return response.migrations?.[0] || null
    },
    enabled: !!projectId && !!migrationId,
    staleTime: DEFAULT_STALE_TIME,
  })

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
      return await sdk.forConsole.projects.createKey({
        projectId,
        name,
        scopes: [
          'users.read',
          'teams.read',
          'databases.read',
          'collections.read',
          'attributes.read',
          'indexes.read',
          'documents.read',
          'tables.read',
          'columns.read',
          'rows.read',
          'files.read',
          'buckets.read',
          'functions.read',
          'execution.read',
          'locale.read',
          'avatars.read',
          'health.read',
        ],
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
 */
export function useCsvExportMigrations(
  projectId: string | null | undefined,
  sessionExportIds: string[],
) {
  const hasSessionIds = sessionExportIds.length > 0
  const { data, refetch } = useQuery({
    queryKey: ['migrations', 'project', projectId, 'csv-export', sessionExportIds],
    queryFn: async () => {
      const result = await fetchCsvExportMigrations(projectId!)
      const set = new Set(sessionExportIds)
      return {
        migrations: (result.migrations || []).filter((m) => set.has(m.$id)),
      }
    },
    enabled: !!projectId && hasSessionIds,
    staleTime: 5 * 1000,
    refetchOnWindowFocus: true,
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
 */
export function useCsvImportMigrations(
  projectId: string | null | undefined,
  sessionImportIds: string[],
) {
  const hasSessionIds = sessionImportIds.length > 0
  const { data, refetch } = useQuery({
    queryKey: ['migrations', 'project', projectId, 'csv-import', sessionImportIds],
    queryFn: async () => {
      const result = await fetchCsvImportMigrations(projectId!)
      const set = new Set(sessionImportIds)
      return {
        migrations: (result.migrations || []).filter((m) => set.has(m.$id)),
      }
    },
    enabled: !!projectId && hasSessionIds,
    staleTime: 5 * 1000,
    refetchOnWindowFocus: true,
  })
  return {
    migrations: data?.migrations ?? [],
    refetch,
  }
}

export interface CreateCSVExportParams {
  resourceId: string
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
        resourceId: params.resourceId,
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
  resourceId: string
  internalFile?: boolean
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
        resourceId: params.resourceId,
        internalFile: params.internalFile ?? false,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['migrations', 'project', projectId],
      })
    },
  })
}
