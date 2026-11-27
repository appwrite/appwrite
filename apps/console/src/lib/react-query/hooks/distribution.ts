import {
  infiniteQueryOptions,
  keepPreviousData,
  queryOptions,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import type { QueryClient, QueryKey } from '@tanstack/react-query'
import { useCallback, useMemo } from 'react'
import { ID, Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type {
  CreateDistributionAndroidSigningIdentityParams,
  CreateDistributionAppleConnectionParams,
  CreateDistributionAppParams,
  CreateDistributionBuildParams,
  CreateDistributionCheckParams,
  CreateDistributionGoogleConnectionParams,
  CreateDistributionIosSigningIdentityParams,
  CreateDistributionReleaseParams,
  CreateDistributionSubmissionParams,
  CreateDistributionTargetParams,
  DistributionApp,
  DistributionAppList,
  DistributionArtifact,
  DistributionArtifactList,
  DistributionArtifactStatus,
  DistributionBuild,
  DistributionBuildList,
  DistributionCheck,
  DistributionConnection,
  DistributionConnectionList,
  DistributionLog,
  DistributionPlatform,
  DistributionProvider,
  DistributionRelease,
  DistributionReleaseList,
  DistributionReleaseStatus,
  DistributionSigningIdentity,
  DistributionSigningIdentityDownload,
  DistributionSigningIdentityList,
  DistributionSubmission,
  DistributionTargetList,
  UpdateDistributionAppleConnectionParams,
  UpdateDistributionAppParams,
  UpdateDistributionBuildStatusParams,
  UpdateDistributionGoogleConnectionParams,
  UpdateDistributionReleaseParams,
  UpdateDistributionSubmissionParams,
  UpdateDistributionSubmissionStatusParams,
  UpdateDistributionTargetParams,
} from '@/lib/appwrite/distribution'
import { mergeNewerLogs, type LogPage } from '@/lib/distribution/logs'
import { captureExceptionWithContext } from '@/lib/sentry/report-error'
import {
  fetchAllVariables,
  getVariableValueError,
  validateVariables,
} from '@/lib/variables'
import { DEFAULT_PAGE_SIZE, DEFAULT_STALE_TIME } from './constants'

function pageQueries(page: number, limit: number, search: string): string[] {
  const term = search.trim()
  return [
    ...(term ? [Query.search('search', term)] : []),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]
}

function requireProject(projectId: string | null | undefined): string {
  if (!projectId) {
    throw new Error('Project ID is required')
  }
  return projectId
}

function requireApp(
  projectId: string | null | undefined,
  appId: string | null | undefined,
): { projectId: string; appId: string } {
  if (!projectId || !appId) {
    throw new Error('Project ID and App ID are required')
  }
  return { projectId, appId }
}

export async function fetchDistributionApps(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
): Promise<DistributionAppList> {
  if (!projectId) {
    return { apps: [], total: 0 }
  }
  return await sdk.forProject(projectId).distribution.listApps({
    queries: pageQueries(page, limit, search),
  })
}

export async function fetchDistributionApp(
  projectId: string,
  appId: string,
): Promise<DistributionApp> {
  requireApp(projectId, appId)
  return await sdk.forProject(projectId).distribution.getApp({ appId })
}

export async function fetchDistributionTargets(
  projectId: string,
  appId: string,
): Promise<DistributionTargetList> {
  if (!projectId || !appId) {
    return { targets: [], total: 0 }
  }
  return await sdk.forProject(projectId).distribution.listTargets({
    appId,
    queries: [Query.orderAsc('$createdAt')],
  })
}

export async function fetchDistributionVariables(
  projectId: string,
  appId: string,
): Promise<{ variables: Models.Variable[]; total: number }> {
  if (!projectId || !appId) {
    return { variables: [], total: 0 }
  }
  const { distribution } = sdk.forProject(projectId)
  return await fetchAllVariables((queries) =>
    distribution.listVariables({ appId, queries }),
  )
}

export async function fetchDistributionConnections(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
  provider?: DistributionProvider,
): Promise<DistributionConnectionList> {
  if (!projectId) {
    return { connections: [], total: 0 }
  }
  return await sdk.forProject(projectId).distribution.listConnections({
    queries: [
      ...(provider ? [Query.equal('provider', [provider])] : []),
      ...pageQueries(page, limit, search),
    ],
  })
}

export async function fetchDistributionConnection(
  projectId: string,
  connectionId: string,
): Promise<DistributionConnection> {
  if (!projectId || !connectionId) {
    throw new Error('Project ID and Connection ID are required')
  }
  return await sdk
    .forProject(projectId)
    .distribution.getConnection({ connectionId })
}

export async function fetchDistributionSigningIdentities(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  platform?: DistributionPlatform,
): Promise<DistributionSigningIdentityList> {
  if (!projectId) {
    return { signingIdentities: [], total: 0 }
  }
  return await sdk.forProject(projectId).distribution.listSigningIdentities({
    queries: [
      ...(platform ? [Query.equal('platform', [platform])] : []),
      ...pageQueries(page, limit, ''),
    ],
  })
}

export async function fetchDistributionSigningIdentity(
  projectId: string,
  signingIdentityId: string,
): Promise<DistributionSigningIdentity> {
  if (!projectId || !signingIdentityId) {
    throw new Error('Project ID and Signing identity ID are required')
  }
  return await sdk
    .forProject(projectId)
    .distribution.getSigningIdentity({ signingIdentityId })
}

export async function fetchDistributionSigningIdentityDownload(
  projectId: string,
  signingIdentityId: string,
): Promise<DistributionSigningIdentityDownload> {
  if (!projectId || !signingIdentityId) {
    throw new Error('Project ID and Signing identity ID are required')
  }
  return await sdk
    .forProject(projectId)
    .distribution.getSigningIdentityDownload({ signingIdentityId })
}

export function distributionAppsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
) {
  return queryOptions({
    queryKey: ['distribution-apps', projectId, page, limit, search],
    queryFn: () => fetchDistributionApps(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function distributionAppQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-app', projectId, appId],
    queryFn: () => fetchDistributionApp(projectId!, appId!),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function distributionTargetsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-targets', projectId, appId],
    queryFn: () => fetchDistributionTargets(projectId!, appId!),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function distributionVariablesQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-variables', projectId, appId],
    queryFn: () => fetchDistributionVariables(projectId!, appId!),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && appId ? 5 * 60 * 1000 : 0,
  })
}

export function distributionConnectionsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
  provider?: DistributionProvider,
) {
  return queryOptions({
    queryKey: [
      'distribution-connections',
      projectId,
      page,
      limit,
      search,
      provider ?? null,
    ],
    queryFn: () =>
      fetchDistributionConnections(projectId!, page, limit, search, provider),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function distributionSigningIdentitiesQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  platform?: DistributionPlatform,
) {
  return queryOptions({
    queryKey: [
      'distribution-signing-identities',
      projectId,
      page,
      limit,
      platform ?? null,
    ],
    queryFn: () =>
      fetchDistributionSigningIdentities(projectId!, page, limit, platform),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useDistributionApps(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
) {
  const { data, isLoading, isFetching, isPlaceholderData, error, refetch } =
    useQuery(distributionAppsQueryOptions(projectId, page, limit, search))
  return {
    apps: data?.apps ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
    refetch,
  }
}

export function useDistributionApp(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  return useQuery(distributionAppQueryOptions(projectId, appId))
}

export function useDistributionTargets(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    distributionTargetsQueryOptions(projectId, appId),
  )
  return {
    targets: data?.targets ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useDistributionVariables(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit?: number,
) {
  const { data, isLoading, error, refetch } = useQuery(
    distributionVariablesQueryOptions(projectId, appId),
  )
  const all = data?.variables
  const { variables, total } = useMemo(() => {
    const list = all ?? []
    if (limit === undefined) {
      return { variables: list, total: list.length }
    }
    const start = page * limit
    return { variables: list.slice(start, start + limit), total: list.length }
  }, [all, page, limit])
  return { variables, total, isLoading, error, refetch }
}

export function useDistributionConnections(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
  provider?: DistributionProvider,
) {
  const { data, isLoading, isFetching, isPlaceholderData, error, refetch } =
    useQuery(
      distributionConnectionsQueryOptions(
        projectId,
        page,
        limit,
        search,
        provider,
      ),
    )
  return {
    connections: data?.connections ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
    refetch,
  }
}

export function useDistributionSigningIdentities(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  platform?: DistributionPlatform,
) {
  const { data, isLoading, isFetching, isPlaceholderData, error, refetch } =
    useQuery(
      distributionSigningIdentitiesQueryOptions(
        projectId,
        page,
        limit,
        platform,
      ),
    )
  return {
    signingIdentities: data?.signingIdentities ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
    refetch,
  }
}

async function refresh(
  queryClient: QueryClient,
  queryKey: QueryKey,
): Promise<void> {
  queryClient.removeQueries({ queryKey, type: 'inactive' })
  await queryClient.invalidateQueries({ queryKey })
}

function forget(queryClient: QueryClient, queryKey: QueryKey): void {
  queryClient.removeQueries({ queryKey, type: 'inactive' })
  const cache = queryClient.getQueryCache()
  const pending = new Set(
    cache.findAll({ queryKey }).map((query) => query.queryHash),
  )
  if (!pending.size) return
  const unsubscribe = cache.subscribe(({ type, query }) => {
    if (!pending.has(query.queryHash)) return
    if (type === 'observerRemoved' && !query.isActive()) {
      cache.remove(query)
      return
    }
    if (type !== 'removed') return
    pending.delete(query.queryHash)
    if (!pending.size) unsubscribe()
  })
}

export function useCreateDistributionApp(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: Omit<CreateDistributionAppParams, 'appId'>) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.createApp({ ...params, appId: ID.unique() }),
    onSuccess: async (app) => {
      queryClient.setQueryData(['distribution-app', projectId, app.$id], app)
      await refresh(queryClient, ['distribution-apps', projectId])
    },
  })
}

export function useUpdateDistributionApp(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: UpdateDistributionAppParams) =>
      sdk.forProject(requireProject(projectId)).distribution.updateApp(params),
    onSuccess: async (app) => {
      queryClient.setQueryData(['distribution-app', projectId, app.$id], app)
      await refresh(queryClient, ['distribution-apps', projectId])
    },
  })
}

export function useDeleteDistributionApp(
  projectId: string | null | undefined,
  onDeleted: () => Promise<unknown>,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (appId: string) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.deleteApp({ appId }),
    onSuccess: async (_, appId) => {
      const queryKeys = [
        'distribution-app',
        'distribution-targets',
        'distribution-variables',
        'distribution-builds',
        'distribution-build',
        'distribution-build-logs',
        'distribution-artifacts',
        'distribution-artifact',
        'distribution-releases',
        'distribution-release',
        'distribution-submissions',
        'distribution-submission',
        'distribution-check',
      ].map((resource) => [resource, projectId, appId])
      await Promise.all(
        queryKeys.map((queryKey) => queryClient.cancelQueries({ queryKey })),
      )
      await refresh(queryClient, ['distribution-apps', projectId])
      try {
        await onDeleted()
      } catch (error) {
        captureExceptionWithContext(error, {
          projectId: projectId ?? undefined,
          source: 'distribution-app-delete',
        })
      } finally {
        for (const queryKey of queryKeys) {
          queryClient.removeQueries({ queryKey })
        }
      }
    },
  })
}

export function useCreateDistributionTarget(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: Omit<CreateDistributionTargetParams, 'targetId'>) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.createTarget({ ...params, targetId: ID.unique() }),
    onSuccess: async (target) => {
      await refresh(queryClient, [
        'distribution-targets',
        projectId,
        target.appId,
      ])
    },
  })
}

export function useUpdateDistributionTarget(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: UpdateDistributionTargetParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.updateTarget(params),
    onSuccess: async (target) => {
      await refresh(queryClient, [
        'distribution-targets',
        projectId,
        target.appId,
      ])
    },
  })
}

export function useDeleteDistributionTarget(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: { appId: string; targetId: string }) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.deleteTarget(params),
    onSuccess: async (_, { appId }) => {
      await refresh(queryClient, ['distribution-targets', projectId, appId])
    },
  })
}

export function useCreateDistributionVariable(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (params: {
      key: string
      value: string
      secret?: boolean
    }) => {
      const ids = requireApp(projectId, appId)
      const key = params.key.trim()
      const validationError = validateVariables([{ key, value: params.value }])
      if (validationError) {
        throw new Error(validationError)
      }
      return await sdk.forProject(ids.projectId).distribution.createVariable({
        appId: ids.appId,
        variableId: ID.unique(),
        key,
        value: params.value,
        secret: params.secret,
      })
    },
    onSuccess: async () => {
      await refresh(queryClient, ['distribution-variables', projectId, appId])
    },
    gcTime: 0,
  })
}

export function useUpdateDistributionVariable(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (params: {
      variableId: string
      key: string
      value: string
      secret?: boolean
    }) => {
      const ids = requireApp(projectId, appId)
      const key = params.key.trim()
      if (!key) {
        throw new Error('Variable key is required')
      }
      const valueError = getVariableValueError(key, params.value)
      if (valueError) {
        throw new Error(valueError)
      }
      return await sdk.forProject(ids.projectId).distribution.updateVariable({
        appId: ids.appId,
        variableId: params.variableId,
        key,
        value: params.value,
        secret: params.secret,
      })
    },
    onSuccess: async () => {
      await refresh(queryClient, ['distribution-variables', projectId, appId])
    },
    gcTime: 0,
  })
}

export function useDeleteDistributionVariable(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (variableId: string) => {
      const ids = requireApp(projectId, appId)
      return await sdk
        .forProject(ids.projectId)
        .distribution.deleteVariable({ appId: ids.appId, variableId })
    },
    onSuccess: async () => {
      await refresh(queryClient, ['distribution-variables', projectId, appId])
    },
  })
}

function useRefreshDistributionConnections(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return () => refresh(queryClient, ['distribution-connections', projectId])
}

export function useCreateDistributionAppleConnection(
  projectId: string | null | undefined,
) {
  const onSuccess = useRefreshDistributionConnections(projectId)
  return useMutation({
    mutationFn: (
      params: Omit<CreateDistributionAppleConnectionParams, 'connectionId'>,
    ) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.createAppleConnection({
          ...params,
          connectionId: ID.unique(),
        }),
    onSuccess,
    gcTime: 0,
  })
}

export function useUpdateDistributionAppleConnection(
  projectId: string | null | undefined,
) {
  const onSuccess = useRefreshDistributionConnections(projectId)
  return useMutation({
    mutationFn: (params: UpdateDistributionAppleConnectionParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.updateAppleConnection(params),
    onSuccess,
    gcTime: 0,
  })
}

export function useCreateDistributionGoogleConnection(
  projectId: string | null | undefined,
) {
  const onSuccess = useRefreshDistributionConnections(projectId)
  return useMutation({
    mutationFn: (
      params: Omit<CreateDistributionGoogleConnectionParams, 'connectionId'>,
    ) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.createGoogleConnection({
          ...params,
          connectionId: ID.unique(),
        }),
    onSuccess,
    gcTime: 0,
  })
}

export function useUpdateDistributionGoogleConnection(
  projectId: string | null | undefined,
) {
  const onSuccess = useRefreshDistributionConnections(projectId)
  return useMutation({
    mutationFn: (params: UpdateDistributionGoogleConnectionParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.updateGoogleConnection(params),
    onSuccess,
    gcTime: 0,
  })
}

export function useDeleteDistributionConnection(
  projectId: string | null | undefined,
) {
  const onSuccess = useRefreshDistributionConnections(projectId)
  return useMutation({
    mutationFn: (connectionId: string) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.deleteConnection({ connectionId }),
    onSuccess,
    gcTime: 0,
  })
}

function useRefreshDistributionSigningIdentities(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return () =>
    refresh(queryClient, ['distribution-signing-identities', projectId])
}

export function useCreateDistributionIosSigningIdentity(
  projectId: string | null | undefined,
) {
  const onSuccess = useRefreshDistributionSigningIdentities(projectId)
  return useMutation({
    mutationFn: (
      params: Omit<
        CreateDistributionIosSigningIdentityParams,
        'signingIdentityId'
      >,
    ) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.createIosSigningIdentity({
          ...params,
          signingIdentityId: ID.unique(),
        }),
    onSuccess,
    gcTime: 0,
  })
}

export function useCreateDistributionAndroidSigningIdentity(
  projectId: string | null | undefined,
) {
  const onSuccess = useRefreshDistributionSigningIdentities(projectId)
  return useMutation({
    mutationFn: (
      params: Omit<
        CreateDistributionAndroidSigningIdentityParams,
        'signingIdentityId'
      >,
    ) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.createAndroidSigningIdentity({
          ...params,
          signingIdentityId: ID.unique(),
        }),
    onSuccess,
    gcTime: 0,
  })
}

export function useDeleteDistributionSigningIdentity(
  projectId: string | null | undefined,
) {
  const onSuccess = useRefreshDistributionSigningIdentities(projectId)
  return useMutation({
    mutationFn: (signingIdentityId: string) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.deleteSigningIdentity({ signingIdentityId }),
    onSuccess,
    gcTime: 0,
  })
}

const ALL_PAGE_SIZE = 100

async function fetchAllPages<T extends { $id: string }>(
  list: (queries: string[]) => Promise<{ total: number; items: T[] }>,
): Promise<T[]> {
  const items: T[] = []
  for (;;) {
    const last = items.at(-1)
    const { total, items: page } = await list([
      Query.orderDesc('$createdAt'),
      Query.limit(ALL_PAGE_SIZE),
      ...(last ? [Query.cursorAfter(last.$id)] : []),
    ])
    items.push(...page)
    if (page.length < ALL_PAGE_SIZE || items.length >= total) return items
  }
}

export async function fetchAllDistributionConnections(
  projectId: string,
  provider: DistributionProvider,
): Promise<DistributionConnection[]> {
  const { distribution } = sdk.forProject(requireProject(projectId))
  return await fetchAllPages(async (queries) => {
    const { total, connections } = await distribution.listConnections({
      queries: [Query.equal('provider', [provider]), ...queries],
    })
    return { total, items: connections }
  })
}

export async function fetchAllDistributionSigningIdentities(
  projectId: string,
  platform: DistributionPlatform,
): Promise<DistributionSigningIdentity[]> {
  const { distribution } = sdk.forProject(requireProject(projectId))
  return await fetchAllPages(async (queries) => {
    const { total, signingIdentities } =
      await distribution.listSigningIdentities({
        queries: [Query.equal('platform', [platform]), ...queries],
      })
    return { total, items: signingIdentities }
  })
}

export function allDistributionConnectionsQueryOptions(
  projectId: string | null | undefined,
  provider: DistributionProvider,
) {
  return queryOptions({
    queryKey: ['distribution-connections', projectId, 'all', provider],
    queryFn: () => fetchAllDistributionConnections(projectId!, provider),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function allDistributionSigningIdentitiesQueryOptions(
  projectId: string | null | undefined,
  platform: DistributionPlatform,
) {
  return queryOptions({
    queryKey: ['distribution-signing-identities', projectId, 'all', platform],
    queryFn: () => fetchAllDistributionSigningIdentities(projectId!, platform),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useAllDistributionConnections(
  projectId: string | null | undefined,
  provider: DistributionProvider,
) {
  const { data, isLoading, error } = useQuery(
    allDistributionConnectionsQueryOptions(projectId, provider),
  )
  return { connections: data ?? [], isLoading, error }
}

export function useAllDistributionSigningIdentities(
  projectId: string | null | undefined,
  platform: DistributionPlatform,
) {
  const { data, isLoading } = useQuery(
    allDistributionSigningIdentitiesQueryOptions(projectId, platform),
  )
  return { signingIdentities: data ?? [], isLoading }
}

export const DISTRIBUTION_LOGS_PAGE_SIZE = 100

const CHECK_STALE_TIME = 60 * 1000

const queryDefaults = {
  staleTime: DEFAULT_STALE_TIME,
  retry: false,
  refetchOnMount: false,
  refetchOnWindowFocus: false,
  refetchOnReconnect: false,
} as const

export type DistributionLogPage = LogPage<DistributionLog>

export const DISTRIBUTION_READY_ARTIFACT_STATUS: DistributionArtifactStatus =
  'ready'

export const DISTRIBUTION_OPEN_RELEASE_STATUSES: readonly DistributionReleaseStatus[] =
  ['draft', 'active']

function requireResource(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  resourceId: string | null | undefined,
  label: string,
): void {
  requireApp(projectId, appId)
  if (!resourceId) {
    throw new Error(`${label} ID is required`)
  }
}

function targetQueries(targetId: string | null | undefined): string[] {
  return targetId ? [Query.equal('targetId', [targetId])] : []
}

function latestQueries(targetId: string): string[] {
  return [
    Query.equal('targetId', [targetId]),
    Query.orderDesc('$createdAt'),
    Query.limit(1),
  ]
}

export async function fetchDistributionBuilds(
  projectId: string,
  appId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  targetId?: string | null,
): Promise<DistributionBuildList> {
  if (!projectId || !appId) {
    return { builds: [], total: 0 }
  }
  return await sdk.forProject(projectId).distribution.listBuilds({
    appId,
    queries: [...targetQueries(targetId), ...pageQueries(page, limit, '')],
  })
}

export async function fetchDistributionBuild(
  projectId: string,
  appId: string,
  buildId: string,
): Promise<DistributionBuild> {
  requireResource(projectId, appId, buildId, 'Build')
  return await sdk
    .forProject(projectId)
    .distribution.getBuild({ appId, buildId })
}

export async function fetchDistributionLatestBuild(
  projectId: string,
  appId: string,
  targetId: string,
): Promise<DistributionBuild | null> {
  if (!projectId || !appId || !targetId) {
    return null
  }
  const { builds } = await sdk.forProject(projectId).distribution.listBuilds({
    appId,
    queries: latestQueries(targetId),
    total: false,
  })
  return builds[0] ?? null
}

export async function fetchDistributionReleaseBuilds(
  projectId: string,
  appId: string,
  releaseId: string,
): Promise<DistributionBuild[]> {
  if (!projectId || !appId || !releaseId) {
    return []
  }
  const { distribution } = sdk.forProject(projectId)
  return await fetchAllPages(async (queries) => {
    const { total, builds } = await distribution.listBuilds({
      appId,
      queries: [Query.equal('releaseId', [releaseId]), ...queries],
    })
    return { total, items: builds }
  })
}

export async function fetchDistributionBuildLogs(
  projectId: string,
  appId: string,
  buildId: string,
  cursor: string | null = null,
): Promise<DistributionLogPage> {
  requireResource(projectId, appId, buildId, 'Build')
  const { logs } = await sdk.forProject(projectId).distribution.listBuildLogs({
    appId,
    buildId,
    queries: [
      Query.orderDesc('sequence'),
      Query.orderDesc('chunk'),
      Query.limit(DISTRIBUTION_LOGS_PAGE_SIZE),
      ...(cursor ? [Query.cursorAfter(cursor)] : []),
    ],
    total: false,
  })
  return {
    logs,
    more: logs.length === DISTRIBUTION_LOGS_PAGE_SIZE,
    cursor: logs.at(-1)?.$id ?? null,
    newest: logs[0]?.$id ?? null,
  }
}

export async function fetchNewerDistributionBuildLogs(
  projectId: string,
  appId: string,
  buildId: string,
  anchor: string | null,
  known: ReadonlySet<string>,
): Promise<DistributionLogPage> {
  const logs: DistributionLog[] = []
  const unseen = new Set(anchor ? [] : known)
  let cursor: string | null = null
  for (;;) {
    const page = await fetchDistributionBuildLogs(
      projectId,
      appId,
      buildId,
      cursor,
    )
    const end = anchor ? page.logs.findIndex((log) => log.$id === anchor) : -1
    const rows = end < 0 ? page.logs : page.logs.slice(0, end)
    logs.push(...rows)
    for (const log of rows) unseen.delete(log.$id)
    cursor = page.cursor
    if (end >= 0 || !page.more || (!anchor && !unseen.size)) {
      return {
        logs,
        more: page.more,
        cursor,
        newest: logs[0]?.$id ?? null,
      }
    }
  }
}

type LogSync = { again: boolean; promise: Promise<void> }

const logSyncs = new WeakMap<QueryClient, Map<string, LogSync>>()

async function syncDistributionBuildLogsOnce(
  queryClient: QueryClient,
  projectId: string,
  appId: string,
  buildId: string,
): Promise<void> {
  const { queryKey } = distributionBuildLogsQueryOptions(
    projectId,
    appId,
    buildId,
  )
  const data = queryClient.getQueryData(queryKey)
  if (!data) {
    await queryClient.refetchQueries(
      { queryKey, exact: true, type: 'active' },
      { cancelRefetch: false },
    )
    return
  }
  const anchor = data.pages[0]?.newest ?? null
  const known = new Set(
    data.pages.flatMap((page) => page.logs.map((log) => log.$id)),
  )
  const fetched = await fetchNewerDistributionBuildLogs(
    projectId,
    appId,
    buildId,
    anchor,
    known,
  )
  queryClient.setQueryData(queryKey, (old) =>
    mergeNewerLogs(old, fetched, anchor),
  )
}

export function syncDistributionBuildLogs(
  queryClient: QueryClient,
  projectId: string,
  appId: string,
  buildId: string,
): Promise<void> {
  let syncs = logSyncs.get(queryClient)
  if (!syncs) {
    syncs = new Map()
    logSyncs.set(queryClient, syncs)
  }
  const key = JSON.stringify([projectId, appId, buildId])
  const running = syncs.get(key)
  if (running) {
    running.again = true
    return running.promise
  }
  const sync: LogSync = { again: false, promise: Promise.resolve() }
  sync.promise = (async () => {
    try {
      do {
        sync.again = false
        await syncDistributionBuildLogsOnce(
          queryClient,
          projectId,
          appId,
          buildId,
        )
      } while (sync.again)
    } finally {
      syncs.delete(key)
    }
  })()
  syncs.set(key, sync)
  return sync.promise
}

export async function fetchDistributionArtifacts(
  projectId: string,
  appId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  targetId?: string | null,
): Promise<DistributionArtifactList> {
  if (!projectId || !appId) {
    return { artifacts: [], total: 0 }
  }
  return await sdk.forProject(projectId).distribution.listArtifacts({
    appId,
    queries: [...targetQueries(targetId), ...pageQueries(page, limit, '')],
  })
}

export async function fetchReadyDistributionArtifacts(
  projectId: string,
  appId: string,
  targetId: string,
): Promise<DistributionArtifact[]> {
  if (!projectId || !appId || !targetId) {
    return []
  }
  const { distribution } = sdk.forProject(projectId)
  return await fetchAllPages(async (queries) => {
    const { total, artifacts } = await distribution.listArtifacts({
      appId,
      queries: [
        Query.equal('targetId', [targetId]),
        Query.equal('status', [DISTRIBUTION_READY_ARTIFACT_STATUS]),
        ...queries,
      ],
    })
    return { total, items: artifacts }
  })
}

export async function fetchDistributionArtifact(
  projectId: string,
  appId: string,
  artifactId: string,
): Promise<DistributionArtifact> {
  requireResource(projectId, appId, artifactId, 'Artifact')
  return await sdk
    .forProject(projectId)
    .distribution.getArtifact({ appId, artifactId })
}

export async function fetchDistributionReleases(
  projectId: string,
  appId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
): Promise<DistributionReleaseList> {
  if (!projectId || !appId) {
    return { releases: [], total: 0 }
  }
  return await sdk.forProject(projectId).distribution.listReleases({
    appId,
    queries: pageQueries(page, limit, search),
  })
}

export async function fetchOpenDistributionReleases(
  projectId: string,
  appId: string,
): Promise<DistributionRelease[]> {
  if (!projectId || !appId) {
    return []
  }
  const { distribution } = sdk.forProject(projectId)
  return await fetchAllPages(async (queries) => {
    const { total, releases } = await distribution.listReleases({
      appId,
      queries: [
        Query.equal('status', [...DISTRIBUTION_OPEN_RELEASE_STATUSES]),
        ...queries,
      ],
    })
    return { total, items: releases }
  })
}

export async function fetchDistributionRelease(
  projectId: string,
  appId: string,
  releaseId: string,
): Promise<DistributionRelease> {
  requireResource(projectId, appId, releaseId, 'Release')
  return await sdk
    .forProject(projectId)
    .distribution.getRelease({ appId, releaseId })
}

export async function fetchDistributionSubmissions(
  projectId: string,
  appId: string,
  releaseId: string,
): Promise<DistributionSubmission[]> {
  if (!projectId || !appId || !releaseId) {
    return []
  }
  const { distribution } = sdk.forProject(projectId)
  return await fetchAllPages(async (queries) => {
    const { total, submissions } = await distribution.listSubmissions({
      appId,
      queries: [Query.equal('releaseId', [releaseId]), ...queries],
    })
    return { total, items: submissions }
  })
}

export async function fetchDistributionLatestSubmission(
  projectId: string,
  appId: string,
  targetId: string,
): Promise<DistributionSubmission | null> {
  if (!projectId || !appId || !targetId) {
    return null
  }
  const { submissions } = await sdk
    .forProject(projectId)
    .distribution.listSubmissions({
      appId,
      queries: latestQueries(targetId),
      total: false,
    })
  return submissions[0] ?? null
}

export async function fetchDistributionCheck(
  projectId: string,
  appId: string,
  targetId: string,
  track?: string | null,
): Promise<DistributionCheck> {
  requireResource(projectId, appId, targetId, 'Target')
  return await sdk
    .forProject(projectId)
    .distribution.createCheck({ appId, targetId, track: track ?? undefined })
}

export function distributionBuildsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  targetId?: string | null,
) {
  return queryOptions({
    queryKey: [
      'distribution-builds',
      projectId,
      appId,
      page,
      limit,
      targetId ?? null,
    ],
    queryFn: () =>
      fetchDistributionBuilds(projectId!, appId!, page, limit, targetId),
    enabled: !!projectId && !!appId,
    ...queryDefaults,
    placeholderData: keepPreviousData,
  })
}

export function distributionBuildQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  buildId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-build', projectId, appId, buildId],
    queryFn: () => fetchDistributionBuild(projectId!, appId!, buildId!),
    enabled: !!projectId && !!appId && !!buildId,
    ...queryDefaults,
  })
}

export function distributionLatestBuildQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  targetId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-builds', projectId, appId, 'latest', targetId],
    queryFn: () => fetchDistributionLatestBuild(projectId!, appId!, targetId!),
    enabled: !!projectId && !!appId && !!targetId,
    ...queryDefaults,
  })
}

export function distributionReleaseBuildsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  releaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-builds', projectId, appId, 'release', releaseId],
    queryFn: () =>
      fetchDistributionReleaseBuilds(projectId!, appId!, releaseId!),
    enabled: !!projectId && !!appId && !!releaseId,
    ...queryDefaults,
    placeholderData: keepPreviousData,
  })
}

export function distributionBuildLogsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  buildId: string | null | undefined,
) {
  return infiniteQueryOptions({
    queryKey: ['distribution-build-logs', projectId, appId, buildId],
    queryFn: ({ pageParam }) =>
      fetchDistributionBuildLogs(projectId!, appId!, buildId!, pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (last: DistributionLogPage) =>
      last.more ? last.cursor : undefined,
    enabled: !!projectId && !!appId && !!buildId,
    ...queryDefaults,
  })
}

export function distributionArtifactsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  targetId?: string | null,
) {
  return queryOptions({
    queryKey: [
      'distribution-artifacts',
      projectId,
      appId,
      page,
      limit,
      targetId ?? null,
    ],
    queryFn: () =>
      fetchDistributionArtifacts(projectId!, appId!, page, limit, targetId),
    enabled: !!projectId && !!appId,
    ...queryDefaults,
    placeholderData: keepPreviousData,
  })
}

export function readyDistributionArtifactsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  targetId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-artifacts', projectId, appId, 'ready', targetId],
    queryFn: () =>
      fetchReadyDistributionArtifacts(projectId!, appId!, targetId!),
    enabled: !!projectId && !!appId && !!targetId,
    ...queryDefaults,
    placeholderData: keepPreviousData,
  })
}

export function distributionArtifactQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  artifactId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-artifact', projectId, appId, artifactId],
    queryFn: () => fetchDistributionArtifact(projectId!, appId!, artifactId!),
    enabled: !!projectId && !!appId && !!artifactId,
    ...queryDefaults,
  })
}

export function distributionReleasesQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
) {
  return queryOptions({
    queryKey: ['distribution-releases', projectId, appId, page, limit, search],
    queryFn: () =>
      fetchDistributionReleases(projectId!, appId!, page, limit, search),
    enabled: !!projectId && !!appId,
    ...queryDefaults,
    placeholderData: keepPreviousData,
  })
}

export function openDistributionReleasesQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-releases', projectId, appId, 'open'],
    queryFn: () => fetchOpenDistributionReleases(projectId!, appId!),
    enabled: !!projectId && !!appId,
    ...queryDefaults,
    placeholderData: keepPreviousData,
  })
}

export function distributionReleaseQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  releaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-release', projectId, appId, releaseId],
    queryFn: () => fetchDistributionRelease(projectId!, appId!, releaseId!),
    enabled: !!projectId && !!appId && !!releaseId,
    ...queryDefaults,
  })
}

export function distributionSubmissionsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  releaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'distribution-submissions',
      projectId,
      appId,
      'release',
      releaseId,
    ],
    queryFn: () => fetchDistributionSubmissions(projectId!, appId!, releaseId!),
    enabled: !!projectId && !!appId && !!releaseId,
    ...queryDefaults,
    placeholderData: keepPreviousData,
  })
}

export function distributionLatestSubmissionQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  targetId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'distribution-submissions',
      projectId,
      appId,
      'latest',
      targetId,
    ],
    queryFn: () =>
      fetchDistributionLatestSubmission(projectId!, appId!, targetId!),
    enabled: !!projectId && !!appId && !!targetId,
    ...queryDefaults,
  })
}

export function distributionCheckQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  targetId: string | null | undefined,
  track?: string | null,
) {
  return queryOptions({
    queryKey: ['distribution-check', projectId, appId, targetId, track ?? null],
    queryFn: () => fetchDistributionCheck(projectId!, appId!, targetId!, track),
    enabled: !!projectId && !!appId && !!targetId,
    ...queryDefaults,
    staleTime: CHECK_STALE_TIME,
  })
}

export function useDistributionBuilds(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  targetId?: string | null,
) {
  const { data, isLoading, isFetching, isPlaceholderData, error, refetch } =
    useQuery(
      distributionBuildsQueryOptions(projectId, appId, page, limit, targetId),
    )
  return {
    builds: data?.builds ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
    refetch,
  }
}

export function useDistributionBuild(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  buildId: string | null | undefined,
) {
  return useQuery(distributionBuildQueryOptions(projectId, appId, buildId))
}

export function useDistributionLatestBuild(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  targetId: string | null | undefined,
) {
  return useQuery(
    distributionLatestBuildQueryOptions(projectId, appId, targetId),
  )
}

export function useDistributionReleaseBuilds(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  releaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    distributionReleaseBuildsQueryOptions(projectId, appId, releaseId),
  )
  return { builds: data ?? [], isLoading, isFetching, error, refetch }
}

const NO_LOG_PAGES: DistributionLogPage[] = []

export function useDistributionBuildLogs(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  buildId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  const {
    data,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isLoading,
    error,
    refetch,
  } = useInfiniteQuery(
    distributionBuildLogsQueryOptions(projectId, appId, buildId),
  )
  const sync = useCallback(
    () =>
      syncDistributionBuildLogs(
        queryClient,
        projectId ?? '',
        appId ?? '',
        buildId ?? '',
      ),
    [queryClient, projectId, appId, buildId],
  )
  return {
    pages: data?.pages ?? NO_LOG_PAGES,
    sync,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isLoading,
    error,
    refetch,
  }
}

export function useDistributionArtifacts(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  targetId?: string | null,
) {
  const { data, isLoading, isFetching, isPlaceholderData, error, refetch } =
    useQuery(
      distributionArtifactsQueryOptions(
        projectId,
        appId,
        page,
        limit,
        targetId,
      ),
    )
  return {
    artifacts: data?.artifacts ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
    refetch,
  }
}

export function useReadyDistributionArtifacts(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  targetId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    readyDistributionArtifactsQueryOptions(projectId, appId, targetId),
  )
  return { artifacts: data ?? [], isLoading, isFetching, error, refetch }
}

export function useDistributionArtifact(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  artifactId: string | null | undefined,
) {
  return useQuery(
    distributionArtifactQueryOptions(projectId, appId, artifactId),
  )
}

export function useDistributionReleases(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search: string = '',
) {
  const { data, isLoading, isFetching, isPlaceholderData, error, refetch } =
    useQuery(
      distributionReleasesQueryOptions(projectId, appId, page, limit, search),
    )
  return {
    releases: data?.releases ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    isPlaceholderData,
    error,
    refetch,
  }
}

export function useOpenDistributionReleases(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    openDistributionReleasesQueryOptions(projectId, appId),
  )
  return { releases: data ?? [], isLoading, isFetching, error, refetch }
}

export function useDistributionRelease(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  releaseId: string | null | undefined,
) {
  return useQuery(distributionReleaseQueryOptions(projectId, appId, releaseId))
}

export function useDistributionSubmissions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  releaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    distributionSubmissionsQueryOptions(projectId, appId, releaseId),
  )
  return { submissions: data ?? [], isLoading, isFetching, error, refetch }
}

export function useDistributionLatestSubmission(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  targetId: string | null | undefined,
) {
  return useQuery(
    distributionLatestSubmissionQueryOptions(projectId, appId, targetId),
  )
}

export function useDistributionCheck(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  targetId: string | null | undefined,
  track?: string | null,
) {
  return useQuery(
    distributionCheckQueryOptions(projectId, appId, targetId, track),
  )
}

async function refreshBuilds(
  queryClient: QueryClient,
  projectId: string | null | undefined,
  appId: string,
): Promise<void> {
  await Promise.all([
    refresh(queryClient, ['distribution-builds', projectId, appId]),
    refresh(queryClient, ['distribution-app', projectId, appId]),
  ])
}

async function refreshSubmission(
  queryClient: QueryClient,
  projectId: string | null | undefined,
  appId: string,
  submission: DistributionSubmission,
): Promise<void> {
  queryClient.setQueryData(
    ['distribution-submission', projectId, appId, submission.$id],
    submission,
  )
  await Promise.all([
    refresh(queryClient, ['distribution-submissions', projectId, appId]),
    refresh(queryClient, [
      'distribution-release',
      projectId,
      appId,
      submission.releaseId,
    ]),
    refresh(queryClient, ['distribution-releases', projectId, appId]),
  ])
}

export function useCreateDistributionBuild(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: CreateDistributionBuildParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.createBuild(params),
    onSuccess: async (build, { appId }) => {
      queryClient.setQueryData(
        ['distribution-build', projectId, appId, build.$id],
        build,
      )
      await refreshBuilds(queryClient, projectId, appId)
    },
  })
}

export function useUpdateDistributionBuildStatus(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: UpdateDistributionBuildStatusParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.updateBuildStatus(params),
    onSuccess: async (build, { appId, buildId }) => {
      queryClient.setQueryData(
        ['distribution-build', projectId, appId, buildId],
        build,
      )
      await refreshBuilds(queryClient, projectId, appId)
    },
  })
}

export function useDeleteDistributionBuild(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: { appId: string; buildId: string }) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.deleteBuild(params),
    onSuccess: async (_, { appId, buildId }) => {
      forget(queryClient, ['distribution-build', projectId, appId, buildId])
      forget(queryClient, [
        'distribution-build-logs',
        projectId,
        appId,
        buildId,
      ])
      await refreshBuilds(queryClient, projectId, appId)
    },
  })
}

export function useDeleteDistributionArtifact(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: { appId: string; artifactId: string }) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.deleteArtifact(params),
    onSuccess: async (_, { appId, artifactId }) => {
      forget(queryClient, [
        'distribution-artifact',
        projectId,
        appId,
        artifactId,
      ])
      await refresh(queryClient, ['distribution-artifacts', projectId, appId])
    },
  })
}

export function useCreateDistributionRelease(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (
      params: Omit<CreateDistributionReleaseParams, 'releaseId'> & {
        releaseId?: string
      },
    ) =>
      sdk.forProject(requireProject(projectId)).distribution.createRelease({
        ...params,
        releaseId: params.releaseId ?? ID.unique(),
      }),
    onSuccess: async (release, { appId }) => {
      queryClient.setQueryData(
        ['distribution-release', projectId, appId, release.$id],
        release,
      )
      await refresh(queryClient, ['distribution-releases', projectId, appId])
    },
  })
}

export function useUpdateDistributionRelease(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: UpdateDistributionReleaseParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.updateRelease(params),
    onSuccess: async (release, { appId, releaseId }) => {
      queryClient.setQueryData(
        ['distribution-release', projectId, appId, releaseId],
        release,
      )
      await refresh(queryClient, ['distribution-releases', projectId, appId])
    },
  })
}

export function useDeleteDistributionRelease(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: { appId: string; releaseId: string }) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.deleteRelease(params),
    onSuccess: async (_, { appId, releaseId }) => {
      forget(queryClient, ['distribution-release', projectId, appId, releaseId])
      await Promise.all([
        refresh(queryClient, ['distribution-releases', projectId, appId]),
        refresh(queryClient, [
          'distribution-builds',
          projectId,
          appId,
          'release',
          releaseId,
        ]),
        refresh(queryClient, ['distribution-submissions', projectId, appId]),
      ])
    },
  })
}

export function useCreateDistributionSubmission(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: CreateDistributionSubmissionParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.createSubmission(params),
    onSuccess: (submission, { appId }) =>
      refreshSubmission(queryClient, projectId, appId, submission),
  })
}

export function useUpdateDistributionSubmission(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: UpdateDistributionSubmissionParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.updateSubmission(params),
    onSuccess: (submission, { appId }) =>
      refreshSubmission(queryClient, projectId, appId, submission),
  })
}

export function useUpdateDistributionSubmissionStatus(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: UpdateDistributionSubmissionStatusParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.updateSubmissionStatus(params),
    onSuccess: (submission, { appId }) =>
      refreshSubmission(queryClient, projectId, appId, submission),
  })
}

export function useCreateDistributionCheck(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: CreateDistributionCheckParams) =>
      sdk
        .forProject(requireProject(projectId))
        .distribution.createCheck(params),
    onSuccess: (check, { appId, targetId, track }) => {
      queryClient.setQueryData(
        ['distribution-check', projectId, appId, targetId, track ?? null],
        check,
      )
    },
  })
}
