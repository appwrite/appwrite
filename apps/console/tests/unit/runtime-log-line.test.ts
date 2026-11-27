import { describe, expect, test } from 'bun:test'
import {
  formatRawLog,
  logfmtFields,
  normalizeLevel,
  parseRuntimeLogLine,
  stripAnsi,
  summarizeRuntimeLogMessage,
} from '@/lib/runtime-log-line'

describe('runtime log line', () => {
  describe('json', () => {
    test('reads level and message, keeping the rest as fields', () => {
      const line = parseRuntimeLogLine(
        '{"level":"warn","msg":"slow query","duration":812,"table":"orders"}',
      )
      expect(line).toEqual({
        format: 'json',
        level: 'warn',
        message: 'slow query',
        fields: [
          { key: 'duration', value: '812' },
          { key: 'table', value: 'orders' },
        ],
      })
    })

    test('maps pino numeric levels', () => {
      const line = parseRuntimeLogLine(
        '{"level":50,"time":1732370115000,"pid":1,"msg":"boom"}',
      )
      expect(line.level).toBe('error')
      expect(line.message).toBe('boom')
      expect(line.fields.map((f) => f.key)).toEqual(['time', 'pid'])
    })

    test('flattens nested objects to dotted keys', () => {
      const line = parseRuntimeLogLine(
        JSON.stringify({
          severity: 'ERROR',
          message: 'request failed',
          err: { type: 'TypeError', stack: 'TypeError: x\n    at y' },
          tags: ['a', 'b'],
        }),
      )
      expect(line.level).toBe('error')
      expect(line.fields).toEqual([
        { key: 'err.type', value: 'TypeError' },
        { key: 'err.stack', value: 'TypeError: x\n    at y' },
        { key: 'tags', value: '["a","b"]' },
      ])
    })

    test('reads OpenTelemetry and Serilog shapes', () => {
      const otel = parseRuntimeLogLine(
        '{"severityText":"WARN2","severityNumber":14,"body":"retrying"}',
      )
      expect(otel.level).toBe('warn')
      expect(otel.message).toBe('retrying')
      expect(otel.fields).toEqual([])

      const numberOnly = parseRuntimeLogLine(
        '{"severityNumber":17,"body":"export failed","attempt":2}',
      )
      expect(numberOnly.level).toBe('error')
      expect(numberOnly.fields).toEqual([{ key: 'attempt', value: '2' }])

      const serilog = parseRuntimeLogLine(
        '{"@t":"2026-11-23T13:55:15Z","@l":"Debug","@m":"cache miss"}',
      )
      expect(serilog.level).toBe('debug')
      expect(serilog.message).toBe('cache miss')
    })

    test('keeps an object without level or message structured', () => {
      const line = parseRuntimeLogLine('{"userId":"u1","count":3}')
      expect(line.format).toBe('json')
      expect(line.level).toBeNull()
      expect(line.message).toBe('')
      expect(line.fields).toHaveLength(2)
    })

    test('leaves arrays, scalars and broken JSON as text', () => {
      for (const raw of ['[1,2,3]', '{"a":', '{not json}', '42']) {
        expect(parseRuntimeLogLine(raw).format).toBe('text')
      }
    })

    test('reads JSON wrapped in color codes', () => {
      const esc = String.fromCharCode(27)
      const line = parseRuntimeLogLine(
        `${esc}[32m{"level":"info","msg":"ok"}${esc}[0m`,
      )
      expect(line.format).toBe('json')
      expect(line.message).toBe('ok')
    })
  })

  describe('logfmt', () => {
    test('reads quoted and bare values', () => {
      const line = parseRuntimeLogLine(
        'time=2026-11-23T13:55:15Z level=error msg="db timeout" attempt=3',
      )
      expect(line).toEqual({
        format: 'logfmt',
        level: 'error',
        message: 'db timeout',
        fields: [
          { key: 'time', value: '2026-11-23T13:55:15Z' },
          { key: 'attempt', value: '3' },
        ],
      })
    })

    test('leaves prose with an equals sign as text', () => {
      expect(parseRuntimeLogLine('set x=1 and y=2').format).toBe('text')
      expect(parseRuntimeLogLine('a=1 b=2').format).toBe('text')
    })
  })

  describe('text', () => {
    test('reads a leading level word', () => {
      expect(parseRuntimeLogLine('ERROR could not connect').level).toBe('error')
      expect(parseRuntimeLogLine('[warn] disk almost full').level).toBe('warn')
      expect(parseRuntimeLogLine('info: listening').level).toBe('info')
      expect(parseRuntimeLogLine('DEBUG| tick').level).toBe('debug')
    })

    test('reads uncaught exceptions as errors', () => {
      expect(
        parseRuntimeLogLine('TypeError: Cannot read properties of undefined')
          .level,
      ).toBe('error')
      expect(parseRuntimeLogLine('Error: boom').level).toBe('error')
      expect(
        parseRuntimeLogLine('java.lang.IllegalStateException: closed').level,
      ).toBe('error')
    })

    test('names no level for ordinary text', () => {
      const line = parseRuntimeLogLine('Listening on port 3000')
      expect(line).toEqual({
        format: 'text',
        level: null,
        message: 'Listening on port 3000',
        fields: [],
      })
      expect(parseRuntimeLogLine('e: not a level').level).toBeNull()
    })

    test('leaves the level word out of the message', () => {
      expect(parseRuntimeLogLine('[warn] disk almost full').message).toBe(
        'disk almost full',
      )
      expect(parseRuntimeLogLine('ERROR could not connect').message).toBe(
        'could not connect',
      )
      // A line that is only a level keeps it as its message
      expect(parseRuntimeLogLine('ERROR').message).toBe('ERROR')
    })

    test('reads prose as prose, not as a level', () => {
      expect(parseRuntimeLogLine('Alert sent to the on-call team').level).toBe(
        null,
      )
      expect(parseRuntimeLogLine('Trace id 42 started').level).toBeNull()
      // A bracketed status code is part of the message
      const line = parseRuntimeLogLine('[200] /docs/getting-started 12ms')
      expect(line.level).toBeNull()
      expect(line.message).toBe('[200] /docs/getting-started 12ms')
    })

    test('reads the level after a PostgreSQL timestamp and pid', () => {
      const line = parseRuntimeLogLine(
        '2026-11-27 16:46:23.447 UTC [727] ERROR:  duplicate key value violates unique constraint "orders_pkey"',
      )
      expect(line.level).toBe('error')
      expect(line.message).toBe(
        'duplicate key value violates unique constraint "orders_pkey"',
      )
      expect(
        parseRuntimeLogLine(
          '2026-11-27 16:46:23.447 UTC [727] LOG:  checkpoint complete',
        ),
      ).toMatchObject({ level: 'info', message: 'checkpoint complete' })
      expect(
        parseRuntimeLogLine(
          '2026-11-27 16:46:23.447 UTC [727] WARNING:  there is no transaction in progress',
        ).level,
      ).toBe('warn')
    })

    test('reads the level after a MySQL timestamp and thread', () => {
      const line = parseRuntimeLogLine(
        '2026-11-27T10:00:00.123456Z 0 [Warning] [MY-010068] [Server] CA certificate ca.pem is self signed.',
      )
      expect(line.level).toBe('warn')
      expect(line.message).toBe(
        '[MY-010068] [Server] CA certificate ca.pem is self signed.',
      )
    })

    test('drops a leading timestamp even without a level', () => {
      const line = parseRuntimeLogLine(
        '[2026-11-27 10:00:00] queue drained in 12ms',
      )
      expect(line.level).toBeNull()
      expect(line.message).toBe('queue drained in 12ms')
    })

    test("keeps the logger of Python's default format", () => {
      const line = parseRuntimeLogLine('WARNING:root:retrying in 5s')
      expect(line.level).toBe('warn')
      expect(line.message).toBe('WARNING:root:retrying in 5s')
    })

    test('reads tracebacks and Next.js marks', () => {
      expect(
        parseRuntimeLogLine(
          'Traceback (most recent call last):\n  File "main.py", line 1',
        ).level,
      ).toBe('error')
      expect(
        parseRuntimeLogLine(' ⚠ Image with src "/hero.png" is missing "sizes"')
          .level,
      ).toBe('warn')
    })

    test('aligns indented lines', () => {
      expect(parseRuntimeLogLine(' GET /pricing 200 in 34ms').message).toBe(
        'GET /pricing 200 in 34ms',
      )
    })
  })

  test('normalizes level names and numbers', () => {
    expect(normalizeLevel('FATAL')).toBe('error')
    expect(normalizeLevel('Warning')).toBe('warn')
    expect(normalizeLevel('trace')).toBe('debug')
    expect(normalizeLevel(30)).toBe('info')
    expect(normalizeLevel('40')).toBe('warn')
    expect(normalizeLevel(9)).toBe('info')
    expect(normalizeLevel('nonsense')).toBeNull()
    expect(normalizeLevel(true)).toBeNull()
  })

  test('strips color codes', () => {
    const esc = String.fromCharCode(27)
    expect(stripAnsi(`${esc}[1;31mred${esc}[0m plain`)).toBe('red plain')
    expect(stripAnsi('no codes')).toBe('no codes')
  })
})

describe('logfmtFields', () => {
  test('splits a line made only of pairs, with no level or message key', () => {
    expect(logfmtFields('mode=delta orgs=a,b chunks=1')).toEqual([
      { key: 'mode', value: 'delta' },
      { key: 'orgs', value: 'a,b' },
      { key: 'chunks', value: '1' },
    ])
  })

  test('leaves prose and single pairs alone', () => {
    expect(logfmtFields('delta since x=1 (last success)')).toBeNull()
    expect(logfmtFields('retries=3')).toBeNull()
    expect(logfmtFields('a=1\nb=2')).toBeNull()
  })
})

describe('formatRawLog', () => {
  test('indents JSON objects and arrays', () => {
    expect(formatRawLog('{"a":1,"b":{"c":true}}')).toBe(
      '{\n  "a": 1,\n  "b": {\n    "c": true\n  }\n}',
    )
    expect(formatRawLog('[1,2]')).toBe('[\n  1,\n  2\n]')
  })

  test('returns anything else unchanged', () => {
    expect(formatRawLog('plain text')).toBe('plain text')
    expect(formatRawLog('{not json}')).toBe('{not json}')
  })
})

describe('summarizeRuntimeLogMessage', () => {
  test('keeps one line as it is and cuts the rest', () => {
    expect(summarizeRuntimeLogMessage('listening')).toBe('listening')
    expect(
      summarizeRuntimeLogMessage('Error: boom\n    at main (index.js:1:1)'),
    ).toBe('Error: boom …')
  })

  test('names a Python traceback by its exception', () => {
    expect(
      summarizeRuntimeLogMessage(
        'Traceback (most recent call last):\n  File "main.py", line 42, in main\n    send(user)\nTimeoutError: SMTP connection timed out\n',
      ),
    ).toBe('TimeoutError: SMTP connection timed out')
  })
})
