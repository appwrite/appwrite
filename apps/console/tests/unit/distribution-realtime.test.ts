import { afterEach, beforeEach, describe, expect, spyOn, test } from 'bun:test'
import {
  InfiniteQueryObserver,
  QueryClient,
  QueryObserver,
  type QueryKey,
} from '@tanstack/react-query'
import { distributionEvents } from '../../e2e/fixtures/distribution-events'
import { sdk } from '@/lib/appwrite/sdk'
import type { LogChunk, LogPage, LogPages } from '@/lib/distribution/logs'
import {
  handleDistributionRealtimeEvents,
  isDistributionRealtimeSignal,
} from '@/lib/realtime/distribution-cache'

const PROJECT = 'project1'
const APP = 'app1'

function rootEvents(service: string, id: string, action: string): string[] {
  return distributionEvents([[service, id]], action)
}

function childEvents(type: string, id: string, action: string): string[] {
  return distributionEvents(
    [
      ['distributionApps', APP],
      [type, id],
    ],
    action,
  )
}

function logEvents(buildId: string, logId: string): string[] {
  return distributionEvents(
    [
      ['distributionApps', APP],
      ['builds', buildId],
      ['logs', logId],
    ],
    'create',
  )
}

let queryClient: QueryClient
let unsubscribes: Array<() => void>
let spies: Array<{ mockRestore: () => void }>

beforeEach(() => {
  queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, staleTime: Infinity } },
  })
  unsubscribes = []
  spies = []
})

afterEach(() => {
  for (const unsubscribe of unsubscribes) unsubscribe()
  for (const spy of spies) spy.mockRestore()
  queryClient.clear()
})

async function settle(): Promise<void> {
  for (let attempt = 0; attempt < 50; attempt++) {
    await new Promise((resolve) => setTimeout(resolve, 0))
    if (queryClient.isFetching() === 0) return
  }
}

function cached(queryKey: QueryKey): unknown {
  return queryClient.getQueryData<unknown>(queryKey)
}

type Observed = { fetches: () => number; data: () => unknown }

async function observe(queryKey: QueryKey, data: unknown): Promise<Observed> {
  let fetches = 0
  const observer = new QueryObserver(queryClient, {
    queryKey,
    queryFn: async () => {
      fetches++
      return data
    },
  })
  unsubscribes.push(observer.subscribe(() => {}))
  await settle()
  return {
    fetches: () => fetches,
    data: () => cached(queryKey),
  }
}

function handle(events: string[], payload: Record<string, unknown> | null) {
  handleDistributionRealtimeEvents(queryClient, PROJECT, events, payload)
}

function row(id: string, fields: Record<string, unknown> = {}) {
  return { $id: id, status: 'processing', ...fields }
}

function chunk(
  $id: string,
  sequence: string,
  index: number,
  content: string,
): LogChunk & { buildId: string; stage: string; $createdAt: string } {
  return {
    $id,
    $createdAt: '2026-11-01T00:00:00.000Z',
    buildId: 'build1',
    sequence,
    chunk: index,
    stage: 'build',
    content,
  }
}

describe('isDistributionRealtimeSignal', () => {
  test('matches distribution services', () => {
    expect(
      isDistributionRealtimeSignal(childEvents('builds', 'b', 'update')),
    ).toBe(true)
    expect(
      isDistributionRealtimeSignal(
        rootEvents('distributionConnections', 'c', 'update'),
      ),
    ).toBe(true)
    expect(
      isDistributionRealtimeSignal(
        rootEvents('distributionSigningIdentities', 'i', 'create'),
      ),
    ).toBe(true)
  })

  test('ignores other services', () => {
    expect(isDistributionRealtimeSignal(['sites.s.deployments.d.update'])).toBe(
      false,
    )
    expect(isDistributionRealtimeSignal(['distribution.x.update'])).toBe(false)
  })
})

describe('event selection', () => {
  test('ignores a set without a wildcard-free event ending in an action', async () => {
    const list = await observe(
      ['distribution-builds', PROJECT, APP, 0, 25, null],
      {
        total: 0,
        builds: [],
      },
    )

    handle(
      [
        'distributionApps.*.builds.*.update',
        `distributionApps.${APP}.builds.*.update`,
        'distributionApps.*.builds.build1.update',
        `distributionApps.${APP}.builds.build1`,
        `distributionApps.${APP}`,
        'distributionApps.*',
      ],
      row('build1'),
    )
    await settle()

    expect(list.fetches()).toBe(1)
  })

  test('selects the most specific event in any order', async () => {
    const logsKey = ['distribution-build-logs', PROJECT, APP, 'build1']
    queryClient.setQueryData<LogPages>(logsKey, {
      pages: [{ logs: [], more: false, cursor: null }],
      pageParams: [null],
    })
    const list = await observe(
      ['distribution-builds', PROJECT, APP, 0, 25, null],
      { total: 0, builds: [] },
    )

    handle(
      [...logEvents('build1', 'log1')].reverse(),
      chunk('log1', '1700000000000000001', 0, 'line\n'),
    )
    await settle()

    expect(list.fetches()).toBe(1)
    expect(
      queryClient
        .getQueryData<LogPages>(logsKey)
        ?.pages[0].logs.map((log) => log.$id),
    ).toEqual(['log1'])
  })

  test('an id named like an action does not shadow the full event', async () => {
    const logsKey = ['distribution-build-logs', PROJECT, APP, 'build1']
    queryClient.setQueryData<LogPages>(logsKey, {
      pages: [{ logs: [], more: false, cursor: null }],
      pageParams: [null],
    })

    handle(
      [...logEvents('build1', 'update')].reverse(),
      chunk('update', '1700000000000000001', 0, 'line\n'),
    )
    await settle()

    expect(
      queryClient
        .getQueryData<LogPages>(logsKey)
        ?.pages[0].logs.map((log) => log.$id),
    ).toEqual(['update'])
  })

  test('a log event is handled as a log, not as its parent build', async () => {
    const build = row('build1', { status: 'building' })
    queryClient.setQueryData(
      ['distribution-build', PROJECT, APP, 'build1'],
      build,
    )
    const list = await observe(
      ['distribution-builds', PROJECT, APP, 0, 25, null],
      {
        total: 1,
        builds: [build],
      },
    )
    const logsKey = ['distribution-build-logs', PROJECT, APP, 'build1']
    queryClient.setQueryData<LogPages>(logsKey, {
      pages: [{ logs: [], more: false, cursor: null }],
      pageParams: [null],
    })

    handle(
      logEvents('build1', 'log1'),
      chunk('log1', '1700000000000000001', 0, 'line\n'),
    )
    await settle()

    expect(cached(['distribution-build', PROJECT, APP, 'build1'])).toBe(build)
    expect(list.fetches()).toBe(1)
    expect(
      queryClient
        .getQueryData<LogPages>(logsKey)
        ?.pages[0].logs.map((log) => log.$id),
    ).toEqual(['log1'])
  })
})

describe('apps', () => {
  test('update merges into the app and the app lists', async () => {
    queryClient.setQueryData(['distribution-app', PROJECT, APP], {
      $id: APP,
      name: 'Shop',
      latestBuildStatus: 'building',
    })
    const list = await observe(['distribution-apps', PROJECT, 0, 25, ''], {
      total: 2,
      apps: [
        { $id: APP, name: 'Shop' },
        { $id: 'app2', name: 'Notes' },
      ],
    })

    handle(rootEvents('distributionApps', APP, 'update'), {
      $id: APP,
      latestBuildStatus: 'ready',
    })
    await settle()

    expect(cached(['distribution-app', PROJECT, APP])).toEqual({
      $id: APP,
      name: 'Shop',
      latestBuildStatus: 'ready',
    })
    expect(list.data()).toEqual({
      total: 2,
      apps: [
        { $id: APP, name: 'Shop', latestBuildStatus: 'ready' },
        { $id: 'app2', name: 'Notes' },
      ],
    })
    expect(list.fetches()).toBe(1)
  })

  test('create and delete refetch the app lists', async () => {
    const list = await observe(['distribution-apps', PROJECT, 0, 25, ''], {
      total: 0,
      apps: [],
    })

    handle(rootEvents('distributionApps', 'app2', 'create'), { $id: 'app2' })
    await settle()
    expect(list.fetches()).toBe(2)

    handle(rootEvents('distributionApps', 'app2', 'delete'), { $id: 'app2' })
    await settle()
    expect(list.fetches()).toBe(3)
  })
})

describe('targets', () => {
  test('any target event refetches targets and invalidates checks', async () => {
    const targets = await observe(['distribution-targets', PROJECT, APP], {
      total: 0,
      targets: [],
    })
    const check = await observe(
      ['distribution-check', PROJECT, APP, 'target1', 'production'],
      {
        ready: true,
        issues: [],
      },
    )
    const otherApp = await observe(
      ['distribution-check', PROJECT, 'app2', 'target9', 'production'],
      {
        ready: true,
        issues: [],
      },
    )

    handle(childEvents('targets', 'target1', 'update'), row('target1'))
    await settle()

    expect(targets.fetches()).toBe(2)
    expect(check.fetches()).toBe(2)
    expect(otherApp.fetches()).toBe(1)
  })
})

describe('builds', () => {
  test('update merges into the detail, the pages, the latest build and the release builds', async () => {
    const build = row('build1', { status: 'building', targetId: 'target1' })
    const detail = await observe(
      ['distribution-build', PROJECT, APP, 'build1'],
      build,
    )
    const page = await observe(
      ['distribution-builds', PROJECT, APP, 0, 25, null],
      {
        total: 2,
        builds: [build, row('build2')],
      },
    )
    const latest = await observe(
      ['distribution-builds', PROJECT, APP, 'latest', 'target1'],
      build,
    )
    const release = await observe(
      ['distribution-builds', PROJECT, APP, 'release', 'release1'],
      [build],
    )
    const emptyLatest = await observe(
      ['distribution-builds', PROJECT, APP, 'latest', 'target2'],
      null,
    )

    handle(childEvents('builds', 'build1', 'update'), {
      $id: 'build1',
      status: 'ready',
    })
    await settle()

    const merged = { ...build, status: 'ready' }
    expect(detail.data()).toEqual(merged)
    expect(page.data()).toEqual({ total: 2, builds: [merged, row('build2')] })
    expect(latest.data()).toEqual(merged)
    expect(release.data()).toEqual([merged])
    expect(emptyLatest.data()).toBeNull()
    expect(page.fetches()).toBe(1)
    expect(latest.fetches()).toBe(1)
    expect(detail.fetches()).toBe(1)
  })

  test('update of a build in no list refetches the active lists', async () => {
    const page = await observe(
      ['distribution-builds', PROJECT, APP, 0, 25, null],
      {
        total: 1,
        builds: [row('build2')],
      },
    )
    queryClient.setQueryData(
      ['distribution-builds', PROJECT, APP, 1, 25, null],
      {
        total: 1,
        builds: [row('build3')],
      },
    )

    handle(childEvents('builds', 'build1', 'update'), {
      $id: 'build1',
      status: 'ready',
    })
    await settle()

    expect(page.fetches()).toBe(2)
    expect(
      cached(['distribution-builds', PROJECT, APP, 1, 25, null]),
    ).toBeUndefined()
  })

  test('update without a payload id refetches the detail and the lists', async () => {
    const detail = await observe(
      ['distribution-build', PROJECT, APP, 'build1'],
      row('build1'),
    )
    const page = await observe(
      ['distribution-builds', PROJECT, APP, 0, 25, null],
      {
        total: 1,
        builds: [row('build1')],
      },
    )

    handle(childEvents('builds', 'build1', 'update'), null)
    await settle()

    expect(detail.fetches()).toBe(2)
    expect(page.fetches()).toBe(2)
  })

  test('create refetches the lists', async () => {
    const page = await observe(
      ['distribution-builds', PROJECT, APP, 0, 25, null],
      {
        total: 0,
        builds: [],
      },
    )
    const latest = await observe(
      ['distribution-builds', PROJECT, APP, 'latest', 'target1'],
      null,
    )

    handle(
      childEvents('builds', 'build1', 'create'),
      row('build1', { status: 'waiting' }),
    )
    await settle()

    expect(page.fetches()).toBe(2)
    expect(latest.fetches()).toBe(2)
  })

  test('delete refetches the lists and removes the build and its logs', async () => {
    const page = await observe(
      ['distribution-builds', PROJECT, APP, 0, 25, null],
      {
        total: 1,
        builds: [row('build1')],
      },
    )
    queryClient.setQueryData(
      ['distribution-build', PROJECT, APP, 'build1'],
      row('build1'),
    )
    queryClient.setQueryData(
      ['distribution-build-logs', PROJECT, APP, 'build1'],
      {
        pages: [{ logs: [], more: false, cursor: null }],
        pageParams: [null],
      },
    )
    queryClient.setQueryData(
      ['distribution-build', PROJECT, APP, 'build2'],
      row('build2'),
    )

    handle(childEvents('builds', 'build1', 'delete'), row('build1'))
    await settle()

    expect(page.fetches()).toBe(2)
    expect(
      cached(['distribution-build', PROJECT, APP, 'build1']),
    ).toBeUndefined()
    expect(
      cached(['distribution-build-logs', PROJECT, APP, 'build1']),
    ).toBeUndefined()
    expect(cached(['distribution-build', PROJECT, APP, 'build2'])).toEqual(
      row('build2'),
    )
  })
})

describe('build logs', () => {
  const logsKey = ['distribution-build-logs', PROJECT, APP, 'build1']
  const PAGE_SIZE = 100

  type Row = ReturnType<typeof chunk>

  async function observeLogs(head: LogPage) {
    const params: unknown[] = []
    const observer = new InfiniteQueryObserver(queryClient, {
      queryKey: logsKey,
      initialPageParam: null as string | null,
      queryFn: async ({ pageParam }) => {
        params.push(pageParam)
        return head
      },
      getNextPageParam: (last: LogPage) =>
        last.more ? last.cursor : undefined,
    })
    unsubscribes.push(observer.subscribe(() => {}))
    await settle()
    return {
      params,
      data: () => queryClient.getQueryData<LogPages<Row>>(logsKey),
    }
  }

  function serve(stored: () => Row[]) {
    const requests: Array<string | null> = []
    const spy = spyOn(sdk, 'forProject').mockReturnValue({
      distribution: {
        listBuildLogs: async ({ queries }: { queries: string[] }) => {
          const cursor =
            queries
              .map(
                (query) =>
                  JSON.parse(query) as { method: string; values?: string[] },
              )
              .find((query) => query.method === 'cursorAfter')?.values?.[0] ??
            null
          requests.push(cursor)
          const rows = stored()
          const start = cursor
            ? rows.findIndex((item) => item.$id === cursor) + 1
            : 0
          return { total: 0, logs: rows.slice(start, start + PAGE_SIZE) }
        },
      },
    } as never)
    spies.push(spy)
    return requests
  }

  function line(index: number, part: number = 0): Row {
    return chunk(
      `log${index}c${part}`,
      String(1700000000000000000n + BigInt(index)),
      part,
      `${index}.${part}\n`,
    )
  }

  function newestFirst(rows: Row[]): Row[] {
    return [...rows].sort((left, right) =>
      left.sequence === right.sequence
        ? right.chunk - left.chunk
        : left.sequence < right.sequence
          ? 1
          : -1,
    )
  }

  function ids(data: LogPages<Row> | undefined): string[] {
    return (data?.pages ?? []).flatMap((page) =>
      page.logs.map((log) => log.$id),
    )
  }

  test('a single-chunk event appends to the first page without a refetch', async () => {
    const requests = serve(() => [])
    const existing = chunk('log1', '1700000000000000001', 0, 'first\n')
    const logs = await observeLogs({
      logs: [existing],
      more: true,
      cursor: 'log1',
    })

    const payload = chunk('log2', '1700000000000000002', 0, 'second\n')
    handle(logEvents('build1', 'log2'), payload)
    await settle()

    expect(logs.params).toEqual([null])
    expect(requests).toEqual([])
    expect(logs.data()?.pages[0]).toEqual({
      logs: [payload, existing],
      more: true,
      cursor: 'log1',
    })
  })

  test('a duplicate event is not appended twice', async () => {
    const existing = chunk('log1', '1700000000000000001', 0, 'first\n')
    const logs = await observeLogs({
      logs: [existing],
      more: false,
      cursor: 'log1',
    })

    handle(logEvents('build1', 'log1'), existing)
    await settle()

    expect(logs.data()?.pages[0].logs).toEqual([existing])
  })

  test('the last chunk of a multi-chunk event fetches the missing chunks', async () => {
    const existing = line(1)
    const stored = newestFirst([existing, line(2, 0), line(2, 1), line(2, 2)])
    const requests = serve(() => stored)
    const logs = await observeLogs({
      logs: [existing],
      more: false,
      cursor: existing.$id,
      newest: existing.$id,
    })

    handle(logEvents('build1', 'log2c2'), { ...line(2, 2), chunks: 3 })
    await settle()

    expect(logs.params).toEqual([null])
    expect(requests).toEqual([null])
    expect(ids(logs.data()).sort()).toEqual(
      ['log1c0', 'log2c0', 'log2c1', 'log2c2'].sort(),
    )
    expect(logs.data()?.pages[0].newest).toBe('log2c2')
  })

  test('a chunk after the first fetches even without a chunk count', async () => {
    const stored = newestFirst([line(3, 0), line(3, 1)])
    const requests = serve(() => stored)
    const logs = await observeLogs({
      logs: [],
      more: false,
      cursor: null,
      newest: null,
    })

    handle(logEvents('build1', 'log3c1'), line(3, 1))
    await settle()

    expect(requests).toEqual([null])
    expect(ids(logs.data()).sort()).toEqual(['log3c0', 'log3c1'])
  })

  test('a single chunk with a count of one does not fetch', async () => {
    const requests = serve(() => [])
    const logs = await observeLogs({ logs: [], more: false, cursor: null })

    handle(logEvents('build1', 'log3'), {
      ...chunk('log3', '1700000000000000003', 0, 'a'),
      chunks: 1,
    })
    await settle()

    expect(logs.params).toEqual([null])
    expect(requests).toEqual([])
  })

  test('a burst of multi-chunk events coalesces into one follow-up fetch', async () => {
    const existing = line(1)
    const stored = newestFirst([
      existing,
      line(4, 0),
      line(4, 1),
      line(5, 0),
      line(5, 1),
      line(6, 0),
      line(6, 1),
    ])
    const requests = serve(() => stored)
    const logs = await observeLogs({
      logs: [existing],
      more: false,
      cursor: existing.$id,
      newest: existing.$id,
    })

    for (const index of [4, 5, 6]) {
      handle(logEvents('build1', `log${index}c1`), {
        ...line(index, 1),
        chunks: 2,
      })
    }
    await settle()

    expect(requests).toEqual([null, null])
    expect(ids(logs.data())).toHaveLength(7)
  })

  test('a gap fill keeps every loaded row, including earlier pages and realtime rows', async () => {
    const server = Array.from({ length: 150 }, (_, index) => line(index))
    const requests = serve(() => newestFirst(server))
    const fetched = newestFirst(server)
    const head = fetched.slice(0, PAGE_SIZE)
    const older = fetched.slice(PAGE_SIZE)
    await observeLogs({ logs: [], more: false, cursor: null })
    queryClient.setQueryData<LogPages<Row>>(logsKey, {
      pages: [
        {
          logs: head,
          more: true,
          cursor: head.at(-1)!.$id,
          newest: head[0].$id,
        },
        { logs: older, more: false, cursor: older.at(-1)!.$id },
      ],
      pageParams: [null, head.at(-1)!.$id],
    })

    for (let index = 150; index < 300; index++) {
      server.push(line(index))
      handle(logEvents('build1', `log${index}c0`), line(index))
    }
    server.push(line(300, 0), line(300, 1), line(300, 2))
    handle(logEvents('build1', 'log300c2'), { ...line(300, 2), chunks: 3 })
    await settle()

    const data = queryClient.getQueryData<LogPages<Row>>(logsKey)
    const loaded = new Set(ids(data))
    expect(loaded.size).toBe(303)
    expect(ids(data)).toHaveLength(303)
    for (const log of server) expect(loaded.has(log.$id)).toBe(true)
    expect(data?.pages[1].logs).toBe(older)
    expect(data?.pages[0].cursor).toBe(head.at(-1)!.$id)
    expect(data?.pages[0].more).toBe(true)
    expect(data?.pages[0].newest).toBe('log300c2')
    expect(requests).toEqual([null, 'log203c0'])
  })

  test('a gap in an inactive log query removes it', async () => {
    const requests = serve(() => [])
    queryClient.setQueryData<LogPages>(logsKey, {
      pages: [{ logs: [], more: false, cursor: null }],
      pageParams: [null],
    })

    handle(logEvents('build1', 'log3'), {
      ...chunk('log3', '3', 1, 'b'),
      chunks: 2,
    })
    await settle()

    expect(cached(logsKey)).toBeUndefined()
    expect(requests).toEqual([])
  })

  test('a payload without an id or chunk takes the event id and chunk zero', async () => {
    const logs = await observeLogs({ logs: [], more: false, cursor: null })

    handle(logEvents('build1', 'log9'), {
      buildId: 'build1',
      sequence: '1700000000000000009',
      stage: 'build',
      content: 'tail\n',
    })
    await settle()

    const [log] = logs.data()?.pages[0].logs ?? []
    expect(log).toMatchObject({
      $id: 'log9',
      buildId: 'build1',
      sequence: '1700000000000000009',
      chunk: 0,
      stage: 'build',
      content: 'tail\n',
    })
    expect(logs.params).toEqual([null])
  })

  test('nothing is created when the logs are not cached', async () => {
    handle(logEvents('build1', 'log1'), chunk('log1', '1', 0, 'a'))
    await settle()

    expect(cached(logsKey)).toBeUndefined()
  })
})

describe('artifacts', () => {
  test('update merges into the artifact and its lists', async () => {
    const artifact = row('artifact1', { status: 'processing' })
    queryClient.setQueryData(
      ['distribution-artifact', PROJECT, APP, 'artifact1'],
      artifact,
    )
    const page = await observe(
      ['distribution-artifacts', PROJECT, APP, 0, 25, null],
      {
        total: 1,
        artifacts: [artifact],
      },
    )

    handle(childEvents('artifacts', 'artifact1', 'update'), {
      $id: 'artifact1',
      status: 'ready',
    })
    await settle()

    const merged = { ...artifact, status: 'ready' }
    expect(
      cached(['distribution-artifact', PROJECT, APP, 'artifact1']),
    ).toEqual(merged)
    expect(page.data()).toEqual({ total: 1, artifacts: [merged] })
    expect(page.fetches()).toBe(1)
  })

  test('create refetches the artifact lists', async () => {
    const ready = await observe(
      ['distribution-artifacts', PROJECT, APP, 'ready', 'target1'],
      [],
    )

    handle(childEvents('artifacts', 'artifact2', 'create'), row('artifact2'))
    await settle()

    expect(ready.fetches()).toBe(2)
  })
})

describe('releases', () => {
  test('update merges into the release and its lists', async () => {
    const release = row('release1', { status: 'draft', version: '1.5.0' })
    const detail = await observe(
      ['distribution-release', PROJECT, APP, 'release1'],
      release,
    )
    const open = await observe(
      ['distribution-releases', PROJECT, APP, 'open'],
      [release],
    )

    handle(childEvents('releases', 'release1', 'update'), {
      $id: 'release1',
      status: 'active',
    })
    await settle()

    const merged = { ...release, status: 'active' }
    expect(detail.data()).toEqual(merged)
    expect(open.data()).toEqual([merged])
    expect(open.fetches()).toBe(1)
  })

  test('delete refetches the release lists and removes the release', async () => {
    const page = await observe(
      ['distribution-releases', PROJECT, APP, 0, 25, ''],
      {
        total: 1,
        releases: [row('release1')],
      },
    )
    queryClient.setQueryData(
      ['distribution-release', PROJECT, APP, 'release1'],
      row('release1'),
    )

    handle(childEvents('releases', 'release1', 'delete'), row('release1'))
    await settle()

    expect(page.fetches()).toBe(2)
    expect(
      cached(['distribution-release', PROJECT, APP, 'release1']),
    ).toBeUndefined()
  })
})

describe('filtered lists', () => {
  async function observePage(type: string, rows: unknown[]) {
    return observe(['distribution-' + type, PROJECT, APP, 0, 25, null], {
      total: rows.length,
      [type]: rows,
    })
  }

  test('an artifact that becomes ready refreshes the ready list of its target', async () => {
    const artifact = row('artifact1', {
      status: 'processing',
      targetId: 'target1',
    })
    const page = await observePage('artifacts', [artifact])
    const ready = await observe(
      ['distribution-artifacts', PROJECT, APP, 'ready', 'target1'],
      [],
    )
    const other = await observe(
      ['distribution-artifacts', PROJECT, APP, 'ready', 'target2'],
      [],
    )

    handle(childEvents('artifacts', 'artifact1', 'update'), {
      ...artifact,
      status: 'ready',
    })
    await settle()

    expect(page.fetches()).toBe(1)
    expect(ready.fetches()).toBe(2)
    expect(other.fetches()).toBe(1)
  })

  test('an artifact that stops being ready refreshes the ready list', async () => {
    const artifact = row('artifact1', { status: 'ready', targetId: 'target1' })
    await observePage('artifacts', [artifact])
    const ready = await observe(
      ['distribution-artifacts', PROJECT, APP, 'ready', 'target1'],
      [artifact],
    )

    handle(childEvents('artifacts', 'artifact1', 'update'), {
      ...artifact,
      status: 'invalid',
    })
    await settle()

    expect(ready.fetches()).toBe(2)
  })

  test('a ready artifact that stays ready merges without a refresh', async () => {
    const artifact = row('artifact1', { status: 'ready', targetId: 'target1' })
    await observePage('artifacts', [artifact])
    const ready = await observe(
      ['distribution-artifacts', PROJECT, APP, 'ready', 'target1'],
      [artifact],
    )

    handle(childEvents('artifacts', 'artifact1', 'update'), {
      ...artifact,
      name: 'renamed.ipa',
    })
    await settle()

    expect(ready.fetches()).toBe(1)
    expect(ready.data()).toEqual([{ ...artifact, name: 'renamed.ipa' }])
  })

  test('a release leaving or joining the open statuses refreshes the open list', async () => {
    const active = row('release1', { status: 'active' })
    const attention = row('release2', { status: 'attention' })
    await observePage('releases', [active, attention])
    const open = await observe(
      ['distribution-releases', PROJECT, APP, 'open'],
      [active],
    )

    handle(childEvents('releases', 'release1', 'update'), {
      ...active,
      status: 'published',
    })
    await settle()
    expect(open.fetches()).toBe(2)

    handle(childEvents('releases', 'release2', 'update'), {
      ...attention,
      status: 'active',
    })
    await settle()
    expect(open.fetches()).toBe(3)
  })

  test('a release keeping its status merges into the open list without a refresh', async () => {
    const draft = row('release1', { status: 'draft', version: '1.5.0' })
    await observePage('releases', [draft])
    const open = await observe(
      ['distribution-releases', PROJECT, APP, 'open'],
      [draft],
    )

    handle(childEvents('releases', 'release1', 'update'), {
      ...draft,
      version: '1.5.1',
    })
    await settle()

    expect(open.fetches()).toBe(1)
    expect(open.data()).toEqual([{ ...draft, version: '1.5.1' }])
  })

  test('a build attached to a release refreshes that release builds', async () => {
    const build = row('build1', { status: 'ready', releaseId: '' })
    await observePage('builds', [build])
    const release = await observe(
      ['distribution-builds', PROJECT, APP, 'release', 'release1'],
      [],
    )

    handle(childEvents('builds', 'build1', 'update'), {
      ...build,
      releaseId: 'release1',
    })
    await settle()

    expect(release.fetches()).toBe(2)
  })

  test('a newer build of a target refreshes a missing or older latest build', async () => {
    const older = row('build0', {
      targetId: 'target1',
      $createdAt: '2026-11-01T00:00:00.000Z',
    })
    const build = row('build1', {
      targetId: 'target1',
      $createdAt: '2026-11-02T00:00:00.000Z',
    })
    await observePage('builds', [build, older])
    const latest = await observe(
      ['distribution-builds', PROJECT, APP, 'latest', 'target1'],
      older,
    )
    const empty = await observe(
      ['distribution-builds', PROJECT, APP, 'latest', 'target2'],
      null,
    )

    handle(childEvents('builds', 'build1', 'update'), build)
    await settle()

    expect(latest.fetches()).toBe(2)
    expect(empty.fetches()).toBe(1)
  })

  test('an older build of a target leaves the latest build alone', async () => {
    const older = row('build0', {
      targetId: 'target1',
      $createdAt: '2026-11-01T00:00:00.000Z',
    })
    const build = row('build1', {
      targetId: 'target1',
      $createdAt: '2026-11-02T00:00:00.000Z',
    })
    await observePage('builds', [build, older])
    const latest = await observe(
      ['distribution-builds', PROJECT, APP, 'latest', 'target1'],
      build,
    )

    handle(childEvents('builds', 'build0', 'update'), {
      ...older,
      status: 'failed',
    })
    await settle()

    expect(latest.fetches()).toBe(1)
  })

  test('a submission of a target refreshes a missing latest submission', async () => {
    const submission = row('submission1', {
      targetId: 'target1',
      releaseId: 'release1',
    })
    await observe(
      ['distribution-submissions', PROJECT, APP, 'release', 'release1'],
      [submission],
    )
    const latest = await observe(
      ['distribution-submissions', PROJECT, APP, 'latest', 'target1'],
      null,
    )

    handle(childEvents('submissions', 'submission1', 'update'), {
      ...submission,
      status: 'reviewing',
    })
    await settle()

    expect(latest.fetches()).toBe(2)
  })
})

describe('submissions', () => {
  test('update merges into the submission, its lists and the latest one, and refreshes its release', async () => {
    const submission = row('submission1', {
      status: 'reviewing',
      releaseId: 'release1',
      targetId: 'target1',
    })
    const detail = await observe(
      ['distribution-submission', PROJECT, APP, 'submission1'],
      submission,
    )
    const forRelease = await observe(
      ['distribution-submissions', PROJECT, APP, 'release', 'release1'],
      [submission],
    )
    const latest = await observe(
      ['distribution-submissions', PROJECT, APP, 'latest', 'target1'],
      submission,
    )
    const release = await observe(
      ['distribution-release', PROJECT, APP, 'release1'],
      row('release1'),
    )
    const otherRelease = await observe(
      ['distribution-release', PROJECT, APP, 'release2'],
      row('release2'),
    )
    const releases = await observe(
      ['distribution-releases', PROJECT, APP, 0, 25, ''],
      {
        total: 1,
        releases: [row('release1')],
      },
    )

    handle(childEvents('submissions', 'submission1', 'update'), {
      $id: 'submission1',
      status: 'approved',
      releaseId: 'release1',
    })
    await settle()

    const merged = { ...submission, status: 'approved' }
    expect(detail.data()).toEqual(merged)
    expect(forRelease.data()).toEqual([merged])
    expect(latest.data()).toEqual(merged)
    expect(forRelease.fetches()).toBe(1)
    expect(release.fetches()).toBe(2)
    expect(otherRelease.fetches()).toBe(1)
    expect(releases.fetches()).toBe(2)
  })

  test('create refetches the submission lists and refreshes its release', async () => {
    const forRelease = await observe(
      ['distribution-submissions', PROJECT, APP, 'release', 'release1'],
      [],
    )
    const release = await observe(
      ['distribution-release', PROJECT, APP, 'release1'],
      row('release1'),
    )

    handle(
      childEvents('submissions', 'submission2', 'create'),
      row('submission2', {
        status: 'waiting',
        releaseId: 'release1',
      }),
    )
    await settle()

    expect(forRelease.fetches()).toBe(2)
    expect(release.fetches()).toBe(2)
  })

  test('without a release id every release of the app is refreshed', async () => {
    const release = await observe(
      ['distribution-release', PROJECT, APP, 'release1'],
      row('release1'),
    )

    handle(childEvents('submissions', 'submission1', 'update'), {
      $id: 'submission1',
    })
    await settle()

    expect(release.fetches()).toBe(2)
  })
})

describe('connections and signing identities', () => {
  test('a connection event invalidates connections and checks', async () => {
    const connections = await observe(
      ['distribution-connections', PROJECT, 0, 25, '', null],
      {
        total: 0,
        connections: [],
      },
    )
    const check = await observe(
      ['distribution-check', PROJECT, APP, 'target1', 'production'],
      {
        ready: false,
        issues: [],
      },
    )
    const identities = await observe(
      ['distribution-signing-identities', PROJECT, 0, 25, null],
      {
        total: 0,
        identities: [],
      },
    )

    handle(
      rootEvents('distributionConnections', 'connection1', 'update'),
      row('connection1'),
    )
    await settle()

    expect(connections.fetches()).toBe(2)
    expect(check.fetches()).toBe(2)
    expect(identities.fetches()).toBe(1)
  })

  test('a signing identity event invalidates identities and checks', async () => {
    const identities = await observe(
      ['distribution-signing-identities', PROJECT, 'all', null],
      [],
    )
    const check = await observe(
      ['distribution-check', PROJECT, APP, 'target1', 'production'],
      {
        ready: false,
        issues: [],
      },
    )
    const connections = await observe(
      ['distribution-connections', PROJECT, 'all', null],
      [],
    )

    handle(
      rootEvents('distributionSigningIdentities', 'identity1', 'create'),
      row('identity1'),
    )
    await settle()

    expect(identities.fetches()).toBe(2)
    expect(check.fetches()).toBe(2)
    expect(connections.fetches()).toBe(1)
  })
})
