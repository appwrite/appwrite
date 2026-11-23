import { describe, expect, test } from 'bun:test'
import { shouldSkipSentryError } from '@/lib/sentry/skip-error'
import { canUseWebGL, isWebGLContextError } from '@/lib/webgl'

describe('isWebGLContextError', () => {
  test('matches Three.js WebGL creation failures', () => {
    expect(isWebGLContextError(new Error('Error creating WebGL context.'))).toBe(
      true,
    )
    expect(
      isWebGLContextError(new Error('Could not create a WebGL context')),
    ).toBe(true)
  })

  test('ignores unrelated errors', () => {
    expect(isWebGLContextError(new Error('Network request failed'))).toBe(false)
  })
})

describe('shouldSkipSentryError', () => {
  test('skips WebGL context creation errors', () => {
    expect(
      shouldSkipSentryError(new Error('Error creating WebGL context.')),
    ).toBe(true)
  })
})

describe('canUseWebGL', () => {
  test('returns a boolean in jsdom', () => {
    expect(typeof canUseWebGL()).toBe('boolean')
  })
})
