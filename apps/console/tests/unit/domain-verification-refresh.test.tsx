import { afterAll, beforeAll, describe, expect, spyOn, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, waitFor } from '@testing-library/react'
import { createElement } from 'react'
import { sdk } from '@/lib/appwrite/sdk'
import { useRetryDomainVerification } from '@/lib/react-query/hooks/domains'

describe('domain verification', () => {
  let call: ReturnType<typeof spyOn>

  beforeAll(() => {
    GlobalRegistrator.register()
    call = spyOn(sdk.forConsole.client, 'call').mockResolvedValue({
      $id: 'domain',
      nameservers: 'Appwrite',
    })
  })

  afterAll(async () => {
    cleanup()
    call.mockRestore()
    await GlobalRegistrator.unregister()
  })

  test('refreshes the organization domains list that is not on screen', async () => {
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
