import { afterAll, afterEach, describe, expect, spyOn, test } from 'bun:test'
import { registerDom, unregisterDom } from './dom.ts'
import {
  QueryClient,
  QueryClientProvider,
  QueryObserver,
  type QueryKey,
} from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useCreateDistributionRelease,
  useDeleteDistributionArtifact,
  useDeleteDistributionBuild,
  useDeleteDistributionRelease,
} from '@/lib/react-query/hooks/distribution'

registerDom({ url: 'http://localhost/' })
const { act, cleanup, renderHook } = await import('@testing-library/react')

const PROJECT = 'project1'
const APP = 'app1'

type Call = { method: string; params: Record<string, unknown> }

let queryClient = new QueryClient()
const calls: Call[] = []
const spies: Array<{ mockRestore: () => void }> = []
const unsubscribes: Array<() => void> = []

function serve(): void {
  const record =
    (method: string) =>
    async (
      params: Record<string, unknown>,
    ): Promise<Record<string, unknown>> => {
      calls.push({ method, params })
      return { $id: String(params.releaseId ?? 'resource'), ...params }
    }
  spies.push(
    spyOn(sdk, 'forProject').mockReturnValue({
      distribution: {
        createRelease: record('createRelease'),
        deleteBuild: record('deleteBuild'),
        deleteArtifact: record('deleteArtifact'),
        deleteRelease: record('deleteRelease'),
      },
    } as never),
  )
}

function wrapper({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  )
}

function seed(queryKey: QueryKey): void {
  queryClient.setQueryData(queryKey, { $id: String(queryKey[3]) })
}

type Observed = { fetches: () => number; unsubscribe: () => void }

function observe(queryKey: QueryKey): Observed {
  let fetches = 0
  const observer = new QueryObserver(queryClient, {
    queryKey,
    queryFn: async () => {
      fetches++
      return { $id: String(queryKey[3]) }
    },
    staleTime: Infinity,
  })
  const unsubscribe = observer.subscribe(() => {})
  unsubscribes.push(unsubscribe)
  return { fetches: () => fetches, unsubscribe }
}

afterEach(() => {
  for (const unsubscribe of unsubscribes.splice(0)) unsubscribe()
  for (const spy of spies.splice(0)) spy.mockRestore()
  calls.length = 0
  cleanup()
  queryClient.clear()
  queryClient = new QueryClient()
})

afterAll(async () => {
  await unregisterDom()
})

describe('distribution delete mutations', () => {
  test('deleting a build forgets its detail and logs', async () => {
    serve()
    seed(['distribution-build', PROJECT, APP, 'build1'])
    seed(['distribution-build-logs', PROJECT, APP, 'build1'])
    seed(['distribution-build', PROJECT, APP, 'build2'])
    const { result } = renderHook(() => useDeleteDistributionBuild(PROJECT), {
      wrapper,
    })

    await act(() =>
      result.current.mutateAsync({ appId: APP, buildId: 'build1' }),
    )

    expect(
      queryClient.getQueryData(['distribution-build', PROJECT, APP, 'build1']),
    ).toBeUndefined()
    expect(
      queryClient.getQueryData([
        'distribution-build-logs',
        PROJECT,
        APP,
        'build1',
      ]),
    ).toBeUndefined()
    expect(
      queryClient.getQueryData<unknown>([
        'distribution-build',
        PROJECT,
        APP,
        'build2',
      ]),
    ).toEqual({ $id: 'build2' })
  })

  test('a mounted build page keeps its data until it unmounts, then forgets it', async () => {
    serve()
    const detailKey = ['distribution-build', PROJECT, APP, 'build1']
    const detail = observe(detailKey)
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0))
    })
    const { result } = renderHook(() => useDeleteDistributionBuild(PROJECT), {
      wrapper,
    })

    await act(() =>
      result.current.mutateAsync({ appId: APP, buildId: 'build1' }),
    )

    expect(queryClient.getQueryData<unknown>(detailKey)).toEqual({
      $id: 'build1',
    })
    expect(detail.fetches()).toBe(1)

    detail.unsubscribe()

    expect(queryClient.getQueryData(detailKey)).toBeUndefined()
    expect(detail.fetches()).toBe(1)
  })

  test('deleting an artifact forgets its detail', async () => {
    serve()
    seed(['distribution-artifact', PROJECT, APP, 'artifact1'])
    const { result } = renderHook(
      () => useDeleteDistributionArtifact(PROJECT),
      { wrapper },
    )

    await act(() =>
      result.current.mutateAsync({ appId: APP, artifactId: 'artifact1' }),
    )

    expect(
      queryClient.getQueryData([
        'distribution-artifact',
        PROJECT,
        APP,
        'artifact1',
      ]),
    ).toBeUndefined()
  })

  test('deleting a release forgets it and refreshes its builds and the submissions', async () => {
    serve()
    seed(['distribution-release', PROJECT, APP, 'release1'])
    const builds = observe([
      'distribution-builds',
      PROJECT,
      APP,
      'release',
      'release1',
    ])
    const submissions = observe([
      'distribution-submissions',
      PROJECT,
      APP,
      'latest',
      'target1',
    ])
    const otherBuilds = observe([
      'distribution-builds',
      PROJECT,
      APP,
      'release',
      'release2',
    ])
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0))
    })
    const { result } = renderHook(() => useDeleteDistributionRelease(PROJECT), {
      wrapper,
    })

    await act(() =>
      result.current.mutateAsync({ appId: APP, releaseId: 'release1' }),
    )

    expect(
      queryClient.getQueryData([
        'distribution-release',
        PROJECT,
        APP,
        'release1',
      ]),
    ).toBeUndefined()
    expect(builds.fetches()).toBe(2)
    expect(submissions.fetches()).toBe(2)
    expect(otherBuilds.fetches()).toBe(1)
  })
})

describe('distribution release creation', () => {
  test('uses the release ID it is given', async () => {
    serve()
    const { result } = renderHook(() => useCreateDistributionRelease(PROJECT), {
      wrapper,
    })

    await act(() =>
      result.current.mutateAsync({
        appId: APP,
        releaseId: 'release9',
        version: '2.0.0',
      }),
    )
    await act(() =>
      result.current.mutateAsync({
        appId: APP,
        releaseId: 'release9',
        version: '2.0.0',
      }),
    )

    expect(calls.map((call) => call.params.releaseId)).toEqual([
      'release9',
      'release9',
    ])
  })

  test('generates a release ID when none is given', async () => {
    serve()
    const { result } = renderHook(() => useCreateDistributionRelease(PROJECT), {
      wrapper,
    })

    await act(() =>
      result.current.mutateAsync({ appId: APP, version: '2.0.0' }),
    )

    const [call] = calls
    expect(typeof call.params.releaseId).toBe('string')
    expect(String(call.params.releaseId).length).toBeGreaterThan(0)
  })
})
