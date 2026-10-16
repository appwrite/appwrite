import { describe, expect, test } from 'bun:test'
import {
  bumpUserPhotoVersion,
  getUserPhotoVersion,
  subscribeUserPhotoVersion,
  withUserPhotoVersion,
} from '@/lib/user-photo'

describe('user photo version', () => {
  test('appends the version only once a photo changed', () => {
    const src =
      'https://cloud.appwrite.io/v1/avatars/photo?width=64&project=console'
    expect(withUserPhotoVersion(src, 0)).toBe(src)
    expect(withUserPhotoVersion(src, 3)).toBe(`${src}&v=3`)
    expect(withUserPhotoVersion('https://example.test/photo', 1)).toBe(
      'https://example.test/photo?v=1',
    )
  })

  test('bumping notifies subscribers with the new version', () => {
    const previousWindow = (globalThis as { window?: unknown }).window
    ;(globalThis as { window?: unknown }).window = new EventTarget()
    try {
      const seen: number[] = []
      const unsubscribe = subscribeUserPhotoVersion((version) =>
        seen.push(version),
      )
      const before = getUserPhotoVersion()

      expect(bumpUserPhotoVersion()).toBe(before + 1)
      expect(getUserPhotoVersion()).toBe(before + 1)
      expect(seen).toEqual([before + 1])

      unsubscribe()
      bumpUserPhotoVersion()
      expect(seen).toEqual([before + 1])
    } finally {
      ;(globalThis as { window?: unknown }).window = previousWindow
    }
  })
})
