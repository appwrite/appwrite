// Regression: a new DocumentsDB or VectorsDB database reports `health: unhealthy`
// while its compute is still starting. Reading that as `failed` showed the
// failure banner, stopped status polling, and broke the create wizard.

import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { readProductDatabaseLifecycleStatus } from '@/lib/react-query/hooks/databases'

function database(overrides: Partial<Models.Database>): Models.Database {
  return {
    $id: 'database',
    name: 'Database',
    $createdAt: '2026-10-06T00:00:00.000Z',
    $updatedAt: '2026-10-06T00:00:00.000Z',
    enabled: true,
    type: 'documentsdb' as Models.Database['type'],
    ...overrides,
  }
}

function status(
  overrides: Partial<Models.DatabaseStatus>,
): Models.DatabaseStatus {
  return {
    health: 'unknown',
    ready: false,
    engine: 'mongodb',
    version: '8',
    uptime: 0,
    syncMode: 'async',
    syncDegraded: false,
    syncAcknowledgements: 0,
    syncStandbyCount: 0,
    replicas: [],
    volumes: [],
    ...overrides,
  }
}

describe('readProductDatabaseLifecycleStatus', () => {
  test('unhealthy compute that is still starting is provisioning', () => {
    expect(
      readProductDatabaseLifecycleStatus(
        database({ status: status({ health: 'unhealthy' }) }),
      ),
    ).toBe('provisioning')
  })

  test('degraded compute is provisioning', () => {
    expect(
      readProductDatabaseLifecycleStatus(
        database({ status: status({ health: 'degraded' }) }),
      ),
    ).toBe('provisioning')
  })

  test('ready compute is ready', () => {
    expect(
      readProductDatabaseLifecycleStatus(
        database({ status: status({ health: 'healthy', ready: true }) }),
      ),
    ).toBe('ready')
  })

  test('a backing error is failed', () => {
    expect(
      readProductDatabaseLifecycleStatus(
        database({
          status: status({ health: 'unhealthy' }),
          error: 'Provisioning failed',
        }),
      ),
    ).toBe('failed')
  })

  test('a failed container is failed', () => {
    expect(
      readProductDatabaseLifecycleStatus(
        database({
          status: status({ health: 'unhealthy' }),
          containerStatus: 'failed',
        }),
      ),
    ).toBe('failed')
  })

  test('unmeasured compute has no status yet', () => {
    expect(
      readProductDatabaseLifecycleStatus(database({ status: status({}) })),
    ).toBeNull()
  })
})
