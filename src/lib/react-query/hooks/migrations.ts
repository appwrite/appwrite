/**
 * React Query hooks for Migrations
 *
 * Handles migration fetching and API key creation for migrations.
 */

import { useMutation, useQuery } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
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
