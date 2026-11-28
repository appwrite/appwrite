import { afterAll, describe, expect, mock, test } from 'bun:test'
import { AppwriteException } from '@appwrite.io/console'
import * as actualSdk from '@/lib/appwrite/sdk'
import { updateProductDatabaseSpecification } from '@/lib/react-query/hooks/databases'

const PROJECT_ID = 'proj0000000000000000001'
const DATABASE_ID = 'db000000000000000000001'
const TARGET_SPECIFICATION = 's-2vcpu-4gb'
const IDENTITY = { name: 'Orders', enabled: true }

type Call = { service: string; method: string; params: unknown }

/**
 * Cloud keeps product databases off the raw engine API: `GET /v1/mysql/{id}`
 * is a 404 for every TablesDB database, dedicated or not. Whether a TablesDB
 * database already runs on dedicated compute can only be read from TablesDB.
 */
describe('updateProductDatabaseSpecification', () => {
  // Copy the real module now: the namespace import is live and points at the mock once it is set.
  const realSdk = { ...actualSdk }

  afterAll(() => {
    mock.module('@/lib/appwrite/sdk', () => realSdk)
  })

  function mockSdk(options: {
    specification?: string
    createMigration?: () => Promise<unknown>
  }) {
    const calls: Call[] = []
    const record =
      <T>(service: string, method: string, result: () => T | Promise<T>) =>
      async (params: unknown) => {
        calls.push({ service, method, params })
        return result()
      }
    const engineNotFound = () => {
      throw new AppwriteException(
        'Database not found',
        404,
        'dedicated_database_not_found',
      )
    }

    mock.module('@/lib/appwrite/sdk', () => ({
      ...actualSdk,
      sdk: {
        forProject: () => ({
          tablesDB: {
            get: record('tablesDB', 'get', () => ({
              $id: DATABASE_ID,
              name: 'Orders',
              ...(options.specification === undefined
                ? {}
                : { specification: options.specification }),
            })),
            update: record('tablesDB', 'update', () => ({
              $id: DATABASE_ID,
              specification: TARGET_SPECIFICATION,
            })),
            listMigrations: record('tablesDB', 'listMigrations', () => ({
              migrations: [],
              total: 0,
            })),
            createMigration: record(
              'tablesDB',
              'createMigration',
              options.createMigration ??
                (() => ({ $id: 'migration1', phase: 'pending' })),
            ),
          },
          documentsDB: {
            update: record('documentsDB', 'update', () => ({
              $id: DATABASE_ID,
              specification: TARGET_SPECIFICATION,
            })),
          },
          mysql: {
            get: record('mysql', 'get', engineNotFound),
            list: record('mysql', 'list', () => ({ databases: [], total: 0 })),
            createMigration: record('mysql', 'createMigration', engineNotFound),
          },
        }),
      },
    }))

    return calls
  }

  function methodsOn(calls: Call[], service: string): string[] {
    return calls
      .filter((call) => call.service === service)
      .map((call) => call.method)
  }

  test('resizes a dedicated TablesDB database through tablesDB.update', async () => {
    const calls = mockSdk({ specification: 's-1vcpu-1gb' })

    await updateProductDatabaseSpecification(
      PROJECT_ID,
      DATABASE_ID,
      'tablesdb',
      TARGET_SPECIFICATION,
      IDENTITY,
    )

    expect(methodsOn(calls, 'tablesDB')).toEqual(['get', 'update'])
    expect(calls.at(-1)?.params).toEqual({
      databaseId: DATABASE_ID,
      name: 'Orders',
      enabled: true,
      specification: TARGET_SPECIFICATION,
    })
    expect(methodsOn(calls, 'mysql')).toEqual([])
  })

  test('keeps a disabled database disabled when it changes tier', async () => {
    const calls = mockSdk({ specification: 's-1vcpu-1gb' })

    await updateProductDatabaseSpecification(
      PROJECT_ID,
      DATABASE_ID,
      'tablesdb',
      TARGET_SPECIFICATION,
      { name: 'Orders', enabled: false },
    )

    expect(calls.at(-1)).toEqual({
      service: 'tablesDB',
      method: 'update',
      params: {
        databaseId: DATABASE_ID,
        name: 'Orders',
        enabled: false,
        specification: TARGET_SPECIFICATION,
      },
    })
  })

  test('sends the name DocumentsDB requires when it changes tier', async () => {
    const calls = mockSdk({})

    await updateProductDatabaseSpecification(
      PROJECT_ID,
      DATABASE_ID,
      'documentsdb',
      TARGET_SPECIFICATION,
      { name: 'Catalog', enabled: false },
    )

    expect(calls).toEqual([
      {
        service: 'documentsDB',
        method: 'update',
        params: {
          databaseId: DATABASE_ID,
          name: 'Catalog',
          enabled: false,
          specification: TARGET_SPECIFICATION,
        },
      },
    ])
  })

  test.each([['shared'], ['serverless'], [undefined]])(
    'migrates a serverless TablesDB database (specification %p)',
    async (specification) => {
      const calls = mockSdk({ specification })

      await updateProductDatabaseSpecification(
        PROJECT_ID,
        DATABASE_ID,
        'tablesdb',
        TARGET_SPECIFICATION,
        IDENTITY,
      )

      expect(methodsOn(calls, 'tablesDB')).toEqual([
        'get',
        'listMigrations',
        'createMigration',
      ])
      expect(calls.at(-1)?.params).toEqual({
        databaseId: DATABASE_ID,
        specification: TARGET_SPECIFICATION,
        autoCutover: true,
      })
      expect(methodsOn(calls, 'mysql')).toEqual([])
    },
  )

  test('resizes through tablesDB.update when migration reports the database is dedicated', async () => {
    const calls = mockSdk({
      createMigration: () => {
        throw new AppwriteException(
          'This database is already attached to a dedicated compute and is not serverless.',
          400,
          'database_not_serverless',
        )
      },
    })

    await updateProductDatabaseSpecification(
      PROJECT_ID,
      DATABASE_ID,
      'tablesdb',
      TARGET_SPECIFICATION,
      IDENTITY,
    )

    expect(calls.at(-1)).toEqual({
      service: 'tablesDB',
      method: 'update',
      params: {
        databaseId: DATABASE_ID,
        name: 'Orders',
        enabled: true,
        specification: TARGET_SPECIFICATION,
      },
    })
  })

  test('surfaces a migration 404 instead of retrying on the mysql engine', async () => {
    const calls = mockSdk({
      specification: 'shared',
      createMigration: () => {
        throw new AppwriteException(
          'Database not found',
          404,
          'database_not_found',
        )
      },
    })

    const attempt = updateProductDatabaseSpecification(
      PROJECT_ID,
      DATABASE_ID,
      'tablesdb',
      TARGET_SPECIFICATION,
      IDENTITY,
    )

    await expect(attempt).rejects.toMatchObject({
      code: 404,
      type: 'database_not_found',
    })
    expect(methodsOn(calls, 'mysql')).toEqual([])
  })
})
