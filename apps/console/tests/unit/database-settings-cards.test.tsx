import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement, type ReactNode } from 'react'
import { join } from 'node:path'
import * as actualSdk from '@/lib/appwrite/sdk'
import * as actualConsoleProfile from '../../src/hooks/use-console-profile.ts'
import * as actualHooks from '../../src/lib/react-query/hooks/index.ts'

GlobalRegistrator.register()
const { act, cleanup, fireEvent, render, waitFor } = await import(
  '@testing-library/react'
)

const consoleRoot = join(import.meta.dir, '../..')
const settingsDir = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/databases/settings',
)

const PROJECT_ID = 'proj0000000000000000001'
const DATABASE_ID = 'db000000000000000000001'

type Call = { service: string; method: string; params: unknown }

// The hooks pull in billing; loading Stripe would inject its script into the DOM
mock.module('@stripe/stripe-js', () => ({ loadStripe: async () => null }))

// React DOM wires up typing only if a DOM existed when it first loaded, which
// depends on the files run before this one, so the test types through props.
const inputPath = join(consoleRoot, 'src/components/ui/input.tsx')
const realInput = { ...(await import(inputPath)) }
const typers = new Map<string, (value: string) => void>()
function FakeInput(props: {
  id?: string
  placeholder?: string
  value?: string
  onChange?: (event: { target: { value: string } }) => void
}) {
  typers.set(props.id ?? props.placeholder ?? '', (value) =>
    props.onChange?.({ target: { value } }),
  )
  return createElement('input', {
    id: props.id,
    placeholder: props.placeholder,
    value: props.value,
    readOnly: true,
  })
}
mock.module(inputPath, () => ({ Input: FakeInput }))

function type(field: string, value: string) {
  act(() => typers.get(field)?.(value))
}

// Copy the real module now: the namespace import is live and points at the mock once it is set.
const realSdk = { ...actualSdk }
const calls: Call[] = []

function productService(name: string) {
  const record =
    (method: string) => async (params: Record<string, unknown>) => {
      calls.push({ service: name, method, params })
      return { $id: DATABASE_ID, name: 'Orders', ...params }
    }
  return { update: record('update'), get: record('get') }
}

mock.module('@/lib/appwrite/sdk', () => ({
  ...actualSdk,
  sdk: {
    ...actualSdk.sdk,
    forProject: () => ({
      tablesDB: productService('tablesDB'),
      documentsDB: productService('documentsDB'),
      vectorsDB: productService('vectorsDB'),
    }),
  },
}))

function specification(
  slug: string,
  name: string,
  price: number,
  includedStorage: number,
) {
  return {
    slug,
    name,
    price,
    storageOverageRate: 0.25,
    bandwidthOverageRate: 0,
    replicaRate: 0.5,
    pitrRate: 0.2,
    cpu: 2000,
    memory: 4096,
    maxConnections: 200,
    includedStorage,
    includedBandwidth: 0,
    enabled: true,
  }
}

const consoleProfilePath = join(consoleRoot, 'src/hooks/use-console-profile.ts')
const hooksPath = join(consoleRoot, 'src/lib/react-query/hooks/index.ts')
const realConsoleProfile = { ...actualConsoleProfile }
const realHooks = { ...actualHooks }
mock.module(consoleProfilePath, () => ({
  ...actualConsoleProfile,
  useConsoleProfile: () => ({ features: { billing: true } }),
}))
mock.module(hooksPath, () => ({
  ...actualHooks,
  useProject: () => ({ project: { teamId: 'team1', region: 'fra' } }),
  useOrganizationPlan: () => ({
    plan: {
      supportsDedicatedDatabases: true,
      dedicatedDatabases: { maxPitrRetentionDays: 35 },
    },
  }),
  useDatabaseSpecifications: () => ({
    data: {
      specifications: [
        specification('s-1vcpu-1gb', 'Starter', 10, 10),
        specification('s-2vcpu-4gb', 'Standard Plus', 40, 50),
        specification('s-4vcpu-8gb', 'Pro', 80, 100),
      ],
      pricing: { replicaRate: 0.5, pitrRate: 0.2 },
    },
    isSuccess: true,
  }),
}))

const { DatabaseNameCard } = await import(
  join(settingsDir, 'DatabaseNameCard.tsx')
)
const { DatabasePitrCard } = await import(
  join(settingsDir, 'DatabasePitrCard.tsx')
)
const { DatabaseSpecificationCard } = await import(
  join(settingsDir, 'DatabaseSpecificationCard.tsx')
)
const { TooltipProvider } = await import(
  join(consoleRoot, 'src/components/ui/tooltip.tsx')
)

function wrap(element: ReactNode) {
  return render(
    createElement(
      QueryClientProvider,
      { client: new QueryClient() },
      createElement(TooltipProvider, null, element),
    ),
  )
}

const database = {
  $id: DATABASE_ID,
  name: 'Orders',
  enabled: false,
  createdAt: '2026-11-01T00:00:00.000Z',
  updatedAt: '2026-11-01T00:00:00.000Z',
}

afterEach(() => {
  cleanup()
  calls.length = 0
})

afterAll(async () => {
  mock.module(hooksPath, () => realHooks)
  mock.module(consoleProfilePath, () => realConsoleProfile)
  mock.module(inputPath, () => realInput)
  mock.module('@/lib/appwrite/sdk', () => realSdk)
  mock.restore()
  await GlobalRegistrator.unregister()
})

describe('DatabaseNameCard', () => {
  test('renaming a disabled database keeps it disabled', async () => {
    const view = wrap(
      createElement(DatabaseNameCard, {
        projectId: PROJECT_ID,
        databaseId: DATABASE_ID,
        dbKind: 'documentsdb',
        database,
        canWrite: true,
      }),
    )

    type('Database name', 'Invoices')
    fireEvent.click(view.getByText('Update'))

    await waitFor(() => expect(calls.length).toBeGreaterThan(0))
    expect(calls[0]).toEqual({
      service: 'documentsDB',
      method: 'update',
      params: { databaseId: DATABASE_ID, name: 'Invoices', enabled: false },
    })
  })
})

describe('DatabasePitrCard', () => {
  const dedicated = {
    ...database,
    enabled: true,
    specification: 's-2vcpu-4gb',
    pitr: false,
    pitrRetentionDays: 7,
  }

  function renderCard(overrides: Record<string, unknown> = {}) {
    return wrap(
      createElement(DatabasePitrCard, {
        projectId: PROJECT_ID,
        databaseId: DATABASE_ID,
        dbKind: 'vectorsdb',
        database: { ...dedicated, ...overrides },
        canWrite: true,
      }),
    )
  }

  test('turning pitr on sends it with the new retention, name and enabled', async () => {
    const view = renderCard()

    fireEvent.click(view.getByRole('switch'))
    expect(view.getByText(/\$8\.00/)).toBeTruthy()
    type('pitr-retention', '14')
    fireEvent.click(view.getByText('Update'))

    await waitFor(() => expect(calls.length).toBeGreaterThan(0))
    expect(calls[0]).toEqual({
      service: 'vectorsDB',
      method: 'update',
      params: {
        databaseId: DATABASE_ID,
        name: 'Orders',
        enabled: true,
        pitr: true,
        pitrRetentionDays: 14,
      },
    })
  })

  test('turning pitr off leaves the retention alone', async () => {
    const view = renderCard({ pitr: true })

    fireEvent.click(view.getByRole('switch'))
    fireEvent.click(view.getByText('Update'))

    await waitFor(() => expect(calls.length).toBeGreaterThan(0))
    expect(calls[0]?.params).toEqual({
      databaseId: DATABASE_ID,
      name: 'Orders',
      enabled: true,
      pitr: false,
    })
  })

  test('a retention past the plan maximum cannot be saved', () => {
    const view = renderCard({ pitr: true })

    type('pitr-retention', '36')

    expect(
      view.getByText('Retention must be between 1 and 35 days.'),
    ).toBeTruthy()
    expect(view.getByText('Update').closest('button')?.disabled).toBe(true)
  })
})

describe('DatabaseSpecificationCard', () => {
  function renderCard(dbKind: string, storage: number | null) {
    return wrap(
      createElement(DatabaseSpecificationCard, {
        projectId: PROJECT_ID,
        databaseId: DATABASE_ID,
        dbKind,
        database: {
          ...database,
          specification: 's-2vcpu-4gb',
          storage,
        },
        canWrite: true,
      }),
    )
  }

  test('offers lower tiers as a downgrade that keeps the storage', async () => {
    const view = renderCard('vectorsdb', 200)

    expect(view.getAllByText('Downgrade')).toHaveLength(1)
    expect(view.getAllByText('Upgrade')).toHaveLength(1)
    expect(
      view.getByText(
        'Storage stays at 200 GB, billed at $47.50 per month above this tier.',
      ),
    ).toBeTruthy()
    expect(
      view.getByText(
        'Storage stays at 200 GB, billed at $25.00 per month above this tier.',
      ),
    ).toBeTruthy()

    fireEvent.click(view.getByText('Downgrade'))

    await waitFor(() => expect(calls.length).toBeGreaterThan(0))
    expect(calls[0]).toEqual({
      service: 'vectorsDB',
      method: 'update',
      params: {
        databaseId: DATABASE_ID,
        name: 'Orders',
        enabled: false,
        specification: 's-1vcpu-1gb',
      },
    })
  })

  test('never offers to move a dedicated database back to serverless', () => {
    const view = renderCard('tablesdb', 50)

    expect(view.getByText('Serverless')).toBeTruthy()
    expect(view.getAllByText('Downgrade')).toHaveLength(1)
    expect(view.getAllByText(/Storage stays at/)).toHaveLength(1)
  })

  test('shows no storage charge when the database storage is unknown', () => {
    const view = renderCard('vectorsdb', null)

    expect(view.getAllByText('Downgrade')).toHaveLength(1)
    expect(view.queryByText(/Storage stays at/)).toBeNull()
  })
})
