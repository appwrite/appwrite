import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import {
  getActiveEnvironmentId,
  registerActiveEnvironmentChange,
  restoreActiveEnvironment,
  setActiveEnvironment,
  subscribeActiveEnvironment,
} from '@/lib/active-environment'

beforeEach(() => {
  const store = new Map<string, string>()
  ;(globalThis as { window?: unknown }).window = {
    sessionStorage: {
      getItem: (k: string) => store.get(k) ?? null,
      setItem: (k: string, v: string) => void store.set(k, v),
      removeItem: (k: string) => void store.delete(k),
    },
  }
})

afterEach(() => {
  registerActiveEnvironmentChange(() => {})
  setActiveEnvironment('app', null)
  setActiveEnvironment('other', null)
  setActiveEnvironment('next', null)
  delete (globalThis as { window?: unknown }).window
})

describe('active environment', () => {
  test('each project keeps its own environment', () => {
    setActiveEnvironment('app', 'staging')
    setActiveEnvironment('other', 'preview')

    expect(getActiveEnvironmentId('app')).toBe('staging')
    expect(getActiveEnvironmentId('other')).toBe('preview')
    // A project with none set is in production
    expect(getActiveEnvironmentId('none')).toBeNull()
  })

  test('preloading another project does not clobber the current one', () => {
    setActiveEnvironment('app', 'staging')
    // Route intent-preload restores a different project's environment
    restoreActiveEnvironment('other')
    // The current project still carries its own
    expect(getActiveEnvironmentId('app')).toBe('staging')
  })

  test('restore makes a project match its saved environment, or production', () => {
    setActiveEnvironment('app', 'staging')
    setActiveEnvironment('next', 'qa')

    restoreActiveEnvironment('app')
    expect(getActiveEnvironmentId('app')).toBe('staging')
    restoreActiveEnvironment('next')
    expect(getActiveEnvironmentId('next')).toBe('qa')
    restoreActiveEnvironment('fresh')
    expect(getActiveEnvironmentId('fresh')).toBeNull()
  })

  test('changing a project environment drops that project caches, once per change', () => {
    const dropped: string[] = []
    registerActiveEnvironmentChange((projectId) => dropped.push(projectId))

    setActiveEnvironment('app', 'staging')
    setActiveEnvironment('app', 'staging') // no change, no drop
    setActiveEnvironment('app', 'preview') // change, drop
    setActiveEnvironment('other', 'qa') // a different project, its own drop

    expect(dropped).toEqual(['app', 'app', 'other'])
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
