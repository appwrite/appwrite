import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import type { QueryClient, QueryKey } from '@tanstack/react-query'
import { useMemo } from 'react'
import { ID, Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type {
  CreateDistributionAndroidSigningIdentityParams,
  CreateDistributionAppleConnectionParams,
  CreateDistributionAppParams,
  CreateDistributionGoogleConnectionParams,
  CreateDistributionIosSigningIdentityParams,
  CreateDistributionTargetParams,
  DistributionApp,
  DistributionAppList,
  DistributionConnection,
  DistributionConnectionList,
  DistributionPlatform,
  DistributionProvider,
  DistributionSigningIdentity,
  DistributionSigningIdentityDownload,
  DistributionSigningIdentityList,
  DistributionTargetList,
  UpdateDistributionAppleConnectionParams,
  UpdateDistributionAppParams,
  UpdateDistributionGoogleConnectionParams,
  UpdateDistributionTargetParams,
} from '@/lib/appwrite/distribution'
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
