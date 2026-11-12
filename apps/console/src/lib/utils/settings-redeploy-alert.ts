import { useEffect } from 'react'
import {
  useQuery,
  useQueryClient,
  type QueryClient,
  type QueryKey,
} from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
  isDeploymentInProgress,
  isLatestBuildAfterResourceUpdate,
  keepNewerLatestDeployment,
  patchResourceAfterRedeploy,
} from '@/lib/utils/deployment-status'

export type SettingsRedeployResourceType = 'function' | 'site'

export function settingsRedeployPendingQueryKey(
  resourceType: SettingsRedeployResourceType,
  projectId: string,
  resourceId: string,
) {
  return [
    'settings-redeploy-pending',
    resourceType,
    projectId,
    resourceId,
  ] as const
}

type SpecSettingsResource = {
  live?: boolean
  runtimeSpecification?: string
  buildSpecification?: string
}

/**
 * Spec updates do not set `live=false` on the API (it only cold-starts the
 * current runtime). Without this, the settings redeploy alert never appears.
 */
export function withRedeployAlertIfSpecsChanged<T extends SpecSettingsResource>(
  previous: T | null | undefined,
  updated: T,
): T {
  if (!previous) return updated

  const specsChanged =
    (previous.runtimeSpecification || '') !==
      (updated.runtimeSpecification || '') ||
    (previous.buildSpecification || '') !== (updated.buildSpecification || '')

  if (!specsChanged) return updated

  return { ...updated, live: false }
}

export function cacheUpdatedFunctionOrSite<T extends SpecSettingsResource>(
  queryClient: QueryClient,
  queryKey: QueryKey,
  updated: T,
) {
  const previous = queryClient.getQueryData<T>(queryKey)
  queryClient.setQueryData(
    queryKey,
    keepNewerLatestDeployment(
      previous,
      withRedeployAlertIfSpecsChanged(previous, updated),
    ),
  )
}

export function markSettingsRedeployPending(
  queryClient: QueryClient,
  resourceType: SettingsRedeployResourceType,
  projectId: string,
  resourceId: string,
) {
  queryClient.setQueryData(
    settingsRedeployPendingQueryKey(resourceType, projectId, resourceId),
    true,
  )
}

export function clearSettingsRedeployPending(
  queryClient: QueryClient,
  resourceType: SettingsRedeployResourceType,
  projectId: string,
  resourceId: string,
) {
  queryClient.setQueryData(
    settingsRedeployPendingQueryKey(resourceType, projectId, resourceId),
    false,
  )
}

function normalizeRedeployDeploymentStatus(status: string): string {
  return isDeploymentInProgress(status) ? status : 'processing'
}

/** Apply redeploy result to cache and mark the settings alert as acknowledged. */
export async function applySettingsRedeploySuccess(
  queryClient: QueryClient,
  options: {
    resourceType: SettingsRedeployResourceType
    projectId: string
    resourceId: string
    resourceQueryKey: QueryKey
    deploymentQueryKey: QueryKey
    deploymentsQueryKey: QueryKey
    deployment: Models.Deployment
  },
) {
  const status = normalizeRedeployDeploymentStatus(options.deployment.status)
  const patchedDeployment = { ...options.deployment, status }

  queryClient.setQueryData(
    options.resourceQueryKey,
    (
      current:
        | {
            latestDeploymentId?: string | null
            latestDeploymentStatus?: string
          }
        | undefined,
    ) =>
      current
        ? patchResourceAfterRedeploy(current, patchedDeployment)
        : current,
  )
  queryClient.setQueryData(options.deploymentQueryKey, patchedDeployment)
  markSettingsRedeployPending(
    queryClient,
    options.resourceType,
    options.projectId,
    options.resourceId,
  )

  await queryClient.refetchQueries({
    queryKey: options.deploymentsQueryKey,
  })
}

export function useSettingsRedeployPending(
  resourceType: SettingsRedeployResourceType,
  projectId: string | null | undefined,
  resourceId: string | null | undefined,
  resource?: {
    live?: boolean
    $updatedAt?: string
    latestDeploymentCreatedAt?: string
    latestDeploymentStatus?: string
  } | null,
) {
  const queryClient = useQueryClient()
  const enabled = !!projectId && !!resourceId

  const { data: pending = false } = useQuery({
    queryKey: settingsRedeployPendingQueryKey(
      resourceType,
      projectId ?? '',
      resourceId ?? '',
    ),
    queryFn: () => false,
    enabled,
    initialData: false,
    staleTime: Infinity,
    gcTime: Infinity,
  })

  useEffect(() => {
    if (!enabled || !pending || !projectId || !resourceId) return

    const resourceQueryKey =
      resourceType === 'function'
        ? (['function', 'project', projectId, resourceId] as const)
        : (['site', 'project', projectId, resourceId] as const)

    const interval = setInterval(() => {
      void queryClient.refetchQueries({ queryKey: resourceQueryKey })
    }, 5000)

    return () => clearInterval(interval)
  }, [enabled, pending, projectId, resourceId, queryClient, resourceType])

  useEffect(() => {
    if (!enabled || !pending || !resource) return

    if (resource.live) {
      clearSettingsRedeployPending(
        queryClient,
        resourceType,
        projectId!,
        resourceId!,
      )
      return
    }

    if (
      !resource.live &&
      !isLatestBuildAfterResourceUpdate(resource)
    ) {
      clearSettingsRedeployPending(
        queryClient,
        resourceType,
        projectId!,
        resourceId!,
      )
      return
    }

    if (
      resource.latestDeploymentStatus === 'failed' &&
      isLatestBuildAfterResourceUpdate(resource)
    ) {
      clearSettingsRedeployPending(
        queryClient,
        resourceType,
        projectId!,
        resourceId!,
      )
    }
  }, [
    enabled,
    pending,
    resource?.live,
    resource?.latestDeploymentStatus,
    queryClient,
    resourceType,
    projectId,
    resourceId,
    resource,
  ])

  return pending
}
