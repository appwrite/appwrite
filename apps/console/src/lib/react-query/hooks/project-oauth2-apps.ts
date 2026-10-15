/**
 * React Query hooks for project-scoped OAuth2 client apps.
 *
 * Uses sdk.forProject(projectId).apps (project API), unlike org marketplace apps
 * which use sdk.forConsole.apps.
 */

import {
  infiniteQueryOptions,
  queryOptions,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME } from './constants'
import { nextCursorAfter } from './cursor'

export const PROJECT_OAUTH2_APPS_LIMIT = 100
export const PROJECT_OAUTH2_APP_INSTALLATIONS_PAGE_SIZE = 50
export const PROJECT_OAUTH2_APP_KEYS_PAGE_SIZE = 50

export type CreateProjectOAuth2AppInput = {
  name: string
  appId?: string
  redirectUris: string[]
  postLogoutRedirectUris?: string[]
  type?: string
  deviceFlow?: boolean
  description?: string
  tagline?: string
  tags?: string[]
  enabled?: boolean
  clientUri?: string
  logoUri?: string
  privacyPolicyUrl?: string
  termsUrl?: string
  contacts?: string[]
  images?: string[]
  supportUrl?: string
  dataDeletionUrl?: string
}

export type UpdateProjectOAuth2AppInput = {
  appId: string
  name: string
  description?: string
  clientUri?: string
  logoUri?: string
  privacyPolicyUrl?: string
  termsUrl?: string
  contacts?: string[]
  tagline?: string
  tags?: string[]
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

export async function fetchProjectOAuth2Apps(
  projectId: string,
  region?: string,
) {
  if (!projectId) {
    return { apps: [], total: 0 }
  }

  const response = await sdk.forProject(projectId, region).apps.list({
    queries: [
      Query.orderDesc('$createdAt'),
      Query.limit(PROJECT_OAUTH2_APPS_LIMIT),
    ],
    total: true,
  })

  return {
    apps: response.apps ?? [],
    total: response.total ?? 0,
  }
}

export async function fetchProjectOAuth2App(
  projectId: string,
  appId: string,
  region?: string,
) {
  return sdk.forProject(projectId, region).apps.get({ appId })
}

export async function fetchProjectOAuth2AppSecrets(
  projectId: string,
  appId: string,
  region?: string,
) {
  const response = await sdk
    .forProject(projectId, region)
    .apps.listSecrets({ appId })
  return response.secrets ?? []
}

/**
 * Scopes an app may request when installed on a team: the project's
 * `oAuth2ServerInstallationScopes`, decorated with catalog metadata where
 * Appwrite knows the value.
 */
export async function fetchProjectOAuth2InstallationScopes(
  projectId: string,
  region?: string,
) {
  const response = await sdk
    .forProject(projectId, region)
    .apps.listInstallationScopes()
  return response.scopes ?? []
}

/** One cursor page of an app's installations, newest first. */
export async function fetchProjectOAuth2AppInstallations(
  projectId: string,
  appId: string,
  region?: string,
  cursor?: string,
) {
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(PROJECT_OAUTH2_APP_INSTALLATIONS_PAGE_SIZE),
  ]
  if (cursor) queries.push(Query.cursorAfter(cursor))

  const response = await sdk
    .forProject(projectId, region)
    .apps.listInstallations({ appId, queries, total: false })
  return { installations: response.installations ?? [] }
}

/** One cursor page of an app's keys, newest first. */
export async function fetchProjectOAuth2AppKeys(
  projectId: string,
  appId: string,
  region?: string,
  cursor?: string,
) {
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(PROJECT_OAUTH2_APP_KEYS_PAGE_SIZE),
  ]
  if (cursor) queries.push(Query.cursorAfter(cursor))

  const response = await sdk
    .forProject(projectId, region)
    .apps.listKeys({ appId, queries, total: false })
  return { keys: response.keys ?? [] }
}

export function projectOAuth2AppsQueryOptions(
  projectId: string | null | undefined,
  region?: string,
) {
  return queryOptions({
    queryKey: ['oauth2-apps', 'project', projectId, region],
    queryFn: () => fetchProjectOAuth2Apps(projectId!, region),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function projectOAuth2AppQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  region?: string,
) {
  return queryOptions({
    queryKey: ['oauth2-app', 'project', projectId, appId, region],
    queryFn: () => fetchProjectOAuth2App(projectId!, appId!, region),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && appId ? 5 * 60 * 1000 : 0,
  })
}

export function projectOAuth2AppSecretsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  region?: string,
) {
  return queryOptions({
    queryKey: ['oauth2-app', 'project', projectId, appId, 'secrets', region],
    queryFn: () => fetchProjectOAuth2AppSecrets(projectId!, appId!, region),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && appId ? 5 * 60 * 1000 : 0,
  })
}

export function projectOAuth2InstallationScopesQueryOptions(
  projectId: string | null | undefined,
  region?: string,
) {
  return queryOptions({
    queryKey: ['oauth2-installation-scopes', 'project', projectId, region],
    queryFn: () => fetchProjectOAuth2InstallationScopes(projectId!, region),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function projectOAuth2AppInstallationsInfiniteQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  region?: string,
) {
  return infiniteQueryOptions({
    queryKey: [
      'oauth2-app',
      'project',
      projectId,
      appId,
      'installations',
      region,
    ],
    queryFn: ({ pageParam }) =>
      fetchProjectOAuth2AppInstallations(projectId!, appId!, region, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      nextCursorAfter(
        lastPage.installations,
        PROJECT_OAUTH2_APP_INSTALLATIONS_PAGE_SIZE,
      ),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && appId ? 5 * 60 * 1000 : 0,
  })
}

export function projectOAuth2AppKeysInfiniteQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  region?: string,
) {
  return infiniteQueryOptions({
    queryKey: ['oauth2-app', 'project', projectId, appId, 'keys', region],
    queryFn: ({ pageParam }) =>
      fetchProjectOAuth2AppKeys(projectId!, appId!, region, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      nextCursorAfter(lastPage.keys, PROJECT_OAUTH2_APP_KEYS_PAGE_SIZE),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && appId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectOAuth2Apps(
  projectId: string | null | undefined,
  region?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    projectOAuth2AppsQueryOptions(projectId, region),
  )

  return {
    apps: data?.apps ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useProjectOAuth2App(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  region?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    projectOAuth2AppQueryOptions(projectId, appId, region),
  )

  return {
    app: data ?? null,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useProjectOAuth2AppSecrets(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  region?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    projectOAuth2AppSecretsQueryOptions(projectId, appId, region),
  )

  return {
    secrets: data ?? [],
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useProjectOAuth2InstallationScopes(
  projectId: string | null | undefined,
  region?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    projectOAuth2InstallationScopesQueryOptions(projectId, region),
  )

  return {
    scopes: data ?? [],
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useProjectOAuth2AppInstallations(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  region?: string,
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
    isFetchNextPageError,
  } = useInfiniteQuery(
    projectOAuth2AppInstallationsInfiniteQueryOptions(projectId, appId, region),
  )

  return {
    installations: data?.pages.flatMap((page) => page.installations) ?? [],
    hasMore: hasNextPage,
    loadMore: () => void fetchNextPage(),
    isLoadingMore: isFetchingNextPage,
    loadMoreFailed: isFetchNextPageError,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useProjectOAuth2AppKeys(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  region?: string,
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
    isFetchNextPageError,
  } = useInfiniteQuery(
    projectOAuth2AppKeysInfiniteQueryOptions(projectId, appId, region),
  )

  return {
    keys: data?.pages.flatMap((page) => page.keys) ?? [],
    hasMore: hasNextPage,
    loadMore: () => void fetchNextPage(),
    isLoadingMore: isFetchingNextPage,
    loadMoreFailed: isFetchNextPageError,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useCreateProjectOAuth2App(projectId: string, region?: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: CreateProjectOAuth2AppInput) => {
      const appId = input.appId?.trim()
        ? sanitizeAppId(input.appId) || ID.unique()
        : ID.unique()
      const redirectUris = input.redirectUris
        .map((uri) => uri.trim())
        .filter(Boolean)

      if (redirectUris.length === 0) {
        throw new Error('At least one redirect URI is required.')
      }

      const trimOptional = (value?: string) => {
        const next = value?.trim()
        return next ? next : undefined
      }
      const trimList = (values?: string[]) =>
        values?.map((value) => value.trim()).filter(Boolean)

      return sdk.forProject(projectId, region).apps.create({
        appId,
        name: input.name.trim(),
        redirectUris,
        postLogoutRedirectUris: trimList(input.postLogoutRedirectUris),
        type: input.type ?? 'confidential',
        deviceFlow: input.deviceFlow ?? false,
        description: trimOptional(input.description),
        tagline: trimOptional(input.tagline),
        tags: trimList(input.tags),
        enabled: input.enabled ?? true,
        clientUri: trimOptional(input.clientUri),
        logoUri: trimOptional(input.logoUri),
        privacyPolicyUrl: trimOptional(input.privacyPolicyUrl),
        termsUrl: trimOptional(input.termsUrl),
        contacts: trimList(input.contacts),
        images: trimList(input.images),
        supportUrl: trimOptional(input.supportUrl),
        dataDeletionUrl: trimOptional(input.dataDeletionUrl),
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['oauth2-apps', 'project', projectId],
      })
    },
  })
}

export function useUpdateProjectOAuth2App(projectId: string, region?: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: UpdateProjectOAuth2AppInput) => {
      return sdk.forProject(projectId, region).apps.update({
        appId: input.appId,
        name: input.name.trim(),
        description: input.description,
        clientUri: input.clientUri,
        logoUri: input.logoUri,
        privacyPolicyUrl: input.privacyPolicyUrl,
        termsUrl: input.termsUrl,
        contacts: input.contacts,
        tagline: input.tagline,
        tags: input.tags,
        images: input.images,
        supportUrl: input.supportUrl,
        dataDeletionUrl: input.dataDeletionUrl,
        enabled: input.enabled,
        redirectUris: input.redirectUris,
        postLogoutRedirectUris: input.postLogoutRedirectUris,
        type: input.type,
        deviceFlow: input.deviceFlow,
        installationScopes: input.installationScopes,
        installationRedirectUrl: input.installationRedirectUrl,
      })
    },
    onSuccess: async (app, variables) => {
      queryClient.setQueryData(
        ['oauth2-app', 'project', projectId, variables.appId, region],
        app,
      )
      await queryClient.refetchQueries({
        queryKey: ['oauth2-apps', 'project', projectId],
      })
      await queryClient.invalidateQueries({
        queryKey: ['oauth2-app', 'project', projectId, variables.appId],
      })
    },
  })
}

export function useDeleteProjectOAuth2App(projectId: string, region?: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (appId: string) => {
      await sdk.forProject(projectId, region).apps.delete({ appId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['oauth2-apps', 'project', projectId],
      })
    },
  })
}

export function useDeleteProjectOAuth2AppTokens(
  projectId: string,
  region?: string,
) {
  return useMutation({
    mutationFn: async (appId: string) => {
      await sdk.forProject(projectId, region).apps.deleteTokens({ appId })
    },
  })
}

export function useCreateProjectOAuth2AppSecret(
  projectId: string,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (appId: string) => {
      return sdk.forProject(projectId, region).apps.createSecret({ appId })
    },
    onSuccess: async (_data, appId) => {
      await queryClient.refetchQueries({
        queryKey: ['oauth2-app', 'project', projectId, appId, 'secrets'],
      })
      await queryClient.refetchQueries({
        queryKey: ['oauth2-apps', 'project', projectId],
      })
    },
  })
}

export function useDeleteProjectOAuth2AppSecret(
  projectId: string,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      appId,
      secretId,
    }: {
      appId: string
      secretId: string
    }) => {
      await sdk
        .forProject(projectId, region)
        .apps.deleteSecret({ appId, secretId })
    },
    onSuccess: async (_data, { appId }) => {
      await queryClient.refetchQueries({
        queryKey: ['oauth2-app', 'project', projectId, appId, 'secrets'],
      })
    },
  })
}

export function useDeleteProjectOAuth2AppInstallation(
  projectId: string,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      appId,
      installationId,
    }: {
      appId: string
      installationId: string
    }) => {
      await sdk
        .forProject(projectId, region)
        .apps.deleteInstallation({ appId, installationId })
    },
    onSuccess: async (_data, { appId }) => {
      await queryClient.refetchQueries({
        queryKey: ['oauth2-app', 'project', projectId, appId, 'installations'],
      })
    },
  })
}

export function useCreateProjectOAuth2AppKey(
  projectId: string,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (appId: string) => {
      return sdk.forProject(projectId, region).apps.createKey({ appId })
    },
    onSuccess: async (_data, appId) => {
      await queryClient.refetchQueries({
        queryKey: ['oauth2-app', 'project', projectId, appId, 'keys'],
      })
    },
  })
}

export function useDeleteProjectOAuth2AppKey(
  projectId: string,
  region?: string,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ appId, keyId }: { appId: string; keyId: string }) => {
      await sdk.forProject(projectId, region).apps.deleteKey({ appId, keyId })
    },
    onSuccess: async (_data, { appId }) => {
      await queryClient.refetchQueries({
        queryKey: ['oauth2-app', 'project', projectId, appId, 'keys'],
      })
    },
  })
}

export type ProjectOAuth2App = Models.App
