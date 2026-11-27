import { afterAll, describe, expect, mock, test } from 'bun:test'
import * as actualSdk from '@/lib/appwrite/sdk'
import { updateDedicatedDatabaseHa } from '@/lib/react-query/hooks/dedicated-database-replication'

const PROJECT_ID = 'proj0000000000000000001'
const DATABASE_ID = 'db000000000000000000001'

type Call = { service: string; params: unknown }

describe('dedicated database HA update', () => {
  // Copy the real module now: the namespace import is live and points at the mock once it is set.
  const realSdk = { ...actualSdk }

  afterAll(() => {
    mock.module('@/lib/appwrite/sdk', () => realSdk)
  })

  function mockSdk() {
    const calls: Call[] = []
    const service = (name: string) => ({
      update: async (params: Record<string, unknown>) => {
        calls.push({ service: name, params })
        return { $id: DATABASE_ID, ...params }
      },
    })

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
    'replicas on a disabled %s database keep it disabled and named',
    async (api, service) => {
      const calls = mockSdk()

      await updateDedicatedDatabaseHa(
        PROJECT_ID,
        DATABASE_ID,
        {
          type: 'product',
          api,
          identity: { name: 'Orders', enabled: false },
        },
        { replicas: 2 },
      )

      expect(calls).toEqual([
        {
          service,
          params: {
            databaseId: DATABASE_ID,
            name: 'Orders',
            enabled: false,
            replicas: 2,
          },
        },
      ])
    },
  )

  test('sync mode on a product database carries its identity', async () => {
    const calls = mockSdk()

    await updateDedicatedDatabaseHa(
      PROJECT_ID,
      DATABASE_ID,
      {
        type: 'product',
        api: 'documentsdb',
        identity: { name: '   ', enabled: true },
      },
      { syncMode: 'sync' },
    )

    expect(calls).toEqual([
      {
        service: 'documentsDB',
        params: {
          databaseId: DATABASE_ID,
          name: DATABASE_ID,
          enabled: true,
          syncMode: 'sync',
        },
      },
    ])
  })

  test.each([['mysql'], ['postgresql']])(
    'a native %s database sends only the HA fields',
    async (engine) => {
      const calls = mockSdk()

      await updateDedicatedDatabaseHa(
        PROJECT_ID,
        DATABASE_ID,
        { type: 'engine', engine },
        { replicas: 1, syncMode: 'async' },
      )

      expect(calls).toEqual([
        {
          service: engine,
          params: { databaseId: DATABASE_ID, replicas: 1, syncMode: 'async' },
        },
      ])
    },
  )
})
