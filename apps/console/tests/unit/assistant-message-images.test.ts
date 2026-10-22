import { describe, expect, test } from 'bun:test'
import { isLoadableMessageImage } from '@/lib/assistant/message-images'

const origin = 'https://cloud.appwrite.io'

describe('isLoadableMessageImage', () => {
  test('loads images on the console origin', () => {
    for (const src of [
      '/images/logo.png',
      'images/logo.png',
      'https://cloud.appwrite.io/images/logo.png',
    ]) {
      expect(isLoadableMessageImage(src, origin)).toBe(true)
    }
  })

  test('does not load images from anywhere else', () => {
    for (const src of [
      'https://collector.example/pixel.png?d=secret',
      '//collector.example/pixel.png',
      'http://cloud.appwrite.io/images/logo.png',
      'https://fra.cloud.appwrite.io/v1/avatars/image?url=https://collector.example',
      'data:image/png;base64,iVBORw0KGgo=',
    ]) {
      expect(isLoadableMessageImage(src, origin)).toBe(false)
    }
  })

  test('loads nothing without a browser origin', () => {
    expect(isLoadableMessageImage('/images/logo.png', '')).toBe(false)
  })
})
