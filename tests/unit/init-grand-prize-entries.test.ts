import { describe, expect, test } from 'bun:test'
import { readFileSync } from 'fs'
import { join } from 'path'
import {
  InitGrandPrizeCsvError,
  parseCsvRows,
  parseInitGrandPrizeEntries,
} from '@/lib/init/grand-prize-entries'

const HEADER =
  'name,username,post_url,ticket_url,ticket_id,ticket_domain,post_date,within_7d_cutoff,matched_text,verified_at'

function row(overrides: Partial<Record<string, string>> = {}): string {
  const values = {
    name: 'Ada Lovelace',
    username: 'ada',
    post_url: 'https://x.com/ada/status/1',
    ticket_url: 'https://appwrite.io/init/ticket/tkt_1',
    ticket_id: 'tkt_1',
    ticket_domain: 'appwrite.io',
    post_date: '2026-09-01T10:00:00Z',
    within_7d_cutoff: 'true',
    matched_text: 'Got my Init ticket',
    verified_at: '2026-09-03T08:00:00Z',
    ...overrides,
  }
  return HEADER.split(',')
    .map((column) => values[column as keyof typeof values] ?? '')
    .join(',')
}

describe('parseCsvRows', () => {
  test('handles quoted cells, escaped quotes, CRLF, and a BOM', () => {
    const text = '﻿a,b\r\n"one, two","say ""hi"""\r\n\r\nx,y\n'
    expect(parseCsvRows(text)).toEqual([
      ['a', 'b'],
      ['one, two', 'say "hi"'],
      ['x', 'y'],
    ])
  })
})

describe('parseCsvRows', () => {
  test('rejects an unterminated quoted cell instead of merging rows', () => {
    const text = 'a,b\n"broken,c\nx,y\n'
    expect(() => parseCsvRows(text)).toThrow(InitGrandPrizeCsvError)
    try {
      parseCsvRows(text)
    } catch (error) {
      expect((error as InitGrandPrizeCsvError).code).toBe('unterminated-quote')
    }
  })
})

describe('parseInitGrandPrizeEntries', () => {
  test('maps every column and keeps CSV order', () => {
    const text = [HEADER, row(), row({ name: 'Grace', username: '@grace', ticket_id: 'tkt_2' })].join('\n')
    const result = parseInitGrandPrizeEntries(text)

    expect(result.totalRows).toBe(2)
    expect(result.entries).toHaveLength(2)
    expect(result.entries[0]).toMatchObject({
      name: 'Ada Lovelace',
      username: 'ada',
      postUrl: 'https://x.com/ada/status/1',
      ticketUrl: 'https://appwrite.io/init/ticket/tkt_1',
      ticketId: 'tkt_1',
      ticketDomain: 'appwrite.io',
      postDate: '2026-09-01T10:00:00Z',
      matchedText: 'Got my Init ticket',
      verifiedAt: '2026-09-03T08:00:00Z',
    })
    expect(result.entries[1].username).toBe('grace')
    expect(result.entries[0].id).not.toBe(result.entries[1].id)
  })

  test('accepts headers in any order and with different casing', () => {
    const text = [
      'Username,Name,verified_at,matched_text,within_7d_cutoff,post_date,ticket_domain,ticket_id,ticket_url,post_url',
      'ada,Ada,v,m,true,d,appwrite.io,tkt_1,https://t,https://p',
    ].join('\n')
    const result = parseInitGrandPrizeEntries(text)
    expect(result.entries[0]).toMatchObject({ name: 'Ada', username: 'ada', ticketId: 'tkt_1' })
  })

  test('keeps rows regardless of the cutoff column and skips rows without an identity', () => {
    const text = [
      HEADER,
      row(),
      row({ username: 'late', within_7d_cutoff: 'false' }),
      row({ name: '', username: '' }),
    ].join('\n')
    const result = parseInitGrandPrizeEntries(text)

    expect(result.totalRows).toBe(3)
    expect(result.entries.map((entry) => entry.username)).toEqual(['ada', 'late'])
    expect(result.skippedIncomplete).toBe(1)
  })

  test('does not require the within_7d_cutoff column', () => {
    const columns = HEADER.split(',')
    const cutoffIndex = columns.indexOf('within_7d_cutoff')
    const header = columns.filter((column) => column !== 'within_7d_cutoff').join(',')
    const values = row().split(',')
    values.splice(cutoffIndex, 1)
    const result = parseInitGrandPrizeEntries([header, values.join(',')].join('\n'))
    expect(result.entries).toHaveLength(1)
    expect(result.entries[0].username).toBe('ada')
  })

  test('falls back to the username when the name is blank', () => {
    const result = parseInitGrandPrizeEntries([HEADER, row({ name: '' })].join('\n'))
    expect(result.entries[0].name).toBe('ada')
  })

  test('parses the sample CSV that mirrors the day 5 export', () => {
    const text = readFileSync(
      join(import.meta.dir, 'fixtures', 'init-grand-prize-entries.sample.csv'),
      'utf8',
    )
    const result = parseInitGrandPrizeEntries(text)

    expect(result.totalRows).toBe(6)
    expect(result.skippedIncomplete).toBe(0)
    expect(result.entries.map((entry) => entry.username)).toEqual([
      'ada_codes',
      'gracehopper',
      'torvalds',
      'margaret_h',
      'late_poster',
      'dmr',
    ])
    // Quoted cell with a comma and doubled quotes survives intact.
    expect(result.entries[1].matchedText).toBe(
      'Appwrite Init week is here. My ticket: "tkt_01J6GRACE"',
    )
    // Blank name falls back to the username so the wheel always has a label.
    expect(result.entries[3].name).toBe('margaret_h')
    // A false within_7d_cutoff no longer excludes the row.
    expect(result.entries[4].username).toBe('late_poster')
    expect(result.entries.map((entry) => entry.ticketId)).toEqual([
      'tkt_01J6ADA',
      'tkt_01J6GRACE',
      'tkt_01J6LINUS',
      'tkt_01J6MARG',
      'tkt_01J6LATE',
      'tkt_01J6DMR',
    ])
  })

  test('rejects an empty file', () => {
    expect(() => parseInitGrandPrizeEntries('')).toThrow(InitGrandPrizeCsvError)
    expect(() => parseInitGrandPrizeEntries('\n\n')).toThrow(InitGrandPrizeCsvError)
  })

  test('reports the missing required columns', () => {
    try {
      parseInitGrandPrizeEntries('name,username\nAda,ada')
      throw new Error('expected a parse error')
    } catch (error) {
      expect(error).toBeInstanceOf(InitGrandPrizeCsvError)
      const csvError = error as InitGrandPrizeCsvError
      expect(csvError.code).toBe('missing-columns')
      expect(csvError.missingColumns).toContain('post_url')
      expect(csvError.missingColumns).toContain('verified_at')
      expect(csvError.missingColumns).not.toContain('name')
    }
  })
})
