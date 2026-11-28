import { describe, expect, test } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import {
  countRuntimeLogLevels,
  rowRuntimeLogFields,
  runtimeLogDownloadLine,
  toRuntimeLogEntry,
} from '@/lib/runtime-log-entry'

function log(message: string, stream = 'stdout'): Models.RuntimeLog {
  return {
    $id: 'log1',
    resourceType: 'functions',
    resourceId: 'checkout',
    resourceInternalId: '1',
    deploymentId: 'dep1',
    region: 'fra',
    timestamp: '2026-11-27T16:46:23.447Z',
    stream,
    message,
  }
}

describe('runtime log entry', () => {
  test('files a line under its own level', () => {
    const entry = toRuntimeLogEntry(
      log('{"level":"warn","msg":"slow"}', 'stderr'),
    )
    expect(entry.level).toBe('warn')
    expect(entry.levelFromStream).toBe(false)
  })

  test('lets the stream stand in for a missing level', () => {
    const stderr = toRuntimeLogEntry(log('connection reset', 'stderr'))
    expect(stderr.level).toBe('error')
    expect(stderr.levelFromStream).toBe(true)
    const stdout = toRuntimeLogEntry(log('listening', 'stdout'))
    expect(stdout.level).toBe('info')
    expect(stdout.levelFromStream).toBe(true)
  })

  test('searches and downloads the line without color codes', () => {
    const esc = String.fromCharCode(27)
    const entry = toRuntimeLogEntry(log(`${esc}[31mBoom${esc}[0m happened`))
    expect(entry.plain).toBe('Boom happened')
    expect(entry.searchText).toBe('boom happened')
    expect(runtimeLogDownloadLine(entry, false)).toBe(
      '2026-11-27T16:46:23.447Z fra stdout Boom happened',
    )
    expect(runtimeLogDownloadLine(entry, true)).toBe(
      '2026-11-27T16:46:23.447Z fra stdout functions/checkout Boom happened',
    )
  })

  test('keeps time and process details off the row', () => {
    const entry = toRuntimeLogEntry(
      log(
        JSON.stringify({
          level: 30,
          time: 1,
          pid: 1,
          hostname: 'host',
          msg: 'request completed',
          status: 200,
        }),
      ),
    )
    expect(rowRuntimeLogFields(entry.line)).toEqual([
      { key: 'status', value: '200' },
    ])
  })

  test('counts lines per level', () => {
    const counts = countRuntimeLogLevels([
      toRuntimeLogEntry(log('ERROR one')),
      toRuntimeLogEntry(log('two', 'stderr')),
      toRuntimeLogEntry(log('three')),
    ])
    expect(counts).toEqual({ debug: 0, info: 1, warn: 0, error: 2 })
  })
})
