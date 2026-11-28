import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { runtimeLogDatabaseSource } from '@/lib/react-query/hooks/runtime-logs'

type ListedDatabase = Parameters<typeof runtimeLogDatabaseSource>[0]

function database(fields: Partial<ListedDatabase>): ListedDatabase {
  return {
    $id: 'db1',
    name: 'Orders',
    type: 'tablesdb' as Models.Database['type'],
    engine: null,
    specification: null,
    replicas: null,
    ...fields,
  }
}

describe('runtimeLogDatabaseSource', () => {
  test('a native database on dedicated compute logs under its own engine', () => {
    expect(
      runtimeLogDatabaseSource(
        database({
          type: 'postgresql' as Models.Database['type'],
          engine: 'postgresql',
          specification: 's-2vcpu-2gb',
          replicas: 0,
        }),
      ),
    ).toEqual({
      type: 'databases',
      id: 'db1',
      name: 'Orders',
      engine: 'postgresql',
      api: undefined,
    })
  })

  test('a TablesDB on a dedicated specification logs, named by its product', () => {
    expect(
      runtimeLogDatabaseSource(
        database({ engine: 'mysql', specification: 's-1vcpu-1gb' }),
      ),
    ).toMatchObject({ engine: 'mysql', api: 'tablesdb' })
  })

  test.each([
    ['without a backing', {}],
    ['on the serverless specification', { engine: 'mysql', specification: 'serverless' }],
    ['on the shared specification', { engine: 'mysql', specification: 'shared' }],
    [
      'on a serverless specification with replicas reported',
      { engine: 'mysql', specification: 'serverless', replicas: 0 },
    ],
    ['with a backing but no dedicated specification', { engine: 'mysql' }],
  ] satisfies [string, Partial<ListedDatabase>][])(
    'a serverless TablesDB %s writes no logs',
    (_, fields) => {
      expect(runtimeLogDatabaseSource(database(fields))).toBeNull()
    },
  )
})
