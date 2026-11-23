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
import { createElement } from 'react'
import { Distribution } from '@/lib/appwrite/distribution'
import type { DistributionConnection } from '@/lib/appwrite/distribution'
import {
  useCreateDistributionAndroidSigningIdentity,
  useCreateDistributionGoogleConnection,
  useCreateDistributionVariable,
} from '@/lib/react-query/hooks/distribution'

const PROJECT_ID = 'project'
const PRIVATE_KEY =
  '-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----'

describe('distribution mutations holding secrets', () => {
  const spies: ReturnType<typeof spyOn>[] = []

  beforeAll(() => {
    registerDom()
    spies.push(
      spyOn(Distribution.prototype, 'createGoogleConnection').mockResolvedValue(
        { $id: 'connection' } as DistributionConnection,
      ),
      spyOn(Distribution.prototype, 'listConnections').mockResolvedValue({
        connections: [],
        total: 0,
      }),
      spyOn(
        Distribution.prototype,
        'createAndroidSigningIdentity',
      ).mockResolvedValue({} as never),
      spyOn(Distribution.prototype, 'listSigningIdentities').mockResolvedValue({
        signingIdentities: [],
        total: 0,
      }),
      spyOn(Distribution.prototype, 'createVariable').mockResolvedValue(
        {} as never,
      ),
      spyOn(Distribution.prototype, 'listVariables').mockResolvedValue({
        variables: [],
        total: 0,
      }),
    )
  })

  afterEach(() => {
    cleanup()
  })

  afterAll(async () => {
    for (const spy of spies) spy.mockRestore()
    await unregisterDom()
  })

  async function runAndUnmount(
    useRun: () => () => Promise<unknown>,
  ): Promise<QueryClient> {
    const queryClient = new QueryClient()
    let run: (() => Promise<unknown>) | undefined
    function Form() {
      run = useRun()
      return null
    }
    const view = render(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(Form),
      ),
    )
    await run!()
    act(() => view.unmount())
    return queryClient
  }

  test.each([
    [
      'a Google Play key',
      () => {
        const create = useCreateDistributionGoogleConnection(PROJECT_ID)
        return () =>
          create.mutateAsync({
            name: 'Google Play',
            serviceAccount: {
              client_email: 'release@acme.iam.gserviceaccount.com',
              private_key: PRIVATE_KEY,
            },
          })
      },
    ],
    [
      'an Android keystore',
      () => {
        const create = useCreateDistributionAndroidSigningIdentity(PROJECT_ID)
        return () =>
          create.mutateAsync({
            name: 'Upload key',
            keystore: 'a2V5c3RvcmU=',
            storePassword: 'keystore-password',
            keyAlias: 'upload',
          })
      },
    ],
    [
      'a variable value',
      () => {
        const create = useCreateDistributionVariable(PROJECT_ID, 'app')
        return () =>
          create.mutateAsync({ key: 'API_TOKEN', value: 'token', secret: true })
      },
    ],
  ])('forgets %s once the form is gone', async (_, useRun) => {
    const queryClient = await runAndUnmount(useRun)

    await waitFor(() =>
      expect(queryClient.getMutationCache().getAll()).toHaveLength(0),
    )
  })
})
