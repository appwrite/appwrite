import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import {
  getNotificationLinks,
  getNotificationNavigateTarget,
} from '../../src/lib/notifications/notification-links'

function notification(
  fields: Partial<Models.Notification>,
): Models.Notification {
  return {
    $id: 'notification1',
    $createdAt: '2026-11-24T08:00:00.000+00:00',
    $updatedAt: '2026-11-24T08:00:00.000+00:00',
    type: 'info',
    channel: 'console',
    resourceType: 'users',
    resourceId: 'user1',
    parentResourceType: 'projects',
    parentResourceId: 'project1',
    projectId: 'project1',
    teamId: '',
    subjectType: '',
    subjectId: '',
    status: '',
    title: 'Title',
    body: '',
    read: false,
    ...fields,
  }
}

describe('getNotificationNavigateTarget', () => {
  test('opens the monitor alert a notification is about', () => {
    expect(
      getNotificationNavigateTarget(
        notification({
          type: 'critical',
          teamId: 'team1',
          subjectType: 'alerts',
          subjectId: 'alert1',
          status: 'firing',
        }),
      ),
    ).toEqual({
      to: '/projects/$projectId/monitors/alerts',
      params: { projectId: 'project1' },
      search: { alert: 'alert1' },
    })
  })

  test("opens the organization's billing for a budget alert", () => {
    // A budget alert belongs to the organization and is filed under the console project.
    for (const projectId of ['console', '', null]) {
      expect(
        getNotificationNavigateTarget(
          notification({
            projectId,
            parentResourceType: 'teams',
            parentResourceId: 'team1',
            teamId: 'team1',
            subjectType: 'alerts',
            subjectId: 'alert1',
            status: 'firing',
          }),
        ),
      ).toEqual({
        to: '/organizations/$orgId/settings/billing',
        params: { orgId: 'team1' },
      })
    }
  })

  test('opens the project for a notification about nothing more specific', () => {
    expect(getNotificationNavigateTarget(notification({}))).toEqual({
      to: '/projects/$projectId',
      params: { projectId: 'project1' },
    })
  })

  test('has nowhere to go without a project or an alert', () => {
    // Notifications from servers that do not send the subject fields lack them.
    const older: Partial<Models.Notification> = notification({ projectId: '' })
    delete older.subjectType
    delete older.subjectId
    delete older.teamId
    expect(
      getNotificationNavigateTarget(older as Models.Notification),
    ).toBeNull()
    expect(
      getNotificationNavigateTarget(notification({ projectId: 'console' })),
    ).toBeNull()
  })
})

describe('getNotificationLinks', () => {
  test('links a live domain to the domain itself', () => {
    expect(
      getNotificationLinks(
        notification({ title: 'Your domain shop.example.com is live' }),
      ),
    ).toEqual([{ label: 'Visit domain', href: 'https://shop.example.com' }])
  })

  test('shows no button for a certificate failure', () => {
    expect(
      getNotificationLinks(
        notification({ title: 'Certificate failure for api.example.com' }),
      ),
    ).toEqual([])
  })
})
