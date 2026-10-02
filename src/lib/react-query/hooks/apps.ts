/**
 * React Query hooks for Console OAuth2 Apps (marketplace + org settings).
 *
 * All operations use sdk.forConsole.apps and sdk.forConsole.oauth2.
 */

import { useMemo } from 'react'
import {
  infiniteQueryOptions,
  keepPreviousData,
  useInfiniteQuery,
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
import { nextCursorAfter } from './cursor'

export const MARKETPLACE_APPS_LIMIT = 100
export const MARKETPLACE_PAGE_SIZE = 15

export type MarketplaceCatalogPageOptions = {
  category?: MarketplaceAppCategory
  page: number
  /** Server-side text search across the whole catalog. */
  search?: string
}

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

/**
 * Server-side text match, so search covers the whole catalog instead of only
 * the first page. Apps have no fulltext index, so `contains` (substring) is
 * used per attribute. `author` is derived client-side (official apps read as
 * "Appwrite") and therefore cannot be matched here.
 */
function marketplaceSearchQueries(search: string | undefined) {
  const term = search?.trim()
  if (!term) return []
  return [
    Query.or([
      Query.contains('name', term),
      Query.contains('tagline', term),
      Query.contains('description', term),
      Query.contains('tags', term),
    ]),
  ]
}

export async function fetchMarketplaceCatalogPageRaw(
  organizationId: string,
  options: MarketplaceCatalogPageOptions,
) {
  if (!organizationId) {
    return { apps: [], total: 0 }
  }

  const queries = [
    ...marketplaceBaseQueries(),
    ...marketplaceSearchQueries(options.search),
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

/**
 * Same filters and sort as marketplace Explore / Catalog browse
 * ({@link fetchMarketplaceCatalogPageRaw} without category or search), capped
 * at {@link MARKETPLACE_APPS_LIMIT} for debug pickers and other compact lists.
 */
export async function fetchMarketplaceCatalogPickerRaw(organizationId: string) {
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
export async function fetchMarketplaceNavCountsRaw(
  organizationId: string,
  search?: string,
) {
  if (!organizationId) {
    return {
      catalogTotal: 0,
      categoryTotals: {} as Record<MarketplaceAppCategory, number>,
    }
  }

  const countQueries = (extra: string[] = []) => ({
    queries: [
      ...marketplaceBaseQueries(),
      ...marketplaceSearchQueries(search),
      ...extra,
      Query.limit(1),
    ],
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
  /**
   * The update endpoint replaces the whole document: leaving these out resets
   * them to empty, so callers must always pass the current values.
   */
  installationScopes?: string[]
  installationRedirectUrl?: string
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

export function marketplaceCatalogPageQueryOptions(
  organizationId: string | null | undefined,
  options: MarketplaceCatalogPageOptions,
) {
  return queryOptions({
    queryKey: [
      'apps',
      'marketplace',
      'catalog-page',
      organizationId,
      options.category ?? 'all',
      options.search?.trim() || '',
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

export function marketplaceCatalogPickerQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['apps', 'marketplace', 'catalog-picker', organizationId],
    queryFn: () => fetchMarketplaceCatalogPickerRaw(organizationId!),
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
  search?: string,
) {
  const term = search?.trim() || ''
  return queryOptions({
    queryKey: ['apps', 'marketplace', 'nav-counts', organizationId, term],
    queryFn: () => fetchMarketplaceNavCountsRaw(organizationId!, term),
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

/**
 * One 15-app page of the catalog (optionally scoped to a category and/or a
 * search term) plus the server total.
 */
export function useMarketplaceCatalogPage(
  organizationId: string | null | undefined,
  options: MarketplaceCatalogPageOptions,
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

/** Server totals for sidebar badges and category tiles, scoped to the search term. */
export function useMarketplaceNavCounts(
  organizationId: string | null | undefined,
  search?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    marketplaceNavCountsQueryOptions(organizationId, search),
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
        installationScopes: input.installationScopes,
        installationRedirectUrl: input.installationRedirectUrl,
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

// ============================================================================
// INSTALLATION SCOPES, INSTALLATIONS, AND APP KEYS
// ============================================================================

export const ORGANIZATION_APP_INSTALLATIONS_PAGE_SIZE = 50
export const ORGANIZATION_APP_KEYS_PAGE_SIZE = 50

/**
 * Scopes an app may request when installed on an organization: the Console
 * project's installation scopes, with catalog metadata (category, description).
 */
export async function fetchConsoleInstallationScopes() {
  const response = await sdk.forConsole.apps.listInstallationScopes()
  return response.scopes ?? []
}

/** One cursor page of an app's installations, newest first. */
export async function fetchOrganizationAppInstallations(
  appId: string,
  cursor?: string,
) {
  if (!appId) throw new Error('App ID is required')
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(ORGANIZATION_APP_INSTALLATIONS_PAGE_SIZE),
  ]
  if (cursor) queries.push(Query.cursorAfter(cursor))

  const response = await sdk.forConsole.apps.listInstallations({
    appId,
    queries,
    total: false,
  })
  return { installations: response.installations ?? [] }
}

/** One cursor page of an app's keys, newest first. */
export async function fetchOrganizationAppKeys(appId: string, cursor?: string) {
  if (!appId) throw new Error('App ID is required')
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(ORGANIZATION_APP_KEYS_PAGE_SIZE),
  ]
  if (cursor) queries.push(Query.cursorAfter(cursor))

  const response = await sdk.forConsole.apps.listKeys({
    appId,
    queries,
    total: false,
  })
  return { keys: response.keys ?? [] }
}

export function consoleInstallationScopesQueryOptions() {
  return queryOptions({
    queryKey: ['apps', 'installation-scopes', 'console'],
    queryFn: fetchConsoleInstallationScopes,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function organizationAppInstallationsInfiniteQueryOptions(
  appId: string | null | undefined,
) {
  return infiniteQueryOptions({
    queryKey: ['app', appId, 'installations'],
    queryFn: ({ pageParam }) =>
      fetchOrganizationAppInstallations(appId!, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      nextCursorAfter(
        lastPage.installations,
        ORGANIZATION_APP_INSTALLATIONS_PAGE_SIZE,
      ),
    enabled: !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: appId ? 5 * 60 * 1000 : 0,
  })
}

export function organizationAppKeysInfiniteQueryOptions(
  appId: string | null | undefined,
) {
  return infiniteQueryOptions({
    queryKey: ['app', appId, 'keys'],
    queryFn: ({ pageParam }) => fetchOrganizationAppKeys(appId!, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      nextCursorAfter(lastPage.keys, ORGANIZATION_APP_KEYS_PAGE_SIZE),
    enabled: !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: appId ? 5 * 60 * 1000 : 0,
  })
}

export function useConsoleInstallationScopes() {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    consoleInstallationScopesQueryOptions(),
  )

  return {
    scopes: data ?? [],
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useOrganizationAppInstallations(
  appId: string | null | undefined,
) {
  const {
    data,
    isLoading,
    isFetching,
    error,
    refetch,
    hasNextPage,
    fetchNextPage,
    isFetchingNextPage,
  } = useInfiniteQuery(organizationAppInstallationsInfiniteQueryOptions(appId))

  return {
    installations: data?.pages.flatMap((page) => page.installations) ?? [],
    hasMore: hasNextPage,
    loadMore: () => void fetchNextPage(),
    isLoadingMore: isFetchingNextPage,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useOrganizationAppKeys(appId: string | null | undefined) {
  const {
    data,
    isLoading,
    isFetching,
    error,
    refetch,
    hasNextPage,
    fetchNextPage,
    isFetchingNextPage,
  } = useInfiniteQuery(organizationAppKeysInfiniteQueryOptions(appId))

  return {
    keys: data?.pages.flatMap((page) => page.keys) ?? [],
    hasMore: hasNextPage,
    loadMore: () => void fetchNextPage(),
    isLoadingMore: isFetchingNextPage,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useDeleteOrganizationAppInstallation(
  appId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (installationId: string) => {
      if (!appId) throw new Error('App ID is required')
      await sdk.forConsole.apps.deleteInstallation({ appId, installationId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['app', appId, 'installations'],
      })
    },
  })
}

export function useCreateOrganizationAppKey(appId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async () => {
      if (!appId) throw new Error('App ID is required')
      return await sdk.forConsole.apps.createKey({ appId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['app', appId, 'keys'],
      })
    },
  })
}

export function useDeleteOrganizationAppKey(appId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (keyId: string) => {
      if (!appId) throw new Error('App ID is required')
      await sdk.forConsole.apps.deleteKey({ appId, keyId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['app', appId, 'keys'],
      })
    },
  })
}
