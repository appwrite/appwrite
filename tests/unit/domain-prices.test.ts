import { describe, expect, test } from 'bun:test'
import { batchDomainPriceRequests } from '../../src/lib/domains/prices'

describe('domain price request batches', () => {
  test('keeps two-year .ai pricing independent from standard registrations', () => {
    expect(
      batchDomainPriceRequests(['example.com', 'example.ai', 'example.dev']),
    ).toEqual([
      { domains: ['example.com', 'example.dev'], periodYears: undefined },
      { domains: ['example.ai'], periodYears: 2 },
    ])
  })

  test('normalizes and deduplicates domains before building requests', () => {
    expect(
      batchDomainPriceRequests([
        ' Example.COM ',
        '',
        'example.com',
        'EXAMPLE.AI',
        ' example.ai ',
      ]),
    ).toEqual([
      { domains: ['example.com'], periodYears: undefined },
      { domains: ['example.ai'], periodYears: 2 },
    ])
  })

  test('caps requests at 50 without dropping or repeating domains across terms', () => {
    const standard = Array.from({ length: 101 }, (_, i) => `example${i}.com`)
    const ai = Array.from({ length: 51 }, (_, i) => `example${i}.ai`)
    const batches = batchDomainPriceRequests([...standard, ...ai])
    expect(batches.map((batch) => batch.domains.length)).toEqual([
      50, 50, 1, 50, 1,
    ])
    expect(batches.flatMap((batch) => batch.domains)).toEqual([
      ...standard,
      ...ai,
    ])
    expect(batches.map((batch) => batch.periodYears)).toEqual([
      undefined,
      undefined,
      undefined,
      2,
      2,
    ])
  })

  test('does not make a request for an empty list', () => {
    expect(batchDomainPriceRequests([])).toEqual([])
    expect(batchDomainPriceRequests(['', ' '])).toEqual([])
  })
})
