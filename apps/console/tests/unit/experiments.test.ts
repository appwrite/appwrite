import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import { isRedirect } from '@tanstack/react-router'
import { CONSOLE_PROFILES } from '@/lib/console-profiles'
import {
  EXPERIMENTS,
  hasQualityLabel,
  setExperimentEnabled,
} from '@/lib/experiments'
import { Route as AlertsRoute } from '@/routes/_public/projects.$projectId.usage.alerts'
import { Route as MonitorsRoute } from '@/routes/_public/projects.$projectId.usage.monitors'

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
  test('lists Appwrite Analytics, project environments, project transfers, and usage alerts', () => {
    expect(EXPERIMENTS.map((experiment) => experiment.flag)).toEqual([
      'analytics',
      'projectEnvironments',
      'projectTransfers',
      'alerts',
    ])
    expect(EXPERIMENTS[0]?.name).toBe('Appwrite Analytics')
    expect(EXPERIMENTS[1]?.name).toBe('Project environments')
    expect(EXPERIMENTS[2]?.name).toBe('Project transfers')
    expect(EXPERIMENTS[3]?.name).toBe('Usage alerts')
    for (const experiment of EXPERIMENTS) {
      expect(experiment.description.length).toBeGreaterThan(0)
    }
  })

  test('usage alerts are off in every profile until a preview turns them on', () => {
    for (const profile of Object.values(CONSOLE_PROFILES)) {
      expect(profile.features.alerts).toBe(false)
    }
  })
})

describe('usage alerts preview', () => {
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

  function enter(route: typeof AlertsRoute | typeof MonitorsRoute): unknown {
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

  test('a deep link to alerts or monitors lands on usage while the preview is off', () => {
    for (const route of [AlertsRoute, MonitorsRoute]) {
      const thrown = enter(route)
      expect(isRedirect(thrown)).toBe(true)
      expect(
        (thrown as { options: { to: string; params: unknown } }).options,
      ).toMatchObject({ to: '/projects/$projectId/usage', params })
    }
  })

  test('the pages open once the preview is turned on', () => {
    setExperimentEnabled('alerts', true)
    expect(enter(AlertsRoute)).toBeNull()
    expect(enter(MonitorsRoute)).toBeNull()

    setExperimentEnabled('alerts', false)
    expect(isRedirect(enter(MonitorsRoute))).toBe(true)
  })
})
