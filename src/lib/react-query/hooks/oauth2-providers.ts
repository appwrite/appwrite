import { useMutation, useQuery, queryOptions } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk, type ProjectSdk } from '@/lib/appwrite/sdk'
import { updateProjectOAuth2Provider } from '@/lib/oauth2/update-project-oauth2'
import { DEFAULT_STALE_TIME } from './constants'

export async function fetchConsoleOAuth2Catalog(): Promise<Models.ConsoleOAuth2ProviderList> {
  return await sdk.forConsole.console.listOAuth2Providers()
}

export async function fetchProjectOAuth2Providers(
  projectId: string,
): Promise<Models.OAuth2ProviderList> {
  return await sdk.forProject(projectId).project.listOAuth2Providers()
}

export function consoleOAuth2CatalogQueryOptions() {
  return queryOptions({
    queryKey: ['oauth2', 'console', 'catalog'],
    queryFn: fetchConsoleOAuth2Catalog,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function projectOAuth2ProvidersQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['oauth2', 'project', projectId, 'providers'],
    queryFn: () => fetchProjectOAuth2Providers(projectId!),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useConsoleOAuth2Catalog() {
  return useQuery(consoleOAuth2CatalogQueryOptions())
}

export function useProjectOAuth2Providers(projectId: string | null | undefined) {
  return useQuery(projectOAuth2ProvidersQueryOptions(projectId))
}

export function useUpdateProjectOAuth2Provider(projectId: string | null | undefined) {
  return useMutation({
    mutationFn: async (input: {
      providerId: string
      values: Record<string, string | boolean>
    }) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId) as ProjectSdk
      await updateProjectOAuth2Provider(
        projectSdk,
        input.providerId,
        input.values,
      )
    },
  })
}
