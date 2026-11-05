import { afterAll, beforeAll, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, waitFor } from '@testing-library/react'
import { createElement } from 'react'
import * as actualSdk from '@/lib/appwrite/sdk'

describe('domain verification', () => {
  beforeAll(() => {
    GlobalRegistrator.register()
    mock.module('@/lib/appwrite/sdk', () => ({
      ...actualSdk,
      sdk: {
        forConsole: {
          domains: {
            verifyNameservers: async ({ domainId }: { domainId: string }) => ({
              $id: domainId,
              nameservers: 'Appwrite',
            }),
          },
        },
      },
    }))
  })

  afterAll(async () => {
    cleanup()
    mock.module('@/lib/appwrite/sdk', () => actualSdk)
    await GlobalRegistrator.unregister()
  })

  test('refreshes the organization domains list that is not on screen', async () => {
    const { useRetryDomainVerification } = await import(
      '@/lib/react-query/hooks/domains'
    )
    const queryClient = new QueryClient()
    let listFetches = 0
    await queryClient.prefetchQuery({
      queryKey: ['domains', 'organization', 'org', 0, 25],
      queryFn: async () => {
        listFetches++
        return { domains: [], total: 0 }
      },
    })

    let verify: ((domainId: string) => Promise<unknown>) | undefined
    function Verify() {
      verify = useRetryDomainVerification('org').mutateAsync
      return null
    }
    render(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(Verify),
      ),
    )

    await verify!('domain')

    await waitFor(() => expect(listFetches).toBe(2))
  })
})
