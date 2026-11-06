import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import * as actualRouter from '@tanstack/react-router'
import { createElement, type ReactNode } from 'react'
import { join } from 'node:path'
import type { Models } from '@appwrite.io/console'
import * as actualConsoleProfile from '../../src/hooks/use-console-profile.ts'
import * as actualHooks from '../../src/lib/react-query/hooks/index.ts'

GlobalRegistrator.register({ url: 'http://localhost/' })
const { cleanup, render } = await import('@testing-library/react')

const consoleRoot = join(import.meta.dir, '../..')
const children = ({ children }: { children?: ReactNode }) =>
  createElement('div', null, children)

// The hooks pull in billing; loading Stripe would inject its script into the DOM
mock.module('@stripe/stripe-js', () => ({ loadStripe: async () => null }))

mock.module('@tanstack/react-router', () => ({
  ...actualRouter,
  Link: children,
  useNavigate: () => () => {},
  useParams: () => ({ orgId: 'org1', domainId: 'domain1' }),
  useLocation: () => ({
    pathname: '/organizations/org1/domains/domain1',
    search: '',
    searchStr: '',
    hash: '',
  }),
  useSearch: () => ({}),
}))

mock.module(join(consoleRoot, 'src/hooks/use-console-profile.ts'), () => ({
  ...actualConsoleProfile,
  useConsoleProfile: () => ({ isCloud: true, features: {} }),
}))

mock.module(
  join(consoleRoot, 'src/components/global/layout/ConsoleLayout.tsx'),
  () => ({ ConsoleLayout: children }),
)

mock.module(
  join(
    consoleRoot,
    'src/components/pages/projects/$projectId/shared/ServiceHeader.tsx',
  ),
  () => ({ ServiceHeader: () => null }),
)

const query = { data: undefined, isLoading: false, refetch: async () => ({}) }
mock.module(join(consoleRoot, 'src/lib/react-query/hooks/index.ts'), () => ({
  ...actualHooks,
  useDomain: () => query,
  useDomainZone: () => query,
  useDomainTransferStatus: () => query,
  useDomainRecords: () => ({ dnsRecords: [], total: 0, isLoading: false }),
  useOrganizations: () => ({ organizations: [], isLoading: false }),
}))

const { View } = await import(
  join(
    consoleRoot,
    'src/components/pages/organizations/$orgId/domains/$domainId/View.tsx',
  )
)

function renderView(nameservers: string) {
  const domain = {
    $id: 'domain1',
    domain: 'example.com',
    nameservers,
    registrar: '',
    autoRenewal: false,
  } as unknown as Models.Domain
  return render(
    createElement(
      QueryClientProvider,
      { client: new QueryClient() },
      createElement(View, {
        initialData: { domain, records: { dnsRecords: [], total: 0 } },
      }),
    ),
  )
}

afterEach(() => cleanup())

afterAll(async () => {
  mock.restore()
  await GlobalRegistrator.unregister()
})

describe('organization domain nameservers', () => {
  test('shows Appwrite nameservers without a Status or Verify', () => {
    const view = renderView('Appwrite')

    expect(view.getByText('Appwrite')).toBeTruthy()
    expect(view.queryByText('Status')).toBeNull()
    expect(view.queryByText('Verify')).toBeNull()
  })

  test('shows external nameservers with Verify, not a pending Status', () => {
    // The server sends "Appwrite" or an empty string
    const view = renderView('')

    expect(view.getAllByText('3rd party').length).toBeGreaterThan(0)
    expect(view.getByText('Verify')).toBeTruthy()
    expect(view.queryByText('Status')).toBeNull()
    expect(view.queryByText('Pending')).toBeNull()
  })
})
