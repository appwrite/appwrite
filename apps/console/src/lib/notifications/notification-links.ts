import type { Models } from '@appwrite.io/console'

export type NotificationNavigateTarget = {
  to: string
  params: Record<string, string>
  search?: Record<string, string>
  hash?: string
}

function normalizeResourceType(value: string | undefined): string {
  return value?.trim().toLowerCase() ?? ''
}

/**
 * Best-effort deep link for a console notification. A monitor alert opens the
 * alert itself; a budget alert, which belongs to an organization and no
 * project, opens the organization's billing. Returns null when the
 * notification has no project context.
 */
export function getNotificationNavigateTarget(
  notification: Models.Notification,
): NotificationNavigateTarget | null {
  const alertId =
    notification.subjectType === 'alerts' ? notification.subjectId?.trim() : ''
  const teamId = notification.teamId?.trim()
  // Notifications about no project are filed under the console project.
  const projectId =
    notification.projectId?.trim() === 'console'
      ? ''
      : notification.projectId?.trim()

  if (alertId && projectId) {
    return {
      to: '/projects/$projectId/monitors/alerts',
      params: { projectId },
      search: { alert: alertId },
    }
  }

  if (alertId && teamId) {
    return {
      to: '/organizations/$orgId/settings/billing',
      params: { orgId: teamId },
    }
  }

  if (!projectId) return null

  const resourceType = normalizeResourceType(notification.resourceType)
  const resourceId = notification.resourceId?.trim()
  const parentResourceType = normalizeResourceType(
    notification.parentResourceType,
  )
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

export type NotificationLink = {
  label: string
  target: NotificationNavigateTarget
}

/**
 * Notifications other than alerts carry no subject, so the page they link to
 * is found by the title the server writes for them. An unknown title gets no
 * link.
 */
const TITLE_LINKS: {
  prefix: string
  label: string
  to: string
}[] = [
  {
    prefix: 'Webhook deliveries have been paused',
    label: 'Open webhooks',
    to: '/projects/$projectId/settings/webhooks',
  },
  {
    prefix: 'Your domain ',
    label: 'Open domain settings',
    to: '/projects/$projectId/settings/domains',
  },
  {
    prefix: 'Certificate failure for ',
    label: 'Open domain settings',
    to: '/projects/$projectId/settings/domains',
  },
  {
    prefix: 'Your scheduled database backup is failing',
    label: 'Open databases',
    to: '/projects/$projectId/databases',
  },
  {
    prefix: 'Your database storage was automatically expanded',
    label: 'Open databases',
    to: '/projects/$projectId/databases',
  },
]

/** The buttons a notification shows, each opening the page it names. */
export function getNotificationLinks(
  notification: Models.Notification,
): NotificationLink[] {
  if (notification.subjectType === 'alerts') {
    const target = getNotificationNavigateTarget(notification)
    if (!target) return []
    // An alert outside a project is an organization's budget alert.
    if (target.to === '/organizations/$orgId/settings/billing') {
      return [
        {
          label: 'Edit budget cap',
          target: { ...target, hash: 'update-budget' },
        },
      ]
    }
    return [{ label: 'View alert', target }]
  }

  const projectId = notification.projectId?.trim() ?? ''
  if (!projectId || projectId === 'console') return []

  const match = TITLE_LINKS.find((link) =>
    notification.title.startsWith(link.prefix),
  )
  if (!match) return []

  return [
    {
      label: match.label,
      target: { to: match.to, params: { projectId } },
    },
  ]
}
