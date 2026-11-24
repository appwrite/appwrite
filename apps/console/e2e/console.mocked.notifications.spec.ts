import type { Locator, Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

const NOW = new Date()
const hoursAgo = (hours: number) =>
  new Date(NOW.getTime() - hours * 3_600_000).toISOString()
const daysAgo = (days: number) => hoursAgo(days * 24)

const PROJECT_ID = 'notificationsproj001'
const ORGANIZATION_ID = 'orgacme00000000001'
const ALERT_ID = 'alertfiring000000001'
const PHONE_SNOOZE = new Date(NOW.getTime() + 3 * 3_600_000).toISOString()

const ACCOUNT: Models.User<Models.Preferences> = {
  $id: 'notificationsuser001',
  $createdAt: daysAgo(30),
  $updatedAt: daysAgo(30),
  name: 'Test User',
  email: 'user@example.com',
  registration: daysAgo(30),
  status: true,
  labels: [],
  passwordUpdate: daysAgo(30),
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  mfa: false,
  prefs: { 'console.example': 'kept' },
  targets: [],
  accessedAt: NOW.toISOString(),
}

function notification(
  id: string,
  fields: Partial<Models.Notification>,
): Models.Notification {
  return {
    $id: id,
    $createdAt: hoursAgo(1),
    $updatedAt: hoursAgo(1),
    type: 'info',
    channel: 'console',
    resourceType: 'users',
    resourceId: ACCOUNT.$id,
    parentResourceType: 'projects',
    parentResourceId: PROJECT_ID,
    projectId: PROJECT_ID,
    teamId: '',
    alertId: '',
    alertStatus: '',
    title: '',
    body: '',
    read: false,
    ...fields,
  }
}

const ALERT: Models.Alert = {
  $id: ALERT_ID,
  $createdAt: hoursAgo(1),
  $updatedAt: hoursAgo(1),
  monitorId: 'monitorbandwidth0001',
  name: 'Bandwidth over 80%',
  status: 'firing',
  resolvedAt: null,
  acknowledgedAt: null,
  acknowledgedBy: '',
  acknowledgedByName: '',
  rules: [],
  severity: 'warning',
  critical: false,
  metric: 'network.outbound',
  comparator: 'greaterThan',
  threshold: 80_000_000_000,
  value: 84_000_000_000,
  peak: 84_000_000_000,
  type: 'usage',
  resourceType: 'project',
  resourceId: '',
  notifications: 1,
} as unknown as Models.Alert

const NOTIFICATIONS = [
  notification('alertwarning00000001', {
    type: 'warning',
    title: 'Bandwidth is over 80% on Production',
    body: 'Usage reached 84 GB of the 100 GB monitor threshold.',
    teamId: ORGANIZATION_ID,
    alertId: ALERT_ID,
    alertStatus: 'firing',
  }),
  notification('alertresolved0000001', {
    type: 'warning',
    title: 'Executions are back under 1,000 on Production',
    $createdAt: hoursAgo(2),
    read: true,
    teamId: ORGANIZATION_ID,
    alertId: 'alertexecutions00001',
    alertStatus: 'resolved',
  }),
  notification('buildfailed000000001', {
    type: 'error',
    title: 'Build failed for checkout',
    body: 'The deployment exited with code 1.',
    $createdAt: daysAgo(3),
    read: true,
  }),
]

function corsHeaders(route: Route) {
  return {
    'access-control-allow-origin': route.request().headers().origin ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
    'access-control-allow-headers':
      route.request().headers()['access-control-request-headers'] ?? '*',
  }
}

type Writes = {
  prefs: Record<string, unknown>[]
  reads: { id: string; read: boolean }[]
  acknowledgements: {
    projectId: string | undefined
    alertId: string
    acknowledged: boolean
  }[]
  /** Alerts fetched one at a time, as a deep link opens one. */
  alerts: string[]
  /** Changes the server's prefs, as Beep does from a phone. */
  fromPhone: (beep: Record<string, unknown>) => void
}

/** How the server answers acknowledging an alert. */
type Acknowledging = 'ok' | 'resolved' | 'forbidden'

/** A signed-in user with two organizations and an inbox. */
async function mockApi(
  page: Page,
  beep?: Record<string, unknown>,
  acknowledging: Acknowledging = 'ok',
): Promise<Writes> {
  let serverPrefs: Record<string, unknown> = {
    ...ACCOUNT.prefs,
    ...(beep ? { beep } : {}),
  }
  const writes: Writes = {
    prefs: [],
    reads: [],
    acknowledgements: [],
    alerts: [],
    fromPhone: (next) => {
      serverPrefs = { ...serverPrefs, beep: next }
    },
  }
  const notifications = NOTIFICATIONS.map((item) => ({ ...item }))

  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({
        id: 'cloud',
        features: { notifications: true, alerts: true },
      }),
    )
  })

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
    const apiPath = url.pathname.match(/^\/v1(\/.*)$/)?.[1]
    if (url.origin === localOrigin && !apiPath) return route.continue()
    const headers = corsHeaders(route)
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })

    if (!apiPath || request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    if (apiPath === '/account') {
      return json(200, { ...ACCOUNT, prefs: serverPrefs })
    }
    if (apiPath === '/account/prefs') {
      if (request.method() === 'PATCH') {
        serverPrefs = request.postDataJSON().prefs
        writes.prefs.push(serverPrefs)
        return json(200, { ...ACCOUNT, prefs: serverPrefs })
      }
      return json(200, serverPrefs)
    }
    if (apiPath === '/notifications') {
      return json(200, { total: notifications.length, notifications })
    }
    const notificationId = apiPath.match(/^\/notifications\/([^/]+)$/)?.[1]
    if (notificationId && request.method() === 'PATCH') {
      const read = request.postDataJSON().read as boolean
      writes.reads.push({ id: notificationId, read })
      const item = notifications.find((entry) => entry.$id === notificationId)
      if (item) item.read = read
      return json(200, item)
    }
    if (apiPath === '/alerts') {
      return json(200, { total: 1, alerts: [ALERT] })
    }
    const alertId = apiPath.match(/^\/alerts\/([^/]+)$/)?.[1]
    if (alertId && request.method() === 'GET') {
      writes.alerts.push(alertId)
      return json(200, { ...ALERT, $id: alertId })
    }
    if (alertId && request.method() === 'PATCH') {
      writes.acknowledgements.push({
        projectId: request.headers()['x-appwrite-project'],
        alertId,
        acknowledged: request.postDataJSON().acknowledged as boolean,
      })
      if (acknowledging === 'resolved') {
        return json(400, {
          code: 400,
          type: 'alert_resolved',
          message:
            'The alert has resolved. Only a firing alert can be acknowledged.',
          version: '1.0',
        })
      }
      if (acknowledging === 'forbidden') {
        return json(401, {
          code: 401,
          type: 'general_unauthorized_scope',
          message:
            'user@example.com (role: analyst) missing scopes (["alerts.write"])',
          version: '1.0',
        })
      }
      return json(200, {
        ...ALERT,
        $id: alertId,
        acknowledgedAt: NOW.toISOString(),
        acknowledgedBy: ACCOUNT.$id,
        acknowledgedByName: ACCOUNT.name,
      })
    }
    if (apiPath === '/organizations' || apiPath === '/teams') {
      return json(200, {
        total: 2,
        teams: [
          { $id: 'orgacme00000000001', name: 'Acme', total: 3, prefs: {} },
          {
            $id: 'orgphone0000000001',
            name: 'Side projects',
            total: 1,
            prefs: {},
          },
        ],
      })
    }
    if (
      apiPath === '/project' &&
      request.headers()['x-appwrite-project'] === PROJECT_ID
    ) {
      return json(200, {
        $id: PROJECT_ID,
        $createdAt: daysAgo(10),
        name: 'Production',
        teamId: 'orgacme00000000001',
        region: 'fra',
      })
    }
    return json(404, {
      code: 404,
      type: 'general_route_not_found',
      message: 'general_route_not_found',
      version: '1.0',
    })
  })

  return writes
}

/** The `beep` prefs of the latest write that carried them. */
function lastBeep(writes: Writes): Record<string, unknown> | undefined {
  const write = [...writes.prefs].reverse().find((prefs) => 'beep' in prefs)
  return write?.beep as Record<string, unknown> | undefined
}

async function openRowMenu(popover: Locator, title: string) {
  const row = popover.locator('.group').filter({ hasText: title })
  await row.hover()
  await row.getByRole('button', { name: 'Notification actions' }).click()
  return row
}

async function openNotifications(page: Page) {
  await page.goto('/account')
  await page.getByRole('button', { name: 'Notifications', exact: true }).click()
  const popover = page.getByRole('dialog')
  await expect(popover.getByText('Bandwidth is over 80%')).toBeVisible()
  return popover
}

test.describe('Notification center (mocked API)', () => {
  test('groups by day and filters unread', async ({ page }) => {
    await mockApi(page)
    const popover = await openNotifications(page)

    await expect(popover.getByRole('heading', { name: 'Today' })).toBeVisible()
    await expect(
      popover.getByRole('heading', { name: 'Earlier' }),
    ).toBeVisible()
    await expect(popover.getByText('Build failed for checkout')).toBeVisible()

    await popover.getByRole('button', { name: 'Filter notifications' }).click()
    await page.getByRole('menuitemradio', { name: 'Unread' }).click()
    await expect(popover.getByText('Build failed for checkout')).toBeHidden()
    await expect(popover.getByText('Bandwidth is over 80%')).toBeVisible()
  })

  test('marks a read notification unread from its menu', async ({ page }) => {
    const writes = await mockApi(page)
    const popover = await openNotifications(page)

    const row = popover
      .locator('.group')
      .filter({ hasText: 'Build failed for checkout' })
    await row.hover()
    await row.getByRole('button', { name: 'Notification actions' }).click()
    await page.getByRole('menuitem', { name: 'Mark as unread' }).click()

    await expect
      .poll(() => writes.reads)
      .toEqual([{ id: 'buildfailed000000001', read: false }])
  })

  test('mutes a project for Beep and keeps settings changed on a phone', async ({
    page,
  }) => {
    const writes = await mockApi(page)
    const popover = await openNotifications(page)
    // Beep snoozes an organization after the Console loaded the account.
    writes.fromPhone({ snoozes: { orgphone0000000001: PHONE_SNOOZE } })

    const row = popover
      .locator('.group')
      .filter({ hasText: 'Bandwidth is over 80%' })
    await row.hover()
    await row.getByRole('button', { name: 'Notification actions' }).click()
    await page.getByRole('menuitem', { name: 'Mute project pushes' }).click()

    await expect
      .poll(() => lastBeep(writes))
      .toMatchObject({
        mutedProjectIds: [PROJECT_ID],
        snoozes: { orgphone0000000001: PHONE_SNOOZE },
      })
    expect(writes.prefs.at(-1)).toMatchObject({ 'console.example': 'kept' })
    await expect(row.getByText('Pushes muted')).toBeVisible()
  })

  test('snoozes every organization and resumes', async ({ page }) => {
    const writes = await mockApi(page)
    const popover = await openNotifications(page)

    await popover.getByRole('button', { name: 'Snooze pushes' }).click()
    await page.getByRole('menuitem', { name: '1 hour' }).click()

    await expect(popover.getByText(/Pushes snoozed until/)).toBeVisible()
    await expect
      .poll(() => (lastBeep(writes)?.snoozes as Record<string, string>)?.['*'])
      .toBeTruthy()
    const until = Date.parse(
      (lastBeep(writes)?.snoozes as Record<string, string>)['*'],
    )
    expect(until - Date.now()).toBeGreaterThan(55 * 60_000)

    await popover.getByRole('button', { name: 'Resume' }).click()
    await expect(popover.getByText(/Pushes snoozed until/)).toBeHidden()
    await expect.poll(() => lastBeep(writes)?.snoozes).toEqual({})
  })

  test('mutes an organization and turns off resolved pushes in settings', async ({
    page,
  }) => {
    const writes = await mockApi(page, {
      snoozes: { orgphone0000000001: PHONE_SNOOZE },
    })
    const popover = await openNotifications(page)

    await popover.getByRole('button', { name: 'Notification settings' }).click()
    await expect(popover.getByText(/Snoozed until/)).toBeVisible()

    await popover.getByRole('switch', { name: 'Pushes from Acme' }).click()
    await expect
      .poll(() => lastBeep(writes)?.mutedOrganizationIds)
      .toEqual(['orgacme00000000001'])

    await popover.getByRole('switch', { name: 'Resolved alerts' }).click()
    await expect
      .poll(() => lastBeep(writes))
      .toMatchObject({
        mutedOrganizationIds: ['orgacme00000000001'],
        resolved: false,
        snoozes: { orgphone0000000001: PHONE_SNOOZE },
      })
  })

  test('opens the alert a notification is about', async ({ page }) => {
    const writes = await mockApi(page)
    const popover = await openNotifications(page)

    await popover
      .getByRole('button', { name: 'Bandwidth is over 80% on Production' })
      .click()

    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/monitors/alerts`),
    )
    await expect.poll(() => writes.alerts).toContain(ALERT_ID)
    await expect(
      page.getByRole('dialog').getByText(ALERT.name).first(),
    ).toBeVisible()
    expect(writes.reads).toEqual([{ id: 'alertwarning00000001', read: true }])
  })

  test('acknowledges a firing alert from its menu', async ({ page }) => {
    const writes = await mockApi(page)
    const popover = await openNotifications(page)

    // A resolved alert has nothing to acknowledge.
    await openRowMenu(popover, 'Executions are back under 1,000')
    await expect(page.getByRole('menuitem', { name: 'Open' })).toBeVisible()
    await expect(
      page.getByRole('menuitem', { name: 'Acknowledge' }),
    ).toBeHidden()
    await page.keyboard.press('Escape')

    const row = await openRowMenu(popover, 'Bandwidth is over 80%')
    await page.getByRole('menuitem', { name: 'Acknowledge' }).click()

    await expect
      .poll(() => writes.acknowledgements)
      .toEqual([
        { projectId: PROJECT_ID, alertId: ALERT_ID, acknowledged: true },
      ])
    await expect(page.getByText('Alert acknowledged')).toBeVisible()
    await expect(row.getByText('Acknowledged', { exact: true })).toBeVisible()
    // Acknowledging is seeing it, so the notification is read.
    await expect
      .poll(() => writes.reads)
      .toEqual([{ id: 'alertwarning00000001', read: true }])

    await openRowMenu(popover, 'Bandwidth is over 80%')
    await expect(page.getByRole('menuitem', { name: 'Open' })).toBeVisible()
    await expect(
      page.getByRole('menuitem', { name: 'Acknowledge' }),
    ).toBeHidden()
  })

  test('says why an alert that resolved cannot be acknowledged', async ({
    page,
  }) => {
    const writes = await mockApi(page, undefined, 'resolved')
    const popover = await openNotifications(page)

    await openRowMenu(popover, 'Bandwidth is over 80%')
    await page.getByRole('menuitem', { name: 'Acknowledge' }).click()

    await expect(
      page.getByText('This alert has already resolved'),
    ).toBeVisible()
    await expect(
      page.getByText('Only a firing alert can be acknowledged.'),
    ).toBeVisible()
    expect(writes.acknowledgements).toHaveLength(1)
    expect(writes.reads).toEqual([])

    await openRowMenu(popover, 'Bandwidth is over 80%')
    await expect(page.getByRole('menuitem', { name: 'Open' })).toBeVisible()
    await expect(
      page.getByRole('menuitem', { name: 'Acknowledge' }),
    ).toBeHidden()
  })

  test("says when the member's role cannot acknowledge", async ({ page }) => {
    await mockApi(page, undefined, 'forbidden')
    const popover = await openNotifications(page)

    await openRowMenu(popover, 'Bandwidth is over 80%')
    await page.getByRole('menuitem', { name: 'Acknowledge' }).click()

    await expect(
      page.getByText('Failed to acknowledge the alert'),
    ).toBeVisible()
    await expect(
      page.getByText(
        "Your role can't change alerts. Ask an owner or a developer.",
      ),
    ).toBeVisible()
  })
})
