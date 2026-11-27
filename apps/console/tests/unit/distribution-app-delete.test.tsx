import {
  afterAll,
  afterEach,
  beforeAll,
  describe,
  expect,
  spyOn,
  test,
} from 'bun:test'
import { registerDom, unregisterDom } from './dom.ts'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, cleanup, render, waitFor } from '@testing-library/react'
import { createElement, useState } from 'react'
import { Distribution } from '@/lib/appwrite/distribution'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import {
  useDeleteDistributionApp,
  useDistributionApp,
} from '@/lib/react-query/hooks/distribution'

const PROJECT_ID = 'project'
const APP_ID = 'app'

const app = { $id: APP_ID, name: 'Acme Shop' } as DistributionApp

describe('deleting a distribution app', () => {
  let getApp: ReturnType<typeof spyOn>
  let deleteApp: ReturnType<typeof spyOn>
  let listApps: ReturnType<typeof spyOn>

  beforeAll(() => {
    registerDom()
    getApp = spyOn(Distribution.prototype, 'getApp').mockResolvedValue(app)
    deleteApp = spyOn(Distribution.prototype, 'deleteApp').mockResolvedValue({})
    listApps = spyOn(Distribution.prototype, 'listApps').mockResolvedValue({
      apps: [],
      total: 0,
    })
  })

  afterEach(() => {
    cleanup()
    getApp.mockClear()
    deleteApp.mockClear()
    listApps.mockClear()
  })

  afterAll(async () => {
    getApp.mockRestore()
    deleteApp.mockRestore()
    listApps.mockRestore()
    await unregisterDom()
  })

  test('leaves the app page before forgetting the app, so it is never fetched again', async () => {
    const queryClient = new QueryClient()
    let rerender: (() => void) | undefined
    let remove: (appId: string) => Promise<unknown>
    const unmount = { current: () => {} }
    let cachedWhileLeaving: DistributionApp | undefined
    const onDeleted = async () => {
      cachedWhileLeaving = queryClient.getQueryData<DistributionApp>([
        'distribution-app',
        PROJECT_ID,
        APP_ID,
      ])
      act(() => rerender!())
      await new Promise((resolve) => setTimeout(resolve, 0))
      act(() => unmount.current())
    }

    function Page() {
      const [, setRenders] = useState(0)
      rerender = () => setRenders((renders) => renders + 1)
      useDistributionApp(PROJECT_ID, APP_ID)
      remove = useDeleteDistributionApp(PROJECT_ID, onDeleted).mutateAsync
      return null
    }
    const view = render(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(Page),
      ),
    )
    unmount.current = view.unmount
    await waitFor(() =>
      expect(
        queryClient.getQueryData<DistributionApp>([
          'distribution-app',
          PROJECT_ID,
          APP_ID,
        ]),
      ).toEqual(app),
    )

    await remove!(APP_ID)

    expect(cachedWhileLeaving).toEqual(app)
    expect(getApp).toHaveBeenCalledTimes(1)
    expect(deleteApp).toHaveBeenCalledTimes(1)
    expect(
      queryClient.getQueryCache().find({
        queryKey: ['distribution-app', PROJECT_ID, APP_ID],
      }),
    ).toBeUndefined()
  })

  test('treats a failure after deleting as a completed delete', async () => {
    const queryClient = new QueryClient()
    queryClient.setQueryData(['distribution-app', PROJECT_ID, APP_ID], app)
    queryClient.setQueryData(['distribution-targets', PROJECT_ID, APP_ID], {
      targets: [],
      total: 0,
    })
    let remove: (appId: string) => Promise<unknown>
    function Settings() {
      remove = useDeleteDistributionApp(PROJECT_ID, async () => {
        throw new Error('Navigation failed')
      }).mutateAsync
      return null
    }
    render(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(Settings),
      ),
    )

    await remove!(APP_ID)

    expect(deleteApp).toHaveBeenCalledTimes(1)
    for (const resource of ['distribution-app', 'distribution-targets']) {
      expect(
        queryClient.getQueryCache().find({
          queryKey: [resource, PROJECT_ID, APP_ID],
        }),
      ).toBeUndefined()
    }
  })

  test('drops cached app lists that are not on screen', async () => {
    const queryClient = new QueryClient()
    await queryClient.prefetchQuery({
      queryKey: ['distribution-apps', PROJECT_ID, 0, 25, ''],
      queryFn: async () => ({ apps: [app], total: 1 }),
    })
    let remove: (appId: string) => Promise<unknown>
    function Settings() {
      remove = useDeleteDistributionApp(PROJECT_ID, async () => {}).mutateAsync
      return null
    }
    render(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(Settings),
      ),
    )

    await remove!(APP_ID)

    expect(
      queryClient.getQueryData(['distribution-apps', PROJECT_ID, 0, 25, '']),
    ).toBeUndefined()
    expect(listApps).not.toHaveBeenCalled()
  })
})
