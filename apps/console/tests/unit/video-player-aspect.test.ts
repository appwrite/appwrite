import { describe, expect, test } from 'bun:test'
import { resolvePlayerAspect } from '@/lib/utils/video-format'

describe('resolvePlayerAspect', () => {
  const landscape = { width: 1920, height: 1080 }

  test('keeps the metadata ratio when the stream is the same shape', () => {
    expect(resolvePlayerAspect(landscape, { width: 854, height: 480 })).toEqual(
      landscape,
    )
  })

  test('takes the decoded picture when it is a different shape', () => {
    expect(
      resolvePlayerAspect(landscape, { width: 1080, height: 1920 }),
    ).toEqual({ width: 1080, height: 1920 })
  })

  test('falls back to the aspect ratio label before the stream loads', () => {
    expect(resolvePlayerAspect({ aspectRatio: '4:3' }, null)).toEqual({
      width: 4,
      height: 3,
    })
  })

  test('uses the decoded picture when there is no metadata', () => {
    expect(resolvePlayerAspect({}, { width: 640, height: 480 })).toEqual({
      width: 640,
      height: 480,
    })
  })

  test('defaults to 16:9 with nothing known', () => {
    expect(resolvePlayerAspect({}, null)).toEqual({ width: 16, height: 9 })
  })
})
