import { afterAll, describe, expect, mock, test } from 'bun:test'
import * as actualSdk from '@/lib/appwrite/sdk'
import {
  createDedicatedDatabasePitrRestoration,
  dedicatedDatabasePitrWindowsQueryOptions,
  fetchDedicatedDatabasePitrWindows,
  fetchDedicatedDatabaseRestorations,
} from '@/lib/react-query/hooks/dedicated-database-pitr'

const PROJECT_ID = 'proj0000000000000000001'
const DATABASE_ID = 'db000000000000000000001'
const TARGET_TIME = '2026-11-27T10:00:00.000Z'

type Call = { service: string; method: string; params: unknown }

describe('dedicated database PITR routing', () => {
  // Copy the real module now: the namespace import is live and points at the mock once it is set.
  const realSdk = { ...actualSdk }

  afterAll(() => {
    mock.module('@/lib/appwrite/sdk', () => realSdk)
  })

  function mockSdk() {
    const calls: Call[] = []
    const service = (name: string) => {
      const record = (method: string) => async (params: unknown) => {
        calls.push({ service: name, method, params })
        return method === 'listRestorations'
          ? { restorations: [], total: 0 }
          : { $id: 'result1' }
      }
      return {
        getPitr: record('getPitr'),
        listRestorations: record('listRestorations'),
        createRestoration: record('createRestoration'),
      }
    }
    mock.module('@/lib/appwrite/sdk', () => ({
      ...actualSdk,
      sdk: {
        forProject: () => ({
          tablesDB: service('tablesDB'),
          documentsDB: service('documentsDB'),
          vectorsDB: service('vectorsDB'),
          mysql: service('mysql'),
          postgresql: service('postgresql'),
          mongo: service('mongo'),
        }),
      },
    }))
    return calls
  }

  test.each([
    ['tablesdb', 'tablesDB'],
    ['documentsdb', 'documentsDB'],
    ['vectorsdb', 'vectorsDB'],
  ] as const)(
    'reads the %s recovery window from its own product API',
    async (api, service) => {
      const calls = mockSdk()

      await fetchDedicatedDatabasePitrWindows(PROJECT_ID, DATABASE_ID, {
        type: 'product',
        api,
      })

      expect(calls).toEqual([
        { service, method: 'getPitr', params: { databaseId: DATABASE_ID } },
      ])
    },
  )

  test.each([
    ['mysql', 'mysql'],
    ['mariadb', 'mysql'],
    ['postgresql', 'postgresql'],
    ['mongodb', 'mongo'],
  ])('reads a native %s recovery window from %s', async (engine, service) => {
    const calls = mockSdk()

    await fetchDedicatedDatabasePitrWindows(PROJECT_ID, DATABASE_ID, {
      type: 'engine',
      engine,
    })

    expect(calls.map((call) => call.service)).toEqual([service])
  })

  test('lists product restorations on the product API', async () => {
    const calls = mockSdk()

    await fetchDedicatedDatabaseRestorations(PROJECT_ID, DATABASE_ID, {
      type: 'product',
      api: 'tablesdb',
    })

    expect(calls).toEqual([
      {
        service: 'tablesDB',
        method: 'listRestorations',
        params: { databaseId: DATABASE_ID, limit: 10, offset: 0 },
      },
    ])
  })

  test('restores a product database in place on its product API', async () => {
    const calls = mockSdk()

    await createDedicatedDatabasePitrRestoration(
      PROJECT_ID,
      DATABASE_ID,
      { type: 'product', api: 'documentsdb' },
      { targetTime: TARGET_TIME },
    )

    expect(calls).toEqual([
      {
        service: 'documentsDB',
        method: 'createRestoration',
        params: {
          databaseId: DATABASE_ID,
          type: 'pitr',
          targetTime: TARGET_TIME,
        },
      },
    ])
  })

  test('restores a native database into another database', async () => {
    const calls = mockSdk()

    await createDedicatedDatabasePitrRestoration(
      PROJECT_ID,
      DATABASE_ID,
      { type: 'engine', engine: 'postgresql' },
      { targetTime: TARGET_TIME, targetDatabaseId: 'replica2' },
    )

    expect(calls).toEqual([
      {
        service: 'postgresql',
        method: 'createRestoration',
        params: {
          databaseId: DATABASE_ID,
          type: 'pitr',
          targetTime: TARGET_TIME,
          targetDatabaseId: 'replica2',
        },
      },
    ])
  })

  test('restores in place when no target is chosen', async () => {
    const calls = mockSdk()

    await createDedicatedDatabasePitrRestoration(
      PROJECT_ID,
      DATABASE_ID,
      { type: 'engine', engine: 'mysql' },
      { targetTime: TARGET_TIME },
    )

    expect(calls).toEqual([
      {
        service: 'mysql',
        method: 'createRestoration',
        params: {
          databaseId: DATABASE_ID,
          type: 'pitr',
          targetTime: TARGET_TIME,
        },
      },
    ])
  })

  test('caches product and engine windows apart, and engine aliases together', () => {
    const key = (
      source: Parameters<typeof dedicatedDatabasePitrWindowsQueryOptions>[2],
    ) =>
      dedicatedDatabasePitrWindowsQueryOptions(PROJECT_ID, DATABASE_ID, source)
        .queryKey

    expect(key({ type: 'product', api: 'tablesdb' })).not.toEqual(
      key({ type: 'engine', engine: 'mysql' }),
    )
    expect(key({ type: 'engine', engine: 'mariadb' })).toEqual(
      key({ type: 'engine', engine: 'mysql' }),
    )
  })
})
