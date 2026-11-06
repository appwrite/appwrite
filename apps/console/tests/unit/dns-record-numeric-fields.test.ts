import { describe, expect, test } from 'bun:test'
import {
  RECORD_NUMBER_MAX,
  RECORD_TTL_MAX,
  parseRecordNumber,
} from '@/lib/domains/record-numeric-fields'

describe('parseRecordNumber', () => {
  test('keeps a zero the user typed', () => {
    // MX priority 0 is the highest priority a mail server can be given, and SRV takes 0 for
    // priority, weight and port. `parseInt(field) || undefined` dropped all four.
    expect(parseRecordNumber('0')).toBe(0)
    expect(parseRecordNumber(' 0 ')).toBe(0)
  })

  test('reports an empty or unparseable field as not supplied', () => {
    expect(parseRecordNumber('')).toBeUndefined()
    expect(parseRecordNumber('   ')).toBeUndefined()
    expect(parseRecordNumber('abc')).toBeUndefined()
    expect(parseRecordNumber('1.5')).toBeUndefined()
  })

  test('reads the values either side of zero', () => {
    expect(parseRecordNumber('10')).toBe(10)
    expect(parseRecordNumber('-1')).toBe(-1)
    expect(parseRecordNumber(String(RECORD_NUMBER_MAX))).toBe(RECORD_NUMBER_MAX)
    expect(parseRecordNumber(String(RECORD_TTL_MAX))).toBe(RECORD_TTL_MAX)
  })
})
