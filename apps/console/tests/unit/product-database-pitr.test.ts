import { afterAll, describe, expect, mock, test } from 'bun:test'
import * as actualSdk from '@/lib/appwrite/sdk'
import { DatabaseType } from '@/lib/databases/database-type'
import {
  createProjectDatabase,
  updateProductDatabasePitr,
  waitForCreatedDatabasePitrReady,
} from '@/lib/react-query/hooks/databases'

const PROJECT_ID = 'proj0000000000000000001'
const DATABASE_ID = 'db000000000000000000001'
const SPECIFICATION = 's-2vcpu-4gb'

type Call = { service: string; method: string; params: unknown }

describe('product database PITR', () => {
  // Copy the real module now: the namespace import is live and points at the mock once it is set.
  const realSdk = { ...actualSdk }

  afterAll(() => {
    mock.module('@/lib/appwrite/sdk', () => realSdk)
  })

  function mockSdk(database: Record<string, unknown> = {}) {
    const calls: Call[] = []
    const service = (name: string) => {
      const record =
        (method: string) => async (params: Record<string, unknown>) => {
          calls.push({ service: name, method, params })
          return {
            $id: DATABASE_ID,
            name: 'Orders',
            ...database,
            ...(method === 'get' ? {} : params),
          }
        }
      return {
        create: record('create'),
        update: record('update'),
        get: record('get'),
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

  test.each([[true], [false]])(
    'create sends pitr %p for a dedicated database',
    async (pitrEnabled) => {
      const calls = mockSdk()

      await createProjectDatabase(
        PROJECT_ID,
        { databaseId: DATABASE_ID, name: 'Orders' },
        DatabaseType.Tablesdb,
        { specification: SPECIFICATION, pitrEnabled },
      )

      expect(calls).toEqual([
        {
          service: 'tablesDB',
          method: 'create',
          params: {
            databaseId: DATABASE_ID,
            name: 'Orders',
            specification: SPECIFICATION,
            pitr: pitrEnabled,
          },
        },
      ])
    },
  )

  test('create sends no pitr for a serverless database', async () => {
    const calls = mockSdk()

    await createProjectDatabase(
      PROJECT_ID,
      { databaseId: DATABASE_ID, name: 'Orders' },
      DatabaseType.Tablesdb,
      { specification: 'shared', pitrEnabled: true },
    )

    expect(calls).toEqual([
      {
        service: 'tablesDB',
        method: 'create',
        params: { databaseId: DATABASE_ID, name: 'Orders' },
      },
    ])
  })

  test('create leaves pitr to the server when the caller does not choose', async () => {
    const calls = mockSdk()

    await createProjectDatabase(
      PROJECT_ID,
      { databaseId: DATABASE_ID, name: 'Catalog' },
      DatabaseType.Documentsdb,
      { specification: SPECIFICATION },
    )

    expect(calls.map((call) => call.params)).toEqual([
      {
        databaseId: DATABASE_ID,
        name: 'Catalog',
        specification: SPECIFICATION,
      },
    ])
    expect(calls[0]?.service).toBe('documentsDB')
  })

  test.each([
    ['tablesdb', 'tablesDB'],
    ['documentsdb', 'documentsDB'],
    ['vectorsdb', 'vectorsDB'],
  ] as const)(
    'update on %s carries the loaded name and enabled with pitr',
    async (dbKind, service) => {
      const calls = mockSdk()

      await updateProductDatabasePitr(PROJECT_ID, DATABASE_ID, dbKind, {
        name: 'Orders',
        enabled: false,
        pitr: true,
        pitrRetentionDays: 14,
      })

      expect(calls).toEqual([
        {
          service,
          method: 'update',
          params: {
            databaseId: DATABASE_ID,
            name: 'Orders',
            enabled: false,
            pitr: true,
            pitrRetentionDays: 14,
          },
        },
      ])
    },
  )

  test('turning pitr off leaves the retention unchanged', async () => {
    const calls = mockSdk()

    await updateProductDatabasePitr(PROJECT_ID, DATABASE_ID, 'tablesdb', {
      name: 'Orders',
      enabled: true,
      pitr: false,
    })

    expect(calls[0]?.params).toEqual({
      databaseId: DATABASE_ID,
      name: 'Orders',
      enabled: true,
      pitr: false,
    })
  })

  test('the create wizard reads pitr from the product API', async () => {
    const calls = mockSdk({ pitr: true })

    const ready = await waitForCreatedDatabasePitrReady(
      PROJECT_ID,
      DATABASE_ID,
      { type: 'product', backend: DatabaseType.Tablesdb },
    )

    expect(ready).toBe(true)
    expect(calls).toEqual([
      {
        service: 'tablesDB',
        method: 'get',
        params: { databaseId: DATABASE_ID },
      },
    ])
  })
})
