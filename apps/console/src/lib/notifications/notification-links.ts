import type { Models } from '@appwrite.io/console'

export type NotificationNavigateTarget = {
  to: string
  params: Record<string, string>
}

function normalizeResourceType(value: string | undefined): string {
  return value?.trim().toLowerCase() ?? ''
}

/**
 * Best-effort deep link for a console notification. Returns null when the
 * notification has no project context or the resource type is unknown.
 */
export function getNotificationNavigateTarget(
  notification: Models.Notification,
): NotificationNavigateTarget | null {
  const projectId = notification.projectId?.trim()
  if (!projectId) return null

  const resourceType = normalizeResourceType(notification.resourceType)
  const resourceId = notification.resourceId?.trim()
  const parentResourceType = normalizeResourceType(notification.parentResourceType)
  const parentResourceId = notification.parentResourceId?.trim()

  if (resourceType === 'deployments' && resourceId && parentResourceId) {
    if (parentResourceType === 'functions') {
      return {
        to: '/projects/$projectId/functions/$functionId/deployments/$deploymentId',
        params: {
          projectId,
          functionId: parentResourceId,
          deploymentId: resourceId,
        },
      }
    }
    if (parentResourceType === 'sites') {
      return {
        to: '/projects/$projectId/sites/$siteId/deployments/$deploymentId',
        params: {
          projectId,
          siteId: parentResourceId,
          deploymentId: resourceId,
        },
      }
    }
  }

  if (resourceType === 'functions' && resourceId) {
    return {
      to: '/projects/$projectId/functions/$functionId',
      params: { projectId, functionId: resourceId },
    }
  }

  if (resourceType === 'sites' && resourceId) {
    return {
      to: '/projects/$projectId/sites/$siteId',
      params: { projectId, siteId: resourceId },
    }
  }

  if ((resourceType === 'buckets' || resourceType === 'bucket') && resourceId) {
    return {
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId, bucketId: resourceId },
    }
  }

  if (resourceType === 'messages' && resourceId) {
    return {
      to: '/projects/$projectId/messaging/$messageId',
      params: { projectId, messageId: resourceId },
    }
  }

  if (resourceType === 'executions' && parentResourceId) {
    return {
      to: '/projects/$projectId/functions/$functionId',
      params: { projectId, functionId: parentResourceId },
    }
  }

  if (
    (resourceType === 'databases' ||
      resourceType === 'tables' ||
      resourceType === 'collections') &&
    resourceId
  ) {
    return {
      to: '/projects/$projectId/databases',
      params: { projectId },
    }
  }

  return {
    to: '/projects/$projectId',
    params: { projectId },
  }
}
