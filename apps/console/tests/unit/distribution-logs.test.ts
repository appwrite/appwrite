import { describe, expect, test } from 'bun:test'
import {
  compareLogs,
  insertLog,
  logsToText,
  mergeLogs,
  mergeNewerLogs,
  type LogChunk,
  type LogPages,
} from '@/lib/distribution/logs'

function log(
  $id: string,
  sequence: string,
  chunk: number,
  content: string = $id,
): LogChunk {
  return { $id, sequence, chunk, content }
}

describe('compareLogs', () => {
  test('orders 19-digit sequences beyond 2^53 as strings', () => {
    const earlier = log('a', '1700000000000000001', 0)
    const later = log('b', '1700000000000000002', 0)

    expect(Number(earlier.sequence)).toBe(Number(later.sequence))
    expect(compareLogs(earlier, later)).toBeLessThan(0)
    expect(compareLogs(later, earlier)).toBeGreaterThan(0)
  })

  test('orders a shorter sequence before a longer one', () => {
    expect(
      compareLogs(
        log('a', '999999999999999999', 0),
        log('b', '1000000000000000000', 0),
      ),
    ).toBeLessThan(0)
  })

  test('orders by chunk, then by id, within a sequence', () => {
    const sequence = '1700000000000000001'

    expect(
      compareLogs(log('z', sequence, 0), log('a', sequence, 1)),
    ).toBeLessThan(0)
    expect(
      compareLogs(log('a', sequence, 1), log('b', sequence, 1)),
    ).toBeLessThan(0)
    expect(compareLogs(log('a', sequence, 1), log('a', sequence, 1))).toBe(0)
  })
})

describe('mergeLogs', () => {
  test('dedupes by id and sorts ascending', () => {
    const logs = [
      log('c', '1700000000000000003', 0),
      log('a', '1700000000000000001', 1),
      log('b', '1700000000000000002', 0),
      log('a', '1700000000000000001', 1),
      log('d', '1700000000000000001', 0),
    ]

    expect(mergeLogs(logs).map((row) => row.$id)).toEqual(['d', 'a', 'b', 'c'])
  })

  test('keeps extra fields of the rows', () => {
    const row = { ...log('a', '1', 0), stage: 'build', buildId: 'build1' }

    expect(mergeLogs([row])[0]).toBe(row)
  })
})

describe('logsToText', () => {
  test('joins chunk contents without a separator', () => {
    expect(
      logsToText([
        log('a', '1', 0, 'Compiling '),
        log('b', '1', 1, 'app\n'),
        log('c', '2', 0, 'Done'),
      ]),
    ).toBe('Compiling app\nDone')
  })

  test('returns an empty string for no logs', () => {
    expect(logsToText([])).toBe('')
  })
})

describe('insertLog', () => {
  function pages(): LogPages {
    return {
      pages: [
        { logs: [log('c', '3', 0), log('b', '2', 0)], more: true, cursor: 'b' },
        { logs: [log('a', '1', 0)], more: false, cursor: 'a' },
      ],
      pageParams: [null, 'b'],
    }
  }

  test('prepends a new log to the first page', () => {
    const data = pages()
    const next = insertLog(data, log('d', '4', 0))

    expect(next?.pages[0].logs.map((row) => row.$id)).toEqual(['d', 'c', 'b'])
    expect(next?.pages[1]).toBe(data.pages[1])
    expect(next?.pageParams).toBe(data.pageParams)
  })

  test('leaves more and cursor untouched', () => {
    const next = insertLog(pages(), log('d', '4', 0))

    expect(next?.pages[0].more).toBe(true)
    expect(next?.pages[0].cursor).toBe('b')
    expect(next?.pages[1].more).toBe(false)
    expect(next?.pages[1].cursor).toBe('a')
  })

  test('skips a log already on any page', () => {
    const data = pages()

    expect(insertLog(data, log('a', '1', 0))).toBe(data)
    expect(insertLog(data, log('c', '3', 0))).toBe(data)
  })

  test('passes undefined through', () => {
    expect(insertLog(undefined, log('a', '1', 0))).toBeUndefined()
  })

  test('leaves data without pages unchanged', () => {
    const data: LogPages = { pages: [], pageParams: [] }

    expect(insertLog(data, log('a', '1', 0))).toBe(data)
  })

  test('keeps the row type of the pages', () => {
    type Row = LogChunk & { stage: string; buildId: string }
    const data: LogPages<Row> = {
      pages: [{ logs: [], more: false, cursor: null }],
      pageParams: [null],
    }
    const row: Row = { ...log('a', '1', 0), stage: 'build', buildId: 'build1' }

    const next: LogPages<Row> | undefined = insertLog(data, row)

    expect(next?.pages[0].logs[0].stage).toBe('build')
  })
})

describe('mergeNewerLogs', () => {
  function pages(): LogPages {
    return {
      pages: [
        {
          logs: [log('r1', '5', 1), log('c', '3', 0), log('b', '2', 0)],
          more: true,
          cursor: 'b',
          newest: 'c',
        },
        { logs: [log('a', '1', 0)], more: false, cursor: 'a', newest: 'a' },
      ],
      pageParams: [null, 'b'],
    }
  }

  test('adds the fetched rows to the head and moves its anchor', () => {
    const data = pages()
    const next = mergeNewerLogs(
      data,
      {
        logs: [log('r1', '5', 1), log('r0', '5', 0), log('d', '4', 0)],
        more: true,
        cursor: 'd',
        newest: 'r1',
      },
      'c',
    )

    expect(next?.pages[0].logs.map((row) => row.$id)).toEqual([
      'r0',
      'd',
      'r1',
      'c',
      'b',
    ])
    expect(next?.pages[0].newest).toBe('r1')
    expect(next?.pages[0].more).toBe(true)
    expect(next?.pages[0].cursor).toBe('b')
    expect(next?.pages[1]).toBe(data.pages[1])
    expect(next?.pageParams).toBe(data.pageParams)
  })

  test('keeps the anchor when nothing newer was fetched', () => {
    const next = mergeNewerLogs(
      pages(),
      { logs: [], more: true, cursor: null, newest: null },
      'c',
    )

    expect(next?.pages[0].newest).toBe('c')
    expect(next?.pages[0].logs).toHaveLength(3)
  })

  test('only adds rows when the head changed while fetching', () => {
    const data = pages()
    data.pages[0].newest = 'r1'
    const next = mergeNewerLogs(
      data,
      { logs: [log('r0', '5', 0)], more: true, cursor: 'r0', newest: 'r0' },
      'c',
    )

    expect(next?.pages[0].newest).toBe('r1')
    expect(next?.pages[0].logs.map((row) => row.$id)).toContain('r0')
  })

  test('returns the same data when the head changed and nothing is new', () => {
    const data = pages()
    data.pages[0].newest = 'r1'

    expect(
      mergeNewerLogs(
        data,
        { logs: [log('c', '3', 0)], more: true, cursor: 'c', newest: 'c' },
        'c',
      ),
    ).toBe(data)
  })

  test('takes the paging of a fetch from the top when the head had no anchor', () => {
    const data: LogPages = {
      pages: [
        { logs: [log('r1', '5', 1)], more: false, cursor: null, newest: null },
      ],
      pageParams: [null],
    }
    const next = mergeNewerLogs(
      data,
      {
        logs: [log('r1', '5', 1), log('r0', '5', 0)],
        more: true,
        cursor: 'r0',
        newest: 'r1',
      },
      null,
    )

    expect(next?.pages[0]).toEqual({
      logs: [log('r0', '5', 0), log('r1', '5', 1)],
      more: true,
      cursor: 'r0',
      newest: 'r1',
    })
  })

  test('passes undefined through', () => {
    expect(
      mergeNewerLogs(undefined, { logs: [], more: false, cursor: null }, null),
    ).toBeUndefined()
  })
})
