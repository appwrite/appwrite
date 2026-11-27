import { afterEach, describe, expect, spyOn, test } from 'bun:test'
import { Client } from '@appwrite.io/console'
import { QueryClient } from '@tanstack/react-query'
import { Distribution } from '@/lib/appwrite/distribution'
import type {
  DistributionBuild,
  DistributionLog,
} from '@/lib/appwrite/distribution'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DISTRIBUTION_LOGS_PAGE_SIZE,
  distributionBuildLogsQueryOptions,
  distributionCheckQueryOptions,
  fetchDistributionBuildLogs,
  fetchDistributionLatestBuild,
  fetchNewerDistributionBuildLogs,
} from '@/lib/react-query/hooks/distribution'

const ENDPOINT = 'https://appwrite.test/v1'
const PROJECT_ID = 'project'
const APP_ID = 'app'
const BUILD_ID = 'build'

type Query = { method: string; attribute?: string; values?: unknown[] }

function distribution(): Distribution {
  return new Distribution(
    new Client().setEndpoint(ENDPOINT).setProject(PROJECT_ID),
  )
}

function log(index: number): DistributionLog {
  return {
    $id: `log${index}`,
    $createdAt: '',
    buildId: BUILD_ID,
    sequence: String(1000 - index),
    chunk: 0,
    stage: 'build',
    content: `line ${index}\n`,
  }
}

describe('distribution client', () => {
  const spies: ReturnType<typeof spyOn>[] = []

  afterEach(() => {
    for (const spy of spies.splice(0)) {
      spy.mockRestore()
    }
  })

  test('uploads an artifact in chunks to the artifacts route', async () => {
    const service = distribution()
    const chunkedUpload = spyOn(
      service.client,
      'chunkedUpload',
    ).mockResolvedValue({ $id: 'artifact' })
    spies.push(chunkedUpload)
    const file = new File(['binary'], 'app.ipa')

    await service.createArtifact({ appId: APP_ID, file })

    const [method, url, headers, payload, onProgress] =
      chunkedUpload.mock.calls[0]
    expect(method).toBe('post')
    expect(String(url)).toBe(`${ENDPOINT}/distribution/apps/app/artifacts`)
    expect(headers).toEqual({
      'X-Appwrite-Project': PROJECT_ID,
      'content-type': 'multipart/form-data',
      accept: 'application/json',
    })
    expect(payload).toEqual({ file })
    expect(typeof onProgress).toBe('function')
  })

  test('resumes an artifact upload under its id', async () => {
    const service = distribution()
    const chunkedUpload = spyOn(
      service.client,
      'chunkedUpload',
    ).mockResolvedValue({ $id: 'artifact' })
    spies.push(chunkedUpload)
    const file = new File(['binary'], 'app.ipa')

    await service.createArtifact({
      appId: APP_ID,
      file,
      artifactId: 'artifact',
    })

    const [, , headers] = chunkedUpload.mock.calls[0]
    expect(headers).toEqual({
      'X-Appwrite-Project': PROJECT_ID,
      'content-type': 'multipart/form-data',
      accept: 'application/json',
      'x-appwrite-id': 'artifact',
    })
  })

  test('builds an artifact download URL without sending a request', () => {
    const service = distribution()
    const call = spyOn(service.client, 'call')
    spies.push(call)

    const url = service.getArtifactDownload({
      appId: APP_ID,
      artifactId: 'artifact',
    })

    expect(url).toBe(
      `${ENDPOINT}/distribution/apps/app/artifacts/artifact/download?project=${PROJECT_ID}`,
    )
    expect(call).not.toHaveBeenCalled()
  })

  test('requests submission status changes on the status route', async () => {
    const service = distribution()
    const call = spyOn(service.client, 'call').mockResolvedValue({})
    spies.push(call)

    await service.updateSubmissionStatus({
      appId: APP_ID,
      submissionId: 'submission',
      status: 'halted',
    })

    const [method, url, , payload] = call.mock.calls[0]
    expect(method).toBe('patch')
    expect(String(url)).toBe(
      `${ENDPOINT}/distribution/apps/app/submissions/submission/status`,
    )
    expect(payload).toEqual({ status: 'halted' })
  })
})

describe('distribution build logs', () => {
  const requests: { queries: Query[]; total?: boolean }[] = []
  let forProject: ReturnType<typeof spyOn> | undefined

  afterEach(() => {
    requests.length = 0
    forProject?.mockRestore()
  })

  function serve(stored: DistributionLog[]) {
    forProject = spyOn(sdk, 'forProject').mockReturnValue({
      distribution: {
        listBuildLogs: async ({
          queries,
          total,
        }: {
          queries: string[]
          total?: boolean
        }) => {
          const parsed = queries.map((query) => JSON.parse(query) as Query)
          requests.push({ queries: parsed, total })
          const cursor = parsed.find((query) => query.method === 'cursorAfter')
            ?.values?.[0]
          const start = cursor
            ? stored.findIndex((item) => item.$id === cursor) + 1
            : 0
          return {
            total: 0,
            logs: stored.slice(start, start + DISTRIBUTION_LOGS_PAGE_SIZE),
          }
        },
      },
    } as never)
  }

  test('pages newest first and points the cursor at the oldest fetched log', async () => {
    const stored = Array.from({ length: 150 }, (_, index) => log(index))
    serve(stored)

    const first = await fetchDistributionBuildLogs(PROJECT_ID, APP_ID, BUILD_ID)
    const second = await fetchDistributionBuildLogs(
      PROJECT_ID,
      APP_ID,
      BUILD_ID,
      first.cursor,
    )

    expect(first.logs).toHaveLength(DISTRIBUTION_LOGS_PAGE_SIZE)
    expect(first.more).toBe(true)
    expect(first.cursor).toBe('log99')
    expect(second.logs).toHaveLength(50)
    expect(second.more).toBe(false)
    expect(second.cursor).toBe('log149')
    expect(requests[0]).toEqual({
      queries: [
        { method: 'orderDesc', attribute: 'sequence' },
        { method: 'orderDesc', attribute: 'chunk' },
        { method: 'limit', values: [DISTRIBUTION_LOGS_PAGE_SIZE] },
      ],
      total: false,
    })
    expect(requests[1].queries.at(-1)).toEqual({
      method: 'cursorAfter',
      values: ['log99'],
    })
  })

  test('stops paging once a page comes back short', async () => {
    serve([log(0)])
    const queryClient = new QueryClient()

    await queryClient.fetchInfiniteQuery(
      distributionBuildLogsQueryOptions(PROJECT_ID, APP_ID, BUILD_ID),
    )
    const options = distributionBuildLogsQueryOptions(
      PROJECT_ID,
      APP_ID,
      BUILD_ID,
    )
    const data = queryClient.getQueryData(options.queryKey)

    expect(data?.pages[0]).toEqual({
      logs: [log(0)],
      more: false,
      cursor: 'log0',
      newest: 'log0',
    })
    expect(
      options.getNextPageParam(data!.pages[0], data!.pages, null, [null]),
    ).toBeUndefined()
  })

  test('returns no page cursor for a build without logs', async () => {
    serve([])

    const page = await fetchDistributionBuildLogs(PROJECT_ID, APP_ID, BUILD_ID)

    expect(page).toEqual({ logs: [], more: false, cursor: null, newest: null })
  })

  test('anchors the newest page at its first fetched log', async () => {
    serve(Array.from({ length: 150 }, (_, index) => log(index)))

    const first = await fetchDistributionBuildLogs(PROJECT_ID, APP_ID, BUILD_ID)

    expect(first.newest).toBe('log0')
  })

  test('fetches every log newer than the anchor across pages', async () => {
    serve(Array.from({ length: 300 }, (_, index) => log(index)))

    const page = await fetchNewerDistributionBuildLogs(
      PROJECT_ID,
      APP_ID,
      BUILD_ID,
      'log180',
      new Set(),
    )

    expect(page.logs.map((row) => row.$id)).toEqual(
      Array.from({ length: 180 }, (_, index) => `log${index}`),
    )
    expect(page.newest).toBe('log0')
    expect(requests).toHaveLength(2)
  })

  test('stops at the anchor on the first page', async () => {
    serve(Array.from({ length: 300 }, (_, index) => log(index)))

    const page = await fetchNewerDistributionBuildLogs(
      PROJECT_ID,
      APP_ID,
      BUILD_ID,
      'log0',
      new Set(),
    )

    expect(page.logs).toEqual([])
    expect(page.newest).toBeNull()
    expect(requests).toHaveLength(1)
  })

  test('without an anchor fetches until every known log is seen', async () => {
    serve(Array.from({ length: 300 }, (_, index) => log(index)))

    const page = await fetchNewerDistributionBuildLogs(
      PROJECT_ID,
      APP_ID,
      BUILD_ID,
      null,
      new Set(['log3', 'log120']),
    )

    expect(page.logs).toHaveLength(200)
    expect(page.more).toBe(true)
    expect(page.cursor).toBe('log199')
    expect(requests).toHaveLength(2)
  })

  test('without an anchor or known logs fetches one page', async () => {
    serve(Array.from({ length: 300 }, (_, index) => log(index)))

    const page = await fetchNewerDistributionBuildLogs(
      PROJECT_ID,
      APP_ID,
      BUILD_ID,
      null,
      new Set(),
    )

    expect(page.logs).toHaveLength(DISTRIBUTION_LOGS_PAGE_SIZE)
    expect(requests).toHaveLength(1)
  })
})

describe('distribution latest build', () => {
  test('asks for one build of the target without a total', async () => {
    const build = { $id: BUILD_ID } as DistributionBuild
    const calls: { queries: Query[]; total?: boolean }[] = []
    const forProject = spyOn(sdk, 'forProject').mockReturnValue({
      distribution: {
        listBuilds: async ({
          queries,
          total,
        }: {
          queries: string[]
          total?: boolean
        }) => {
          calls.push({
            queries: queries.map((query) => JSON.parse(query) as Query),
            total,
          })
          return { total: 0, builds: [build] }
        },
      },
    } as never)

    try {
      expect(
        await fetchDistributionLatestBuild(PROJECT_ID, APP_ID, 'target'),
      ).toBe(build)
      expect(calls).toEqual([
        {
          queries: [
            { method: 'equal', attribute: 'targetId', values: ['target'] },
            { method: 'orderDesc', attribute: '$createdAt' },
            { method: 'limit', values: [1] },
          ],
          total: false,
        },
      ])
    } finally {
      forProject.mockRestore()
    }
  })
})

describe('distribution check key', () => {
  test('keys a check without a track under null', () => {
    const { queryKey } = distributionCheckQueryOptions(
      PROJECT_ID,
      APP_ID,
      'target',
    )

    expect([...queryKey]).toEqual([
      'distribution-check',
      PROJECT_ID,
      APP_ID,
      'target',
      null,
    ])
  })
})
