import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement, type ReactNode } from 'react'
import { join } from 'node:path'
import * as actualSdk from '@/lib/appwrite/sdk'

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

const { DatabaseNameCard } = await import(
  join(settingsDir, 'DatabaseNameCard.tsx')
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
