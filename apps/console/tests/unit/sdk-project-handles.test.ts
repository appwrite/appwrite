import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import { setActiveEnvironment } from '@/lib/active-environment'
import {
  applyConsoleImpersonateUserId,
  clearConsoleImpersonateUser,
  sdk,
} from '@/lib/appwrite/sdk'

/**
 * What each request the SDK sends says about where it acts. A handle from
 * `sdk.forProject` is routinely held across awaits (create, then deploy; a
 * chunked upload; a bulk loop), while other queries ask for other projects or
 * for the project itself from inside one of its environments. The handle has
 * to keep acting where it was obtained, or a step taken in an environment
 * lands on production.
 */
interface Sent {
  project: string | undefined
  environment: string | undefined
  impersonate: string | undefined
}

const sent: Sent[] = []
const originalFetch = globalThis.fetch

beforeEach(() => {
  sent.length = 0
  globalThis.fetch = (async (_url: unknown, init?: RequestInit) => {
    const headers = new Map(
      Object.entries((init?.headers ?? {}) as Record<string, string>).map(
        ([key, value]) => [key.toLowerCase(), value],
      ),
    )
    sent.push({
      project: headers.get('x-appwrite-project'),
      environment: headers.get('x-appwrite-environment'),
      impersonate: headers.get('x-appwrite-impersonate-user-id'),
    })
    return new Response(JSON.stringify({ total: 0, functions: [] }), {
      status: 200,
      headers: { 'content-type': 'application/json' },
    })
  }) as typeof fetch
})

afterEach(() => {
  globalThis.fetch = originalFetch
  setActiveEnvironment('app', null)
  setActiveEnvironment('other', null)
  clearConsoleImpersonateUser()
})

describe('sdk.forProject handles', () => {
  test('a handle obtained in an environment stays in it', async () => {
    setActiveEnvironment('app', 'staging')
    const staging = sdk.forProject('app')

    await staging.functions.list()
    // Listing the project's environments, as the switcher does on every refetch
    sdk.forProject('app', undefined, { environment: null })
    await staging.functions.list()

    expect(sent).toEqual([
      { project: 'app', environment: 'staging', impersonate: undefined },
      { project: 'app', environment: 'staging', impersonate: undefined },
    ])
  })

  test('a handle for the project itself stays out of its environments', async () => {
    const production = sdk.forProject('app')

    await production.functions.list()
    // Another query acts in an environment the Console knows by name
    sdk.forProject('app', undefined, { environment: 'staging' })
    await production.functions.list()
    // The Console enters the environment; the handle was obtained before
    setActiveEnvironment('app', 'staging')
    await production.functions.list()

    expect(sent.map((request) => request.environment)).toEqual([
      undefined,
      undefined,
      undefined,
    ])
  })

  test('a handle keeps its project when another project is asked for', async () => {
    const app = sdk.forProject('app')

    await app.functions.list()
    // Route intent-preload fetches another project while this one is open
    await sdk.forProject('other').functions.list()
    await app.functions.list()

    expect(sent.map((request) => request.project)).toEqual([
      'app',
      'other',
      'app',
    ])
  })

  test('each call asks where the Console is now', async () => {
    await sdk.forProject('app').functions.list()
    setActiveEnvironment('app', 'staging')
    await sdk.forProject('app').functions.list()
    await sdk
      .forProject('app', undefined, { environment: null })
      .functions.list()
    setActiveEnvironment('app', null)
    await sdk.forProject('app').functions.list()

    expect(sent.map((request) => request.environment)).toEqual([
      undefined,
      'staging',
      undefined,
      undefined,
    ])
  })

  test('impersonation reaches the handles already held', async () => {
    setActiveEnvironment('app', 'staging')
    const staging = sdk.forProject('app')

    applyConsoleImpersonateUserId('operator-target')
    await staging.functions.list()
    await sdk.forProject('other').functions.list()
    clearConsoleImpersonateUser()
    await staging.functions.list()

    expect(sent).toEqual([
      {
        project: 'app',
        environment: 'staging',
        impersonate: 'operator-target',
      },
      {
        project: 'other',
        environment: undefined,
        impersonate: 'operator-target',
      },
      { project: 'app', environment: 'staging', impersonate: undefined },
    ])
  })
})
