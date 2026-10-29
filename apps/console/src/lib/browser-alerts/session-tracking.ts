/**
 * Persist in-progress build tracking across the permission-grant reload.
 * Chrome on macOS needs a reload after the first Notification permission
 * grant; without this snapshot the reload drops tracked builds and completion
 * alerts never fire for builds that were already running.
 */

const SESSION_KEY = 'console.buildNotifications.trackedBuilds'

interface StoredBuildTracking {
  projectId: string
  builds: Array<{
    deploymentId: string
    resourceId: string
    resourceType: 'site' | 'function'
    status: string
    createdAt?: string | null
  }>
}

export function saveBuildTrackingBeforeReload(
  projectId: string,
  builds: Iterable<{
    deploymentId: string
    resourceId: string
    resourceType: 'site' | 'function'
    status: string
    createdAt?: string | null
  }>,
  trackedDeploymentIds: Iterable<string>,
): void {
  if (typeof window === 'undefined') return
  const tracked = new Set(trackedDeploymentIds)
  const snapshot = Array.from(builds).filter((build) =>
    tracked.has(build.deploymentId),
  )
  if (snapshot.length === 0) return
  try {
    const data: StoredBuildTracking = { projectId, builds: snapshot }
    sessionStorage.setItem(SESSION_KEY, JSON.stringify(data))
  } catch {
    // Private mode or quota exceeded - reload still happens, worst case is a
    // missed alert for this one build.
  }
}

export function restoreBuildTracking(
  projectId: string,
): StoredBuildTracking['builds'] | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = sessionStorage.getItem(SESSION_KEY)
    sessionStorage.removeItem(SESSION_KEY)
    if (!raw) return null
    const data = JSON.parse(raw) as StoredBuildTracking
    if (data.projectId !== projectId || !Array.isArray(data.builds)) {
      return null
    }
    return data.builds.filter(
      (build) =>
        typeof build.deploymentId === 'string' &&
        typeof build.resourceId === 'string' &&
        (build.resourceType === 'site' || build.resourceType === 'function') &&
        typeof build.status === 'string',
    )
  } catch {
    return null
  }
}
