import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'

type DeploymentWithScreenshots = Models.Deployment & {
  screenshotDark?: string
  screenshotLight?: string
}

/**
 * Merge list + detail deployment rows for the active-deployment card.
 * List queries often use Query.select and omit screenshot fields (or return
 * empty strings); keep non-empty screenshot IDs from the full deployment.
 */
export function mergeActiveDeploymentForCard(
  fromHook: Models.Deployment | null | undefined,
  resolved: Models.Deployment | null | undefined,
): Models.Deployment | undefined {
  if (!resolved) return undefined
  if (fromHook?.$id !== resolved.$id) return resolved

  const hook = fromHook as DeploymentWithScreenshots
  const list = resolved as DeploymentWithScreenshots
  const merged = {
    ...hook,
    ...list,
  } as DeploymentWithScreenshots

  merged.screenshotDark = list.screenshotDark || hook.screenshotDark
  merged.screenshotLight = list.screenshotLight || hook.screenshotLight

  return merged
}

/**
 * Refresh site/list/deployment caches so dashboard previews see screenshots
 * that arrived via realtime while the create wizard was open.
 */
export async function refetchSitePreviewCaches(
  queryClient: QueryClient,
  projectId: string,
  siteId: string,
  deploymentId?: string | null,
): Promise<void> {
  await Promise.all([
    queryClient.refetchQueries({
      queryKey: ['site', 'project', projectId, siteId],
    }),
    queryClient.refetchQueries({
      queryKey: ['sites', 'project', projectId],
    }),
    queryClient.refetchQueries({
      queryKey: ['deployments', 'site', projectId, siteId],
      exact: false,
    }),
    deploymentId
      ? queryClient.refetchQueries({
          queryKey: ['deployment', 'site', projectId, siteId, deploymentId],
        })
      : Promise.resolve(),
  ])
}

export function deploymentHasScreenshot(
  deployment: Models.Deployment | null | undefined,
): boolean {
  if (!deployment) return false
  const shot = deployment as DeploymentWithScreenshots
  return Boolean(shot.screenshotDark || shot.screenshotLight)
}
