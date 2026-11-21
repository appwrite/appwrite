import { describe, expect, test } from 'bun:test'
import {
  isDocsProductNavNew,
  isProductNavItemNew,
} from '@/lib/products/new-badge'

describe('Videos New badge', () => {
  test('shows for three months from the December 1 launch', () => {
    expect(
      isProductNavItemNew('videos', new Date('2026-12-01T00:00:00Z')),
    ).toBe(true)
    expect(
      isProductNavItemNew('videos', new Date('2027-02-28T23:59:59Z')),
    ).toBe(true)
    expect(
      isProductNavItemNew('videos', new Date('2027-03-01T00:00:00Z')),
    ).toBe(false)
  })

  test('marks the Videos docs link in the docs sidebar', () => {
    const during = new Date('2026-12-15T00:00:00Z')
    expect(isDocsProductNavNew('/docs/products/videos', during)).toBe(true)
    expect(isDocsProductNavNew('/docs/products/videos/', during)).toBe(true)
    expect(
      isDocsProductNavNew(
        '/docs/products/videos',
        new Date('2027-03-02T00:00:00Z'),
      ),
    ).toBe(false)
  })
})
