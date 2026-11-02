import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import {
  registerActiveEnvironmentChange,
  setActiveEnvironment,
} from '@/lib/active-environment'
import { realtimeEventSource } from '@/lib/realtime/event-source'

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
  delete (globalThis as { window?: unknown }).window
})

describe('realtime event source', () => {
  test('in the project, its own events are current', () => {
    expect(realtimeEventSource('app', ['console', 'projects.app'])).toBe(
      'current',
    )
  })

  test('in the project, events of another project or environment are other', () => {
    // An environment's events name its internal project ID
    expect(realtimeEventSource('app', ['console', 'projects.6a1f0c2b9d'])).toBe(
      'other',
    )
  })

  test('inside an environment, the project’s own events are other', () => {
    setActiveEnvironment('app', 'staging')
    expect(realtimeEventSource('app', ['console', 'projects.app'])).toBe(
      'other',
    )
  })

  test('inside an environment, events naming another project ID may be its own', () => {
    setActiveEnvironment('app', 'staging')
    expect(realtimeEventSource('app', ['console', 'projects.6a1f0c2b9d'])).toBe(
      'unknown',
    )
  })

  test('events naming no project are unknown', () => {
    expect(realtimeEventSource('app', ['console'])).toBe('unknown')
    setActiveEnvironment('app', 'staging')
    expect(realtimeEventSource('app', ['console'])).toBe('unknown')
  })

  test('a project ID that only starts with this one is another project', () => {
    expect(realtimeEventSource('app', ['console', 'projects.app2'])).toBe(
      'other',
    )
  })
})
