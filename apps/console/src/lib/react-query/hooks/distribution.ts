import {
  useQuery,
  useMutation,
  useQueryClient,
  queryOptions,
} from '@tanstack/react-query'
import { Query, ID } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, DEFAULT_PAGE_SIZE } from './constants'

export interface DistributionApp {
  $id: string
  $createdAt: string
  $updatedAt: string
  name: string
  platforms: string[]
  framework: string
  buildRuntime: string
  rootDirectory: string
  buildCommand: string
  outputArtifact: string
  applicationId: string
  bundleId: string
  packageIdentity: string
  versionStrategy: string
  androidConnectionId: string
  iosConnectionId: string
  windowsConnectionId: string
  signingMode: string
  autoSubmit: boolean
  defaultTrack: string
  buildSpecification: string
  runtimeSpecification: string
  enabled: boolean
  logging: boolean
  teamId: string
  userId: string
}

export interface DistributionBuild {
  $id: string
  $createdAt: string
  $updatedAt: string
  resourceId: string
  platform: string
  artifactType: string
  parentDeploymentId: string
  versionName: string
  versionCode: number
  status: string
  buildSize: number
  buildDuration: number
}

export interface DistributionSubmission {
  $id: string
  $createdAt: string
  $updatedAt: string
  appId: string
  deploymentId: string
  provider: string
  track: string
  status: string
  storeReleaseId: string
  rolloutFraction: number
  releaseNotes: string
  errorMessage: string
}

export interface DistributionAppList {
  apps: DistributionApp[]
  total: number
}

export interface DistributionBuildList {
  builds: DistributionBuild[]
  total: number
}

export interface DistributionSubmissionList {
  submissions: DistributionSubmission[]
  total: number
}

export interface CreateDistributionAppParams {
  name: string
  platforms: string[]
  framework: string
  applicationId?: string
  bundleId?: string
  packageIdentity?: string
  teamId?: string
}

export const DISTRIBUTION_DEFAULT_SORT_BY = '$createdAt'
export const DISTRIBUTION_DEFAULT_SORT_ORDER = 'desc' as const

function distributionUrl(projectId: string, path = ''): URL {
  const { client } = sdk.forProject(projectId)
  return new URL(`${client.config.endpoint}/distribution${path}`)
}

export async function fetchDistributionApps(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
): Promise<DistributionAppList> {
  if (!projectId) {
    return { apps: [], total: 0 }
  }
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]
  const res = (await sdk.forProject(projectId).client.call(
    'get',
    distributionUrl(projectId, '/apps'),
    {},
    {
      queries,
      search: search?.trim() || undefined,
    },
  )) as DistributionAppList
  return { apps: res.apps ?? [], total: res.total ?? 0 }
}

export async function fetchDistributionApp(
  projectId: string,
  appId: string,
): Promise<DistributionApp> {
  if (!projectId || !appId) {
    throw new Error('Project ID and App ID are required')
  }
  return (await sdk
    .forProject(projectId)
    .client.call(
      'get',
      distributionUrl(projectId, `/apps/${appId}`),
      {},
      {},
    )) as DistributionApp
}

export async function fetchDistributionBuilds(
  projectId: string,
  appId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<DistributionBuildList> {
  if (!projectId || !appId) {
    return { builds: [], total: 0 }
  }
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]
  const res = (await sdk
    .forProject(projectId)
    .client.call(
      'get',
      distributionUrl(projectId, `/apps/${appId}/builds`),
      {},
      { queries },
    )) as DistributionBuildList
  return { builds: res.builds ?? [], total: res.total ?? 0 }
}

export async function fetchDistributionSubmissions(
  projectId: string,
  appId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<DistributionSubmissionList> {
  if (!projectId || !appId) {
    return { submissions: [], total: 0 }
  }
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]
  const res = (await sdk
    .forProject(projectId)
    .client.call(
      'get',
      distributionUrl(projectId, `/apps/${appId}/submissions`),
      {},
      { queries },
    )) as DistributionSubmissionList
  return { submissions: res.submissions ?? [], total: res.total ?? 0 }
}

export async function createDistributionApp(
  projectId: string,
  params: CreateDistributionAppParams,
): Promise<DistributionApp> {
  if (!projectId) {
    throw new Error('Project ID is required')
  }
  const payload: Record<string, unknown> = {
    appId: ID.unique(),
    name: params.name,
    platforms: params.platforms,
    framework: params.framework,
  }
  if (params.applicationId) payload.applicationId = params.applicationId
  if (params.bundleId) payload.bundleId = params.bundleId
  if (params.packageIdentity) payload.packageIdentity = params.packageIdentity
  if (params.teamId) payload.teamId = params.teamId

  return (await sdk
    .forProject(projectId)
    .client.call(
      'post',
      distributionUrl(projectId, '/apps'),
      { 'content-type': 'application/json' },
      payload,
    )) as DistributionApp
}

export function distributionAppsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: ['distribution-apps', 'project', projectId, page, limit, search],
    queryFn: () => fetchDistributionApps(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function distributionAppQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-app', 'project', projectId, appId],
    queryFn: () => fetchDistributionApp(projectId!, appId!),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function distributionBuildsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['distribution-builds', 'project', projectId, appId, page, limit],
    queryFn: () => fetchDistributionBuilds(projectId!, appId!, page, limit),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function distributionSubmissionsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: [
      'distribution-submissions',
      'project',
      projectId,
      appId,
      page,
      limit,
    ],
    queryFn: () =>
      fetchDistributionSubmissions(projectId!, appId!, page, limit),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useDistributionApps(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    distributionAppsQueryOptions(projectId, page, limit, search),
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

export function useDistributionApp(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  return useQuery(distributionAppQueryOptions(projectId, appId))
}

export function useDistributionBuilds(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    distributionBuildsQueryOptions(projectId, appId, page, limit),
  )
  return {
    builds: data?.builds ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useDistributionSubmissions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    distributionSubmissionsQueryOptions(projectId, appId, page, limit),
  )
  return {
    submissions: data?.submissions ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useCreateDistributionApp(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: CreateDistributionAppParams) =>
      createDistributionApp(projectId!, params),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ['distribution-apps', 'project', projectId],
      })
    },
  })
}
