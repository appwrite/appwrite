import { describe, expect, test } from 'bun:test'
import {
  isAtLeast,
  normalizeLevel,
  parseRuntimeLogLine,
  stripAnsi,
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

  test('compares levels', () => {
    expect(isAtLeast('error', 'warn')).toBe(true)
    expect(isAtLeast('info', 'warn')).toBe(false)
    expect(isAtLeast('debug', 'debug')).toBe(true)
  })

  test('strips color codes', () => {
    const esc = String.fromCharCode(27)
    expect(stripAnsi(`${esc}[1;31mred${esc}[0m plain`)).toBe('red plain')
    expect(stripAnsi('no codes')).toBe('no codes')
  })
})
