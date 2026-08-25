/**
 * React Query hooks for Console OAuth2 Apps (marketplace + org settings).
 *
 * All operations use sdk.forConsole.apps and sdk.forConsole.oauth2.
 */

import { useMemo } from 'react'
import {
  useMutation,
  useQuery,
  useQueryClient,
  queryOptions,
} from '@tanstack/react-query'
import { ID, Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { mapAppsToMarketplaceApps } from '@/lib/marketplace/map-app'
import type { MarketplaceApp } from '@/lib/marketplace/types'
import { DEFAULT_STALE_TIME } from './constants'

export const MARKETPLACE_APPS_LIMIT = 100

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

export async function fetchOrganizationAppsRaw(organizationId: string) {
  if (!organizationId) {
    return { apps: [], total: 0 }
  }

  const response = await sdk.forConsole.apps.list({
    queries: [
      Query.equal('teamId', organizationId),
      Query.orderDesc('$createdAt'),
      Query.limit(MARKETPLACE_APPS_LIMIT),
    ],
    total: true,
  })

  return {
    apps: response.apps ?? [],
    total: response.total ?? 0,
  }
}

export async function fetchMarketplaceCatalogAppsRaw(organizationId: string) {
  if (!organizationId) {
    return { apps: [], total: 0 }
  }

  const response = await sdk.forConsole.apps.list({
    queries: [
      Query.contains('labels', 'official'),
      Query.equal('enabled', true),
      Query.orderDesc('$createdAt'),
      Query.limit(MARKETPLACE_APPS_LIMIT),
    ],
    total: true,
  })

  const apps = (response.apps ?? []).filter(
    (app) => app.teamId !== organizationId,
  )

  return {
    apps,
    total: apps.length,
  }
}

function sanitizeAppId(value: string): string {
  const cleaned = value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]/g, '-')
    .replace(/^[^a-z0-9]+/, '')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 36)
  return cleaned
}

export function defaultMarketplaceRedirectUri(): string {
  if (typeof window === 'undefined') return 'https://cloud.appwrite.io/oauth2/consent'
  return `${window.location.origin}/oauth2/consent`
}

export type UpdateOrganizationAppInput = {
  appId: string
  name: string
  description?: string
  tagline?: string
  tags?: string[]
  clientUri?: string
  logoUri?: string
  privacyPolicyUrl?: string
  termsUrl?: string
  contacts?: string[]
  images?: string[]
  supportUrl?: string
  dataDeletionUrl?: string
  enabled?: boolean
  redirectUris?: string[]
  postLogoutRedirectUris?: string[]
  type?: string
  deviceFlow?: boolean
}

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

export async function fetchOrganizationApp(appId: string) {
  if (!appId) throw new Error('App ID is required')
  return await sdk.forConsole.apps.get({ appId })
}

export async function fetchOrganizationAppSecrets(appId: string) {
  if (!appId) throw new Error('App ID is required')
  const response = await sdk.forConsole.apps.listSecrets({ appId })
  return response.secrets ?? []
}

export function organizationAppQueryOptions(appId: string | null | undefined) {
  return queryOptions({
    queryKey: ['app', appId],
    queryFn: () => fetchOrganizationApp(appId!),
    enabled: !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: appId ? 5 * 60 * 1000 : 0,
  })
}

export function organizationAppSecretsQueryOptions(
  appId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['app', appId, 'secrets'],
    queryFn: () => fetchOrganizationAppSecrets(appId!),
    enabled: !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: appId ? 5 * 60 * 1000 : 0,
  })
}

export function organizationAppsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['apps', 'organization', organizationId],
    queryFn: () => fetchOrganizationAppsRaw(organizationId!),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

export function marketplaceCatalogQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['apps', 'marketplace', 'catalog', organizationId],
    queryFn: () => fetchMarketplaceCatalogAppsRaw(organizationId!),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

function mapListedApps(
  apps: Models.App[],
  organizationId: string,
  teamNamesById?: Record<string, string>,
): MarketplaceApp[] {
  return mapAppsToMarketplaceApps(apps, {
    organizationId,
    teamNamesById,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

export function useOrganizationApps(
  organizationId: string | null | undefined,
  teamNamesById?: Record<string, string>,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    organizationAppsQueryOptions(organizationId),
  )

  const apps = useMemo(
    () =>
      data?.apps
        ? mapListedApps(data.apps, organizationId!, teamNamesById)
        : [],
    [data?.apps, organizationId, teamNamesById],
  )

  return {
    apps,
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useMarketplaceCatalog(
  organizationId: string | null | undefined,
  teamNamesById?: Record<string, string>,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    marketplaceCatalogQueryOptions(organizationId),
  )

  const apps = useMemo(
    () =>
      data?.apps
        ? mapListedApps(data.apps, organizationId!, teamNamesById)
        : [],
    [data?.apps, organizationId, teamNamesById],
  )

  return {
    apps,
    total: data?.total ?? apps.length,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useOrganizationApp(appId: string | null | undefined) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    organizationAppQueryOptions(appId),
  )

  return {
    app: data,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useOrganizationAppSecrets(appId: string | null | undefined) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    organizationAppSecretsQueryOptions(appId),
  )

  return {
    secrets: data ?? [],
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export type CreateOrganizationAppInput = {
  name: string
  slug?: string
  shortDescription?: string
  description?: string
  category?: string
  redirectUri?: string
  /** Defaults to false (marketplace draft). Pass true for Sign in with Appwrite setup. */
  enabled?: boolean
}

export function useCreateOrganizationApp(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: CreateOrganizationAppInput) => {
      if (!organizationId) throw new Error('Organization ID is required')

      // Custom slug (marketplace / explicit ID). Without a slug, always use a
      // unique ID so quick-create flows never collide on sanitized names.
      const appId = input.slug?.trim()
        ? sanitizeAppId(input.slug) || ID.unique()
        : ID.unique()
      const description = (
        input.description ||
        input.shortDescription ||
        input.name
      ).trim()
      const tagline = (input.shortDescription || input.name).trim()
      const tags = input.category ? [input.category] : undefined

      return await sdk.forConsole.apps.create({
        appId,
        name: input.name.trim(),
        redirectUris: [
          input.redirectUri?.trim() || defaultMarketplaceRedirectUri(),
        ],
        description,
        tagline,
        tags,
        teamId: organizationId,
        enabled: input.enabled ?? false,
        type: 'confidential',
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['apps', 'organization', organizationId],
      })
    },
  })
}

export function useUpdateOrganizationApp(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: UpdateOrganizationAppInput) => {
      return await sdk.forConsole.apps.update({
        appId: input.appId,
        name: input.name,
        enabled: input.enabled,
        description: input.description,
        tagline: input.tagline,
        tags: input.tags,
        clientUri: input.clientUri,
        logoUri: input.logoUri,
        privacyPolicyUrl: input.privacyPolicyUrl,
        termsUrl: input.termsUrl,
        contacts: input.contacts,
        images: input.images,
        supportUrl: input.supportUrl,
        dataDeletionUrl: input.dataDeletionUrl,
        redirectUris: input.redirectUris,
        postLogoutRedirectUris: input.postLogoutRedirectUris,
        type: input.type,
        deviceFlow: input.deviceFlow,
      })
    },
    onSuccess: async (app) => {
      queryClient.setQueryData(['app', app.$id], app)
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['apps', 'organization', organizationId],
        }),
        queryClient.refetchQueries({
          queryKey: ['apps', 'marketplace', 'catalog', organizationId],
        }),
      ])
    },
  })
}

export function useCreateOrganizationAppSecret(appId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (options?: { appId?: string }) => {
      const id = options?.appId || appId
      if (!id) throw new Error('App ID is required')
      return await sdk.forConsole.apps.createSecret({ appId: id })
    },
    onSuccess: async (_data, options) => {
      const id = options?.appId || appId
      await queryClient.refetchQueries({
        queryKey: ['app', id, 'secrets'],
      })
    },
  })
}

export function useDeleteOrganizationAppSecret(
  appId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (secretId: string) => {
      if (!appId) throw new Error('App ID is required')
      await sdk.forConsole.apps.deleteSecret({ appId, secretId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['app', appId, 'secrets'],
      })
    },
  })
}

export function useDeleteOrganizationApp(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (appId: string) => {
      await sdk.forConsole.apps.delete({ appId })
    },
    onSuccess: async (_, appId) => {
      queryClient.removeQueries({ queryKey: ['app', appId] })
      await queryClient.refetchQueries({
        queryKey: ['apps', 'organization', organizationId],
      })
    },
  })
}
