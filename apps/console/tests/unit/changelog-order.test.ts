import { describe, expect, test } from 'bun:test'
import { compareChangelogEntries } from '../../src/lib/changelog/order'

describe('compareChangelogEntries', () => {
  test('orders newest first, and later same-day entries first', () => {
    const entries = [
      { slug: '2026-10-21-1', date: '2026-10-21' },
      { slug: '2026-09-24', date: '2026-09-24' },
      { slug: '2026-10-21-2', date: '2026-10-21' },
      { slug: '2026-10-21-10', date: '2026-10-21' },
      { slug: '2026-10-21', date: '2026-10-21' },
    ]

    expect(entries.sort(compareChangelogEntries).map((e) => e.slug)).toEqual([
      '2026-10-21-10',
      '2026-10-21-2',
      '2026-10-21-1',
      '2026-10-21',
      '2026-09-24',
    ])
  })
})
