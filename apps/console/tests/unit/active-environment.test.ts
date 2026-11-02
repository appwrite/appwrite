import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import {
  activeEnvironmentCacheScope,
  getActiveEnvironment,
  getActiveEnvironmentId,
  restoreActiveEnvironment,
  setActiveEnvironment,
  subscribeActiveEnvironment,
} from '@/lib/active-environment'

// A minimal sessionStorage on window so persistence (per project) can be driven.
beforeEach(() => {
  const store = new Map<string, string>()
  ;(globalThis as { window?: unknown }).window = {
    sessionStorage: {
      getItem: (key: string) => store.get(key) ?? null,
      setItem: (key: string, value: string) => void store.set(key, value),
      removeItem: (key: string) => void store.delete(key),
    },
  }
})

afterEach(() => {
  setActiveEnvironment('app', null)
  setActiveEnvironment('next', null)
  delete (globalThis as { window?: unknown }).window
})

describe('active environment', () => {
  test('an environment is the project it belongs to plus its id', () => {
    setActiveEnvironment('app', 'staging')

    expect(getActiveEnvironment()).toEqual({
      projectId: 'app',
      environmentId: 'staging',
    })
    expect(getActiveEnvironmentId('app')).toBe('staging')
    // Another project's requests carry no environment of this one
    expect(getActiveEnvironmentId('other')).toBeNull()
  })

  test('leaving names no environment and is production', () => {
    setActiveEnvironment('app', 'staging')
    setActiveEnvironment('app', null)

    expect(getActiveEnvironment()).toBeNull()
    expect(activeEnvironmentCacheScope()).toBe('')
  })

  test('the cache scope tells environments apart and leaves production keys alone', () => {
    expect(activeEnvironmentCacheScope()).toBe('')
    setActiveEnvironment('app', 'staging')
    const staging = activeEnvironmentCacheScope()
    setActiveEnvironment('app', 'preview')
    expect(activeEnvironmentCacheScope()).not.toBe(staging)
    expect(activeEnvironmentCacheScope()).not.toBe('')
  })

  test('restore makes the store match the project saved in session storage', () => {
    // The switcher set each project's environment while the Console was in it
    setActiveEnvironment('app', 'staging')
    setActiveEnvironment('next', 'qa')

    // Entering a project restores its own, not whichever was last active
    restoreActiveEnvironment('app')
    expect(getActiveEnvironmentId('app')).toBe('staging')

    restoreActiveEnvironment('next')
    expect(getActiveEnvironmentId('next')).toBe('qa')
    expect(getActiveEnvironmentId('app')).toBeNull()

    // A project with none saved restores to production
    restoreActiveEnvironment('fresh')
    expect(getActiveEnvironment()).toBeNull()
  })

  test('listeners hear a change and nothing else', () => {
    let calls = 0
    const unsubscribe = subscribeActiveEnvironment(() => {
      calls += 1
    })

    setActiveEnvironment('app', 'staging')
    setActiveEnvironment('app', 'staging')
    expect(calls).toBe(1)

    unsubscribe()
    setActiveEnvironment('app', 'preview')
    expect(calls).toBe(1)
  })
})
