export type LogChunk = {
  $id: string
  sequence: string
  chunk: number
  content: string
  stage?: string
  buildId?: string
}

export type LogPage<T extends LogChunk = LogChunk> = {
  logs: T[]
  more: boolean
  cursor: string | null
  // Every row from `newest` down to `cursor` was fetched; rows newer than it
  // arrived over realtime and can have gaps.
  newest?: string | null
}

export type LogPages<T extends LogChunk = LogChunk> = {
  pages: LogPage<T>[]
  pageParams: unknown[]
}

function compareSequences(left: string, right: string): number {
  if (left.length !== right.length) return left.length - right.length
  if (left === right) return 0
  return left < right ? -1 : 1
}

export function compareLogs(left: LogChunk, right: LogChunk): number {
  const sequence = compareSequences(left.sequence, right.sequence)
  if (sequence !== 0) return sequence
  if (left.chunk !== right.chunk) return left.chunk - right.chunk
  if (left.$id === right.$id) return 0
  return left.$id < right.$id ? -1 : 1
}

export function mergeLogs<T extends LogChunk>(logs: T[]): T[] {
  const unique = new Map<string, T>()
  for (const log of logs) {
    if (!unique.has(log.$id)) unique.set(log.$id, log)
  }
  return [...unique.values()].sort(compareLogs)
}

export function logsToText(logs: LogChunk[]): string {
  return logs.map((log) => log.content).join('')
}

export function insertLog<T extends LogChunk>(
  data: LogPages<T> | undefined,
  log: T,
): LogPages<T> | undefined {
  if (!data) return data
  const [head, ...rest] = data.pages
  if (!head) return data
  if (data.pages.some((page) => page.logs.some((row) => row.$id === log.$id))) {
    return data
  }
  return {
    ...data,
    pages: [{ ...head, logs: [log, ...head.logs] }, ...rest],
  }
}

export function mergeNewerLogs<T extends LogChunk>(
  data: LogPages<T> | undefined,
  fetched: LogPage<T>,
  anchor: string | null,
): LogPages<T> | undefined {
  if (!data) return data
  const [head, ...rest] = data.pages
  if (!head) return data
  const known = new Set(
    data.pages.flatMap((page) => page.logs.map((log) => log.$id)),
  )
  const added = fetched.logs.filter((log) => !known.has(log.$id))
  const logs = [...added, ...head.logs]
  if ((head.newest ?? null) !== anchor) {
    if (!added.length) return data
    return { ...data, pages: [{ ...head, logs }, ...rest] }
  }
  const fromTop = anchor === null && rest.length === 0
  return {
    ...data,
    pages: [
      {
        ...head,
        logs,
        newest: fetched.newest ?? anchor,
        ...(fromTop ? { more: fetched.more, cursor: fetched.cursor } : {}),
      },
      ...rest,
    ],
  }
}
