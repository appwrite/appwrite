/**
 * React Query hooks for Console OAuth2 Apps (marketplace + org settings).
 *
 * All operations use sdk.forConsole.apps and sdk.forConsole.oauth2.
 */

import { useMemo } from 'react'
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
  queryOptions,
} from '@tanstack/react-query'
import { ID, Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  mapAppsToMarketplaceApps,
  sortMarketplaceApps,
} from '@/lib/marketplace/map-app'
import {
  MARKETPLACE_CATEGORY_ORDER,
  type MarketplaceApp,
  type MarketplaceAppCategory,
} from '@/lib/marketplace/types'
import { DEFAULT_STALE_TIME } from './constants'

export const MARKETPLACE_APPS_LIMIT = 100
export const MARKETPLACE_PAGE_SIZE = 15

/** Labels only Appwrite can set; used for curated marketplace sections. */
export type MarketplaceCurationLabel = 'official' | 'suggested'

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

/**
 * Published marketplace apps, own organization's included. Note: `notEqual`
 * on teamId must be avoided here — official apps have no teamId and Appwrite's
 * notEqual drops null/empty values, returning an empty list.
 */
function marketplaceBaseQueries() {
  return [
    Query.equal('enabled', true),
    // Every marketplace list only shows Appwrite-curated official apps.
    Query.contains('labels', 'official'),
    // DCR-registered OAuth clients must never surface in marketplace
    // listings. The isNull branch keeps unlabeled apps included (notContains
    // alone drops rows with empty labels).
    Query.or([
      Query.notContains('labels', 'oauth-dcr'),
      Query.isNull('labels'),
    ]),
  ]
}

/**
 * Server-side match for a category. Apps without any category tag resolve to
 * devtools client-side (see resolveCategory), so the devtools query must also
 * match apps whose tags contain no category at all (or are empty/null).
 */
function marketplaceCategoryQueries(category: MarketplaceAppCategory) {
  if (category !== 'devtools') {
    return [Query.contains('tags', category)]
  }
  return [
    Query.or([
      Query.contains('tags', 'devtools'),
      Query.notContains('tags', [...MARKETPLACE_CATEGORY_ORDER]),
      Query.isNull('tags'),
    ]),
  ]
}

export async function fetchMarketplaceCatalogAppsRaw(organizationId: string) {
  if (!organizationId) {
    return { apps: [], total: 0 }
  }

  const response = await sdk.forConsole.apps.list({
    queries: [
      ...marketplaceBaseQueries(),
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

export async function fetchMarketplaceCatalogPageRaw(
  organizationId: string,
  options: { category?: MarketplaceAppCategory; page: number },
) {
  if (!organizationId) {
    return { apps: [], total: 0 }
  }

  const queries = [
    ...marketplaceBaseQueries(),
    Query.orderDesc('$createdAt'),
    Query.limit(MARKETPLACE_PAGE_SIZE),
    Query.offset(Math.max(0, options.page - 1) * MARKETPLACE_PAGE_SIZE),
  ]
  if (options.category) {
    queries.push(...marketplaceCategoryQueries(options.category))
  }

  const response = await sdk.forConsole.apps.list({
    queries,
    total: true,
  })

  return {
    apps: response.apps ?? [],
    total: response.total ?? 0,
  }
}

export async function fetchMarketplaceLabeledAppsRaw(
  organizationId: string,
  label: MarketplaceCurationLabel,
  category?: MarketplaceAppCategory,
) {
  if (!organizationId) {
    return { apps: [], total: 0 }
  }

  const queries = [
    ...marketplaceBaseQueries(),
    Query.contains('labels', label),
    Query.orderDesc('$createdAt'),
    Query.limit(MARKETPLACE_PAGE_SIZE),
  ]
  if (category) {
    queries.push(...marketplaceCategoryQueries(category))
  }

  const response = await sdk.forConsole.apps.list({
    queries,
    total: true,
  })

  return {
    apps: response.apps ?? [],
    total: response.total ?? 0,
  }
}

/** Server-side totals for the sidebar and category tiles (limit-1 count queries). */
export async function fetchMarketplaceNavCountsRaw(organizationId: string) {
  if (!organizationId) {
    return {
      catalogTotal: 0,
      categoryTotals: {} as Record<MarketplaceAppCategory, number>,
    }
  }

  const countQueries = (extra: string[] = []) => ({
    queries: [...marketplaceBaseQueries(), ...extra, Query.limit(1)],
    total: true,
  })

  const [catalog, ...categories] = await Promise.all([
    sdk.forConsole.apps.list(countQueries()),
    ...MARKETPLACE_CATEGORY_ORDER.map((category) =>
      sdk.forConsole.apps.list(
        countQueries(marketplaceCategoryQueries(category)),
      ),
    ),
  ])

  return {
    catalogTotal: catalog.total ?? 0,
    categoryTotals: Object.fromEntries(
      MARKETPLACE_CATEGORY_ORDER.map((category, index) => [
        category,
        categories[index]?.total ?? 0,
      ]),
    ) as Record<MarketplaceAppCategory, number>,
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

export function marketplaceCatalogPageQueryOptions(
  organizationId: string | null | undefined,
  options: { category?: MarketplaceAppCategory; page: number },
) {
  return queryOptions({
    queryKey: [
      'apps',
      'marketplace',
      'catalog-page',
      organizationId,
      options.category ?? 'all',
      options.page,
    ],
    queryFn: () => fetchMarketplaceCatalogPageRaw(organizationId!, options),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

export function marketplaceLabeledAppsQueryOptions(
  organizationId: string | null | undefined,
  label: MarketplaceCurationLabel,
  category?: MarketplaceAppCategory,
) {
  return queryOptions({
    queryKey: [
      'apps',
      'marketplace',
      'labeled',
      organizationId,
      label,
      category ?? 'all',
    ],
    queryFn: () =>
      fetchMarketplaceLabeledAppsRaw(organizationId!, label, category),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

export function marketplaceNavCountsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['apps', 'marketplace', 'nav-counts', organizationId],
    queryFn: () => fetchMarketplaceNavCountsRaw(organizationId!),
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
        ? sortMarketplaceApps(
            mapListedApps(data.apps, organizationId!, teamNamesById),
          )
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

/** One 15-app page of the catalog (optionally scoped to a category) plus the server total. */
export function useMarketplaceCatalogPage(
  organizationId: string | null | undefined,
  options: { category?: MarketplaceAppCategory; page: number },
  teamNamesById?: Record<string, string>,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    marketplaceCatalogPageQueryOptions(organizationId, options),
  )

  const apps = useMemo(
    () =>
      data?.apps
        ? sortMarketplaceApps(
            mapListedApps(data.apps, organizationId!, teamNamesById),
          )
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

/** Curated apps carrying an Appwrite-set label (official/suggested). */
export function useMarketplaceLabeledApps(
  organizationId: string | null | undefined,
  label: MarketplaceCurationLabel,
  options?: {
    category?: MarketplaceAppCategory
    teamNamesById?: Record<string, string>
  },
) {
  const teamNamesById = options?.teamNamesById
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    marketplaceLabeledAppsQueryOptions(
      organizationId,
      label,
      options?.category,
    ),
  )

  const apps = useMemo(
    () =>
      data?.apps
        ? sortMarketplaceApps(
            mapListedApps(data.apps, organizationId!, teamNamesById),
          )
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

/** Server totals for sidebar badges and category tiles. */
export function useMarketplaceNavCounts(
  organizationId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    marketplaceNavCountsQueryOptions(organizationId),
  )

  return {
    catalogTotal: data?.catalogTotal ?? 0,
    categoryTotals: data?.categoryTotals ?? null,
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
  /** Defaults to true. Pass false to create as a disabled draft. */
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
      // ?? (not ||) so an explicit empty description stays empty.
      const description = (
        input.description ??
        input.shortDescription ??
        input.name
      ).trim()
      const tagline = (input.shortDescription || input.name).trim()
      const tags = input.category ? [input.category] : undefined

      return await sdk.forConsole.apps.create({
        appId,
        name: input.name.trim(),
        // No implicit consent-URL fallback: apps start with no allowed
        // redirect URIs unless the caller provides one.
        redirectUris: input.redirectUri?.trim()
          ? [input.redirectUri.trim()]
          : [],
        description,
        tagline,
        tags,
        teamId: organizationId,
        enabled: input.enabled ?? true,
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
