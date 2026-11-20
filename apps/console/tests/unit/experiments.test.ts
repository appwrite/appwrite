import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import { isRedirect } from '@tanstack/react-router'
import {
  CONSOLE_PROFILES,
  getDebugProfileFeatureOverrides,
} from '@/lib/console-profiles'
import {
  EXPERIMENTS,
  hasQualityLabel,
  setExperimentEnabled,
} from '@/lib/experiments'
import { Route as IdentityServersRoute } from '@/routes/_public/projects.$projectId.settings.identity-servers'
import { Route as MonitorsRoute } from '@/routes/_public/projects.$projectId.monitors'
import { Route as UsageAlertsRoute } from '@/routes/_public/projects.$projectId.usage.alerts'

describe('hasQualityLabel', () => {
  test('matches only the quality label', () => {
    expect(hasQualityLabel(['quality'])).toBe(true)
    expect(hasQualityLabel(['vip', 'quality'])).toBe(true)
    expect(hasQualityLabel(['Quality'])).toBe(false)
    expect(hasQualityLabel([])).toBe(false)
    expect(hasQualityLabel(undefined)).toBe(false)
    expect(hasQualityLabel(null)).toBe(false)
  })
})

describe('experiments catalog', () => {
  test('lists Appwrite Analytics, project environments, single sign-on, alerts and Videos', () => {
    expect(EXPERIMENTS.map((experiment) => experiment.flag)).toEqual([
      'analytics',
      'projectEnvironments',
      'sso',
      'alerts',
      'videos',
    ])
    expect(EXPERIMENTS[0]?.name).toBe('Appwrite Analytics')
    expect(EXPERIMENTS[1]?.name).toBe('Project environments')
    expect(EXPERIMENTS[2]?.name).toBe('Single sign-on')
    expect(EXPERIMENTS[3]?.name).toBe('Monitors and alerts')
    expect(EXPERIMENTS[4]?.name).toBe('Appwrite Videos')
    for (const experiment of EXPERIMENTS) {
      expect(experiment.description.length).toBeGreaterThan(0)
    }
  })

  test('Origin is generally available: no feature flag and not in the previews menu', () => {
    for (const profile of Object.values(CONSOLE_PROFILES)) {
      expect(profile.features).not.toHaveProperty('vcsOrigin')
    }
    expect(
      EXPERIMENTS.some(
        (experiment) => (experiment.flag as string) === 'vcsOrigin',
      ),
    ).toBe(false)
  })

  test('Videos is off in every profile until the experiment turns it on', () => {
    for (const profile of Object.values(CONSOLE_PROFILES)) {
      expect(profile.features.videos).toBe(false)
    }
  })

  test('usage alerts are off in every profile until the experiment turns them on', () => {
    for (const profile of Object.values(CONSOLE_PROFILES)) {
      expect(profile.features.alerts).toBe(false)
    }
  })
})

describe('Origin flag removal', () => {
  const globals = globalThis as { window?: unknown; localStorage?: unknown }
  const originalWindow = globals.window
  const originalLocalStorage = globals.localStorage

  afterEach(() => {
    globals.window = originalWindow
    globals.localStorage = originalLocalStorage
  })

  test('an Origin override saved in the browser is dropped, under either name', () => {
    const storage = new Map<string, string>([
      [
        'debug:consoleProfile',
        JSON.stringify({
          ...CONSOLE_PROFILES.cloud,
          features: {
            ...CONSOLE_PROFILES.cloud.features,
            extraVcsOAuth: true,
            vcsOrigin: false,
          },
        }),
      ],
    ])
    const localStorage = {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => void storage.set(key, value),
      removeItem: (key: string) => void storage.delete(key),
    }
    globals.localStorage = localStorage
    globals.window = {
      localStorage,
      location: new URL('https://cloud.appwrite.io/console'),
      dispatchEvent: () => true,
    }

    const overrides = getDebugProfileFeatureOverrides()
    expect('vcsOrigin' in overrides).toBe(false)
    expect('extraVcsOAuth' in overrides).toBe(false)
  })
})

describe('usage alerts flag', () => {
  const globals = globalThis as { window?: unknown; localStorage?: unknown }
  const originalWindow = globals.window
  const originalLocalStorage = globals.localStorage

  beforeEach(() => {
    const storage = new Map<string, string>()
    const localStorage = {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => void storage.set(key, value),
      removeItem: (key: string) => void storage.delete(key),
    }
    globals.localStorage = localStorage
    globals.window = {
      localStorage,
      location: new URL('https://cloud.appwrite.io/console'),
      dispatchEvent: () => true,
    }
  })

  afterEach(() => {
    globals.window = originalWindow
    globals.localStorage = originalLocalStorage
  })

  const params = { projectId: 'project' }

  function enter(
    route: typeof MonitorsRoute | typeof UsageAlertsRoute,
  ): unknown {
    const beforeLoad = route.options.beforeLoad as unknown as (context: {
      params: typeof params
      search: Record<string, unknown>
    }) => unknown
    try {
      beforeLoad({ params, search: { alert: 'alert' } })
      return null
    } catch (thrown) {
      return thrown
    }
  }

  test('a deep link to monitors lands on the overview while the flag is off', () => {
    const thrown = enter(MonitorsRoute)
    expect(isRedirect(thrown)).toBe(true)
    expect(
      (thrown as { options: { to: string; params: unknown } }).options,
    ).toMatchObject({ to: '/projects/$projectId/overview', params })
  })

  test('the pages open once the experiment turns the flag on', () => {
    setExperimentEnabled('alerts', true)
    expect(enter(MonitorsRoute)).toBeNull()

    setExperimentEnabled('alerts', false)
    expect(isRedirect(enter(MonitorsRoute))).toBe(true)
  })
})

test('an alert link from before the move opens the alert under monitors', () => {
  const beforeLoad = UsageAlertsRoute.options
    .beforeLoad as unknown as (context: {
    params: { projectId: string }
    search: Record<string, unknown>
  }) => unknown
  let thrown: unknown = null
  try {
    beforeLoad({ params: { projectId: 'project' }, search: { alert: 'alert' } })
  } catch (error) {
    thrown = error
  }
  expect(isRedirect(thrown)).toBe(true)
  expect(
    (thrown as { options: { to: string; search: unknown } }).options,
  ).toMatchObject({
    to: '/projects/$projectId/monitors/alerts',
    search: { alert: 'alert' },
  })
})

describe('identity servers', () => {
  const params = { projectId: 'project' }

  function enter(pathname: string): unknown {
    const beforeLoad = IdentityServersRoute.options
      .beforeLoad as unknown as (context: {
      location: { pathname: string }
      params: typeof params
    }) => unknown
    try {
      beforeLoad({ location: { pathname }, params })
      return null
    } catch (thrown) {
      return thrown
    }
  }

  test('is generally available: no feature flag and not in the previews menu', () => {
    for (const profile of Object.values(CONSOLE_PROFILES)) {
      expect(profile.features).not.toHaveProperty('identityServers')
    }
    expect(
      EXPERIMENTS.some(
        (experiment) => (experiment.flag as string) === 'identityServers',
      ),
    ).toBe(false)
  })

  test('the index opens LDAP, the only identity server so far', () => {
    for (const pathname of [
      '/projects/project/settings/identity-servers',
      '/projects/project/settings/identity-servers/',
    ]) {
      const thrown = enter(pathname)
      expect(isRedirect(thrown)).toBe(true)
      expect(
        (thrown as { options: { to: string; params: unknown } }).options,
      ).toMatchObject({
        to: '/projects/$projectId/settings/identity-servers/ldap',
        params,
      })
    }
  })

  test('the LDAP page opens without a redirect', () => {
    expect(enter('/projects/project/settings/identity-servers/ldap')).toBeNull()
  })
})
