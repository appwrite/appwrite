import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement } from 'react'
import { join } from 'node:path'
import type { DedicatedDatabaseSource } from '@/lib/databases/dedicated-database-source'
import * as actualHooks from '../../src/lib/react-query/hooks/index.ts'

GlobalRegistrator.register()
const { cleanup, fireEvent, render, waitFor } = await import(
  '@testing-library/react'
)

const consoleRoot = join(import.meta.dir, '../..')
const PROJECT_ID = 'proj0000000000000000001'
const DATABASE_ID = 'db000000000000000000001'
const EARLIEST = '2026-11-20T00:00:00.000Z'
const LATEST = '2026-11-27T00:00:00.000Z'

type Restoration = {
  databaseId: string
  source: DedicatedDatabaseSource
  params: { targetTime: string; targetDatabaseId?: string }
}

// The hooks pull in billing; loading Stripe would inject its script into the DOM
mock.module('@stripe/stripe-js', () => ({ loadStripe: async () => null }))

const listedProjects: (string | null | undefined)[] = []
const restorations: Restoration[] = []

const readyTarget = {
  $id: 'db000000000000000000002',
  name: 'Replica',
  status: 'ready',
  api: 'postgresql',
  engine: 'postgresql',
  version: '17',
}

const hooksPath = join(consoleRoot, 'src/lib/react-query/hooks/index.ts')
const realHooks = { ...actualHooks }
mock.module(hooksPath, () => ({
  ...actualHooks,
  useDedicatedDatabasePitrWindows: () => ({
    data: { earliest: EARLIEST, latest: LATEST },
  }),
  useProjectDedicatedDatabases: (projectId: string | null | undefined) => {
    listedProjects.push(projectId)
    return { databases: projectId ? [readyTarget] : [] }
  },
  createDedicatedDatabasePitrRestoration: async (
    _projectId: string,
    databaseId: string,
    source: DedicatedDatabaseSource,
    params: Restoration['params'],
  ) => {
    restorations.push({ databaseId, source, params })
    return { $id: 'restoration1' }
  },
}))

const { RestorePitrDialog } = await import(
  join(
    consoleRoot,
    'src/components/pages/projects/$projectId/databases/_components/RestorePitr.tsx',
  )
)

const database = {
  $id: DATABASE_ID,
  name: 'Orders',
  status: 'ready',
  api: 'postgresql',
  engine: 'postgresql',
  version: '17',
  pitr: true,
}

function renderDialog(source: DedicatedDatabaseSource) {
  return render(
    createElement(
      QueryClientProvider,
      { client: new QueryClient() },
      createElement(RestorePitrDialog, {
        open: true,
        onOpenChange: () => {},
        projectId: PROJECT_ID,
        databaseId: DATABASE_ID,
        database,
        source,
        canWrite: true,
        initialTimeZone: 'UTC',
      }),
    ),
  )
}

afterEach(() => {
  cleanup()
  listedProjects.length = 0
  restorations.length = 0
})

afterAll(async () => {
  mock.module(hooksPath, () => realHooks)
  mock.restore()
  await GlobalRegistrator.unregister()
})

describe('RestorePitrDialog', () => {
  test.each(['tablesdb', 'documentsdb', 'vectorsdb'] as const)(
    'a %s source offers no restore target and lists no databases',
    (api) => {
      const view = renderDialog({ type: 'product', api })

      expect(view.queryByText('Restore target')).toBeNull()
      expect(view.queryByText('Another database')).toBeNull()
      expect(view.queryByText('Current database')).toBeNull()
      expect(listedProjects.every((projectId) => !projectId)).toBe(true)
    },
  )

  test('a product source restores in place', async () => {
    const view = renderDialog({ type: 'product', api: 'tablesdb' })

    fireEvent.click(view.getByRole('checkbox'))
    fireEvent.click(view.getByText('Restore'))

    await waitFor(() => expect(restorations).toHaveLength(1))
    expect(restorations[0]).toEqual({
      databaseId: DATABASE_ID,
      source: { type: 'product', api: 'tablesdb' },
      params: { targetTime: LATEST },
    })
  })

  test('an engine source still offers another database', () => {
    const view = renderDialog({ type: 'engine', engine: 'postgresql' })

    expect(view.getByText('Restore target')).toBeTruthy()
    expect(view.getByText('Current database')).toBeTruthy()
    expect(view.getByText('Another database')).toBeTruthy()
    expect(listedProjects).toContain(PROJECT_ID)
    expect(
      (
        view.container.ownerDocument.getElementById(
          'pitr-restore-other',
        ) as HTMLButtonElement | null
      )?.disabled,
    ).toBe(false)
  })
})
