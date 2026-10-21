import { describe, expect, test } from 'bun:test'
import { DPA_PDF_PATH, getDpaDownloadUrl } from '@/lib/legal/dpa'

describe('getDpaDownloadUrl', () => {
  test('appends a cache-busting query to the DPA path', () => {
    expect(getDpaDownloadUrl(1_700_000_000_000)).toBe(
      `${DPA_PDF_PATH}?v=1700000000000`,
    )
  })

  test('uses the current time when no version is passed', () => {
    const before = Date.now()
    const url = getDpaDownloadUrl()
    const after = Date.now()
    const version = Number(
      new URL(url, 'https://appwrite.io').searchParams.get('v'),
    )

    expect(url.startsWith(`${DPA_PDF_PATH}?v=`)).toBe(true)
    expect(version).toBeGreaterThanOrEqual(before)
    expect(version).toBeLessThanOrEqual(after)
  })
})
