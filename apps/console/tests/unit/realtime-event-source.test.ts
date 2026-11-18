import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import {
  registerActiveEnvironmentChange,
  setActiveEnvironment,
} from '@/lib/active-environment'
import {
  isRealtimeContextPing,
  realtimeEventSource,
} from '@/lib/realtime/event-source'

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

  test('in the project, events of another project are other', () => {
    expect(realtimeEventSource('app', ['console', 'projects.other'])).toBe(
      'other',
    )
  })

  test('in the project, events of its environments are other', () => {
    expect(
      realtimeEventSource('app', [
        'console',
        'projects.app.environments.staging',
      ]),
    ).toBe('other')
  })

  test('inside an environment, its own events are current', () => {
    setActiveEnvironment('app', 'staging')
    expect(
      realtimeEventSource('app', [
        'console',
        'projects.app.environments.staging',
      ]),
    ).toBe('current')
  })

  test('inside an environment, the project’s own events are other', () => {
    setActiveEnvironment('app', 'staging')
    expect(realtimeEventSource('app', ['console', 'projects.app'])).toBe(
      'other',
    )
  })

  test('inside an environment, events of another environment are other', () => {
    setActiveEnvironment('app', 'staging')
    expect(
      realtimeEventSource('app', ['console', 'projects.app.environments.qa']),
    ).toBe('other')
    expect(
      realtimeEventSource('app', [
        'console',
        'projects.other.environments.staging',
      ]),
    ).toBe('other')
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

describe('realtime context ping', () => {
  test('in the project, its ping counts', () => {
    expect(
      isRealtimeContextPing(
        'app',
        ['projects.app.ping'],
        ['console', 'projects.app'],
      ),
    ).toBe(true)
  })

  test('inside an environment, its ping counts', () => {
    setActiveEnvironment('app', 'staging')
    expect(
      isRealtimeContextPing(
        'app',
        [
          'projects.app.environments.staging.ping',
          'projects.*.ping',
          'projects.app.environments.staging',
          'projects.*',
        ],
        ['console', 'projects.app.environments.staging'],
      ),
    ).toBe(true)
  })

  test('inside an environment, the project’s ping does not count', () => {
    setActiveEnvironment('app', 'staging')
    expect(
      isRealtimeContextPing(
        'app',
        ['projects.app.ping'],
        ['console', 'projects.app'],
      ),
    ).toBe(false)
  })

  test('another event of the context is not a ping', () => {
    expect(
      isRealtimeContextPing(
        'app',
        ['projects.app.update'],
        ['console', 'projects.app'],
      ),
    ).toBe(false)
  })
})
